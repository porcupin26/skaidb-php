<?php

declare(strict_types=1);

// The SCRAM-SHA-256 handshake (PROTOCOL.md §2) against a server that
// computes the real proofs, plus the Hello that follows it.

use Skaidb\Connection;
use Skaidb\Skaidb;
use Skaidb\SkaidbException;
use SkaidbTests\FakeServer;

test('a correct password authenticates and the server signature is verified', function () {
    $srv = (new FakeServer(['password' => 'secret']))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    assert_true($db->isUsable());
    $db->close();
    $reqs = $srv->requests();
    assert_eq('connect', $reqs[0]['op']);
    assert_eq(8, $reqs[1]['op'], 'Hello follows the handshake');
    assert_eq('ada', $reqs[1]['user']);
    $srv->stop();
});

test('a wrong password is denied with the server\'s reason', function () {
    $srv = (new FakeServer(['password' => 'secret']))->start();
    assert_throws(SkaidbException::class, fn () => new Connection('127.0.0.1', $srv->port, 'ada', 'nope'), '/authentication denied: bad password/');
    $srv->stop();
});

test('a server that cannot prove it knows the password is rejected (mutual auth)', function () {
    $srv = (new FakeServer(['password' => 'secret', 'corruptServerSignature' => true]))->start();
    assert_throws(SkaidbException::class, fn () => new Connection('127.0.0.1', $srv->port, 'ada', 'secret'), '/server signature mismatch/');
    $srv->stop();
});

test('an anonymous connection skips the server-signature check', function () {
    // The fake server has password '' for every user; with an empty client
    // password the driver must not verify the signature (§2.3), so even a
    // corrupted one is accepted.
    $srv = (new FakeServer(['password' => '', 'corruptServerSignature' => true]))->start();
    $db = new Connection('127.0.0.1', $srv->port);
    assert_true($db->isUsable());
    $db->close();
    assert_eq('anonymous', $srv->requests()[1]['user']);
    $srv->stop();
});

test('the Hello frame reports client_name php and the package version', function () {
    $srv = (new FakeServer())->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $hellos = $srv->hellos();
    assert_eq(1, count($hellos));
    assert_eq('php', $hellos[0]['name']);
    assert_eq(Skaidb::VERSION, $hellos[0]['version']);
    $db->close();
    $srv->stop();
});

test('an old server answering Hello with Error is ignored', function () {
    $srv = (new FakeServer(['handle' => function (array $req) {
        if ($req['op'] === 8) {
            return \SkaidbTests\F::error('unknown opcode 8');
        }
        return \SkaidbTests\F::rows(['n'], [[1]]);
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    assert_eq([['n' => 1]], $db->query('SELECT 1 AS n')->fetchAll());
    $db->close();
    $srv->stop();
});

test('database at connect issues USE on every dial', function () {
    $srv = (new FakeServer(['handle' => fn () => \SkaidbTests\F::ddl()]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret', 'QUORUM', 5.0, 'app "quoted"');
    $ops = array_map(fn ($r) => $r['op'], $srv->requests());
    assert_eq(['connect', 8, 1], $ops);
    assert_eq('USE "app ""quoted"""', $srv->requests()[2]['sql']);
    $db->close();
    $srv->stop();
});

test('no reachable endpoint names every seed tried', function () {
    $e = assert_throws(SkaidbException::class, fn () => new Connection('127.0.0.1', 1, 'a', 'b', 'ONE', 0.5, null, false, null, false, 'skaidb', ['127.0.0.1:1', '127.0.0.1:2']), '/no reachable endpoint in /');
    assert_true(str_contains($e->getMessage(), '127.0.0.1:1') && str_contains($e->getMessage(), '127.0.0.1:2'));
});

test('seeds: the driver lands on the seed that answers', function () {
    $srv = (new FakeServer())->start();
    $db = new Connection('h', 1, 'ada', 'secret', 'QUORUM', 5.0, null, false, null, false, 'skaidb', ['127.0.0.1:1', "127.0.0.1:{$srv->port}"]);
    assert_true($db->isUsable());
    $db->close();
    $srv->stop();
});

test('seeds: a seed that refuses its handshake is walked past, like one that refuses the connection', function () {
    // A node at capacity closes every new connection unanswered (PROTOCOL.md
    // §6): the socket connects, the handshake gets end-of-file. That is a
    // failed dial, and the next seed is tried — in whichever order the
    // shuffle put them.
    $full = (new FakeServer(['refuse' => fn () => true]))->start();
    $good = (new FakeServer(['handle' => fn (array $req) => $req['op'] === 1 ? \SkaidbTests\F::rows(['n'], [[1]]) : null]))->start();
    $seeds = ["127.0.0.1:{$full->port}", "127.0.0.1:{$good->port}"];
    $open = fn (string $password, array $seeds) => new Connection('h', 1, 'ada', $password, 'QUORUM', 5.0, null, false, null, false, 'skaidb', $seeds);
    for ($i = 0; $i < 40; $i++) {
        $db = $open('secret', $seeds);
        assert_eq("127.0.0.1:{$good->port}", $db->endpoint(), "constructor {$i}");
        $db->close();
    }
    assert_eq(40, count($good->hellos()));
    // A lost connection walks the seeds the same way when it re-dials.
    $db = $open('secret', $seeds);
    for ($i = 0; $i < 20; $i++) {
        $good->closeAll();
        assert_throws(SkaidbException::class, fn () => $db->query('SELECT 1'), '/^connection closed by server/');
        assert_eq(1, $db->query('SELECT 1')->rowCount(), "re-dial {$i}");
    }
    $db->close();
    // Every seed turning the handshake away: all of them are named, with
    // the last one's reason.
    $other = (new FakeServer(['refuse' => fn () => true]))->start();
    $e = assert_throws(
        SkaidbException::class,
        fn () => $open('secret', ["127.0.0.1:{$full->port}", "127.0.0.1:{$other->port}"]),
        '/^no reachable endpoint in 127\.0\.0\.1:\d+, 127\.0\.0\.1:\d+: connection closed by server$/'
    );
    assert_true(str_contains($e->getMessage(), ":{$full->port}") && str_contains($e->getMessage(), ":{$other->port}"));
    // A single endpoint has nothing to walk: its own error, as it comes.
    assert_throws(SkaidbException::class, fn () => new Connection('127.0.0.1', $full->port, 'ada', 'secret', 'QUORUM', 5.0), '/^connection closed by server$/');
    // A refused login is the caller's, and the same on every member: it
    // stops the walk instead of being tried on the next seed.
    $denied = 0;
    for ($i = 0; $i < 40; $i++) {
        $before = $good->connections();
        try {
            $open('wrong', ["127.0.0.1:{$good->port}", "127.0.0.1:{$good->port}"])->close();
            fail('a wrong password connected');
        } catch (SkaidbException $e) {
            assert_match('/^authentication denied: bad password$/', $e->getMessage());
            assert_eq($before + 1, $good->connections(), 'the denial was tried again on the next seed');
            $denied++;
        }
    }
    assert_eq(40, $denied);
    $full->stop();
    $other->stop();
    $good->stop();
});
