# Pooling

Statements on one `Connection` run one at a time, and opening a connection
costs a TCP (or TLS) handshake plus SCRAM. A `Pool` keeps connections
between uses and spreads them over the cluster's members:

```php
use Skaidb\Connection;
use Skaidb\Pool;

$pool = new Pool(['user' => 'app', 'password' => $pw, 'database' => 'app',
                  'seeds' => ['db1:7000', 'db2:7000'], 'tlsCa' => '/etc/skaidb/skai-ca.crt'], 8);

$n = $pool->withConnection(fn (Connection $c) => $c->query('SELECT count(*) AS n FROM t')->fetchColumn());

$conn = $pool->acquire();
try {
    $conn->prepare('INSERT INTO t (id) VALUES (?)')->execute([1]);
} finally {
    $pool->release($conn);
}

$pool->close();
```

- The first argument is the list of `Connection` constructor arguments,
  positional or named; every pooled connection is built from it, so TLS,
  credentials and the session database all pass through. `host`/`port` or
  `seeds` name the members the pool starts from.
- `$maxsize` (default 10) bounds the connections kept **idle**, not the
  number checked out: a burst opens extras and the surplus is closed on
  release.
- `acquire()` returns an idle connection only if it is still usable and
  otherwise opens a new one. A connection the server closed while it sat
  idle looks fine locally; its next statement throws once and re-dials
  after that (in the pool's order: usable members first, the one it lost
  last), like any other connection.
- `release()` closes a connection instead of pooling it when it is broken,
  still streaming, its member is ejected, not `ready` or gone, or the pool
  is full or closed.
- `withConnection()` releases in a `finally`, whatever the callback does.

## Balancing across members

By default (`loadBalancing: 'random'`) each new connection dials the members
in random order and stays on the member it reached — fine for many clients,
but one process can end up with most of its connections on one member. Pick
a policy to spread a single process's load. The options are named
constructor arguments after `$maxsize`:

```php
$pool = new Pool(['user' => 'app', 'password' => $pw, 'database' => 'app',
                  'seeds' => ['db1:7000', 'db2:7000', 'db3:7000']], 8,
    loadBalancing: 'least_latency',   // or 'round_robin' (Pool::LEAST_LATENCY, Pool::ROUND_ROBIN)
    discover: true,                   // learn the members from the cluster
    readYourWrites: 2.0,              // seconds; see below
    idleOrder: 'fifo',
);
```

| Option | Default | What it does |
|---|---|---|
| `loadBalancing` | `'random'` | `'round_robin'`: checkouts rotate over the usable members. `'least_latency'`: of two members picked at random, the one with the lower round-trip average × checked-out connections (near and idle members win); a member not measured in the last 2 s scores 0, so it is tried again. |
| `idleOrder` | `'lifo'` | `'fifo'` hands out the longest-idle connection first, so every idle connection keeps being used and a server-side idle timeout never finds one. |
| `discover` | `false` | Reads the members with `SHOW ENDPOINTS` (server ≥ 0.327; no privilege needed) when a member is first reached and every `refreshInterval` after. New members are added, members that left are dropped (seeds are kept, marked `unlisted`), and members the server reports `resyncing` or `unreachable` get no new connections until they are `ready` again — the pool re-reads the list every 5 s while one is not. A member that comes back starts with a fresh latency average. An older server (an error) or a standalone one (no rows) leaves the seeds as they are, and the pool stops asking. |
| `refreshInterval` | `60.0` | Seconds between discovery refreshes. |
| `ejectErrors` | `3` | Consecutive transport failures (dial, I/O, timeout, capacity refusal) that eject a member. Statement errors and refused logins do not count. |
| `ejectLatencyFactor`, `ejectLatencyFloor` | `3.0`, `0.05` | A member whose round-trip average is above factor × the other members' median **and** more than floor seconds above it is ejected (after 10 samples on it and on another member). The fastest of a member's first three round trips seeds its average, so a statement that stalls in your own process — a CPU-bound start-up — cannot get a healthy member ejected; the warning in the PHP error log names the average, the median and the slowest of the member's last 10 round trips. A factor of `0` disables it. |
| `ejectBackoff`, `ejectBackoffMax` | `5.0`, `60.0` | Seconds an ejection lasts, doubling per repeat; a member healthy for `ejectBackoffMax` after its re-admission starts over at `ejectBackoff`. The last usable member is never ejected — a slow or flaky member beats none. |
| `readYourWrites` | `0.0` | Seconds after a write during which checkouts with the same affinity key go to the member that took the write. `0` turns it off. |

