# skaidb — PHP driver

[![CI](https://github.com/porcupin26/skaidb-php/actions/workflows/ci.yml/badge.svg)](https://github.com/porcupin26/skaidb-php/actions/workflows/ci.yml)

The official [skaidb](https://skaidb.org/) driver for PHP. The API is
modeled on `PDO`/`PDOStatement`: `prepare()`, `execute([...])`, `fetch()`,
`fetchAll()`, `rowCount()`. **Pure PHP, one file, zero dependencies** (only
the bundled `hash` extension; `openssl` for TLS).

- PHP **8.1 or newer**. No Composer or PECL packages required.
- Speaks skaidb's binary protocol directly: SCRAM-SHA-256 auth, server-side
  prepared statements with typed parameters, one-round-trip batches,
  streaming result sets, full type fidelity (Int, Float, Decimal, UUID,
  Bytes, Timestamp, Array, Document).
- Multi-seed failover, transparent reconnect, TLS, connection pooling,
  stream subscriptions.

Full documentation: this README, the [`docs/`](docs/) folder
([getting started](docs/getting-started.md) · [API reference](docs/api.md) ·
[types](docs/types.md) · [TLS](docs/tls.md) · [streaming](docs/streaming.md) ·
[pooling](docs/pooling.md)), runnable [`examples/`](examples/), the
[changelog](CHANGELOG.md), the
[wire protocol specification](https://skaidb.org/docs/PROTOCOL.html) and the
[skaidb documentation](https://skaidb.org/docs/).

## Install

The package is `skaidb/skaidb`. It is not registered on Packagist yet, so
point Composer at the GitHub repository:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/porcupin26/skaidb-php" }
    ],
    "require": {
        "skaidb/skaidb": "^1.0"
    }
}
```

```sh
composer require skaidb/skaidb:^1.0
```

Composer resolves `^1.0` to the `v1.0.0` tag and wires `src/Skaidb.php` into
`vendor/autoload.php`. (Once the package is on Packagist the `repositories`
entry becomes unnecessary; `composer require skaidb/skaidb` installs the same
thing.)

Without Composer, take `src/Skaidb.php` from a clone or from the
[release asset](https://github.com/porcupin26/skaidb-php/releases) and
require it directly:

```php
require '/path/to/skaidb-php/src/Skaidb.php';
```

## Quick start

```php
<?php
require 'vendor/autoload.php';       // or require 'src/Skaidb.php';

use Skaidb\Connection;

$db = new Connection('localhost', 7000, 'skaidb', 'secret');

$db->exec('CREATE TABLE users (PRIMARY KEY (id))');

$stmt = $db->prepare('INSERT INTO users (id, name, tags) VALUES (?, ?, ?)');
$stmt->execute([1, 'Ada', ['admin']]);

$stmt = $db->prepare('SELECT id, name, tags FROM users WHERE id = ?');
$stmt->execute([1]);
print_r($stmt->fetch());             // ['id' => 1, 'name' => 'Ada', 'tags' => ['admin']]

$db->close();
```

## Connecting

```php
new Connection(
    string $host = 'localhost',      // node hostname
    int $port = 7000,                // binary protocol port
    string $user = 'anonymous',      // 'anonymous' for a server with auth disabled
    string $password = '',
    int|string $consistency = 'QUORUM', // 'ONE' | 'QUORUM' | 'ALL' or 0 | 1 | 2
    float $timeout = 10.0,           // connect and read timeout, seconds
    ?string $database = null,        // optional: `USE <database>` right after the handshake
    bool $tls = false,               // see TLS below
    ?string $tlsCa = null,
    bool $tlsInsecure = false,
    string $tlsServerName = 'skaidb',
    array $seeds = []                // ['db1:7000', 'db2:7000']; wins over host/port when given
);
```

Every argument is positional, so PHP named arguments read best:

```php
$db = new Connection(user: 'app', password: $pw, database: 'app',
                     seeds: ['db1:7000', 'db2:7000', 'db3:7000'],
                     tlsCa: '/etc/skaidb/skai-ca.crt');
```

The constructor connects and runs the SCRAM-SHA-256 handshake; it throws
`Skaidb\SkaidbException` on a connect or authentication failure. With a
non-empty password the server's signature is verified too (mutual auth).

### Seeds and failover

skaidb is leaderless: every node accepts reads and writes, so a seed list is
only "somewhere to land". The seeds are shuffled and tried in turn until one
connects; if none does the constructor throws `no reachable endpoint in
<list>: <last error>`. The same walk runs again on reconnect, so a connection
survives the node it was talking to going away.

### TLS

| Arguments | Meaning |
|---|---|
| *(none)* | Plaintext. A server with `client_tls = required` refuses this. |
| `tls: true` | TLS, server certificate verified against the system trust store. |
| `tlsCa: '/path/ca.crt'` | TLS, certificate verified against this PEM bundle. Implies `tls`. |
| `tlsInsecure: true` | TLS with **no** certificate verification: encrypts, authenticates nothing. Development only. Implies `tls`. |
| `tlsServerName` | SNI and the name the certificate is verified against (default `skaidb`). Must match a SAN on the server certificate, which is usually *not* the address you dialled — skaidb's own certificates carry `DNS:skaidb`. |

See [docs/tls.md](docs/tls.md).

### Consistency

`consistency` selects how many replicas must acknowledge a write or be
consulted for a read: `ONE`, `QUORUM` (the default) or `ALL`. Set it per
connection or per statement:

```php
$db = new Connection(/* ... */, consistency: 'ONE');
$db->setConsistency('ALL');                 // from now on
$stmt->setConsistency('QUORUM');            // this statement only
foreach ($db->stream('SELECT ...', 'ONE') as $row) { ... }
```

`Connection::ONE`, `Connection::QUORUM` and `Connection::ALL` hold the
numeric codes (0, 1, 2).

## Statements and parameters

```php
$db->exec(string $sql): int            // run; returns the affected-row count (0 for DDL)
$db->query(string $sql): Statement     // run without parameters; fetch the result
$db->prepare(string $sql): Statement   // then $stmt->execute([...])

$stmt->execute(array $params = []): bool
$stmt->fetch(): ?array                 // next row as ['column' => value], or null
$stmt->fetchAll(): array               // every remaining row
$stmt->fetchColumn(int $i = 0): mixed  // one cell of the next row, or false when exhausted
$stmt->rowCount(): int                 // rows affected (mutations) or rows returned (SELECT)
$stmt->columnCount(): int
$stmt->columns(): array                // column names
$stmt->resultSets(): ?array            // every set of a CALL that EMITs, else null
```

Placeholders are `?`, positional, in order; a `?` inside a string literal is
left alone. Statements on one connection run one at a time.

With parameters the driver **prepares the statement on the server** (once
per distinct SQL text per connection, cached up to 240 entries) and sends the
values as **typed binary** — no string interpolation, no injection surface.
That is also the only way to send an `Array` or a `Document`, which have no
SQL literal form. The statement's parameter count must match what you pass
(a mismatch throws before anything runs).

Statements the server declines to prepare (DDL, `USE` and other session
statements) fall back to client-side quoting of the same `?` parameters, so
every statement kind accepts parameters; arrays and documents cannot travel
that way and throw (the message names the reason the server would not
prepare the statement). Prepared ids are scoped to a connection and the cache
is dropped on reconnect.

### Batches — many rows, one round-trip

```php
$n = $db->prepare('INSERT INTO t (id, tags) VALUES (?, ?)')
        ->executeBatch([[1, ['a']], [2, []], [3, ['b', 'c']]]);   // 3
```

Runs a prepared statement once per row in a single request and returns the
**total** affected count. Rows **autocommit individually**: on the first
failing row the server answers with an error naming the row index and how
many rows applied before it, and those earlier rows stay applied — use
idempotent statements. Only preparable statements (`SELECT`/`INSERT`/
`UPDATE`/`DELETE`) can be batched; the whole request must fit one 64 MiB
frame. An empty batch returns 0 without a round-trip.

### Multiple result sets

A `CALL` of a procedure whose body runs `EMIT <select>` answers with every
emitted set plus the call's final result:

```php
$stmt = $db->query('CALL report()');
$stmt->fetchAll();       // the LAST set (the call's own result)
$stmt->resultSets();     // every set, in emission order: [['columns' => [...], 'rows' => [...]], ...]
```

## Streaming large results — `$db->stream($sql, $consistency = null)`

`query()` buffers the whole result; a large scan is also bounded by the
server's scan budgets. `stream()` asks the server to deliver the rows in
chunks and yields them one at a time, holding one chunk in memory:

```php
foreach ($db->stream('SELECT id, v FROM readings') as $row) {
    process($row);          // ['id' => ..., 'v' => ...]
}
```

It takes **no parameters** (the streaming opcode carries SQL text only). A
non-row statement streamed this way yields nothing. An error before any row
is an ordinary statement error; an error partway through (a node dying
mid-scan, a scan budget tripping) is thrown after the rows already yielded,
which are valid. On a cluster, name the columns: a bare `SELECT *` only
streams page by page at consistency `ONE`.

**The connection is busy for the whole stream.** Any other statement on it
throws `connection is busy streaming a result set` until the stream ends.
Abandoning the stream — `break`, `return`, an exception, or letting the
generator go out of scope — runs the generator's cleanup, which drains the
frames still in flight so the connection is back at a request boundary;
draining a huge scan costs the rest of that scan, so `close()` the
connection instead if that trade is wrong. See [docs/streaming.md](docs/streaming.md).

## Following a stream — `$db->subscribe($name, $after = null, $poll = 0.5)`

For tables with a `CREATE STREAM`, `subscribe()` yields the stream's events
as they arrive, forever:

```php
foreach ($db->subscribe('big_orders', after: $lastSeenId) as $ev) {
    // $ev = ['id' => ..., 'op' => ..., 'k' => ..., 'ts' => ..., 'doc' => ...]
    save($ev['id']);        // pass it back as $after to resume exactly here
}
```

It polls the stream's log (`_stream_<name>`) with a keyset cursor, 500 events
per page; `id` is the position — an opaque **string** that sorts in log
order, so `$after` takes a saved id or `null`. `break` out of the loop to
stop. For push delivery subscribe to `$stream/<db>/<name>` with any MQTT
client instead — the events are identical.

## Pooling — `new Pool($connectionArgs, $maxsize = 10)`

```php
use Skaidb\Pool;

$pool = new Pool(['db1', 7000, 'app', $pw, 'QUORUM', 10.0, 'app'], 8);

$n = $pool->withConnection(fn (Connection $c) => $c->query('SELECT count(*) AS n FROM t')->fetchColumn());

$conn = $pool->acquire();
try { $conn->exec('...'); } finally { $pool->release($conn); }

$pool->close();
```

`$connectionArgs` are the `Connection` constructor arguments (positional, or
named as `['user' => ..., 'seeds' => [...]]`), so pooled connections inherit
seeds, TLS and the session database. `maxsize` bounds the connections kept
**idle**, not the number checked out: a burst opens extras and the surplus
is closed on release. PHP is share-nothing per request, so a pool pays off
in a worker or a long-running CLI job. See [docs/pooling.md](docs/pooling.md).

## Type mapping

| skaidb | PHP (results) | Bind (parameters) |
|---|---|---|
| Null | `null` | `null` |
| Bool | `bool` | `bool` |
| Int (i64) | `int` | `int` |
| Float | `float` | `float` (NaN/Infinity refused) |
| Decimal | `string`, exact (`'123.45'`) | `new Skaidb\Decimal('123.45')` |
| String | `string` | `string` |
| Bytes | `string` (binary) | `new Skaidb\Bytes($raw)` |
| Uuid | `string`, canonical lowercase `8-4-4-4-12` | `new Skaidb\Uuid('123e4567-…')` |
| Timestamp | `DateTimeImmutable` in UTC, millisecond precision | any `DateTimeInterface` |
| Array | `array` (list) | a list array (`array_is_list`) |
| Document | `array` (associative, insertion order) | an associative array |

A plain PHP `string` binds as a String; wrap it in `Bytes`, `Decimal` or
`Uuid` to send the typed value the server cannot express as a SQL literal.
Decimal is exact across the full 128-bit mantissa without `gmp` or `bcmath`.
Details in [docs/types.md](docs/types.md).

## Errors and reconnect

Every error the driver raises is a `Skaidb\SkaidbException` (an `Exception`
subclass, modeled on `PDOException`).

- **Statement errors** (`SELECT nope` → `no such column …`) throw; the
  connection stays usable.
- **Transport errors** throw `connection closed by server` / `connection
  timed out` and mark the connection *broken*, not closed. The next statement
  **re-dials** (through the seed walk), re-authenticates, re-sends `USE
  <database>` and clears the prepared-statement cache. Because the failed
  statement *may* have executed, the driver never retries it for you: retry a
  write only if it is idempotent. `isUsable()` reports the state.
- **Protocol desync** (an unexpected frame): the driver drops the connection
  rather than hand the next caller a misaligned reply; the next statement
  reconnects.
- `close()` is terminal: a closed connection never reconnects.

## Transactions

Every statement autocommits; the driver adds no transaction API of its own
(no `beginTransaction`/`commit`). Whatever transaction statements your server
version supports are plain SQL sent through `exec()` on one connection —
note that on a **cluster** the server does not accept `BEGIN`. Batch rows
autocommit one by one, as described above.

## Client identification

After authenticating, the driver sends a Hello frame that fills the server's
`drivers` table: `client_name` `php`, `client_version` = `Skaidb\Skaidb::VERSION`
(the package version, `1.0.0`). An older server without the opcode ignores
it. Prepared statements need server ≥ 0.17.0 (older servers get the
client-side fallback automatically), batches ≥ 0.87.0, streaming a server
with the streaming opcode, multiple result sets a server with `EMIT`.

## Development

```sh
php tests/run.php                 # unit tests against an in-process fake server, no skaidb needed
SKAIDB_HOST=127.0.0.1 SKAIDB_PORT=7000 SKAIDB_USER=admin SKAIDB_PASSWORD=pw \
    php tests/live/live.php       # end-to-end against a real node (skipped when SKAIDB_HOST is unset)
php examples/basic.php host 7000 user password
```

CI runs the suite on PHP 8.1, 8.2, 8.3 and 8.4, validates `composer.json`,
installs the package through Composer the way the Install section says, and
checks that the Hello version is derived from `Skaidb::VERSION`. Tagging
`vX.Y.Z` runs the publish workflow, which refuses a tag that does not equal
`Skaidb::VERSION`, builds the source zip and attaches it to a GitHub
Release. Packagist has no secret to hold: registering the repository on
packagist.org is the whole publish step, and until that is done the GitHub
release and the VCS install above are the channels.

## License

[SSPL-1.0](LICENSE).
