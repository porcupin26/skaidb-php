<?php

declare(strict_types=1);

// Pool load balancing (PROTOCOL.md §6.1): policies, idle order, health
// ejection and the inline probe that re-admits a member, member discovery
// (SHOW ENDPOINTS) with its inline refresh, and read-your-writes affinity —
// against fake members, each a forked server serving many connections.

use Skaidb\Connection;
use Skaidb\Pool;
use Skaidb\PoolMember;
use Skaidb\SkaidbException;
use SkaidbTests\F;
use SkaidbTests\FakeServer;

/**
 * A fake skaidb member: the SCRAM handshake on every connection, then
 * `SELECT …` answers one row naming the member (after `delay` seconds),
 * `SHOW ENDPOINTS` answers `listing` (rows of [endpoint, state]; `local`
 * marks the member's own row), `SHOW DATABASES` (the pool's probe) answers
 * no rows, anything else is a one-row mutation. `refuse` closes each new
 * connection before the handshake; `old_server` (or no listing) answers
 * SHOW ENDPOINTS with a parse error. The server runs in a forked child, so
 * the knobs travel through a control file it re-reads on every accept and
 * request.
 */
final class FakeMember
{
    public readonly int $port;

    public readonly string $endpoint;

    private string $ctl;

    private FakeServer $srv;

    private int $owner;

    /** @var array{delay:float, refuse:bool, old_server:bool, listing:array<int,array{0:string,1:string}>|null} */
    private array $knobs = ['delay' => 0.0, 'refuse' => false, 'old_server' => false, 'listing' => null];

    public function __construct(public readonly string $name)
    {
        $this->owner = getmypid();
        $this->ctl = tempnam(sys_get_temp_dir(), 'skaidb-member-');
        $this->save();
        $this->srv = new FakeServer([
            'handle' => fn (array $req) => $this->answer($req),
            'refuse' => fn () => $this->load()['refuse'],
        ]);
        $this->srv->start();
        $this->port = $this->srv->port;
        $this->endpoint = "127.0.0.1:{$this->port}";
    }

    public function set(string $knob, mixed $value): void
    {
        $this->knobs[$knob] = $value;
        $this->save();
    }

    /** How many connections the member accepted (refused ones not counted). */
    public function connections(): int
    {
        return $this->srv->connections();
    }

    /** @return array<int,string> every SQL statement the member ran, in order */
    public function statements(): array
    {
        return array_values(array_map(
            fn (array $r) => $r['sql'],
            array_filter($this->srv->requests(), fn (array $r) => $r['op'] === 1)
        ));
    }

    public function __destruct()
    {
        if (getmypid() === $this->owner) {
            $this->srv->stop();
            @unlink($this->ctl);
        }
    }

    private function save(): void
    {
        file_put_contents($this->ctl . '.new', serialize($this->knobs));
        rename($this->ctl . '.new', $this->ctl);
    }

    /** In the child: the knobs as the test last set them. */
    private function load(): array
    {
        $raw = @file_get_contents($this->ctl);
        return $raw === false || $raw === '' ? $this->knobs : unserialize($raw);
    }

    /** In the child: the reply to one post-handshake request. */
    private function answer(array $req): ?string
    {
        if ($req['op'] !== 1) {
            return null; // Hello: a Ddl ack
        }
        $sql = $req['sql'];
        $k = $this->load();
        if ($sql === 'SHOW ENDPOINTS') {
            if ($k['old_server'] || $k['listing'] === null) {
                return F::error('parse error: expected TABLES, found ENDPOINTS');
            }
            $me = '127.0.0.1:' . $this->srv->port;
            return F::rows(['endpoint', 'state', 'local'], array_map(
                fn (array $r) => [$r[0], $r[1], $r[0] === $me],
                $k['listing']
            ));
        }
        if ($sql === 'SHOW DATABASES') {
            return F::rows(['name'], []);
        }
        if (str_starts_with($sql, 'SELECT')) {
            if ($k['delay'] > 0) {
                usleep((int) ($k['delay'] * 1_000_000));
            }
            return F::rows(['member'], [[$this->name]]);
        }
        return F::mutation(1);
    }
}

/** @return array<int,FakeMember> */
function lb_members(string ...$names): array
{
    return array_map(fn (string $n) => new FakeMember($n), $names);
}

