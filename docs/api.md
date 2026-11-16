# API reference

Everything lives in `src/Skaidb.php` under the `Skaidb` namespace. Every
error is a `Skaidb\SkaidbException`; a lost connection is its subclass
`Skaidb\ConnectionException`.

## `Skaidb\Skaidb`

| Constant | Value |
|---|---|
| `Skaidb::VERSION` | the package version, e.g. `'1.5.1'`; what the Hello frame reports and what a release tag must equal |
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
    ?string $database = null,             // the session database: entered after the handshake, and after every reconnect
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

Walks the shuffled seeds until one connects and completes the SCRAM-SHA-256
handshake — a seed that refuses the connection, or closes it during the
handshake (a node at capacity), is walked past — verifies the server
signature when `$password` is non-empty, sends the Hello frame and issues
`USE $database` if given. Throws on any failure (`no reachable endpoint in
<list>: ...` when no seed is left; `authentication denied: ...` and `server
signature mismatch (mutual auth failed)` at once, without trying the next
seed).

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
parameters and is cached per connection. The 240 most recently used
statements stay prepared; one more closes the least recently used on the
server (`OP_CLOSE` — a server keeps at most 256 per connection), and a
`Statement` whose entry went is prepared again by its next `execute()`.

### `transaction(callable $body, int $attempts = 3, string $begin = 'BEGIN'): mixed`

Runs `$body` inside a transaction on this connection: sends `$begin`, calls
`$body($connection)`, sends `COMMIT`, and returns what `$body` returned.

- `$attempts < 1` throws `SkaidbException` (`attempts must be >= 1`) before
  anything is sent.
- `$begin` fails: its error is thrown; `$body` never runs and nothing else
  is sent.
- `$body` throws (any `Throwable`): `ROLLBACK` is sent, whatever it answers
  is ignored, and the body's exception is rethrown unchanged. It is never
  run again for that, even when the exception is a
  `TransactionConflictException`.
- `COMMIT` is refused with a `TransactionConflictException`: the whole
  transaction runs again from `$begin`, with no pause, until it has run
  `$attempts` times; then the conflict is thrown.
- `COMMIT` fails any other way: its error is thrown, with no further run
  and no `ROLLBACK`.

`$body` can run more than once, so it must be safe to repeat and should do
nothing outside the database that a repeat would do twice. `$begin` is sent
verbatim: `'BEGIN'` on a standalone server, `'BEGIN ATOMIC'` or `'BEGIN
ATOMIC PARTITION (col = literal)'` on a cluster.

A stream `$body` starts must be finished before it returns. If one is still
in flight when `$body` ends (the generator was returned, or kept
somewhere), the connection can take neither the `COMMIT` nor the
`ROLLBACK`: `transaction()` closes it as a lost connection is closed, so
the server drops the transaction, and throws `$body`'s exception if it
threw, otherwise `connection is busy streaming a result set: the
transaction's body returned with a stream still open, …`. Nothing was
committed and the transaction is not run again. Reading the stream on
throws `connection lost: the connection was closed with this stream still
open`; the connection re-dials on the first statement after the generator
is released.

