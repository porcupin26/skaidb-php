# Changelog

All notable changes to the skaidb PHP driver. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/).

## [1.5.2] - 2026-11-16

### Added
- **`Skaidb\ConnectionException`**, a subclass of `SkaidbException`, for a
  connection that was lost or could not be made: `connection closed by
  server`, `connection timed out`, a failed write, `no reachable endpoint
  in …`, a handshake closed or answered with something else, a capacity
  refusal (`authentication denied: too many connections: …`), a reply the
  driver cannot place, and a statement refused because its transaction's
  connection is gone. Everything the driver threw was a bare
  `SkaidbException` before, so code that tolerated a statement error
  swallowed a lost connection with it. What the server answers a statement
  with stays a plain `SkaidbException`, as do a refused login, `connection
  is closed` after `close()` and `connection is busy streaming`; existing
  `catch (SkaidbException $e)` blocks catch all of them as before.

### Fixed
- **A connection the server closed while it sat idle costs no statement.**
  After a restart, a failover or an idle timeout the first statement on
  each idle connection threw `connection closed by server`: a write to a
  closed connection succeeds, only the read fails, and a statement that may
  have run is not sent again. Every statement now looks at the socket
  before its request is written (one poll with a zero timeout, no round
  trip; the socket itself under TLS). A connection found closed, reset, or
  holding bytes no request is waiting for is replaced, and the statement
  runs on the new one — nothing is written to the dead one. `isUsable()`
  makes the same look, so a pool no longer hands out an idle connection
  the server closed, and such a close is not counted against the member.
  Bytes left behind a complete reply used to answer the next statement;
  that connection is replaced too.
- **`SET CONSISTENCY` and `SET SCAN BUDGET` survive a re-dial.** A
  consistency override and a scan budget set with plain statements are
  session state on the server and were lost with the connection: after a
  restart a scan the application had bounded ran unbounded, and a read
  asked for at `ALL` ran at the request's own level. The driver now keeps
  the text of the last `SET CONSISTENCY …` and the last `SET SCAN BUDGET …`
  the server accepted (one statement, `--` comments and a trailing `;`
  aside, through `exec()`, `query()`, `execute()` or `stream()`; `…
  DEFAULT` clears it) and sends them again on a new connection, after the
  `USE` and before the statement. A setting refused there fails the
  statement with that error, unsent.
- **A hand-written transaction is held to its connection.** Only
  `transaction()` kept a lost connection from being replaced. After a
  `BEGIN` sent by the application the driver re-dialled, and the rest of
  the transaction ran on a new session, each statement committed on its
  own; the `COMMIT` then answered `no transaction in progress`, and a
  `ROLLBACK` undid nothing. The driver now notes a statement the server
  accepted whose first word is `BEGIN`, until a `COMMIT` or `ROLLBACK`
  (optionally followed by `TRANSACTION`) has been answered. In between, a
  lost connection is not replaced: every further statement throws
  `connection lost inside a transaction: the statement was not sent …`.
  The application ends it: `ROLLBACK` succeeds without being sent, `COMMIT`
  throws `connection lost inside a transaction: the COMMIT was not sent,
  and nothing was committed …`; the next statement reconnects. A `COMMIT`
  the connection is lost under — the one case with an unknown outcome — now
  says so: `…: the connection was lost under the COMMIT, so whether the
  transaction was committed is not known`.
