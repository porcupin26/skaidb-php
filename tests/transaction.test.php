<?php

declare(strict_types=1);

// Transactions (PROTOCOL.md §6): the typed error for a server Error whose
// message starts with "transaction conflict:", on every statement path, and
// the retrying Connection::transaction() / Pool::transaction() helper. Each
// test scripts a fake server by SQL text and asserts on the statements that
// server received, in order.

use Skaidb\Connection;
use Skaidb\Pool;
use Skaidb\SkaidbException;
use Skaidb\TransactionConflictException;
use SkaidbTests\F;
use SkaidbTests\FakeServer;

/** The server's message for a refused COMMIT. Only its prefix is the contract. */
const TXN_CONFLICT = 'transaction conflict: a row of table t was changed by another writer after '
    . 'the transaction began — nothing was committed; run the transaction again';

/**
 * A server scripted by SQL text. $script maps a statement to the replies it
 * gets, one per arrival, the last one repeating; a reply is a payload, a
 * list of payloads (a stream's frames) or FakeServer::DROP. A statement not
 * in the script answers Ddl when it is BEGIN…, COMMIT or ROLLBACK and a
 * one-row Mutation otherwise. OP_PREPARE is accepted unless
 * "prepare <sql>" is scripted; OP_EXECUTE and OP_EXECUTE_BATCH answer what
 * the prepared SQL is scripted to.
 *
 * @param array<string,array<int,string|array<int,string>>> $script
 */
function txn_server(array $script = []): FakeServer
{
    return (new FakeServer(['handle' => function (array $req) use ($script) {
        static $arrivals = [];
        static $prepared = [];
        static $nextId = 1;
        switch ($req['op']) {
            case 1:
            case 5:
            case 9:
                $key = $req['sql'];
                break;
            case 2:
                $key = 'prepare ' . $req['sql'];
                if (!isset($script[$key])) {
                    $prepared[$nextId] = $req['sql'];
                    return F::prepared($nextId++, substr_count($req['sql'], '?'));
                }
                break;
            case 3:
            case 7:
                $key = $prepared[$req['id']] ?? '';
                break;
            default:
                return null; // Hello: a Ddl ack
        }
        $default = preg_match('/^(BEGIN|COMMIT|ROLLBACK)\b/', $key) === 1 ? F::ddl() : F::mutation(1);
        $replies = $script[$key] ?? [$default];
        $n = $arrivals[$key] ?? 0;
        $arrivals[$key] = $n + 1;
        return $replies[min($n, count($replies) - 1)];
    }]))->start();
}

function txn_connect(FakeServer $srv): Connection
{
    return new Connection('127.0.0.1', $srv->port, 'ada', 'secret', 'QUORUM', 5.0);
}

/**
 * What the server received after the handshake, in order: a statement's SQL,
 * "prepare <sql>", "execute <params>", "batch <rows>", "stream <sql>".
 *
 * @return array<int,string>
 */
function txn_statements(FakeServer $srv): array
{
    $out = [];
    foreach ($srv->requests() as $r) {
        switch ($r['op']) {
            case 1:
                $out[] = $r['sql'];
                break;
            case 2:
                $out[] = 'prepare ' . $r['sql'];
                break;
            case 3:
                $out[] = 'execute ' . json_encode($r['params']);
                break;
            case 7:
                $out[] = 'batch ' . json_encode($r['rows']);
                break;
            case 5:
            case 9:
                $out[] = 'stream ' . $r['sql'];
                break;
        }
    }
    return $out;
}

// ---- the typed error --------------------------------------------------------

