# Streaming

## Large results — `$db->stream($sql, $params = [], $consistency = null)`

`query()`/`execute()` buffer the whole result in the `Statement`; a large
scan is also bounded by the server's scan budgets. `stream()` asks the
server for the rows in chunks (`OP_QUERY_STREAM`) and yields them one at a
time, so memory is one chunk regardless of the result size. The server
executes a plain `SELECT` page by page, and no scan budget applies to a
streamed page (server ≥ 0.330; the rules are under "Streamed queries" in
the [query syntax](https://skaidb.org/docs/query_syntax.html), and `EXPLAIN`
says which statements page):

```php
$n = 0;
foreach ($db->stream('SELECT id, v FROM readings ORDER BY id') as $row) {
    $n++;                          // $row = ['id' => ..., 'v' => ...]
}
```

- `$params` bind the statement's `?` placeholders in order, sent as typed
  values exactly as a prepared `execute()` sends them (associative arrays
  are re-indexed; see [types](types.md)):

  ```php
  foreach ($db->stream('SELECT id, ts, body FROM events WHERE ts >= ? AND ts < ?', [$from, $to]) as $row) {
      export($row);
  }
  ```

  They travel in `OP_QUERY_STREAM_PARAMS`, which needs server ≥ 0.330: an
  older server's refusal throws `server does not support parameterised
  streaming (skaidb >= 0.330): …` and leaves the connection usable. A value
  that cannot be bound throws before anything is sent. A stream without
  parameters sends the original `OP_QUERY_STREAM`, so it works against any
  server that streams.
- `$consistency` overrides the connection's level for this statement
  (`'ONE'`, `'QUORUM'`, `'ALL'` or `0`/`1`/`2`). `stream($sql, 'ONE')`, a
  consistency in the second position, means the same as
  `stream($sql, consistency: 'ONE')`.
- A non-row statement streamed this way yields nothing.
- Once the generator has finished, `getReturn()` describes the statement:
  `['kind' => 'rows'|'mutation'|'ddl', 'columns' => [...], 'affected' => n]`
  — the column names even when no row came, the affected count of a
  non-row statement:

  ```php
  $gen = $db->stream('DELETE FROM t WHERE expired');
  foreach ($gen as $row) {}
  $gen->getReturn()['affected'];   // rows deleted
  ```
- An error before any row throws an ordinary `SkaidbException`. An error
  partway through (a node dying mid-scan, a scan budget tripping) throws
  after the rows already yielded, which are valid.
- A lone `SELECT *` streams page by page too: the server resolves its
  columns up front, so the header still arrives before the first row.

## The abandon rule

**The connection is busy for the whole stream.** While the generator is
alive and has not reached the end, every other statement on that
connection throws `connection is busy streaming a result set ...`, and
`isUsable()` is false (a `Pool` will not hand it out).

Abandoning the stream early — `break`, `return`, an exception thrown from
the loop body, or simply letting the generator go out of scope — runs the
generator's cleanup, which **drains** the remaining chunks so the socket is
back at a request boundary. Draining a huge scan costs the rest of that scan;
if that is the wrong trade, `close()` the connection instead and open a new
one. If the drain cannot complete (the socket died), the connection is marked
broken and the next statement re-dials.

```php
foreach ($db->stream('SELECT id FROM big') as $row) {
    if ($row['id'] >= 10) {
        break;                     // cleanup drains the rest; the connection is reusable
    }
}
$db->query('SELECT 1');            // fine
```

Holding a generator in a variable keeps the connection busy until that
variable is unset or goes out of scope. To run something else while a
stream is open, use a second connection (a `Pool` makes that cheap).

## Following a stream — `$db->subscribe($name, $after = null, $poll = 0.5)`

For a table with `CREATE STREAM <name> ...`, `subscribe()` yields the
stream's events as they arrive, forever:

```php
foreach ($db->subscribe('big_orders', after: $lastSeenId, poll: 0.5) as $ev) {
    // $ev = ['id' => '0000...', 'op' => 'put' | 'delete', 'k' => key, 'ts' => DateTimeImmutable, 'doc' => ...]
    handle($ev);
    save($ev['id']);               // resume later with after: $savedId
}
```

It polls the stream's log (`_stream_<name>`) with a keyset cursor, 500
events per page, sleeping `$poll` seconds when a page comes back empty.
`id` is the position: an opaque **string** that sorts in log order, not a
number, so `$after` takes a saved id or `null` (from the beginning).
`break` out of the loop to stop.

For push delivery subscribe to `$stream/<db>/<name>` with any MQTT client
instead; the events are identical.