- **A pool hands every borrower its own session state.** A scan budget or
  a consistency override set by one borrower applied to the next, and a
  transaction a borrower left open stayed open: the next borrower's
  statements ran inside it and were lost with it. `release()` now puts the
  session back before the connection is kept — `ROLLBACK`, `USE` of the
  pool's database (`USE default` when the pool was given none), `SET SCAN
  BUDGET DEFAULT`, `SET CONSISTENCY DEFAULT`, only the ones the borrower
  made necessary, so a borrower that changed nothing costs no round trip.
  If one of them fails (a server before 0.349 has no `SET CONSISTENCY
  DEFAULT`) the connection is closed instead. A connection a borrower's
  `USE` moved is reset the same way instead of being closed, which cost a
  dial per checkout.
- **A `USE` is never held hostage by the database it leaves.** With the
  session database dropped and the connection lost, every statement failed
  at the re-dial's `USE` — and so did a new `USE other`, because the old
  database was entered first for it too. A re-dial caused by a `USE` no
  longer enters the old database first; the same holds for a `SET
  CONSISTENCY` or `SET SCAN BUDGET` and the setting it replaces. If that
  statement is refused in turn, the connection is dropped rather than used
  half restored.
- **A plain connection walks past a seed that refuses its handshake.** A
  node at capacity accepts a connection and closes it unanswered; with
  seeds `[such a node, a healthy node]` half of all constructors threw
  `connection closed by server`. Such a seed is now a failed dial like one
  that refuses the connection, and the next seed is tried; a refused login
  still ends the walk.
- **Only a statement the server will not prepare falls back to text.**
  Every error answer to a prepare was taken for "declined", so a statement
  with a syntax error was sent a second time as text, and a parameterised
  DDL statement cost a refused prepare on every `execute()`. The fallback
  is now taken on the server's `statement kind cannot be prepared`
  (PROTOCOL.md §6 rule 10) and remembered per connection; any other
  error is thrown as it comes, once. `docs/` no longer names `USE` as a
  statement that takes a parameter: a parameter is a value, never a name,
  and `USE ?` and `SET CONSISTENCY ?` are refused with the server's error
  for the text.
- **A `?` in a quoted identifier or a comment is not a placeholder.**
  `query('SELECT id AS "ok?" FROM t')`, a statement ending in `-- really?`
  and such a `$begin` for `transaction()` were refused with `query has
  placeholders but no parameters given`, and the text fallback counted one
  placeholder too many. The driver now reads a statement as the server's
  lexer does: strings, quoted identifiers and `--` comments are skipped.
- **`executeBatch()` works against a server without the batch opcode**
  (before 0.87): the rows go one prepared execute each, typed, with the
  same result and the same error for the first failing row. It threw
  `protocol error: … unknown opcode`.
- **A dial that loses its new connection fails once.** A server that
  authenticated a connection and dropped it before the session database
  was entered made the dial start over from inside itself, without end:
  `new Connection(..., database: 'app')` hung, dialling in a loop. The
  statement now throws `connection closed by server (write)` and the next
  one dials again.
- A batch the driver runs row by row keeps the `ConnectionException` of a
  row that loses the connection.

### Upgrade notes
- A statement on a connection the server closed while idle used to throw
  once; it now runs. Code that retried after that error no longer sees it.
- After a lost connection inside a `BEGIN` the application sent, statements
  throw until the application sends `ROLLBACK` or `COMMIT`. An error
  handler that rolls back, as one should after any failed transaction,
  needs no change.
- `transaction()` reports a `COMMIT` that could not be sent as `connection
  lost inside a transaction: the COMMIT was not sent, and nothing was
  committed …` (it was `… the statement was not sent …`).
- A syntax error in a statement executed with parameters is the server's
  answer to the prepare, which carries no `parse error: ` in front; the
  statement is no longer sent a second time to get the other wording.
- Against a server before 0.349, a parameter in a literal's place in a
  statement the server cannot prepare (`ALTER TABLE t SET (ttl = ?)`, `SET
  SCAN BUDGET ROWS ?`) throws the server's parse error: such a server does
  not mark that refusal, and the driver no longer sends every refused
  statement as text. Write the value into the text there. `CREATE INDEX …
  WHERE v > ?` and the like, refused with `statement kind cannot be
  prepared` by every server, fall back as before.
- On a pool, a borrower's `USE`, `SET CONSISTENCY`, `SET SCAN BUDGET` and
  open transaction end with its checkout. A `USE` on a pool without a
  `database` argument does not carry over to later borrowers (as since
  1.5.1); the connection is now reset with `USE default` instead of closed.
- `isUsable()` is false, and the connection broken, as soon as the server
  has closed it — not only after a statement failed on it.

## [1.5.1] - 2026-11-16

### Fixed
- **`executeBatch()` on a statement the server declines to prepare** (DDL,
  session statements) threw the internal `Skaidb\Unpreparable`, where
  `execute()` falls back to client-side quoting. It now falls back too: the
  statement runs once per row, each row's values quoted as `execute()`
  quotes them, and the total affected count is returned. Every row is
  bound before the first is sent, so a row with the wrong parameter count
  or a value with no SQL literal form throws with nothing applied; the
  first failing row ends the batch with `batch row N: <its error> (N rows
  applied before it)`.
- **A long-lived connection no longer loses typed parameters after its
  256th distinct statement.** The driver prepared every distinct
  parameterised SQL text and never closed one, and a server keeps at most
  256 prepared statements per connection: from then on it refused every
  prepare (`too many prepared statements (max 256); close some first`),
  and the driver sent those statements as text with client-side literals
  instead. An `Array` or a `Document` parameter then failed (`cannot bind
  value of type array via client-side binding`); a `DateTime` was stored
  as an integer and `Bytes` as a hex string, with no error. The cache now
  keeps the 240 most recently used statements and closes the one it pushes
  out on the server; a `Statement` whose entry went is prepared again by
  its next `execute()`. A prepare the server refuses with `too many
  prepared statements` is thrown, never sent as text.
- **A stream left open by a `transaction()` body no longer leaves the
  transaction open.** With the body's stream still in flight (its
  generator returned as the body's value, or kept somewhere) neither the
  `COMMIT` nor the `ROLLBACK` could be sent; the call threw and the
  transaction stayed open on a live connection. Once the generator was
  released, every later statement on that connection ran inside the
  abandoned transaction — reported as applied, never committed — and
  `$pool->transaction()` could put the connection back in the pool that
  way, for the next borrower. `transaction()` now closes such a connection,
  so the server drops the transaction, and throws the body's exception if
  it threw, otherwise `connection is busy streaming a result set: the
  transaction's body returned with a stream still open, …`. Nothing is
  committed; the connection re-dials on the first statement after the
  generator is released, and reading the stream on throws `connection
  lost: the connection was closed with this stream still open`.