test('conflict: a server Error starting with "transaction conflict:" is a TransactionConflictException', function () {
    $srv = txn_server([
        'COMMIT' => [F::error(TXN_CONFLICT)],
        'SELECT reworded' => [F::error('transaction conflict: whatever a later server says')],
        'SELECT elsewhere' => [F::error('commit failed: transaction conflict: not at the start')],
    ]);
    $db = txn_connect($srv);
    $e = assert_throws(TransactionConflictException::class, fn () => $db->exec('COMMIT'));
    assert_eq(TXN_CONFLICT, $e->getMessage(), 'the message is the server\'s, unchanged');
    // Code that catches the driver's statement error keeps working.
    $caught = null;
    try {
        $db->exec('COMMIT');
    } catch (SkaidbException $s) {
        $caught = $s;
    }
    assert_true($caught instanceof TransactionConflictException);
    // The connection is at a request boundary: the next statement runs on it.
    assert_true($db->isUsable());
    assert_eq(1, $db->exec('UPDATE t SET n = 1'));
    // Only the prefix is matched, and only at the start.
    assert_throws(TransactionConflictException::class, fn () => $db->query('SELECT reworded'), '/whatever a later server says/');
    $plain = assert_throws(SkaidbException::class, fn () => $db->query('SELECT elsewhere'), '/not at the start/');
    assert_eq(SkaidbException::class, get_class($plain));
    assert_eq(1, $srv->connections(), 'a conflict never reconnects');
    $db->close();
    $srv->stop();
});

test('conflict: every statement path raises the typed error and leaves the connection usable', function () {
    $conflict = [F::error(TXN_CONFLICT)];
    $srv = txn_server([
        'COMMIT' => $conflict,
        'UPDATE t SET n = ? WHERE id = ?' => $conflict,
        'INSERT INTO t (id) VALUES (?)' => $conflict,
        'SELECT early' => $conflict,
        'SELECT early WHERE id = ?' => $conflict,
        'SELECT midway' => [[F::header(['n']), F::chunk([[1], [2]]), F::error(TXN_CONFLICT)]],
        'prepare SELECT ? AS refused' => $conflict,
    ]);
    $db = txn_connect($srv);
    $paths = [
        'exec' => fn () => $db->exec('COMMIT'),
        'query' => fn () => $db->query('COMMIT'),
        'execute without parameters' => fn () => $db->prepare('COMMIT')->execute(),
        'execute with parameters' => fn () => $db->prepare('UPDATE t SET n = ? WHERE id = ?')->execute([5, 1]),
        'executeBatch' => fn () => $db->prepare('INSERT INTO t (id) VALUES (?)')->executeBatch([[1], [2]]),
        'stream, before the header' => fn () => iterator_to_array($db->stream('SELECT early')),
        'stream with parameters' => fn () => iterator_to_array($db->stream('SELECT early WHERE id = ?', [1])),
        'the reply to a prepare' => fn () => $db->prepare('SELECT ? AS refused')->execute([1]),
    ];
    foreach ($paths as $name => $run) {
        try {
            $run();
            fail("{$name}: expected a TransactionConflictException");
        } catch (TransactionConflictException $e) {
            assert_eq(TXN_CONFLICT, $e->getMessage(), $name);
        }
        assert_true($db->isUsable(), "{$name}: the connection stays usable");
    }
    // Mid-stream: the rows already delivered stand, then the typed error.
    $seen = [];
    assert_throws(TransactionConflictException::class, function () use ($db, &$seen) {
        foreach ($db->stream('SELECT midway') as $row) {
            $seen[] = $row['n'];
        }
    });
    assert_eq([1, 2], $seen);
    assert_true($db->isUsable());
    assert_eq(1, $db->exec('UPDATE t SET n = 1'));
    // One request per attempt and nothing more: in particular the prepare
    // answered with a conflict was not taken for "declined", so no
    // client-side fallback sent that statement again as text.
    assert_eq([
        'COMMIT',
        'COMMIT',
        'COMMIT',
        'prepare UPDATE t SET n = ? WHERE id = ?',
        'execute [5,1]',
        'prepare INSERT INTO t (id) VALUES (?)',
        'batch [[1],[2]]',
        'stream SELECT early',
        'stream SELECT early WHERE id = ?',
        'prepare SELECT ? AS refused',
        'stream SELECT midway',
        'UPDATE t SET n = 1',
    ], txn_statements($srv));
    assert_eq(1, $srv->connections());
    $db->close();
    $srv->stop();
});