/** A pool over $members (or over `seeds:` when given), with any Pool option. */
function lb_pool(array $members, mixed ...$opts): Pool
{
    $seeds = $opts['seeds'] ?? array_map(fn (FakeMember $m) => $m->endpoint, $members);
    unset($opts['seeds']);
    return new Pool(['user' => 'ada', 'password' => 'secret', 'timeout' => 5.0, 'seeds' => $seeds], 10, ...$opts);
}

/** Which member served one checkout. */
function lb_who(Pool $p, string|int|null $affinity = null): string
{
    return $p->withConnection(fn (Connection $c) => $c->query('SELECT member()')->fetchColumn(), $affinity);
}

/** @return array<int,string> the members that served $n checkouts */
function lb_serve(Pool $p, int $n, string|int|null $affinity = null): array
{
    $served = [];
    for ($i = 0; $i < $n; $i++) {
        $served[] = lb_who($p, $affinity);
    }
    return $served;
}

function lb_state(Pool $p, FakeMember $m): string
{
    foreach ($p->members() as $row) {
        if ($row['endpoint'] === $m->endpoint) {
            return $row['state'];
        }
    }
    fail("{$m->endpoint} is not a member");
}

/** The pool's own record of a member (private state, for assertions). */
function lb_member(Pool $p, FakeMember $m): PoolMember
{
    return (fn () => $this->members[$m->endpoint])->call($p);
}

/** Run $fn inside the pool's scope, for the private state a test pokes at. */
function lb_inside(Pool $p, \Closure $fn): mixed
{
    return \Closure::bind($fn, $p, Pool::class)();
}

/** Every member lists these [endpoint => state] for SHOW ENDPOINTS. */
function lb_list(array $members, array $states): void
{
    ksort($states);
    $rows = [];
    foreach ($states as $ep => $state) {
        $rows[] = [(string) $ep, $state];
    }
    foreach ($members as $m) {
        $m->set('listing', $rows);
    }
}

/** Three members, c resyncing, listing one another. @return array<int,FakeMember> */
function lb_cluster(): array
{
    $ms = lb_members('a', 'b', 'c');
    [$a, $b, $c] = $ms;
    lb_list($ms, [$a->endpoint => 'ready', $b->endpoint => 'ready', $c->endpoint => 'resyncing']);
    return $ms;
}

// ---- policies ------------------------------------------------------------

test('balancing: round_robin rotates over every member', function () {
    $ms = lb_members('a', 'b', 'c');
    $p = lb_pool($ms, loadBalancing: 'round_robin');
    $served = lb_serve($p, 9);
    $sorted = $served;
    sort($sorted);
    assert_eq(['a', 'a', 'a', 'b', 'b', 'b', 'c', 'c', 'c'], $sorted);
    // Strict rotation: three in a row are three different members.
    for ($i = 0; $i < 9; $i += 3) {
        assert_eq(3, count(array_unique(array_slice($served, $i, 3))), implode(',', $served));
    }
    $p->close();
});

test('balancing: least_latency prefers the faster member', function () {
    [$a, $b] = lb_members('a', 'b');
    $b->set('delay', 0.02);
    $p = lb_pool([$a, $b], loadBalancing: 'least_latency', ejectLatencyFactor: 0.0);
    $counts = array_count_values(lb_serve($p, 60));
    assert_true(($counts['a'] ?? 0) > 48, 'a served ' . ($counts['a'] ?? 0) . ' of 60');
    assert_true(($counts['b'] ?? 0) > 0, 'b was measured at least once');
    $p->close();
});

test('balancing: least_latency re-measures a member it has not used lately', function () {
    [$a, $b] = lb_members('a', 'b');
    $b->set('delay', 0.02);
    $p = lb_pool([$a, $b], loadBalancing: 'least_latency', ejectLatencyFactor: 0.0);
    lb_serve($p, 20);
    // b is slow, then recovers: an idle pool notices once b's average goes
    // stale instead of never asking b again.
    $b->set('delay', 0.0);
    lb_member($p, $b)->lastSample -= 10;
    assert_true(in_array('b', lb_serve($p, 3), true), 'b re-measured');
    $p->close();
});

test('balancing: random (the default) reuses one connection, as before', function () {
    $ms = lb_members('a', 'b', 'c');
    $p = lb_pool($ms);
    // One connection, reused: random never targets a member.
    assert_eq(1, count(array_unique(lb_serve($p, 5))));
    $p->close();
});