- **The session database survives a re-dial when it was entered with
  `USE`.** A re-dialled connection re-entered only the constructor's
  `$database`: after a plain `USE app` and a lost connection, the next
  statement ran in the constructor's database, or in none — against the
  default database's table of the same name. The driver now keeps the
  database named by the last `USE` the server accepted (a statement that is
  exactly `USE [DATABASE] <name>`, the name bare or in double quotes, an
  optional `;`, through `exec()`, `query()`, `execute()` or `stream()`)
  and enters it on every dial before anything else is sent. A `USE` with a
  `--` comment is read like any other (`USE app -- the app database`), as
  the server reads it; `USE "a--b"` names `a--b`. If the database cannot
  be entered on the new connection, the statement throws that error
  instead of running in another database.
- **A pool no longer hands one borrower's `USE` to the next.** A
  connection released in another database than the pool's `database`
  argument (with no such argument: after any `USE`) is closed instead of
  kept, so the next checkout is in the pool's database. Name the database
  in the pool's arguments rather than switching on a checked-out
  connection.
- **A broken connection closes its socket at once.** After a transport
  error the socket stayed open until the next statement re-dialled or
  `close()` ran. After a read timeout, or a reply that lost the framing,
  the server was still there and kept the session — with a transaction
  open in it and the rows it had claimed — until then; inside
  `transaction()`, where nothing re-dials, until the first statement after
  the call. The socket is now closed when the connection is marked broken.
  Re-dialling is unchanged.
- **A re-dial the server turns away during its handshake** (a node at
  capacity, or one on its way up or down, closing the new connection
  unanswered) closed the connection for good: every later statement threw
  `connection is closed`. It now stays broken, and the next statement
  dials again, as after a re-dial that finds no endpoint.