test('conflict: a pooled connection goes back to the pool and its member stays in rotation', function () {
    $a = txn_server(['COMMIT' => [F::error(TXN_CONFLICT)]]);
    $b = txn_server(['COMMIT' => [F::error(TXN_CONFLICT)]]);
    // ejectErrors: 1 — a single failure counted against a member would eject it.
    $pool = new Pool(
        ['user' => 'ada', 'password' => 'secret', 'timeout' => 5.0,
         'seeds' => ["127.0.0.1:{$a->port}", "127.0.0.1:{$b->port}"]],
        4,
        loadBalancing: 'round_robin',
        ejectErrors: 1
    );
    for ($i = 0; $i < 6; $i++) {
        assert_throws(
            TransactionConflictException::class,
            fn () => $pool->withConnection(fn (Connection $c) => $c->exec('COMMIT'))
        );
    }
    foreach ($pool->members() as $m) {
        assert_eq(['ready', 0, 1], [$m['state'], $m['in_use'], $m['idle']], $m['endpoint']);
    }
    // Three checkouts each, on one connection each: none was discarded.
    assert_eq([1, 1], [$a->connections(), $b->connections()]);
    assert_eq(3, count(txn_statements($a)));
    $pool->close();
    $a->stop();
    $b->stop();
});

// ---- the helper -------------------------------------------------------------

test('transaction: BEGIN, the body\'s statements, COMMIT — once; the body\'s value is returned', function () {
    $srv = txn_server();
    $db = txn_connect($srv);
    $runs = 0;
    $value = $db->transaction(function (Connection $c) use (&$runs, $db) {
        $runs++;
        assert_true($c === $db, 'the body is handed the connection');
        $c->exec('INSERT INTO log (id) VALUES (1)');
        $st = $c->prepare('UPDATE t SET n = ? WHERE id = ?');
        $st->execute([5, 1]);
        return ['updated' => $st->rowCount()];
    });
    assert_eq(['updated' => 1], $value);
    assert_eq(1, $runs);
    assert_eq([
        'BEGIN',
        'INSERT INTO log (id) VALUES (1)',
        'prepare UPDATE t SET n = ? WHERE id = ?',
        'execute [5,1]',
        'COMMIT',
    ], txn_statements($srv));
    // A body may return nothing.
    assert_eq(null, $db->transaction(function (Connection $c) {
        $c->exec('UPDATE t SET n = 1');
    }));
    assert_eq(1, $srv->connections());
    $db->close();
    $srv->stop();
});

test('transaction: a COMMIT refused twice runs the body three times and returns the last run\'s value', function () {
    $srv = txn_server(['COMMIT' => [F::error(TXN_CONFLICT), F::error(TXN_CONFLICT), F::ddl()]]);
    $db = txn_connect($srv);
    $runs = 0;
    $value = $db->transaction(function (Connection $c) use (&$runs) {
        $runs++;
        $c->exec("UPDATE t SET n = {$runs}");
        return "run {$runs}";
    }, 3);
    assert_eq('run 3', $value);
    assert_eq(3, $runs);
    assert_eq([
        'BEGIN', 'UPDATE t SET n = 1', 'COMMIT',
        'BEGIN', 'UPDATE t SET n = 2', 'COMMIT',
        'BEGIN', 'UPDATE t SET n = 3', 'COMMIT',
    ], txn_statements($srv));
    assert_eq(1, $srv->connections(), 'every run on the same connection');
    $db->close();
    $srv->stop();
});

