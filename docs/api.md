# API reference

Everything lives in `src/Skaidb.php` under the `Skaidb` namespace. Every
error is a `Skaidb\SkaidbException`.

## `Skaidb\Skaidb`

| Constant | Value |
|---|---|
| `Skaidb::VERSION` | the package version, e.g. `'1.4.0'`; what the Hello frame reports and what a release tag must equal |
| `Skaidb::CLIENT_NAME` | `'php'` |

## `new Connection(...)`

```php
new Connection(
    string $host = 'localhost',
    int $port = 7000,
    string $user = 'anonymous',
    string $password = '',
    int|string $consistency = 'QUORUM',   // 'ONE' | 'QUORUM' | 'ALL' | 0 | 1 | 2
    float $timeout = 10.0,                // connect + read timeout, seconds
    ?string $database = null,             // USE <database> after the handshake, and after every reconnect
    bool $tls = false,
    ?string $tlsCa = null,                // PEM bundle; implies $tls
    bool $tlsInsecure = false,            // no verification; implies $tls
    string $tlsServerName = 'skaidb',     // SNI + verified name
    array $seeds = [],                    // ['host:port', ...]; wins over $host/$port when non-empty
    ?string $tlsClientCert = null,        // PEM client certificate presented in TLS; implies $tls
    ?string $tlsClientKey = null,         // its PEM key, when not inside $tlsClientCert
    string $authMechanism = 'scram'       // 'scram' | 'certificate' (TLS client certificate login)
)
```

Connects (walking the shuffled seeds until one accepts), runs the
SCRAM-SHA-256 handshake, verifies the server signature when `$password` is
non-empty, sends the Hello frame and issues `USE $database` if given.
Throws on any failure (`no reachable endpoint in <list>: ...`,
`authentication denied: ...`, `server signature mismatch (mutual auth
failed)`).

With `$authMechanism = 'certificate'` (also accepted: `'external'`,
`'x509'`) the handshake is EXTERNAL: the client certificate is the
credential, its Common Name the user, and no password is sent. `$user` is
sent as a claim the server checks against the Common Name, except the
default `'anonymous'`, which sends none. It needs `$tlsClientCert`; the
constructor refuses the combination without one, and an unknown
mechanism.

Constants: `Connection::ONE = 0`, `Connection::QUORUM = 1`,
`Connection::ALL = 2`.

### `exec(string $sql): int`

Runs `$sql` and returns the affected-row count (0 for DDL and for
row-producing statements). Mirrors `PDO::exec`.

### `query(string $sql): Statement`

Runs `$sql` without parameters and returns the executed `Statement`.
Mirrors `PDO::query`.

### `prepare(string $sql): Statement`

Returns a `Statement` to `execute()` with parameters. Nothing is sent until
`execute()`; the server-side prepare happens on the first execute with
parameters and is cached per connection (up to 240 distinct statements).

### `stream(string $sql, array $params = [], int|string|null $consistency = null): Generator`

Runs `$sql` with the streaming opcode and yields one associative row at a
time, holding one chunk in memory. `$params` bind the `?` placeholders in
order as typed values, exactly as a prepared `Statement::execute()` sends
them (associative arrays are re-indexed), and travel in
`OP_QUERY_STREAM_PARAMS`, which needs server ≥ 0.330; an older server
answers with an error the driver throws as `server does not support
parameterised streaming (skaidb >= 0.330): …`, and the connection stays
usable. A value that cannot be bound throws before anything is sent.
Without parameters the request is `OP_QUERY_STREAM`. An int or string in
the `$params` position with no `$consistency` is the consistency:
`stream($sql, 'ONE')` equals `stream($sql, consistency: 'ONE')`. The
connection is busy until the generator finishes; see
[streaming](streaming.md). After the last row, `getReturn()` on the
generator gives `['kind' => 'rows'|'mutation'|'ddl', 'columns' => [...],
'affected' => int]`: the column names even when no row came, and the
affected count of a non-row statement. A `CALL` whose body `EMIT`s result
sets is answered with all of them in one frame even when streamed: the
generator yields the rows `fetch()` would return (the last set) and
`getReturn()` adds `'result_sets'`, every set as `resultSets()` returns
them.