test('balancing: an unknown policy or idle order is refused', function () {
    assert_throws(SkaidbException::class, fn () => new Pool([], 10, loadBalancing: 'nearest'), '/unknown loadBalancing/');
    assert_throws(SkaidbException::class, fn () => new Pool([], 10, idleOrder: 'random'), '/unknown idleOrder/');
    assert_throws(SkaidbException::class, fn () => new Pool([], 10, ejectErrors: 0), '/ejectErrors/');
    assert_eq('round_robin', Pool::ROUND_ROBIN);
});

// ---- idle order ----------------------------------------------------------

/** Three connections to one member, checked out together and returned in order. */
function lb_three(FakeMember $m, mixed ...$opts): array
{
    $p = lb_pool([$m], ...$opts);
    $conns = [$p->acquire(), $p->acquire(), $p->acquire()];
    foreach ($conns as $c) {
        $p->release($c);
    }
    return [$p, $conns];
}

test('balancing: lifo hands out the most recently returned connection', function () {
    [$m] = lb_members('a');
    [$p, $conns] = lb_three($m);
    assert_true($p->acquire() === $conns[2]);
    $p->close();
});

test('balancing: fifo hands out the longest idle connection', function () {
    [$m] = lb_members('a');
    [$p, $conns] = lb_three($m, idleOrder: 'fifo');
    $got = [$p->acquire(), $p->acquire(), $p->acquire()];
    assert_true($got === $conns);
    $p->close();
});

// ---- health ejection -----------------------------------------------------

test('balancing: a failing member is ejected, then the first checkout after the backoff re-admits it', function () {
    [$a, $b, $c] = lb_members('a', 'b', 'c');
    $b->set('refuse', true);
    $p = lb_pool([$a, $b, $c], loadBalancing: 'round_robin', ejectErrors: 2, ejectBackoff: 0.5);
    $served = lb_serve($p, 12);
    assert_true(!in_array('b', $served, true), implode(',', $served));
    assert_eq('ejected', lb_state($p, $b));
    // Healthy again, but nothing probes before the backoff is over.
    $b->set('refuse', false);
    lb_who($p);
    assert_eq('ejected', lb_state($p, $b));
    $m = lb_member($p, $b);
    $m->ewma = 9.9;
    $m->samples = 50;
    // No background thread: the first checkout after the backoff probes b.
    usleep(550_000);
    lb_who($p);
    assert_eq('ready', lb_state($p, $b));
    assert_eq(0, $m->errors, 'a re-admitted member starts with its errors cleared');
    // ... and a fresh average (this checkout may have measured one sample).
    assert_true($m->samples <= 1 && $m->ewma !== 9.9, "samples {$m->samples}");
    assert_true(in_array('b', lb_serve($p, 6), true));
    $p->close();
});

test('balancing: a probe that fails ejects the member again for twice as long', function () {
    [$a, $b, $c] = lb_members('a', 'b', 'c');
    $b->set('refuse', true);
    $p = lb_pool([$a, $b, $c], loadBalancing: 'round_robin', ejectErrors: 1, ejectBackoff: 0.2);
    lb_serve($p, 6);
    $m = lb_member($p, $b);
    assert_eq(1, $m->ejections);
    usleep(250_000);
    // This checkout probes b (refused), then falls through to another member.
    assert_true(in_array(lb_who($p), ['a', 'c'], true));
    assert_eq(2, $m->ejections);
    assert_eq('ejected', lb_state($p, $b));
    assert_true(lb_inside($p, fn () => $this->backoffFor($m)) >= 0.4);
    $p->close();
});

test('balancing: an ejected member\'s idle connections are closed', function () {
    [$a, $b] = lb_members('a', 'b');
    $p = lb_pool([$a, $b], loadBalancing: 'round_robin');
    $conns = [$p->acquire(), $p->acquire(), $p->acquire(), $p->acquire()];
    foreach ($conns as $c) {
        $p->release($c);
    }
    $toB = array_values(array_filter($conns, fn (Connection $c) => $c->endpoint() === $b->endpoint));
    assert_eq(2, count($toB));
    for ($i = 0; $i < 3; $i++) {
        lb_inside($p, fn () => $this->failed($b->endpoint));
    }
    assert_eq('ejected', lb_state($p, $b));
    assert_true(!$toB[0]->isUsable() && !$toB[1]->isUsable(), 'closed');
    assert_eq([2, 0], array_map(fn ($ep) => array_values(array_filter($p->members(), fn ($r) => $r['endpoint'] === $ep))[0]['idle'], [$a->endpoint, $b->endpoint]));
    $p->close();
});