- **`close()` is terminal for a broken connection too.** A connection
  closed after a transport error dialled a new session on its next
  statement before throwing `connection is closed`, and left that session
  open.
- **A reply the driver cannot place drops the connection**, as the README
  says: an unknown tag in the reply to a statement or to a prepare, and an
  unexpected frame in the middle of a stream, threw but left the
  connection in use, so the frames queued behind it answered the next
  statements — each with the reply to the one before. The connection is
  now marked broken and the next statement re-dials.

## [1.5.0] - 2026-11-15

### Added
- **`TransactionConflictException`**, a subclass of `SkaidbException`, for
  a server error whose message starts with `transaction conflict:` — a
  standalone server (>= 0.346.0) refusing a `COMMIT` because another writer
  changed a row the transaction writes after its `BEGIN`. Every call that
  runs a statement throws it (`exec()`, `query()`, `execute()`,
  `executeBatch()`, `stream()`), with the server's message unchanged; code
  that catches `SkaidbException` catches it as before. It is a statement
  error: the connection stays usable and a pool counts nothing against the
  member.
- **`$db->transaction(callable $body, int $attempts = 3, string $begin =
  'BEGIN')`** sends `$begin`, calls `$body($connection)`, sends `COMMIT`
  and returns what the body returned. A `COMMIT` refused with a conflict
  runs the whole transaction again, with no pause, until it has run
  `$attempts` times; then the conflict is thrown. A body that throws is
  rolled back and its exception rethrown unchanged, whatever the `ROLLBACK`
  answers, and is not run again. A refused `$begin` is thrown before the
  body runs, any other `COMMIT` error without a retry or a `ROLLBACK`, and
  `$attempts < 1` before anything is sent. `$begin` is sent verbatim
  (`'BEGIN ATOMIC'` on a cluster).
- Inside `transaction()` a lost connection is not re-dialled: a statement
  that would have run on a new connection, outside the transaction, throws
  `connection lost inside a transaction` and is not sent. The connection
  re-dials on the first statement after the call.
- **`$pool->transaction($body, $attempts, $begin, $affinity)`**: the same
  on one checked-out connection, released however the transaction ends.

## [1.4.1] - 2026-10-31

### Fixed
- One slow round trip at the start can no longer get a healthy member
  ejected for latency. A member's first round trip set its average
  outright, so a statement that stalled in the client — a CPU-bound
  worker holding the process while it starts — put seconds into it; ten
  round trips later the member was compared with the others, and the
  average had barely decayed: ejected while it answered in milliseconds.
  The fastest of a member's first three round trips now seeds its average
  (a round trip is only ever lengthened), and later ones move it as
  before. Until then the member counts as not measured: `members()`
  reports `latency_ms` as `null`, and `least_latency` keeps trying it.

### Changed
- The pool logs each ejection and re-admission with `error_log()`; nothing
  said before why a member stopped getting connections. A latency
  ejection names the member's average, the median it was compared with
  and the slowest of its last 10 round trips, which tells a slow member
  from one slow statement (`skaidb pool: ejecting db2:7000 (latency
  516.3 ms vs median 0.2 ms; slowest of the last 10 round trips
  900.0 ms)`); an error ejection names its count (`3 consecutive
  connection failures`), and a probe that re-admits a member logs
  `skaidb pool: re-admitted db2:7000`.

## [1.4.0] - 2026-10-29