### `subscribe(string $stream, ?string $after = null, float $poll = 0.5): Generator`

Yields the events of `CREATE STREAM` `$stream` forever, polling its log
(`_stream_<name>`) every `$poll` seconds when idle, 500 events per page.
Each event is `['id' => string, 'op' => string, 'k' => ..., 'ts' => ..., 'doc' => ...]`;
pass the last `id` back as `$after` to resume. `break` to stop.

### `setConsistency(int|string $c): void` · `getConsistency(): int`

The default consistency for statements created afterwards.

### `isUsable(): bool`

False once closed, once a transport error broke the connection (the next
statement will re-dial), and while a stream is in flight.

### `endpoint(): ?string`

The endpoint (`'host:port'`, as configured or discovered) the live socket
reached — the member this connection's statements go to. Changes when the
connection re-dials to another member.

### `close(): void`

Closes the socket. Terminal: a closed connection never reconnects
(`connection is closed`). Also runs from the destructor.

### `static resolveConsistency(int|string $v): int`

`'ONE'`/`'QUORUM'`/`'ALL'` (any case) or `0`/`1`/`2` to the code; throws on
anything else.

### Reconnect

A transport error (`connection closed by server`, `connection timed out`,
`connection closed by server (write)`) throws from the statement in flight
and marks the connection broken. The next statement re-dials through the
seed list (a pooled connection: through the pool's members, usable ones
first and the one it lost last), re-authenticates, re-sends `USE`, re-sends
the Hello and clears the prepared-statement cache, then runs. The failed
statement is never retried by the driver.

## `Statement`

Created by `prepare()`/`query()`. Holds the whole result of the last
`execute()`.

### `execute(array $params = []): bool`

Binds `$params` (positional, in `?` order; associative arrays are
re-indexed) and runs the statement. With a non-empty `$params` the
statement is prepared on the server and the values travel typed; the
parameter count must equal the statement's placeholder count
(`statement expects N parameters, got M`). If the server declines to
prepare it (DDL, `USE`), the values are quoted client-side instead. Returns
`true`; throws on error.

### `executeBatch(array $rows): int`

Runs the statement once per row in one round-trip (`OP_EXECUTE_BATCH`) and
returns the total affected count. Every row must have the statement's
parameter count. Rows autocommit individually: on the first failing row the
server answers with an error naming the row and how many applied before it,
and those stay applied. Only preparable statements can be batched. An empty
`$rows` returns 0 without a round-trip.

### `fetch(): ?array`

The next row as `['column' => value]`, or `null` when exhausted.

### `fetchAll(): array`

Every remaining row.

### `fetchColumn(int $column = 0): mixed`

One cell of the next row (advancing the cursor), or `false` when exhausted.
A `NULL` cell returns `null`.

### `rowCount(): int`

Rows affected for a mutation; rows returned for a row-producing statement;
0 for DDL.

### `kind(): string`

What the last `execute()` returned: `'rows'` (a result set, possibly
empty), `'mutation'` (an affected count; see `rowCount()`) or `'ddl'` (a
bare acknowledgement). `''` before the first `execute()`.

### `columnCount(): int` · `columns(): array`

The result's column names, in order (empty for non-row statements).

### `resultSets(): ?array`

For a `CALL` whose body `EMIT`s: every result set in emission order, each
`['columns' => [...], 'rows' => [assoc rows]]`; the last set is what
`fetch()` returns. `null` for any other statement.

### `setConsistency(int|string $c): static`

Overrides the consistency for this statement only.

## `new Pool(...)`