test('balancing: a consistently slow member is ejected', function () {
    [$a, $b, $c] = lb_members('a', 'b', 'c');
    $b->set('delay', 0.05);
    $p = lb_pool([$a, $b, $c], loadBalancing: 'round_robin', ejectLatencyFloor: 0.02, ejectBackoff: 30.0);
    lb_serve($p, 45);
    assert_eq('ejected', lb_state($p, $b));
    assert_true(!in_array('b', lb_serve($p, 6), true));
    $p->close();
});

test('balancing: an ejected member is not ejected again by late round trips', function () {
    [$a, $b, $c] = lb_members('a', 'b', 'c');
    $b->set('delay', 0.05);
    $p = lb_pool([$a, $b, $c], loadBalancing: 'round_robin', ejectLatencyFloor: 0.02, ejectBackoff: 30.0);
    // A connection to b checked out before the ejection ...
    $held = null;
    while ($held === null) {
        $conn = $p->acquire();
        if ($conn->endpoint() === $b->endpoint) {
            $held = $conn;
        } else {
            $p->release($conn);
        }
    }
    lb_serve($p, 45);
    $m = lb_member($p, $b);
    assert_eq('ejected', lb_state($p, $b));
    $until = $m->ejectedUntil;
    // ... keeps running slow statements after it.
    for ($i = 0; $i < 5; $i++) {
        assert_eq('b', $held->query('SELECT member()')->fetchColumn());
    }
    assert_eq([1, $until], [$m->ejections, $m->ejectedUntil]);
    $p->release($held); // b is ejected: closed, not kept
    assert_true(!$held->isUsable());
    $p->close();
});

test('balancing: one slow statement does not eject a member', function () {
    [$a, $b, $c] = lb_members('a', 'b', 'c');
    $p = lb_pool([$a, $b, $c], loadBalancing: 'round_robin', ejectLatencyFloor: 0.05);
    lb_serve($p, 36);
    $b->set('delay', 0.5);
    while (lb_who($p) !== 'b') {
    }
    $b->set('delay', 0.0);
    lb_serve($p, 6);
    assert_eq('ready', lb_state($p, $b));
    $p->close();
});

test('balancing: a failed dial falls through to the next member', function () {
    [$a, $b, $c] = lb_members('a', 'b', 'c');
    $b->set('refuse', true); // closes each connection before the handshake
    $p = lb_pool([$a, $b, $c], loadBalancing: 'round_robin', ejectErrors: 100);
    $held = [];
    for ($i = 0; $i < 6; $i++) {
        $conn = $p->acquire();
        assert_true($conn->isUsable(), 'the connection that fell through is usable');
        assert_true($conn->endpoint() !== $b->endpoint);
        $held[] = $conn;
    }
    // Every connection a or c accepted was handed out: none was wasted.
    assert_eq(6, $a->connections() + $c->connections());
    assert_eq(2, lb_member($p, $b)->errors);
    $p->close();
});

test('balancing: the last usable member is never ejected', function () {
    [$a, $b, $c] = lb_members('a', 'b', 'c');
    $b->set('refuse', true);
    $c->set('refuse', true);
    $p = lb_pool([$a, $b, $c], loadBalancing: 'round_robin', ejectErrors: 1);
    for ($i = 0; $i < 6; $i++) {
        assert_eq('a', lb_who($p));
    }
    $a->set('refuse', true);
    // Idle connections to a still work (how many depends on the dial order
    // above); once they are used up, every dial fails.
    $held = [];
    assert_throws(SkaidbException::class, function () use ($p, &$held) {
        for ($i = 0; $i < 12; $i++) {
            $held[] = $p->acquire();
        }
    }, '/no reachable endpoint/');
    assert_eq('ready', lb_state($p, $a));
    $p->close();
});