### Fixed
- A streamed `CALL` whose procedure body `EMIT`s result sets returns them.
  The server answers such a call with every set in one `ResultSets` frame
  (tag 8) even to a stream request, and `$db->stream()`, with or without
  parameters, threw `unexpected response tag 8 to stream request` and
  marked the connection broken. The frame is now decoded by the code
  `Statement::execute()` uses: the generator yields the rows `fetch()`
  would return (the call's final result, the last set), and `getReturn()`
  adds `'result_sets'`, every set in order exactly as
  `Statement::resultSets()` returns them. The exchange is complete in that
  one frame, so the connection is handed back before the first row is
  yielded and stays usable.

## [1.3.0] - 2026-10-29

### Added
- **Parameterised streaming**: `$db->stream($sql, $params)` binds `?`
  placeholders with the typed values a prepared `execute($params)` sends
  (associative arrays re-indexed), over `OP_QUERY_STREAM_PARAMS` (server
  >= 0.330) — a range-bounded export no longer needs its values spliced
  into the SQL. The signature is `stream(string $sql, $params = [],
  $consistency = null)`. An older server's refusal throws `server does not
  support parameterised streaming (skaidb >= 0.330): …` and leaves the
  connection usable; a value that cannot be bound throws before anything is
  sent. Without parameters the request is the original `OP_QUERY_STREAM`,
  byte for byte, so it works against every server that streams.
  `stream($sql, 'ONE')` (a consistency in the second position) still means
  the consistency, and `consistency:` by name works as before.
- The conformance suite's `stream_params` case: `conformance/vectors.json`
  and `conformance/README.md` re-vendored, and the harness passes a
  `query_stream` case's `call.params` to `stream()`.

## [1.2.0] - 2026-10-25

### Added
- **Pool load balancing across members** (the rules every skaidb driver
  shares, PROTOCOL.md §6.1): `loadBalancing: 'round_robin'` /
  `'least_latency'` (power of two choices on the round-trip average ×
  checked-out connections, re-measuring a member not used for 2 s;
  `'random'` stays the default), `idleOrder: 'fifo'`, health ejection
  (consecutive transport failures, or a latency average far above the other
  members') with a probe that re-admits the member, `discover: true`
  (members from `SHOW ENDPOINTS`, server ≥ 0.327: joiners added, leavers
  dropped, resyncing/unreachable members skipped and re-checked every 5 s),
  and `readYourWrites: <seconds>` with `acquire($key)` /
  `withConnection($fn, $key)` — reads follow the member that took the key's
  last write. `Pool::members()` reports each member's state, latency and
  connections. PHP has no background threads, so the probe runs on the
  first checkout after a member's backoff and the discovery refresh on the
  first checkout after the refresh interval.
- `Connection::endpoint()`: the `host:port` the connection reached.

### Changed
- A pooled connection that re-dials does so in the pool's order (usable
  members first, the one it lost last), and a pooled dial whose handshake
  the member drops or refuses for capacity moves on to the next member.

## [1.1.0] - 2026-09-26

### Added
- The shared skaidb wire-protocol conformance suite:
  `conformance/vectors.json` and `conformance/README.md`, vendored
  byte-identical from <https://skaidb.org/conformance/vectors.json>, and
  `tests/conformance.test.php`, which runs the value and SCRAM vectors, the
  three auth outcomes and every case through the public API against a
  scripted fake server replaying the server's reference bytes. CI fails when
  the vendored vectors differ from the published copy.
- Certificate login: `tlsClientCert` / `tlsClientKey` present a TLS client
  certificate (either implies TLS), and `authMechanism: 'certificate'` logs
  in with it (wire mechanism EXTERNAL, PROTOCOL.md §2.4) — the certificate's
  Common Name is the user and no password is sent. The default user
  `'anonymous'` sends no username claim; any other `user` is sent for the
  server to check against the Common Name.
- `Statement::kind()`: `'rows'`, `'mutation'` or `'ddl'` for the last
  `execute()`, so a DDL acknowledgement is distinguishable from a mutation
  that affected zero rows.
- `stream()`'s generator returns `['kind', 'columns', 'affected']` once it
  has finished (`getReturn()`).

### Fixed
- Found by the conformance suite: `stream()` lost the column names of a
  result with no rows and the affected-row count of a non-row statement
  (`DELETE` streamed through `OP_QUERY_STREAM`); both are now in the
  generator's return value.

## [1.0.1] - 2026-09-20

Release automation: published from GitHub Actions. No driver code changed
except `Skaidb::VERSION`, which the Hello frame reports.

### Changed
- The Release workflow's Packagist step: with the `PACKAGIST_USERNAME` and
  `PACKAGIST_TOKEN` repository secrets set it asks Packagist to re-crawl the
  repository through the update API and then waits until the tagged version
  is listed in the Composer metadata; without them, while the package is not
  on Packagist, it logs the one-time setup as a notice and stays green. The
  workflow also refuses a tag when `composer.json` does not name
  `skaidb/skaidb`.
- The workflows moved to `actions/checkout@v5` and
  `softprops/action-gh-release@v3`, the Node 24 lines.

## [1.0.0] - 2026-09-20

First release as a standalone package (`github.com/porcupin26/skaidb-php`),
carrying its full history over from the skaidb monorepo. This is the first
version of the driver that was executed against a real skaidb node; the live
end-to-end test in `tests/live/live.php` is what found the fixes below.

### Added
- `Skaidb\Skaidb::VERSION` (`1.0.0`) as the single source of truth for the
  version; `composer.json` carries none (Composer takes it from the git
  tag). The Hello frame the driver sends after authentication reports
  `client_name` `php` and `client_version` = that constant, and CI asserts
  the two agree.
- `Skaidb\Bytes`, `Skaidb\Decimal` and `Skaidb\Uuid` bind wrappers, so a
  parameter can travel as a typed Bytes, Decimal or Uuid value (a plain
  PHP string binds as String; SQL has no literal for these types).
- Exact Decimal encoding and decoding across the full 128-bit mantissa in
  pure PHP; `gmp`/`bcmath` are no longer consulted or suggested.
- `Statement::resultSets()` exposes every result set of a `CALL` whose
  body `EMIT`s (the last set is what `fetch()` returns).
- `Connection::stream()` accepts a consistency name (`'ONE'`) as well as a
  code, like every other consistency argument.
- A dependency-free test suite (`php tests/run.php`) with an in-process
  fake server that speaks the frame layer and SCRAM-SHA-256 for real:
  framing, the handshake (good/bad password, mutual auth, anonymous), the
  value codec for every type, prepare/execute with typed parameters,
  batches, streaming (chunks, mid-stream errors, the abandon/drain rule),
  multiple result sets, reconnect, the pool, `subscribe()`, and the
  Hello-version-equals-package-version check.
- A live end-to-end test (`tests/live/live.php`) against a real node,
  skipped unless `SKAIDB_HOST` is set.
- CI on PHP 8.1, 8.2, 8.3 and 8.4, `composer validate --strict`, a Composer
  VCS install of the package as a consumer, and a publish workflow that
  gates the tag on `Skaidb::VERSION`, builds the source zip and attaches it
  to a GitHub Release.
- Documentation: README plus `docs/` (getting started, API reference,
  types, TLS, streaming, pooling) and runnable `examples/`.

### Fixed
- A `DateTimeInterface` parameter was converted to milliseconds through a
  float (`(float) format('U.u') * 1000`, rounded), which could shift a
  timestamp by a millisecond or into the next second near `.9995`. The
  conversion is now integer arithmetic on seconds and microseconds.
- An integral float bound through the client-side fallback (`2.0`) was
  rendered as `2` and stored as an Int. It now renders as `2.0`.
- Binding an array or document to a statement the server would not prepare
  (for example one with a syntax error) failed with `cannot bind value of
  type array`, hiding the server's reason. The exception now carries the
  prepare error too.
- Decimal values whose mantissa exceeded 64 bits were approximated when
  neither `gmp` nor `bcmath` was loaded; they are exact now.

### Changed
- Package identity: repository and homepage are
  `https://github.com/porcupin26/skaidb-php`; license `SSPL-1.0`; PHP 8.1
  or newer (the driver already used `array_is_list`).
- The example moved from `example.php` to `examples/basic.php`.

[1.0.1]: https://github.com/porcupin26/skaidb-php/releases/tag/v1.0.1
[1.0.0]: https://github.com/porcupin26/skaidb-php/releases/tag/v1.0.0