test('transaction: a COMMIT refused on every attempt throws the typed error after exactly that many runs', function () {
    $srv = txn_server(['COMMIT' => [F::error(TXN_CONFLICT)]]);
    $db = txn_connect($srv);
    $runs = 0;
    $body = function (Connection $c) use (&$runs) {
        $runs++;
        $c->exec('UPDATE t SET n = 1');
    };
    $e = assert_throws(TransactionConflictException::class, fn () => $db->transaction($body, 2));
    assert_eq(TXN_CONFLICT, $e->getMessage());
    assert_eq(2, $runs);
    // No ROLLBACK after the last refusal: the transaction is already over.
    assert_eq(['BEGIN', 'UPDATE t SET n = 1', 'COMMIT', 'BEGIN', 'UPDATE t SET n = 1', 'COMMIT'], txn_statements($srv));
    assert_true($db->isUsable());
    // attempts = 1 never runs the body again; the default is three runs.
    $runs = 0;
    assert_throws(TransactionConflictException::class, fn () => $db->transaction($body, 1));
    assert_eq(1, $runs);
    $runs = 0;
    assert_throws(TransactionConflictException::class, fn () => $db->transaction($body));
    assert_eq(3, $runs);
    assert_eq(1, $srv->connections());
    $db->close();
    $srv->stop();
});

test('transaction: a body that throws is rolled back and its exception propagates unchanged', function () {
    $srv = txn_server();
    $db = txn_connect($srv);
    $boom = new \RuntimeException('the body changed its mind');
    $runs = 0;
    $body = function (Connection $c) use (&$runs, $boom) {
        $runs++;
        $c->exec('UPDATE t SET n = 1');
        throw $boom;
    };
    $e = assert_throws(\RuntimeException::class, fn () => $db->transaction($body, 3));
    assert_true($e === $boom);
    assert_eq(1, $runs);
    assert_eq(['BEGIN', 'UPDATE t SET n = 1', 'ROLLBACK'], txn_statements($srv));
    // A PHP Error from the body is cleaned up after the same way.
    assert_throws(\Error::class, fn () => $db->transaction(function (Connection $c) {
        $c->exec('UPDATE t SET n = 2');
        throw new \Error('an Error, not an Exception');
    }), '/an Error, not an Exception/');
    assert_eq(['BEGIN', 'UPDATE t SET n = 2', 'ROLLBACK'], array_slice(txn_statements($srv), 3));
    assert_true($db->isUsable());
    $db->close();
    $srv->stop();
});

test('transaction: an Error answer to the clean-up ROLLBACK never replaces the body\'s exception', function () {
    $srv = txn_server(['ROLLBACK' => [F::error('no transaction in progress')]]);
    $db = txn_connect($srv);
    $boom = new \DomainException('from the body');
    $e = assert_throws(\DomainException::class, fn () => $db->transaction(function (Connection $c) use ($boom) {
        $c->exec('UPDATE t SET n = 1');
        throw $boom;
    }));
    assert_true($e === $boom);
    assert_eq(['BEGIN', 'UPDATE t SET n = 1', 'ROLLBACK'], txn_statements($srv));
    assert_true($db->isUsable());
    $db->close();
    $srv->stop();
});

test('transaction: a statement error thrown out of the body is not retried, even a conflict', function () {
    $srv = txn_server([
        'UPDATE t SET n = 1' => [F::error(TXN_CONFLICT)],
        'SELECT nope' => [F::error('no such column: nope')],
    ]);
    $db = txn_connect($srv);
    $runs = 0;
    $body = function (Connection $c) use (&$runs) {
        $runs++;
        $c->exec('UPDATE t SET n = 1');
    };
    assert_throws(TransactionConflictException::class, fn () => $db->transaction($body, 3));
    assert_eq(1, $runs);
    assert_eq(['BEGIN', 'UPDATE t SET n = 1', 'ROLLBACK'], txn_statements($srv));
    // A statement error the body handles itself does not end the
    // transaction: the body decides, and here it carries on to the COMMIT.
    $value = $db->transaction(function (Connection $c) {
        try {
            $c->query('SELECT nope');
        } catch (SkaidbException $e) {
            return $c->exec('UPDATE t SET n = 2');
        }
        return -1;
    });
    assert_eq(1, $value);
    assert_eq(['BEGIN', 'SELECT nope', 'UPDATE t SET n = 2', 'COMMIT'], array_slice(txn_statements($srv), 3));
    $db->close();
    $srv->stop();
});

