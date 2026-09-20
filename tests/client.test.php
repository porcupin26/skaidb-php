<?php

declare(strict_types=1);

// Statements over a fake server: framing and opcodes, every response tag,
// prepare/execute with typed parameters, batches, streaming, errors, the
// prepared-statement cache, reconnect and the pool.

use Skaidb\Bytes;
use Skaidb\Connection;
use Skaidb\Decimal;
use Skaidb\Pool;
use Skaidb\SkaidbException;
use Skaidb\Statement;
use Skaidb\Uuid;
use SkaidbTests\F;
use SkaidbTests\FakeServer;

/** A server that prepares anything, echoes bound params back, and scripts a few shapes. */
function scripted_server(): FakeServer
{
    return (new FakeServer(['handle' => function (array $req) {
        static $nextId = 100;
        static $prepared = []; // id => [sql, nparams]
        switch ($req['op']) {
            case 1: // query
                $sql = $req['sql'];
                if (str_starts_with($sql, 'CREATE') || str_starts_with($sql, 'USE')) {
                    return F::ddl();
                }
                if (str_starts_with($sql, 'DELETE')) {
                    return F::mutation(3);
                }
                if (str_starts_with($sql, 'SELECT boom')) {
                    return F::error('no such column: boom');
                }
                if (str_starts_with($sql, 'CALL')) {
                    return F::resultSets([[['a'], [[1], [2]]], [['b', 'c'], [['x', true]]]]);
                }
                if (str_starts_with($sql, 'SELECT empty')) {
                    return F::rows(['id'], []);
                }
                if (str_starts_with($sql, 'SELECT garbage')) {
                    return chr(42);
                }
                return F::rows(['sql', 'consistency'], [[$sql, $req['consistency']]]);
            case 2: // prepare
                if (str_starts_with($req['sql'], 'CREATE') || str_starts_with($req['sql'], 'USE')) {
                    return F::error('cannot prepare DDL');
                }
                $id = $nextId++;
                $n = substr_count($req['sql'], '?');
                $prepared[$id] = [$req['sql'], $n];
                return F::prepared($id, $n);
            case 3: // execute prepared: echo the params as one row
                if (!isset($prepared[$req['id']])) {
                    return F::error("unknown prepared id {$req['id']}");
                }
                if (str_starts_with($prepared[$req['id']][0], 'UPDATE')) {
                    return F::mutation(1);
                }
                $cols = [];
                foreach ($req['params'] as $i => $_) {
                    $cols[] = "p{$i}";
                }
                $cols[] = 'id';
                $cols[] = 'consistency';
                return F::rows($cols, [[...$req['params'], $req['id'], $req['consistency']]]);
            case 7: // batch
                if (!isset($prepared[$req['id']])) {
                    return F::error("unknown prepared id {$req['id']}");
                }
                if (count($req['rows']) === 3 && $req['rows'][2][0] === 'fail') {
                    return F::error('row 2 failed after 2 rows applied');
                }
                return F::mutation(count($req['rows']));
            case 5: // stream
                if (str_starts_with($req['sql'], 'SELECT boom')) {
                    return F::error('no such column: boom');
                }
                if (str_starts_with($req['sql'], 'INSERT')) {
                    return F::mutation(1);
                }
                if (str_starts_with($req['sql'], 'SELECT midfail')) {
                    return [F::header(['n']), F::chunk([[1], [2]]), F::error('scan budget exceeded')];
                }
                if (str_starts_with($req['sql'], 'SELECT big')) {
                    $out = [F::header(['id', 'v'])];
                    for ($c = 0; $c < 10; $c++) {
                        $rows = [];
                        for ($i = 0; $i < 500; $i++) {
                            $rows[] = [$c * 500 + $i, 'v' . ($c * 500 + $i)];
                        }
                        $out[] = F::chunk($rows);
                    }
                    $out[] = F::end();
                    return $out;
                }
                return [F::header(['n', 'tag']), F::chunk([[1, 'a'], [2, 'b']]), F::chunk([]), F::chunk([[3, 'c']]), F::end()];
            case 8:
                return F::ddl();
        }
        return null;
    }]))->start();
}