test('balancing: a refused login is not a member fault; a capacity refusal is', function () {
    assert_true(!Pool::memberFault(new SkaidbException('authentication denied: bad password')));
    assert_true(Pool::memberFault(new SkaidbException('authentication denied: too many connections: 500')));
    assert_true(!Pool::memberFault(new SkaidbException('server signature mismatch (mutual auth failed)')));
    assert_true(Pool::memberFault(new SkaidbException('connection closed by server')));
    assert_true(Pool::memberFault(new SkaidbException('connection timed out')));
    assert_true(Pool::memberFault(new SkaidbException('no reachable endpoint in 127.0.0.1:1: Connection refused (111)')));
    // Wrong credentials against a pool: the error surfaces, nobody is ejected.
    [$a, $b] = lb_members('a', 'b');
    $p = new Pool(['user' => 'ada', 'password' => 'nope', 'timeout' => 5.0, 'seeds' => [$a->endpoint, $b->endpoint]],
        10, loadBalancing: 'round_robin', ejectErrors: 1);
    for ($i = 0; $i < 3; $i++) {
        assert_throws(SkaidbException::class, fn () => $p->acquire(), '/authentication denied: bad password/');
    }
    assert_eq(['ready', 'ready'], array_column($p->members(), 'state'));
    assert_eq(0, lb_member($p, $a)->errors + lb_member($p, $b)->errors);
    $p->close();
});

// ---- discovery -----------------------------------------------------------

test('balancing: discovered members join; resyncing ones get no connections until ready', function () {
    [$a, $b, $c] = $ms = lb_cluster();
    $p = lb_pool([$a], loadBalancing: 'round_robin', discover: true, refreshInterval: 0.3);
    $served = lb_serve($p, 8);
    $who = array_values(array_unique($served));
    sort($who);
    assert_eq(['a', 'b'], $who, implode(',', $served));
    assert_eq('resyncing', lb_state($p, $c));
    // c catches up: the next refresh (inline, on a checkout after the
    // interval) lets it in, measured afresh.
    $m = lb_member($p, $c);
    $m->ewma = 9.9;
    $m->samples = 50;
    lb_list($ms, [$a->endpoint => 'ready', $b->endpoint => 'ready', $c->endpoint => 'ready']);
    usleep(350_000);
    lb_who($p);
    assert_eq([null, 0], [$m->ewma, $m->samples]);
    assert_eq('ready', lb_state($p, $c));
    assert_true(in_array('c', lb_serve($p, 6), true));
    $p->close();
});

test('balancing: a listing with a member not ready refreshes within 5 s', function () {
    [$a, $b, $c] = $ms = lb_cluster();
    $p = lb_pool([$a], loadBalancing: 'round_robin', discover: true, refreshInterval: 60.0);
    lb_who($p);
    $left = fn () => lb_inside($p, fn () => $this->nextRefresh - self::now());
    assert_true($left() <= 5.0, 'c is resyncing: next refresh in ' . $left() . ' s');
    lb_list($ms, [$a->endpoint => 'ready', $b->endpoint => 'ready', $c->endpoint => 'ready']);
    $conn = $p->acquire();
    lb_inside($p, fn () => $this->listThrough($conn));
    $p->release($conn);
    assert_true($left() > 50.0, 'all ready: next refresh in ' . $left() . ' s');
    $p->close();
});

test('balancing: a seed given by another name is not counted twice', function () {
    [$a, $b, $c] = lb_cluster();
    $p = lb_pool([$a], seeds: ["localhost:{$a->port}"], loadBalancing: 'round_robin', discover: true);
    lb_who($p);
    $rows = $p->members();
    assert_eq(3, count($rows), json_encode($rows));
    $seed = array_values(array_filter($rows, fn ($r) => $r['seed']))[0];
    assert_eq("localhost:{$a->port}", $seed['endpoint']);
    assert_eq($a->endpoint, $seed['canonical']);
    $p->close();
});

test('balancing: a member that leaves the listing is dropped', function () {
    [$a, $b, $c] = $ms = lb_cluster();
    $p = lb_pool([$a], loadBalancing: 'round_robin', discover: true, refreshInterval: 0.2);
    lb_who($p);
    assert_true(in_array($b->endpoint, array_column($p->members(), 'endpoint'), true));
    lb_list($ms, [$a->endpoint => 'ready', $c->endpoint => 'resyncing']);
    usleep(250_000);
    lb_who($p); // refreshes inline
    assert_true(!in_array($b->endpoint, array_column($p->members(), 'endpoint'), true));
    $p->close();
});