test('transaction: a refused BEGIN is thrown; the body never runs and nothing else is sent', function () {
    $srv = txn_server(['BEGIN' => [F::error('BEGIN is refused here')]]);
    $db = txn_connect($srv);
    $runs = 0;
    $body = function (Connection $c) use (&$runs) {
        $runs++;
        $c->exec('UPDATE t SET n = 1');
    };
    $e = assert_throws(SkaidbException::class, fn () => $db->transaction($body), '/BEGIN is refused here/');
    assert_eq(SkaidbException::class, get_class($e));
    assert_eq(0, $runs);
    assert_eq(['BEGIN'], txn_statements($srv));
    assert_true($db->isUsable());
    $db->close();
    $srv->stop();
});

test('transaction: a COMMIT that fails for another reason is thrown — no retry, no ROLLBACK', function () {
    $srv = txn_server(['COMMIT' => [F::error('commit failed: constraint violated')]]);
    $db = txn_connect($srv);
    $runs = 0;
    $body = function (Connection $c) use (&$runs) {
        $runs++;
        $c->exec('UPDATE t SET n = 1');
    };
    $e = assert_throws(SkaidbException::class, fn () => $db->transaction($body, 3), '/commit failed: constraint violated/');
    assert_eq(SkaidbException::class, get_class($e));
    assert_eq(1, $runs);
    assert_eq(['BEGIN', 'UPDATE t SET n = 1', 'COMMIT'], txn_statements($srv));
    assert_true($db->isUsable());
    $db->close();
    $srv->stop();
});

test('transaction: attempts below 1 is refused before anything is sent', function () {
    $srv = txn_server();
    $db = txn_connect($srv);
    $runs = 0;
    $body = function (Connection $c) use (&$runs) {
        $runs++;
    };
    assert_throws(SkaidbException::class, fn () => $db->transaction($body, 0), '/attempts must be >= 1/');
    assert_throws(SkaidbException::class, fn () => $db->transaction($body, -2), '/attempts must be >= 1/');
    assert_eq(0, $runs);
    assert_eq([], txn_statements($srv));
    $db->close();
    $srv->stop();
});

test('transaction: a custom begin statement is sent verbatim, on every run', function () {
    $srv = txn_server(['COMMIT' => [F::error(TXN_CONFLICT), F::ddl()]]);
    $db = txn_connect($srv);
    $begin = 'BEGIN ATOMIC PARTITION (tenant = 7)';
    $db->transaction(fn (Connection $c) => $c->exec('UPDATE t SET n = 1'), 3, $begin);
    assert_eq([$begin, 'UPDATE t SET n = 1', 'COMMIT', $begin, 'UPDATE t SET n = 1', 'COMMIT'], txn_statements($srv));
    $db->transaction(fn (Connection $c) => 0, begin: 'begin  atomic -- as typed');
    assert_eq(['begin  atomic -- as typed', 'COMMIT'], array_slice(txn_statements($srv), 6));
    $db->close();
    $srv->stop();
});

// ---- the transport inside a transaction -------------------------------------

test('transaction: a connection lost in the body is thrown as it is — no retry, no reconnect for a ROLLBACK', function () {
    $srv = txn_server(['UPDATE t SET n = 1' => [FakeServer::DROP, F::mutation(1)]]);
    $db = txn_connect($srv);
    $runs = 0;
    $body = function (Connection $c) use (&$runs) {
        $runs++;
        return $c->exec('UPDATE t SET n = 1');
    };
    assert_throws(SkaidbException::class, fn () => $db->transaction($body, 3), '/connection closed by server/');
    assert_eq(1, $runs);
    assert_eq(['BEGIN', 'UPDATE t SET n = 1'], txn_statements($srv));
    assert_eq(1, $srv->connections(), 'the clean-up ROLLBACK did not dial a new connection');
    assert_true(!$db->isUsable());
    // Afterwards the connection recovers as it always does: the next BEGIN
    // re-dials, and that transaction runs whole on the new connection.
    assert_eq(1, $db->transaction($body));
    assert_eq(['BEGIN', 'UPDATE t SET n = 1', 'COMMIT'], array_slice(txn_statements($srv), 2));
    assert_eq(2, $srv->connections());
    $db->close();
    $srv->stop();
});

