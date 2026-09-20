# Streaming

## Large results — `$db->stream($sql, $consistency = null)`

`query()`/`execute()` buffer the whole result in the `Statement`; a large
scan is also bounded by the server's scan budgets. `stream()` asks the
server for the rows in chunks (`OP_QUERY_STREAM`) and yields them one at a
time, so memory is one chunk regardless of the result size:

```php
$n = 0;
foreach ($db->stream('SELECT id, v FROM readings ORDER BY id') as $row) {
    $n++;                          // $row = ['id' => ..., 'v' => ...]
}
```

- No parameters: the streaming opcode carries SQL text only.
- `$consistency` overrides the connection's level for this statement
  (`'ONE'`, `'QUORUM'`, `'ALL'` or `0`/`1`/`2`).
- A non-row statement streamed this way yields nothing.
- An error before any row throws an ordinary `SkaidbException`. An error
  partway through (a node dying mid-scan, a scan budget tripping) throws
  after the rows already yielded, which are valid.
- On a cluster, name the columns: a bare `SELECT *` only streams page by
  page at consistency `ONE`; a named column list streams at any level.

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