test('query: Rows decode into named rows, fetch/fetchAll/fetchColumn/rowCount', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $st = $db->query('SELECT 1');
    assert_eq(['sql', 'consistency'], $st->columns());
    assert_eq(2, $st->columnCount());
    assert_eq(1, $st->rowCount());
    assert_eq(['sql' => 'SELECT 1', 'consistency' => 1], $st->fetch());
    assert_eq(null, $st->fetch());
    $st = $db->query('SELECT 2');
    assert_eq('SELECT 2', $st->fetchColumn());
    assert_eq(false, $st->fetchColumn());
    $st = $db->query('SELECT 3');
    assert_eq(1, $st->fetchColumn(1));
    assert_eq([], $db->query('SELECT empty')->fetchAll());
    assert_eq(0, $db->query('SELECT empty')->rowCount());
    // The request frame carried OP_QUERY with the connection's consistency.
    $q = array_values(array_filter($srv->requests(), fn ($r) => $r['op'] === 1));
    assert_eq(['op' => 1, 'consistency' => 1, 'sql' => 'SELECT 1', 'user' => 'ada'], $q[0]);
    $db->close();
    $srv->stop();
});

test('query: Mutation and Ddl replies; exec() returns the affected count', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    assert_eq(0, $db->exec('CREATE TABLE t (PRIMARY KEY (id))'));
    assert_eq(3, $db->exec('DELETE FROM t'));
    $st = $db->prepare('DELETE FROM t');
    $st->execute();
    assert_eq(3, $st->rowCount());
    assert_eq(0, $st->columnCount());
    assert_eq(null, $st->fetch());
    $db->close();
    $srv->stop();
});

test('query: an Error reply is a SkaidbException and the connection stays usable', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT boom'), '/no such column: boom/');
    assert_true($db->isUsable());
    assert_eq(1, $db->query('SELECT after')->rowCount());
    $db->close();
    $srv->stop();
});

test('query: an unknown response tag throws', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT garbage'), '/unknown response tag 42/');
    $db->close();
    $srv->stop();
});

test('consistency: per connection and per statement, on the wire', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret', 'ONE');
    assert_eq(0, $db->query('SELECT a')->fetch()['consistency']);
    $db->setConsistency('ALL');
    assert_eq(2, $db->query('SELECT b')->fetch()['consistency']);
    $st = $db->prepare('SELECT c')->setConsistency(1);
    $st->execute();
    assert_eq(1, $st->fetch()['consistency']);
    assert_eq(2, $db->getConsistency());
    $db->close();
    $srv->stop();
});

test('prepared: execute with parameters prepares once and sends typed values', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $dt = new \DateTimeImmutable('2023-11-14T22:13:20.123Z');
    $params = [1, 'O\'Brien', 2.5, true, null, [1, 'x'], ['k' => [1]], new Decimal('9.99'),
               new Uuid('123e4567-e89b-12d3-a456-426614174000'), $dt, new Bytes("\x00\xff")];
    $st = $db->prepare('SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?');
    $st->execute($params);
    $row = $st->fetch();
    assert_eq(1, $row['p0']);
    assert_eq('O\'Brien', $row['p1']);
    assert_eq(2.5, $row['p2']);
    assert_eq(true, $row['p3']);
    assert_eq(null, $row['p4']);
    assert_eq([1, 'x'], $row['p5']);
    assert_eq(['k' => [1]], $row['p6']);
    assert_eq('9.99', $row['p7']);
    assert_eq('123e4567-e89b-12d3-a456-426614174000', $row['p8']);
    assert_eq($dt->format('U.u'), $row['p9']->format('U.u'));
    assert_eq("\x00\xff", $row['p10']);
    assert_eq(100, $row['id']);
    // Second execute on the same SQL: no second OP_PREPARE.
    $st->execute($params);
    $st2 = $db->prepare('SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?');
    $st2->execute($params);
    $ops = array_map(fn ($r) => $r['op'], array_filter($srv->requests(), fn ($r) => in_array($r['op'], [2, 3], true)));
    assert_eq([2, 3, 3, 3], array_values($ops));
    // The server saw the exact wire types.
    $exec = array_values(array_filter($srv->requests(), fn ($r) => $r['op'] === 3))[0];
    assert_eq(100, $exec['id']);
    assert_eq("\x00\xff", $exec['params'][10]);
    $db->close();
    $srv->stop();
});

test('prepared: a parameter-count mismatch is refused before anything runs', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $st = $db->prepare('SELECT ?, ?');
    assert_throws(SkaidbException::class, fn () => $st->execute([1]), '/statement expects 2 parameters, got 1/');
    assert_eq([2], array_values(array_map(fn ($r) => $r['op'], array_filter($srv->requests(), fn ($r) => in_array($r['op'], [2, 3], true)))));
    $db->close();
    $srv->stop();
});