test('transaction: after a lost connection nothing more of the transaction is sent on a new one', function () {
    $srv = txn_server(['UPDATE t SET n = 1' => [FakeServer::DROP]]);
    $db = txn_connect($srv);
    $lost = '/connection lost inside a transaction/';
    // The body swallows the transport error and carries on. Outside a
    // transaction the next statement would re-dial and run; in here it
    // would autocommit on the new connection, so every kind of statement is
    // refused instead.
    assert_throws(SkaidbException::class, fn () => $db->transaction(function (Connection $c) use ($lost) {
        try {
            $c->exec('UPDATE t SET n = 1');
        } catch (SkaidbException $e) {
        }
        assert_throws(SkaidbException::class, fn () => $c->prepare('UPDATE t SET n = ? WHERE id = ?')->execute([2, 1]), $lost);
        assert_throws(SkaidbException::class, fn () => iterator_to_array($c->stream('SELECT n FROM t')), $lost);
        $c->exec('UPDATE t SET n = 2');
    }), $lost);
    assert_eq(['BEGIN', 'UPDATE t SET n = 1'], txn_statements($srv));
    assert_eq(1, $srv->connections());
    // The same body returning as if all were well: no COMMIT goes to a new
    // session that has no transaction. (This BEGIN re-dialled, as the first
    // statement after a lost connection does.)
    assert_throws(SkaidbException::class, fn () => $db->transaction(function (Connection $c) {
        try {
            $c->exec('UPDATE t SET n = 1');
        } catch (SkaidbException $e) {
        }
        return 'committed?';
    }), $lost);
    assert_eq(['BEGIN', 'UPDATE t SET n = 1'], array_slice(txn_statements($srv), 2));
    assert_eq(2, $srv->connections());
    // A transaction() inside the body does not lift the guard from the one
    // around it, whatever the server makes of the inner BEGIN.
    assert_throws(SkaidbException::class, fn () => $db->transaction(function (Connection $c) {
        $c->transaction(fn (Connection $c) => $c->exec('UPDATE t SET n = 3'));
        try {
            $c->exec('UPDATE t SET n = 1');
        } catch (SkaidbException $e) {
        }
        $c->exec('UPDATE t SET n = 4');
    }), $lost);
    assert_eq(['BEGIN', 'BEGIN', 'UPDATE t SET n = 3', 'COMMIT', 'UPDATE t SET n = 1'], array_slice(txn_statements($srv), 4));
    assert_eq(3, $srv->connections());
    $db->close();
    $srv->stop();
});

test('transaction: a connection lost on the COMMIT is thrown — not retried, the outcome is unknown', function () {
    $srv = txn_server(['COMMIT' => [FakeServer::DROP]]);
    $db = txn_connect($srv);
    $runs = 0;
    $body = function (Connection $c) use (&$runs) {
        $runs++;
        $c->exec('UPDATE t SET n = 1');
    };
    assert_throws(SkaidbException::class, fn () => $db->transaction($body, 3), '/connection closed by server/');
    assert_eq(1, $runs);
    assert_eq(['BEGIN', 'UPDATE t SET n = 1', 'COMMIT'], txn_statements($srv));
    assert_eq(1, $srv->connections());
    $db->close();
    $srv->stop();
});

// ---- the pool ---------------------------------------------------------------