An unknown `loadBalancing` or `idleOrder` throws `SkaidbException`.

A checkout that targets a member takes an idle connection to it, or dials
it; a failed dial (refused, dropped before the handshake, at capacity)
falls through to the other usable members in random order, then to the
ones that are not usable. Every round trip (request to the first response
frame) feeds the member's latency average: the fastest of its first three
seeds it, and each one after moves it 5 %, so one slow statement barely
moves it.

### Upkeep happens inside `acquire()`

PHP has no background threads, so what another driver does on a timer the
pool does on a checkout:

- **Re-admission probe.** The first `acquire()` after an ejected member's
  backoff runs out probes it before choosing a member: it dials that member
  alone and runs `SHOW DATABASES`, bounded by the connection `timeout`.
  Success re-admits the member with a fresh latency average and its errors
  cleared (the probe connection joins the idle set); failure ejects it
  again for twice as long, and the checkout carries on with the other
  members. One checkout runs at most one probe (the member overdue
  longest), so it waits at most one `timeout` longer.
- **Discovery refresh.** The first `acquire()` after the refresh interval
  runs `SHOW ENDPOINTS` on the connection it is about to hand out.
- Between checkouts nothing runs: an ejected member stays ejected and the
  member list stays as it is until the next `acquire()`.

### Read-your-writes

The member that coordinated a write reads it back at any consistency, `ONE`
included; another member may not have applied it yet. With
`readYourWrites` set, pass an affinity key — a user or session id — when
you check out:

```php
$userId = $_SESSION['user_id'];   // the raw id, not a database lookup (see below)

$pool->withConnection(fn (Connection $c) => $c->prepare(
    'INSERT INTO notes (id, owner, body) VALUES (?, ?, ?)')->execute([$nid, $userId, $body]), $userId);

// ... the next job for the same user, within the window, goes to the same member:
$body = $pool->withConnection(function (Connection $c) use ($nid) {
    $st = $c->prepare('SELECT body FROM notes WHERE id = ?');
    $st->execute([$nid]);
    return $st->fetchColumn();
}, $userId);
```

Derive the key without querying through the same pool: take the raw id
from the session or token (`$_SESSION['user_id']`, a JWT's `sub`). A helper
that checks out a connection and then computes the key with something that
itself reads the database — a framework's "current user" loader — re-enters
the pool from inside a checkout.

Checkouts without a key share one pool-wide key, so with constant writes
everything follows the latest writer — pass a key when you balance. A
statement is a write unless its first keyword (after whitespace, comments
and opening parentheses) is `SELECT`, `SHOW`, `EXPLAIN`, `DESCRIBE`,
`DESC`, `VALUES`, `WITH` or `USE`. The pinning ends early when the member is
ejected or not `ready`.

### Watching it

`$pool->members()` returns one array per member:

```php
[['endpoint' => 'db1:7000', 'canonical' => '10.0.0.1:7000', 'state' => 'ready',
  'latency_ms' => 0.8, 'in_use' => 2, 'idle' => 3, 'seed' => true], ...]
```

`endpoint` is what the pool dials, `canonical` the member's own name once
discovered, `state` one of `ready`, `ejected`, `resyncing`, `unreachable` or
`unlisted`, `latency_ms` the round-trip average (`null` until measured — its
first three round trips seed it).
`Connection::endpoint()` tells which member a connection reached. The rules
are the same in every skaidb driver
([PROTOCOL.md §6.1](https://skaidb.org/docs/PROTOCOL.html#61-balancing-a-pool-across-members)).

Each ejection and re-admission is written with `error_log()` — wherever the
`error_log` setting points, stderr in the CLI when it is unset — with the
reason. A latency ejection names the member's average, the median it was
compared with and the slowest of its last 10 round trips, which tells a
slow member from one slow statement:

```
skaidb pool: ejecting db2:7000 (latency 516.3 ms vs median 0.2 ms; slowest of the last 10 round trips 900.0 ms)
skaidb pool: ejecting db3:7000 (3 consecutive connection failures)
skaidb pool: re-admitted db2:7000
```

## When a pool helps

PHP is share-nothing per request: a plain web request opens a connection,
runs its statements and exits, and a pool there lives for exactly one
request. The pool pays off in long-running processes — a worker consuming
a queue, a CLI job, a ReactPHP/Swoole-style server — where the same process
serves many units of work.