```php
new Pool(
    array $connectionArgs = [],           // Connection constructor arguments, positional or named
    int $maxsize = 10,                    // connections kept idle (>= 1)
    string $loadBalancing = 'random',     // 'random' | 'round_robin' | 'least_latency'
    string $idleOrder = 'lifo',           // 'lifo' | 'fifo'
    bool $discover = false,               // read the members from SHOW ENDPOINTS
    float $refreshInterval = 60.0,        // seconds between discovery refreshes (5 s while one is not ready)
    float $readYourWrites = 0.0,          // seconds a write pins its affinity key to its member; 0 = off
    int $ejectErrors = 3,                 // consecutive transport failures that eject a member (>= 1)
    float $ejectLatencyFactor = 3.0,      // × the other members' median latency; 0 = off
    float $ejectLatencyFloor = 0.05,      // seconds above that median a member must also be
    float $ejectBackoff = 5.0,            // seconds of the first ejection, doubling per repeat
    float $ejectBackoffMax = 60.0         // the longest ejection, seconds
)
```

`$connectionArgs` are the `Connection` constructor arguments, positional
(`['db1', 7000, 'app', $pw]`) or named (`['user' => 'app', 'seeds' => [...]]`);
`host`/`port` or `seeds` name the members the pool starts from, the rest
apply to every connection. The balancing options are meant to be passed by
name (`loadBalancing: 'least_latency'`); the policy names are also
constants: `Pool::RANDOM`, `Pool::ROUND_ROBIN`, `Pool::LEAST_LATENCY`,
`Pool::LIFO`, `Pool::FIFO`. An unknown policy or idle order, `$maxsize < 1`
or `$ejectErrors < 1` throws `SkaidbException`.

- `acquire(string|int|null $affinity = null): Connection` — an idle
  connection to the member the policy picks (still usable), or a new one
  dialed to it, falling back to the other members. `$affinity` is the
  read-your-writes key (a user or session id; `null` = the pool-wide key).
  Runs the pool's upkeep first: probes one ejected member whose backoff is
  over, and refreshes discovery when it is due.
- `release(Connection $c): void` — returns it; closes it instead if it is
  broken, mid-stream, its member is ejected, not `ready` or gone, or the
  pool already holds `$maxsize` idle connections.
- `withConnection(callable $fn, string|int|null $affinity = null): mixed` —
  `acquire($affinity)`, call `$fn($conn)`, `release()` however `$fn` ends;
  returns what `$fn` returns.
- `members(): array` — one array per member, sorted by endpoint:
  `endpoint` (what the pool dials), `canonical` (the member's own name once
  discovered, else `null`), `state` (`'ready'`, `'ejected'`, `'resyncing'`,
  `'unreachable'`, `'unlisted'`), `latency_ms` (the round-trip average,
  `null` until measured), `in_use`, `idle`, `seed`.
- `close(): void` — closes every idle connection; `acquire()` then throws
  `pool is closed`.

See [pooling](pooling.md) for the balancing rules.

## Bind wrappers

`new Bytes(string $raw)`, `new Decimal(string $text)`, `new Uuid(string $text)`:
value objects that make a parameter travel as the typed Bytes, Decimal or
Uuid value. `->value` holds the normalized input; `Uuid::bytes()` the 16
raw bytes. See [types](types.md).

## `SkaidbException` · `Unpreparable`

`SkaidbException extends \Exception` is thrown for every driver error.
`Unpreparable extends SkaidbException` is what `prepareServer()` (an
`@internal` method) throws when the server declines to prepare a statement;
`execute()` catches it and falls back, so user code never sees it.

## Hello and the `drivers` table

After each successful handshake the driver sends `OP_HELLO` with
`Skaidb::CLIENT_NAME` and `Skaidb::VERSION`. The server lists them in its
`drivers` table (`SELECT client_name, client_version FROM drivers`). An
older server answers with an error the driver ignores.

## Wire protocol

<https://skaidb.org/docs/PROTOCOL.html>.