test('balancing: an old server turns discovery off and keeps the seeds', function () {
    [$a, $b, $c] = lb_cluster();
    $a->set('old_server', true);
    $p = lb_pool([$a], loadBalancing: 'round_robin', discover: true);
    assert_eq(['a'], array_values(array_unique(lb_serve($p, 4))));
    assert_true(lb_inside($p, fn () => $this->discoveryOff));
    assert_eq(1, count($p->members()));
    $p->close();
});

test('balancing: a standalone server (no rows) turns discovery off and is not asked again', function () {
    [$a] = lb_members('a');
    $a->set('listing', []);
    $p = lb_pool([$a], discover: true, refreshInterval: 0.05);
    lb_who($p);
    usleep(100_000);
    lb_serve($p, 3);
    assert_true(lb_inside($p, fn () => $this->discoveryOff));
    assert_eq(1, count(array_keys($a->statements(), 'SHOW ENDPOINTS', true)));
    assert_eq(1, count($p->members()));
    $p->close();
});

// ---- read-your-writes ----------------------------------------------------

test('balancing: reads follow the member that took the write, per affinity key', function () {
    $ms = lb_members('a', 'b', 'c');
    $p = lb_pool($ms, loadBalancing: 'round_robin', readYourWrites: 0.4);
    $wrote = $p->withConnection(function (Connection $c) {
        $c->exec('INSERT INTO t VALUES (1)');
        return $c->endpoint();
    }, 'alice');
    $writer = array_values(array_filter($ms, fn (FakeMember $m) => $m->endpoint === $wrote))[0]->name;
    assert_eq([$writer], array_values(array_unique(lb_serve($p, 6, 'alice'))));
    // Another key, or no key, still rotates.
    assert_eq(3, count(array_unique(lb_serve($p, 3, 'bob'))));
    usleep(500_000);
    assert_eq(3, count(array_unique(lb_serve($p, 3, 'alice'))));
    $p->close();
});

test('balancing: reads do not set affinity', function () {
    $ms = lb_members('a', 'b', 'c');
    $p = lb_pool($ms, loadBalancing: 'round_robin', readYourWrites: 5.0);
    lb_who($p, 'alice');
    assert_eq(3, count(array_unique(lb_serve($p, 3, 'alice'))));
    $p->close();
});

test('balancing: statement classification for read-your-writes', function () {
    foreach (['SELECT 1', '  select 1', '(SELECT 1)', "-- c\nSHOW TABLES", '/* x */ EXPLAIN SELECT 1',
              'WITH a AS (SELECT 1) SELECT * FROM a', 'DESCRIBE t', 'desc t', 'VALUES (1)', 'use app'] as $sql) {
        assert_true(!Pool::isWrite($sql), $sql);
    }
    foreach (['INSERT INTO t VALUES (1)', 'update t set a = 1', 'DELETE FROM t',
              'CREATE TABLE t (id INT PRIMARY KEY)', 'COMMIT', 'CALL p()', 'selectx', '/* SELECT */ DELETE FROM t'] as $sql) {
        assert_true(Pool::isWrite($sql), $sql);
    }
});

// ---- introspection -------------------------------------------------------

test('balancing: members() reports state, latency and connections per member', function () {
    [$a, $b] = lb_members('a', 'b');
    $p = lb_pool([$a, $b], loadBalancing: 'round_robin');
    lb_serve($p, 2);
    $held = [$p->acquire(), $p->acquire(), $p->acquire()];
    $rows = $p->members();
    $eps = [$a->endpoint, $b->endpoint];
    sort($eps, SORT_STRING);
    assert_eq($eps, array_column($rows, 'endpoint'));
    foreach ($rows as $r) {
        assert_eq(['endpoint', 'canonical', 'state', 'latency_ms', 'in_use', 'idle', 'seed'], array_keys($r));
        assert_eq('ready', $r['state']);
        assert_eq(null, $r['canonical']);
        assert_true(is_float($r['latency_ms']) && $r['latency_ms'] > 0, 'latency measured');
        assert_true($r['seed']);
    }
    assert_eq(3, array_sum(array_column($rows, 'in_use')));
    // A checked-out connection closed instead of released stops counting.
    $held[0]->close();
    $p->release($held[1]);
    assert_eq(1, array_sum(array_column($p->members(), 'in_use')));
    assert_eq(1, array_sum(array_column($p->members(), 'idle')));
    $p->close();
});