From `$begin` to the `COMMIT` the connection does not re-dial: once the
transport is lost, a further statement of the body, or the `COMMIT`, throws
`connection lost inside a transaction: …` without being sent, because on a
new connection it would run outside the transaction (the clean-up
`ROLLBACK` is not sent either). The transport error itself is thrown as
usual and is not retried; the connection re-dials on the first statement
after the call. See [Transactions](../README.md#transactions).

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

A transport error (a `ConnectionException`: `connection closed by server`,
`connection timed out`, `connection closed by server (write)`) throws from
the statement in flight,
marks the connection broken and closes its socket there and then: the
server's session ends with the socket, and with it a transaction left open
in it — also after a read timeout, when the server is still there. The
next statement re-dials through the seed list (a pooled connection: through
the pool's members, usable ones first and the one it lost last),
re-authenticates, re-sends the Hello, enters the session database again
and clears the prepared-statement cache, then runs. The failed statement is
never retried by the driver. A re-dial that fails — no endpoint reachable,
or a server that turns the new connection away — throws its own error and
leaves the connection broken, so the statement after it dials again. The
new session has no transaction open: a statement sent after a transport
error inside a hand-written `BEGIN` … `COMMIT` autocommits, so stop and
start the transaction again (`transaction()` refuses to send it).

The session database is the constructor's `$database`, or the one named by
the last `USE` the server accepted. The driver reads a statement that is
exactly one `USE [DATABASE] <name>` — the name bare or in double quotes
(`""` for a quote inside), an optional trailing `;` — sent through
`exec()`, `query()`, `execute()` or `stream()`. `--` comments are skipped
first, each to the end of its line, as the server skips them:
`USE app -- the app database` is read, and `USE "a--b"` names `a--b`.
Anything else (two statements in one, say) is not noticed. Every dial sends
`USE "<that database>"` before anything else, so a re-dialled connection is
in the database the lost one was in. If that `USE` fails, the statement
that caused the re-dial throws its error and is not sent; it never runs in
another database, and the next statement tries again.

## `Statement`

Created by `prepare()`/`query()`. Holds the whole result of the last
`execute()`.

### `execute(array $params = []): bool`

Binds `$params` (positional, in `?` order; associative arrays are
re-indexed) and runs the statement. With a non-empty `$params` the
statement is prepared on the server and the values travel typed; the
parameter count must equal the statement's placeholder count
(`statement expects N parameters, got M`). If the server will not prepare
it — it answers `statement kind cannot be prepared`, or its parser stops at
the placeholder where it wants a literal or a name (DDL, session
statements) — the values are quoted into the text client-side instead, and
the connection remembers the refusal (its last 240) so the next `execute()`
goes straight to text. That works where the statement takes a literal; a
name or a keyword cannot be bound, so `USE ?` and `SET CONSISTENCY ?` throw
the server's error for the text (`parse error: unexpected token
"Str(\"app\")", expected identifier`). Any other error answer to the
prepare is the statement's own and is thrown as it comes, with nothing sent
again: a syntax error, or a connection whose statement table is full (`too
many prepared statements …` — as text the values would be typed by their
literals). Returns `true`; throws on error.

### `executeBatch(array $rows): int`

Runs the statement once per row in one round-trip (`OP_EXECUTE_BATCH`) and
returns the total affected count. Every row must have the statement's
parameter count. Rows autocommit individually: on the first failing row the
server answers with an error naming the row and how many applied before it,
and those stay applied. An empty `$rows` returns 0 without a round-trip.

If the server declines to prepare the statement (DDL, session statements),
it runs once per row instead, each row's values quoted client-side as
`execute()` quotes them: one round-trip per row, the same rules. Every row
is bound before the first is sent, so a row with the wrong parameter count
or a value that has no SQL literal form throws with nothing applied
(`batch row N: … via client-side binding; the server declined to prepare
the statement: …`); the first failing row ends the batch with `batch row
N: <its error> (N rows applied before it)`; the total affected count is
returned.

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
  broken, mid-stream, its member is ejected, not `ready` or gone, a `USE`
  moved it out of the pool's `database` (any `USE`, when the pool was given
  none), or the pool already holds `$maxsize` idle connections.
- `withConnection(callable $fn, string|int|null $affinity = null): mixed` —
  `acquire($affinity)`, call `$fn($conn)`, `release()` however `$fn` ends;
  returns what `$fn` returns.
- `transaction(callable $body, int $attempts = 3, string $begin = 'BEGIN',
  string|int|null $affinity = null): mixed` — checks out one connection,
  runs `Connection::transaction($body, $attempts, $begin)` on it (every
  statement and every run on that connection) and releases it however the
  transaction ends. `$attempts < 1` throws before a connection is checked
  out.
- `members(): array` — one array per member, sorted by endpoint:
  `endpoint` (what the pool dials), `canonical` (the member's own name once
  discovered, else `null`), `state` (`'ready'`, `'ejected'`, `'resyncing'`,
  `'unreachable'`, `'unlisted'`), `latency_ms` (the round-trip average,
  `null` until its first three round trips seed it), `in_use`, `idle`,
  `seed`.
- `close(): void` — closes every idle connection; `acquire()` then throws
  `pool is closed`.

See [pooling](pooling.md) for the balancing rules.

## Bind wrappers

`new Bytes(string $raw)`, `new Decimal(string $text)`, `new Uuid(string $text)`:
value objects that make a parameter travel as the typed Bytes, Decimal or
Uuid value. `->value` holds the normalized input; `Uuid::bytes()` the 16
raw bytes. See [types](types.md).

## `SkaidbException` · `ConnectionException` · `TransactionConflictException` · `Unpreparable`

`SkaidbException extends \Exception` is thrown for every driver error.
`ConnectionException extends SkaidbException` says the connection was lost
or could not be made: the server closed the socket (`connection closed by
server`), a read timed out (`connection timed out`), a write failed, no
endpoint answered (`no reachable endpoint in …`), a member turned the
connection away (a handshake closed unanswered, `authentication denied: too
many connections: …`), a reply the driver cannot place made it give the
connection up, or a statement was refused because its transaction's
connection is gone (`connection lost inside a transaction: …`). The
statement it interrupted may or may not have run; the connection re-dials
on the next statement. What the server answers a statement with is never a
`ConnectionException`, and neither is a refused login (`authentication
denied: …`, `server signature mismatch …`), `connection is closed` after
`close()`, or `connection is busy streaming a result set`.
`TransactionConflictException extends SkaidbException` is a server error
whose message starts with `transaction conflict:`
(`TransactionConflictException::PREFIX`; only the prefix is matched): the
server refused a `COMMIT` because another writer changed a row the
transaction writes after its `BEGIN`. Nothing was committed, the
transaction is over and the connection stays usable. It is thrown by every
call that runs a statement — `exec()`, `query()`, `execute()`,
`executeBatch()`, `stream()` — with the server's message unchanged, and it
counts nothing against a pool member. `transaction()` retries on it.
`Unpreparable extends SkaidbException` is what `prepareServer()` (an
`@internal` method) throws when the server declines to prepare a statement;
`execute()` and `executeBatch()` catch it and fall back, so user code never
sees it.

## Hello and the `drivers` table

After each successful handshake the driver sends `OP_HELLO` with
`Skaidb::CLIENT_NAME` and `Skaidb::VERSION`. The server lists them in its
`drivers` table (`SELECT client_name, client_version FROM drivers`). An
older server answers with an error the driver ignores.

## Wire protocol

<https://skaidb.org/docs/PROTOCOL.html>.