test('prepared: DDL the server declines to prepare falls back to client-side quoting', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $st = $db->prepare('USE ?');
    $st->execute(["it's"]);
    $q = array_values(array_filter($srv->requests(), fn ($r) => $r['op'] === 1));
    assert_eq("USE 'it''s'", $q[0]['sql']);
    $db->close();
    $srv->stop();
});

test('prepared: a mutation through execute() reports rowCount', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $st = $db->prepare('UPDATE t SET a = ? WHERE id = ?');
    assert_true($st->execute(['x', 1]));
    assert_eq(1, $st->rowCount());
    $db->close();
    $srv->stop();
});

test('batch: one OP_EXECUTE_BATCH carries every row typed; total affected returned', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $st = $db->prepare('INSERT INTO t (id, tags) VALUES (?, ?)');
    assert_eq(3, $st->executeBatch([[1, ['a']], [2, []], [3, ['b', 'c']]]));
    assert_eq(0, $st->executeBatch([]));
    $b = array_values(array_filter($srv->requests(), fn ($r) => $r['op'] === 7));
    assert_eq(1, count($b));
    assert_eq([[1, ['a']], [2, []], [3, ['b', 'c']]], $b[0]['rows']);
    assert_eq(1, $b[0]['consistency']);
    assert_throws(SkaidbException::class, fn () => $st->executeBatch([[1]]), '/batch row expects 2 parameters, got 1/');
    assert_throws(SkaidbException::class, fn () => $st->executeBatch([[1, 'a'], [2, 'b'], ['fail', 'c']]), '/row 2 failed/');
    assert_true($db->isUsable());
    $db->close();
    $srv->stop();
});

test('multiple result sets: a CALL that EMITs exposes every set, the last one as the rows', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $st = $db->query('CALL report()');
    assert_eq([['b' => 'x', 'c' => true]], $st->fetchAll());
    $sets = $st->resultSets();
    assert_eq(2, count($sets));
    assert_eq(['a'], $sets[0]['columns']);
    assert_eq([['a' => 1], ['a' => 2]], $sets[0]['rows']);
    assert_eq(null, $db->query('SELECT 1')->resultSets());
    $db->close();
    $srv->stop();
});

test('stream: header, chunks (including empty ones) and RowsEnd yield rows lazily', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $rows = [];
    foreach ($db->stream('SELECT n, tag FROM t', 'ALL') as $row) {
        $rows[] = $row;
    }
    assert_eq([['n' => 1, 'tag' => 'a'], ['n' => 2, 'tag' => 'b'], ['n' => 3, 'tag' => 'c']], $rows);
    $s = array_values(array_filter($srv->requests(), fn ($r) => $r['op'] === 5))[0];
    assert_eq(2, $s['consistency']);
    assert_true($db->isUsable());
    // 5000 rows in 10 chunks
    $n = 0;
    foreach ($db->stream('SELECT big') as $row) {
        assert_eq('v' . $n, $row['v']);
        $n++;
    }
    assert_eq(5000, $n);
    $db->close();
    $srv->stop();
});

test('stream: a non-row statement yields nothing; an early error throws', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    assert_eq([], iterator_to_array($db->stream('INSERT INTO t VALUES (1)')));
    assert_throws(SkaidbException::class, fn () => iterator_to_array($db->stream('SELECT boom')), '/no such column: boom/');
    assert_true($db->isUsable());
    $db->close();
    $srv->stop();
});

test('stream: an Error after the header throws after the rows already delivered', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $seen = [];
    $e = assert_throws(SkaidbException::class, function () use ($db, &$seen) {
        foreach ($db->stream('SELECT midfail') as $row) {
            $seen[] = $row['n'];
        }
    }, '/scan budget exceeded/');
    assert_eq([1, 2], $seen);
    assert_true($db->isUsable());
    $db->close();
    $srv->stop();
});

test('stream: the connection is busy until the stream ends; abandoning drains it', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $gen = $db->stream('SELECT big');
    $first = $gen->current();
    assert_eq(0, $first['id']);
    assert_true(!$db->isUsable());
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT 1'), '/busy streaming/');
    assert_throws(SkaidbException::class, fn () => $db->prepare('SELECT ?')->execute([1]), '/busy streaming/');
    unset($gen); // the generator's finally drains the remaining chunks
    assert_true($db->isUsable());
    assert_eq(1, $db->query('SELECT 1')->rowCount());
    // break out of a foreach: same thing
    foreach ($db->stream('SELECT big') as $row) {
        if ($row['id'] >= 3) {
            break;
        }
    }
    assert_eq(1, $db->query('SELECT 2')->rowCount());
    $db->close();
    $srv->stop();
});