test('pool transaction: one checked-out connection carries every run and is returned afterwards', function () {
    $srv = txn_server([
        'COMMIT' => [F::error(TXN_CONFLICT), F::ddl()],
        'UPDATE t SET n = 9' => [FakeServer::DROP, F::mutation(1)],
    ]);
    $pool = new Pool(['127.0.0.1', $srv->port, 'ada', 'secret'], 4);
    $handed = [];
    $value = $pool->transaction(function (Connection $c) use (&$handed, $pool) {
        $handed[] = $c;
        assert_eq(1, $pool->members()[0]['in_use']);
        $c->exec('UPDATE t SET n = 1');
        return 'done';
    });
    assert_eq('done', $value);
    assert_eq(2, count($handed));
    assert_true($handed[0] === $handed[1], 'both runs on the same connection');
    assert_eq(['BEGIN', 'UPDATE t SET n = 1', 'COMMIT', 'BEGIN', 'UPDATE t SET n = 1', 'COMMIT'], txn_statements($srv));
    assert_eq(1, $srv->connections());
    $m = $pool->members()[0];
    assert_eq([0, 1], [$m['in_use'], $m['idle']]);
    // A body that throws: rolled back, and the connection is back in the pool.
    $boom = new \RuntimeException('no');
    $e = assert_throws(\RuntimeException::class, fn () => $pool->transaction(function (Connection $c) use ($boom) {
        throw $boom;
    }, 3, 'BEGIN ATOMIC'));
    assert_true($e === $boom);
    assert_eq(['BEGIN ATOMIC', 'ROLLBACK'], array_slice(txn_statements($srv), 6));
    $m = $pool->members()[0];
    assert_eq([0, 1], [$m['in_use'], $m['idle']]);
    assert_eq(1, $srv->connections());
    // A connection lost inside: thrown, nothing re-dialled for the clean-up,
    // and the dead connection is not pooled. The next transaction gets a
    // new one.
    $lossy = fn (Connection $c) => $c->exec('UPDATE t SET n = 9');
    assert_throws(SkaidbException::class, fn () => $pool->transaction($lossy), '/connection closed by server/');
    assert_eq(['BEGIN', 'UPDATE t SET n = 9'], array_slice(txn_statements($srv), 8));
    $m = $pool->members()[0];
    assert_eq([0, 0], [$m['in_use'], $m['idle']]);
    assert_eq(1, $srv->connections());
    assert_eq(1, $pool->transaction($lossy));
    assert_eq(['BEGIN', 'UPDATE t SET n = 9', 'COMMIT'], array_slice(txn_statements($srv), 10));
    assert_eq(2, $srv->connections());
    $pool->close();
    $srv->stop();
});

test('pool transaction: the affinity key is the checkout\'s, so read-your-writes follows the transaction', function () {
    $a = txn_server(['SELECT who' => [F::rows(['member'], [['a']])]]);
    $b = txn_server(['SELECT who' => [F::rows(['member'], [['b']])]]);
    $pool = new Pool(
        ['user' => 'ada', 'password' => 'secret', 'timeout' => 5.0,
         'seeds' => ["127.0.0.1:{$a->port}", "127.0.0.1:{$b->port}"]],
        4,
        loadBalancing: 'round_robin',
        readYourWrites: 60.0
    );
    $who = fn (Connection $c) => $c->query('SELECT who')->fetchColumn();
    $member = $pool->transaction($who, affinity: 'user 1');
    // Round robin would alternate; the key's reads stay where its transaction ran.
    for ($i = 0; $i < 4; $i++) {
        assert_eq($member, $pool->withConnection($who, 'user 1'));
    }
    $other = $pool->withConnection($who, 'user 2');
    assert_true($pool->withConnection($who, 'user 2') !== $other, 'another key is not pinned');
    $pool->close();
    $a->stop();
    $b->stop();
});

test('pool transaction: attempts below 1 is refused before a connection is dialled', function () {
    $srv = txn_server();
    $pool = new Pool(['127.0.0.1', $srv->port, 'ada', 'secret'], 4);
    $runs = 0;
    $body = function (Connection $c) use (&$runs) {
        $runs++;
    };
    assert_throws(SkaidbException::class, fn () => $pool->transaction($body, 0), '/attempts must be >= 1/');
    assert_eq(0, $runs);
    assert_eq(0, $srv->connections());
    $pool->close();
    $srv->stop();
});
