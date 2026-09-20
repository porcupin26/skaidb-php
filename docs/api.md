# API reference

Everything lives in `src/Skaidb.php` under the `Skaidb` namespace. Every
error is a `Skaidb\SkaidbException`.

## `Skaidb\Skaidb`

| Constant | Value |
|---|---|
| `Skaidb::VERSION` | the package version, e.g. `'1.0.0'`; what the Hello frame reports and what a release tag must equal |
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
    array $seeds = []                     // ['host:port', ...]; wins over $host/$port when non-empty
)
```

Connects (walking the shuffled seeds until one accepts), runs the
SCRAM-SHA-256 handshake, verifies the server signature when `$password` is
non-empty, sends the Hello frame and issues `USE $database` if given.
Throws on any failure (`no reachable endpoint in <list>: ...`,
`authentication denied: ...`, `server signature mismatch (mutual auth
failed)`).

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

### `stream(string $sql, int|string|null $consistency = null): Generator`

Runs `$sql` with the streaming opcode and yields one associative row at a
time, holding one chunk in memory. No parameters. The connection is busy
until the generator finishes; see [streaming](streaming.md).

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
seed list, re-authenticates, re-sends `USE`, re-sends the Hello and clears
the prepared-statement cache, then runs. The failed statement is never
retried by the driver.

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

### `columnCount(): int` · `columns(): array`

The result's column names, in order (empty for non-row statements).

### `resultSets(): ?array`

For a `CALL` whose body `EMIT`s: every result set in emission order, each
`['columns' => [...], 'rows' => [assoc rows]]`; the last set is what
`fetch()` returns. `null` for any other statement.

### `setConsistency(int|string $c): static`

Overrides the consistency for this statement only.

## `new Pool(array $connectionArgs = [], int $maxsize = 10)`

`$connectionArgs` are the `Connection` constructor arguments, positional
(`['db1', 7000, 'app', $pw]`) or named (`['user' => 'app', 'seeds' => [...]]`).
`$maxsize` bounds the connections kept idle (≥ 1).

- `acquire(): Connection` — an idle connection that is still usable, or a
  new one.
- `release(Connection $c): void` — returns it; closes it instead if it is
  broken, mid-stream, or the pool already holds `$maxsize` idle connections.
- `withConnection(callable $fn): mixed` — `acquire()`, call `$fn($conn)`,
  `release()` however `$fn` ends; returns what `$fn` returns.
- `close(): void` — closes every idle connection; `acquire()` then throws
  `pool is closed`.

See [pooling](pooling.md).

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