test('reconnect: a connection the server dropped re-dials on the next statement and clears the prepared cache', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret', 'QUORUM', 5.0, 'app');
    $st = $db->prepare('SELECT ?');
    $st->execute([1]);
    $srv->closeAll();
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT 1'), '/connection closed by server|timed out/');
    assert_true(!$db->isUsable());
    $st->execute([2]);
    assert_eq(2, $st->fetch()['p0']);
    assert_true($db->isUsable());
    $ops = array_map(fn ($r) => $r['op'], $srv->requests());
    // connect, hello, USE, prepare, execute, (query lost), connect, hello, USE, prepare, execute
    assert_eq(['connect', 8, 1, 2, 3, 'connect', 8, 1, 2, 3], $ops);
    assert_eq('USE "app"', $srv->requests()[7]['sql']);
    $db->close();
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT 1'), '/connection is closed/');
    $srv->stop();
});

test('pool: idle connections are reused, broken ones discarded, surplus closed', function () {
    $srv = scripted_server();
    $pool = new Pool(['127.0.0.1', $srv->port, 'ada', 'secret'], 1);
    $n = $pool->withConnection(fn (Connection $c) => $c->query('SELECT 1')->rowCount());
    assert_eq(1, $n);
    $a = $pool->acquire();
    $b = $pool->acquire();
    assert_true($a !== $b);
    $pool->release($a);
    $pool->release($b); // surplus: closed
    assert_true(!$b->isUsable());
    assert_true($a === $pool->acquire());
    $pool->release($a);
    $srv->closeAll();
    $c = $pool->acquire(); // $a still looks usable locally; it is handed out and re-dials
    assert_true($c === $a);
    assert_throws(SkaidbException::class, fn () => $c->query('SELECT 1'));
    $pool->release($c);  // broken now: not pooled
    assert_true(!$c->isUsable());
    $d = $pool->acquire();
    assert_true($d !== $c);
    $pool->release($d);
    $pool->close();
    assert_throws(SkaidbException::class, fn () => $pool->acquire(), '/pool is closed/');
    assert_throws(SkaidbException::class, fn () => new Pool([], 0), '/maxsize/');
    $srv->stop();
});

test('framing: a 0-length frame and a large frame both round-trip', function () {
    $srv = (new FakeServer(['handle' => function (array $req) {
        if ($req['op'] === 1 && $req['sql'] === 'SELECT big') {
            return F::rows(['s'], [[str_repeat('x', 3_000_000)]]);
        }
        return F::rows(['len'], [[strlen($req['sql'] ?? '')]]);
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    assert_eq(3_000_000, strlen($db->query('SELECT big')->fetchColumn()));
    $long = 'SELECT ' . str_repeat('y', 2_000_000);
    assert_eq(strlen($long), $db->query($long)->fetchColumn());
    $db->close();
    $srv->stop();
});

test('subscribe: pages the stream log with a keyset cursor', function () {
    $srv = (new FakeServer(['handle' => function (array $req) {
        static $ids = [100];
        if ($req['op'] === 2) {
            $id = array_shift($ids) ?? 100;
            return F::prepared($id, 1);
        }
        if ($req['op'] === 1) { // first page, no cursor
            return F::rows(['id', 'op', 'k', 'ts', 'doc'], [['0001-a', 'put', 'k1', 1, ['x' => 1]], ['0002-b', 'put', 'k2', 2, ['x' => 2]]]);
        }
        if ($req['op'] === 3) {
            return $req['params'][0] === '0002-b'
                ? F::rows(['id', 'op', 'k', 'ts', 'doc'], [['0003-c', 'delete', 'k1', 3, null]])
                : F::rows(['id', 'op', 'k', 'ts', 'doc'], []);
        }
        return null;
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $got = [];
    foreach ($db->subscribe('orders', null, 0.01) as $ev) {
        $got[] = $ev['id'];
        if (count($got) === 3) {
            break;
        }
    }
    assert_eq(['0001-a', '0002-b', '0003-c'], $got);
    $db->close();
    $srv->stop();
});
