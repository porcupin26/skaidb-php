<?php

declare(strict_types=1);

// Transactions (PROTOCOL.md §6): the typed error for a server Error whose
// message starts with "transaction conflict:", on every statement path, and
// the retrying Connection::transaction() / Pool::transaction() helper. Each
// test scripts a fake server by SQL text and asserts on the statements that
// server received, in order.

use Skaidb\Connection;
use Skaidb\ConnectionException;
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
 * $cutAt: the server closes a connection that many bytes into a request
 * it has not received whole (see FakeServer), 0 for never.
 *
 * @param array<string,array<int,string|array<int,string>>> $script
 */
function txn_server(array $script = [], int $cutAt = 0): FakeServer
{
    return (new FakeServer(['cutAt' => $cutAt, 'handle' => function (array $req) use ($script) {
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

/**
 * Poll $cond until it holds, for at most $seconds: what the fake server's
 * child has seen (a socket closing) reaches its log a moment later.
 */
function txn_wait(callable $cond, float $seconds = 2.0): bool
{
    $until = microtime(true) + $seconds;
    do {
        if ($cond()) {
            return true;
        }
        usleep(5_000);
    } while (microtime(true) < $until);
    return false;
}

/** Whether $db holds an open socket: Connection's private $sock, for what no server can observe. */
function txn_holds_socket(Connection $db): bool
{
    return is_resource((new \ReflectionProperty(Connection::class, 'sock'))->getValue($db));
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
    // Verbatim also where it holds a question mark that is no placeholder.
    $db->transaction(fn (Connection $c) => 0, begin: 'BEGIN ATOMIC PARTITION ("why?" = \'because?\') -- ready?');
    assert_eq(['BEGIN ATOMIC PARTITION ("why?" = \'because?\') -- ready?', 'COMMIT'], array_slice(txn_statements($srv), 8));
    // It takes no parameters: a placeholder in it is refused before
    // anything is sent, and the body does not run.
    $runs = 0;
    assert_throws(
        SkaidbException::class,
        fn () => $db->transaction(function () use (&$runs) {
            $runs++;
        }, 3, 'BEGIN ATOMIC PARTITION (tenant = ?)'),
        '/^query has placeholders but no parameters given$/'
    );
    assert_eq([0, 10], [$runs, count(txn_statements($srv))]);
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

test('transaction: the body swallows a lost connection and writes on — the write is not sent, nothing is committed', function () {
    // PROTOCOL.md §6 rule 7, the whole scenario: statement 1 succeeds, the
    // connection is lost under statement 2, the body catches its error,
    // runs a write as statement 3 and returns as if all were well.
    $srv = txn_server(['UPDATE t SET n = 2' => [FakeServer::DROP]]);
    $db = txn_connect($srv);
    $lost = '/^connection lost inside a transaction: the statement was not sent/';
    $caught = [];
    $body = function (Connection $c) use (&$caught, $lost) {
        assert_eq(1, $c->exec('UPDATE t SET n = 1'));
        try {
            $c->exec('UPDATE t SET n = 2');
        } catch (SkaidbException $e) {
            $caught[] = $e->getMessage();
        }
        // Whatever the body tries next would autocommit on a new session.
        try {
            $c->exec('INSERT INTO log (id) VALUES (3)');
        } catch (SkaidbException $e) {
            $caught[] = $e->getMessage();
        }
        assert_throws(SkaidbException::class, fn () => $c->prepare('INSERT INTO log (id) VALUES (?)')->execute([4]), $lost);
        assert_throws(SkaidbException::class, fn () => $c->prepare('INSERT INTO log (id) VALUES (?)')->executeBatch([[5], [6]]), $lost);
        assert_throws(SkaidbException::class, fn () => iterator_to_array($c->stream('INSERT INTO log (id) VALUES (?)', [7])), $lost);
        return 'all is well';
    };
    // Not the body's value: the loss. The COMMIT was never sent, so the
    // error can say what that means — nothing was committed.
    assert_throws(
        ConnectionException::class,
        fn () => $db->transaction($body),
        '/^connection lost inside a transaction: the COMMIT was not sent, and nothing was committed/'
    );
    assert_eq(2, count($caught));
    assert_match('/^connection closed by server/', $caught[0], 'statement 2: the transport error');
    assert_match($lost, $caught[1], 'statement 3: refused, not sent');
    // The server saw one connection and, on it, the transaction's start,
    // statement 1 and the statement it dropped the connection under: no
    // statement 3, no COMMIT, no ROLLBACK anywhere.
    assert_eq(1, $srv->connections());
    assert_eq(['BEGIN', 'UPDATE t SET n = 1', 'UPDATE t SET n = 2'], txn_statements($srv));
    // Afterwards the connection is what it is after any connection loss:
    // the next statement re-dials and runs, on its own.
    assert_true(!$db->isUsable());
    assert_eq(1, $db->exec('UPDATE t SET n = 9'));
    assert_true($db->isUsable());
    assert_eq(2, $srv->connections());
    assert_eq(['UPDATE t SET n = 9'], array_slice(txn_statements($srv), 3));
    $db->close();
    $srv->stop();
});

test('transaction: the opening statement meets a lost connection exactly as an ordinary statement does', function () {
    $srv = txn_server([
        'UPDATE t SET n = 5' => [FakeServer::DROP, F::mutation(1)],
        'BEGIN ATOMIC' => [FakeServer::DROP, F::ddl()],
    ]);
    $plain = txn_connect($srv);
    $txn = txn_connect($srv);
    $runs = 0;
    $body = function (Connection $c) use (&$runs) {
        $runs++;
        return $c->exec('UPDATE t SET n = 1');
    };
    // Idle connections, and the server goes away under them (a restart).
    // An ordinary statement finds out before it is sent and runs on a new
    // connection; so does the transaction, $begin first.
    $srv->closeAll();
    assert_eq(1, $plain->exec('UPDATE t SET n = 0'));
    assert_eq(1, $txn->transaction($body));
    assert_eq(1, $runs);
    assert_eq(['UPDATE t SET n = 0', 'BEGIN', 'UPDATE t SET n = 1', 'COMMIT'], txn_statements($srv));
    assert_eq(4, $srv->connections());
    assert_true($plain->isUsable() && $txn->isUsable());
    // The connection lost UNDER the statement: an ordinary one throws the
    // transport error, once, and is not sent again. So does $begin — the
    // body never runs — and the next call re-dials, for both.
    $runs = 0;
    $ordinary = assert_throws(ConnectionException::class, fn () => $plain->exec('UPDATE t SET n = 5'), '/^connection closed by server$/');
    $begin = assert_throws(ConnectionException::class, fn () => $txn->transaction($body, 3, 'BEGIN ATOMIC'), '/^connection closed by server$/');
    assert_eq(get_class($ordinary), get_class($begin));
    assert_eq(0, $runs, 'the body never ran');
    assert_eq(['UPDATE t SET n = 5', 'BEGIN ATOMIC'], array_slice(txn_statements($srv), 4), 'and nothing else was sent');
    assert_eq(1, $plain->exec('UPDATE t SET n = 5'));
    assert_eq(1, $txn->transaction($body, 3, 'BEGIN ATOMIC'));
    assert_eq(1, $runs);
    assert_eq(['UPDATE t SET n = 5', 'BEGIN ATOMIC', 'UPDATE t SET n = 1', 'COMMIT'], array_slice(txn_statements($srv), 6));
    assert_eq(6, $srv->connections());
    $plain->close();
    $txn->close();
    // A pool: its idle connection is found closed at the checkout and is
    // not handed out, for an ordinary statement and for a transaction
    // alike.
    $pool = new Pool(['127.0.0.1', $srv->port, 'ada', 'secret'], 4);
    $statement = fn (Connection $c) => $c->exec('UPDATE t SET n = 0');
    assert_eq(1, $pool->withConnection($statement));
    $srv->closeAll();
    assert_eq(1, $pool->withConnection($statement));
    $srv->closeAll();
    $runs = 0;
    assert_eq(1, $pool->transaction($body));
    assert_eq(1, $runs);
    assert_eq(
        ['UPDATE t SET n = 0', 'UPDATE t SET n = 0', 'BEGIN', 'UPDATE t SET n = 1', 'COMMIT'],
        array_slice(txn_statements($srv), 10)
    );
    assert_eq(9, $srv->connections());
    $m = $pool->members()[0];
    assert_eq([0, 1], [$m['in_use'], $m['idle']]);
    $pool->close();
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
    assert_throws(ConnectionException::class, fn () => $db->transaction($body, 3), TXN_UNKNOWN);
    assert_eq(1, $runs);
    assert_eq(['BEGIN', 'UPDATE t SET n = 1', 'COMMIT'], txn_statements($srv));
    assert_eq(1, $srv->connections());
    $db->close();
    $srv->stop();
});

// ---- a transaction the application writes itself ----------------------------

/** The error of a statement that is not sent because its transaction's connection is gone. */
const TXN_NOT_SENT = '/^connection lost inside a transaction: the statement was not sent/';

/** The error of a COMMIT that is not sent for the same reason: nothing can have been committed. */
const TXN_NOT_COMMITTED = '/^connection lost inside a transaction: the COMMIT was not sent, and nothing was committed/';

/** The error of a COMMIT the connection was lost under: sent, never answered. */
const TXN_UNKNOWN = '/^connection closed by server: the connection was lost under the COMMIT, so whether the transaction was committed is not known$/';

test('hand-written: BEGIN, statements, COMMIT with nothing lost go as they always did', function () {
    $srv = txn_server(['COMMIT' => [F::ddl(), F::error(TXN_CONFLICT), F::ddl()]]);
    $db = txn_connect($srv);
    assert_eq(0, $db->exec('BEGIN'));
    assert_eq(1, $db->exec('INSERT INTO t (id) VALUES (1)'));
    $st = $db->prepare('UPDATE t SET n = ? WHERE id = ?');
    $st->execute([5, 1]);
    assert_eq(0, $db->exec('COMMIT'));
    // Rolled back, and a COMMIT the server refuses: each ends the
    // transaction, and the connection goes on as an ordinary one.
    $db->exec('BEGIN TRANSACTION');
    $db->exec('INSERT INTO t (id) VALUES (2)');
    assert_eq('ddl', $db->query('ROLLBACK')->kind());
    $db->exec('begin');
    $db->exec('INSERT INTO t (id) VALUES (3)');
    assert_throws(TransactionConflictException::class, fn () => $db->exec('COMMIT'));
    assert_eq([
        'BEGIN', 'INSERT INTO t (id) VALUES (1)', 'prepare UPDATE t SET n = ? WHERE id = ?', 'execute [5,1]', 'COMMIT',
        'BEGIN TRANSACTION', 'INSERT INTO t (id) VALUES (2)', 'ROLLBACK',
        'begin', 'INSERT INTO t (id) VALUES (3)', 'COMMIT',
    ], txn_statements($srv));
    assert_eq(1, $srv->connections());
    // No transaction is open now: a connection lost here is replaced like
    // any other, and the statement runs.
    $srv->closeAll();
    assert_eq(1, $db->exec('INSERT INTO t (id) VALUES (4)'));
    assert_eq(2, $srv->connections());
    $db->close();
    $srv->stop();
});

test('hand-written: after a lost connection nothing more of the transaction is sent, and COMMIT says nothing was committed', function () {
    // PROTOCOL.md §6 rule 7 for a BEGIN the application sent itself. However
    // the connection goes — closed while idle, half-closed (the write would
    // still succeed), reset, or lost under row 2 — row 2 fails, row 3 fails
    // unsent, and so does the COMMIT.
    $losses = [
        'closed while idle' => FakeServer::CLOSE,
        'half-closed while idle' => FakeServer::HALF,
        'reset while idle' => FakeServer::RESET,
        'lost under the statement' => null,
    ];
    foreach ($losses as $how => $close) {
        $srv = txn_server(['INSERT INTO t (id) VALUES (2)' => [FakeServer::DROP]]);
        $db = txn_connect($srv);
        $db->exec('BEGIN');
        assert_eq(1, $db->exec('INSERT INTO t (id) VALUES (1)'));
        if ($close !== null) {
            $srv->closeAll($close);
        }
        $e = assert_throws(
            ConnectionException::class,
            fn () => $db->exec('INSERT INTO t (id) VALUES (2)'),
            $close === null ? '/^connection closed by server$/' : TXN_NOT_SENT
        );
        // Every further statement, whichever way it is sent.
        assert_throws(ConnectionException::class, fn () => $db->exec('INSERT INTO t (id) VALUES (3)'), TXN_NOT_SENT);
        assert_throws(ConnectionException::class, fn () => $db->query('SELECT n FROM t'), TXN_NOT_SENT);
        assert_throws(ConnectionException::class, fn () => $db->prepare('INSERT INTO t (id) VALUES (?)')->execute([3]), TXN_NOT_SENT);
        assert_throws(ConnectionException::class, fn () => $db->prepare('INSERT INTO t (id) VALUES (?)')->executeBatch([[3]]), TXN_NOT_SENT);
        assert_throws(ConnectionException::class, fn () => iterator_to_array($db->stream('SELECT n FROM t')), TXN_NOT_SENT);
        assert_throws(ConnectionException::class, fn () => $db->exec('BEGIN'), TXN_NOT_SENT);
        assert_true(!$db->isUsable(), $how);
        // The COMMIT is not sent either, and says what that means: the
        // server dropped the transaction with the connection.
        $e = assert_throws(ConnectionException::class, fn () => $db->exec('COMMIT'), TXN_NOT_COMMITTED);
        $sent = $close === null
            ? ['BEGIN', 'INSERT INTO t (id) VALUES (1)', 'INSERT INTO t (id) VALUES (2)']
            : ['BEGIN', 'INSERT INTO t (id) VALUES (1)'];
        assert_eq($sent, txn_statements($srv), $how);
        assert_eq(1, $srv->connections(), "{$how}: something was sent on a new session");
        usleep(50_000);
        assert_eq(0, $srv->strayBytes(), $how);
        // That ended it: the next statement re-dials, as after any loss.
        assert_eq(1, $db->exec('UPDATE t SET n = 9'));
        assert_eq(['UPDATE t SET n = 9'], array_slice(txn_statements($srv), count($sent)), $how);
        assert_eq(2, $srv->connections());
        $db->close();
        $srv->stop();
    }
});

test('hand-written: ROLLBACK after a lost connection succeeds without being sent', function () {
    $srv = txn_server();
    $db = txn_connect($srv);
    $rollbacks = [
        'exec' => fn () => assert_eq(0, $db->exec('ROLLBACK')),
        'query' => fn () => assert_eq('ddl', $db->query('rollback transaction;')->kind()),
        'stream' => function () use ($db) {
            $gen = $db->stream('ROLLBACK -- give up');
            assert_eq([], iterator_to_array($gen));
            assert_eq(['kind' => 'ddl', 'columns' => [], 'affected' => 0], $gen->getReturn());
        },
    ];
    $connections = 1;
    foreach ($rollbacks as $name => $rollback) {
        $db->exec('BEGIN');
        $db->exec('INSERT INTO t (id) VALUES (1)');
        $srv->closeAll(FakeServer::HALF);
        assert_throws(ConnectionException::class, fn () => $db->exec('INSERT INTO t (id) VALUES (2)'), TXN_NOT_SENT);
        $from = count(txn_statements($srv));
        // The server rolled the transaction back when the connection
        // went: there is nothing to send, and nothing to report.
        $rollback();
        assert_eq($from, count(txn_statements($srv)), "{$name}: the ROLLBACK was sent");
        assert_eq($connections, $srv->connections(), "{$name}: the ROLLBACK dialled");
        // The transaction is over for the driver too.
        assert_eq(1, $db->exec('UPDATE t SET n = 9'));
        assert_eq(++$connections, $srv->connections(), $name);
    }
    usleep(50_000);
    assert_eq(0, $srv->strayBytes());
    // With the connection alive a ROLLBACK travels, like any statement.
    $db->exec('BEGIN');
    $db->exec('ROLLBACK');
    assert_eq(['BEGIN', 'ROLLBACK'], array_slice(txn_statements($srv), -2));
    $db->close();
    $srv->stop();
});

test('hand-written: a COMMIT or ROLLBACK that is the first to find the connection gone is not sent either', function () {
    foreach ([FakeServer::CLOSE, FakeServer::HALF, FakeServer::RESET] as $how) {
        $srv = txn_server();
        $db = txn_connect($srv);
        // Nothing has failed yet: the COMMIT is what looks at the socket.
        // Written to a half-closed connection it would go nowhere, and on a
        // new one it would answer for another transaction, or for none.
        $db->exec('BEGIN');
        $db->exec('INSERT INTO t (id) VALUES (1)');
        $srv->closeAll($how);
        assert_throws(ConnectionException::class, fn () => $db->exec('COMMIT'), TXN_NOT_COMMITTED);
        assert_eq(1, $db->exec('UPDATE t SET n = 9'), $how);
        $db->exec('BEGIN');
        $db->exec('INSERT INTO t (id) VALUES (2)');
        $srv->closeAll($how);
        assert_eq(0, $db->exec('ROLLBACK'), $how);
        assert_eq(1, $db->exec('UPDATE t SET n = 9'), $how);
        assert_eq(
            ['BEGIN', 'INSERT INTO t (id) VALUES (1)', 'UPDATE t SET n = 9', 'BEGIN', 'INSERT INTO t (id) VALUES (2)', 'UPDATE t SET n = 9'],
            txn_statements($srv),
            $how
        );
        usleep(50_000);
        assert_eq([3, 0], [$srv->connections(), $srv->strayBytes()], $how);
        $db->close();
        $srv->stop();
    }
});

test('hand-written: a connection lost under the COMMIT itself leaves the outcome unknown, and the error says so', function () {
    $srv = txn_server(['COMMIT' => [FakeServer::DROP], 'ROLLBACK' => [FakeServer::DROP]]);
    $db = txn_connect($srv);
    $db->exec('BEGIN');
    $db->exec('INSERT INTO t (id) VALUES (1)');
    // Sent, and never answered: the server may have applied it before its
    // reply went missing.
    $e = assert_throws(ConnectionException::class, fn () => $db->exec('COMMIT'), TXN_UNKNOWN);
    assert_true($e->getPrevious() instanceof ConnectionException);
    assert_eq(['BEGIN', 'INSERT INTO t (id) VALUES (1)', 'COMMIT'], txn_statements($srv));
    // Either way the transaction is over: the connection re-dials.
    assert_eq(1, $db->exec('UPDATE t SET n = 9'));
    assert_eq(2, $srv->connections());
    // Streamed, the same.
    $db->exec('BEGIN');
    assert_throws(ConnectionException::class, fn () => iterator_to_array($db->stream('COMMIT')), TXN_UNKNOWN);
    assert_eq(1, $db->exec('UPDATE t SET n = 9'));
    // A ROLLBACK lost that way: the plain transport error — there is
    // nothing unknown about a transaction that was not committed — and the
    // transaction is over as well.
    $db->exec('BEGIN');
    assert_throws(ConnectionException::class, fn () => $db->exec('ROLLBACK'), '/^connection closed by server$/');
    assert_eq(1, $db->exec('UPDATE t SET n = 9'));
    // A COMMIT with no transaction open that loses the connection is an
    // ordinary lost statement.
    assert_throws(ConnectionException::class, fn () => $db->exec('COMMIT'), '/^connection closed by server$/');
    assert_eq(1, $db->exec('UPDATE t SET n = 9'));
    assert_eq(5, $srv->connections());
    $db->close();
    $srv->stop();
});

test('hand-written: what opens and what ends a transaction, as the server reads it', function () {
    $read = fn (string $sql) => Connection::sessionStatement($sql)[0] ?? null;
    foreach (['BEGIN', 'begin', ' Begin ; ', 'BEGIN TRANSACTION', 'BEGIN ATOMIC', 'BEGIN ATOMIC TRANSACTION',
        'BEGIN ATOMIC PARTITION (tenant = 7)', "-- start\nBEGIN -- now", 'begin  atomic -- as typed'] as $sql) {
        assert_eq('begin', $read($sql), $sql);
    }
    foreach (['COMMIT', 'commit;', 'COMMIT TRANSACTION', " commit\ttransaction ; ", "COMMIT -- done"] as $sql) {
        assert_eq('commit', $read($sql), $sql);
    }
    foreach (['ROLLBACK', 'rollback', 'ROLLBACK TRANSACTION;', "-- no\nROLLBACK"] as $sql) {
        assert_eq('rollback', $read($sql), $sql);
    }
    foreach (['BEGINNING', 'COMMIT WORK', 'COMMIT; BEGIN', 'ROLLBACK TO SAVEPOINT a', 'COMMITTED', '-- COMMIT', 'ROLLBACKS',
        'SELECT 1 -- COMMIT', 'INSERT INTO t (id, v) VALUES (1, \'BEGIN\')', 'EXPLAIN SELECT 1'] as $sql) {
        assert_eq(null, $read($sql), $sql);
    }
    // A BEGIN the server refuses opens nothing; a statement that fails
    // inside a transaction does not end it.
    $srv = txn_server(['BEGIN ATOMIC' => [F::error('BEGIN ATOMIC needs a cluster')], 'SELECT nope' => [F::error('no such column: nope')]]);
    $db = txn_connect($srv);
    assert_throws(SkaidbException::class, fn () => $db->exec('BEGIN ATOMIC'), '/needs a cluster/');
    $srv->closeAll();
    assert_eq(1, $db->exec('UPDATE t SET n = 1'), 'no transaction was open: the connection re-dials');
    $db->exec('BEGIN');
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT nope'), '/no such column/');
    $srv->closeAll();
    assert_throws(ConnectionException::class, fn () => $db->exec('UPDATE t SET n = 2'), TXN_NOT_SENT);
    assert_eq(0, $db->exec('ROLLBACK'));
    assert_eq(2, $srv->connections());
    $db->close();
    $srv->stop();
});

test('hand-written: transaction() notes its own transaction once, and leaves none behind', function () {
    $srv = txn_server(['UPDATE t SET n = 2' => [FakeServer::DROP, F::mutation(1)]] + txn_stream_script());
    $db = txn_connect($srv);
    // Every way the helper ends, the connection is an ordinary one again:
    // lost afterwards, it is replaced and the statement runs.
    $ends = [
        'committed' => fn () => $db->transaction(fn (Connection $c) => $c->exec('UPDATE t SET n = 1')),
        'rolled back' => fn () => assert_throws(\RuntimeException::class, fn () => $db->transaction(function (Connection $c) {
            throw new \RuntimeException('no');
        })),
        'lost in the body' => fn () => assert_throws(ConnectionException::class, fn () => $db->transaction(fn (Connection $c) => $c->exec('UPDATE t SET n = 2'))),
        'a stream left open' => fn () => assert_throws(SkaidbException::class, fn () => $db->transaction(function (Connection $c) {
            $rows = $c->stream('SELECT n FROM t');
            $rows->current();
            return $rows;
        }), '/^connection is busy streaming/'),
    ];
    foreach ($ends as $name => $run) {
        $run();
        $srv->closeAll();
        assert_eq(1, $db->exec('UPDATE t SET n = 9'), $name);
    }
    // Inside the helper the body's own COMMIT and ROLLBACK are the
    // transaction's too: after a loss neither is sent.
    $srv2 = txn_server();
    $db2 = txn_connect($srv2);
    assert_throws(ConnectionException::class, fn () => $db2->transaction(function (Connection $c) use ($srv2) {
        $c->exec('UPDATE t SET n = 1');
        $srv2->closeAll(FakeServer::HALF);
        assert_throws(ConnectionException::class, fn () => $c->exec('UPDATE t SET n = 2'), TXN_NOT_SENT);
        assert_eq(0, $c->exec('ROLLBACK'));
        // Still inside transaction(): what follows would autocommit.
        assert_throws(ConnectionException::class, fn () => $c->exec('UPDATE t SET n = 3'), TXN_NOT_SENT);
    }), TXN_NOT_COMMITTED);
    assert_eq(['BEGIN', 'UPDATE t SET n = 1'], txn_statements($srv2));
    assert_eq(1, $srv2->connections());
    assert_eq(1, $db2->exec('UPDATE t SET n = 9'));
    $db2->close();
    $srv2->stop();
    $db->close();
    $srv->stop();
});

// ---- a stream the body leaves open --------------------------------------------

/**
 * A script whose stream sends four rows in two chunks: after the first row
 * the connection still owes a chunk and RowsEnd.
 *
 * @return array<string,array<int,array<int,string>>>
 */
function txn_stream_script(): array
{
    return ['SELECT n FROM t' => [[F::header(['n']), F::chunk([[1], [2]]), F::chunk([[3], [4]]), F::end()]]];
}

test('transaction: a body that returns with its stream open is not committed, and the connection is discarded', function () {
    $srv = txn_server(txn_stream_script());
    $db = txn_connect($srv);
    $runs = 0;
    // "The rows, lazily": the generator outlives the body, so the COMMIT
    // cannot be sent on the busy connection.
    $body = function (Connection $c) use (&$runs) {
        $runs++;
        $c->exec('UPDATE t SET n = 1');
        $rows = $c->stream('SELECT n FROM t');
        assert_eq(['n' => 1], $rows->current());
        return $rows;
    };
    $e = assert_throws(SkaidbException::class, fn () => $db->transaction($body), '/^connection is busy streaming a result set/');
    assert_eq(1, $runs, 'never run again for this');
    // The transaction must not stay open on a live socket, where the next
    // statement would silently join it: the socket is closed, so the server
    // drops the session and the transaction with it.
    assert_true(txn_wait(fn () => $srv->hangups() === 1), 'the connection still holds the socket the transaction is open on');
    assert_eq(['BEGIN', 'UPDATE t SET n = 1', 'stream SELECT n FROM t'], txn_statements($srv), 'no COMMIT, no ROLLBACK');
    assert_match('/the transaction was not committed and its connection was closed/', $e->getMessage());
    // Afterwards the connection is as after any connection loss: the next
    // statement runs on a new session, outside the abandoned transaction.
    assert_true(!$db->isUsable());
    assert_eq(1, $db->exec('UPDATE t SET n = 2'));
    assert_eq(2, $srv->connections());
    assert_eq(1, $db->transaction(fn (Connection $c) => $c->exec('UPDATE t SET n = 3')));
    assert_eq(['UPDATE t SET n = 2', 'BEGIN', 'UPDATE t SET n = 3', 'COMMIT'], array_slice(txn_statements($srv), 3));
    $db->close();
    $srv->stop();
});

test('transaction: a body that throws with its stream open gets its own exception, and the connection is discarded', function () {
    $srv = txn_server(txn_stream_script());
    $db = txn_connect($srv);
    $boom = new \RuntimeException('the body changed its mind');
    $held = null;
    $body = function (Connection $c) use (&$held, $boom) {
        $c->exec('UPDATE t SET n = 1');
        $held = $c->stream('SELECT n FROM t');   // kept beyond the body
        assert_eq(['n' => 1], $held->current());
        throw $boom;
    };
    $e = assert_throws(\RuntimeException::class, fn () => $db->transaction($body));
    assert_true($e === $boom, 'the body\'s exception, unchanged');
    assert_true($held instanceof \Generator);
    // The ROLLBACK cannot be sent on the busy connection either.
    assert_true(txn_wait(fn () => $srv->hangups() === 1), 'the connection still holds the socket the transaction is open on');
    assert_eq(['BEGIN', 'UPDATE t SET n = 1', 'stream SELECT n FROM t'], txn_statements($srv));
    // The stream is over. The chunk already read is still handed out; the
    // rest went with the socket. Until the generator is let go the
    // connection is busy, as with any stream.
    assert_throws(SkaidbException::class, fn () => $db->exec('UPDATE t SET n = 2'), '/busy streaming/');
    $seen = [];
    assert_throws(SkaidbException::class, function () use ($held, &$seen) {
        foreach ($held as $row) {
            $seen[] = $row['n'];
        }
    }, '/^connection lost: the connection was closed with this stream still open$/');
    assert_eq([1, 2], $seen);
    $held = null;
    assert_eq(1, $srv->connections(), 'nothing re-dialled while the stream was held');
    assert_eq(1, $db->exec('UPDATE t SET n = 2'));
    assert_eq(2, $srv->connections());
    assert_eq(['UPDATE t SET n = 2'], array_slice(txn_statements($srv), 3));
    $db->close();
    $srv->stop();
});

test('transaction: a stream the body finished or abandoned itself does not cost the connection', function () {
    $srv = txn_server(txn_stream_script());
    $db = txn_connect($srv);
    $first = $db->transaction(function (Connection $c) {
        foreach ($c->stream('SELECT n FROM t') as $row) {
            return $row['n'];             // abandoned: drained as the body returns
        }
    });
    assert_eq(1, $first);
    $all = $db->transaction(fn (Connection $c) => iterator_to_array($c->stream('SELECT n FROM t')));
    assert_eq([1, 2, 3, 4], array_column($all, 'n'));
    // A stream never started sends nothing, so it does not hold the connection.
    $lazy = $db->transaction(fn (Connection $c) => $c->stream('SELECT n FROM t'));
    assert_eq(
        ['BEGIN', 'stream SELECT n FROM t', 'COMMIT', 'BEGIN', 'stream SELECT n FROM t', 'COMMIT', 'BEGIN', 'COMMIT'],
        txn_statements($srv)
    );
    assert_true($db->isUsable());
    assert_eq([1, 0], [$srv->connections(), $srv->hangups()]);
    unset($lazy);
    $db->close();
    $srv->stop();
});

test('transaction: a body that closes the connection with its stream open leaves it closed — nothing re-dials', function () {
    $srv = txn_server(txn_stream_script());
    $db = txn_connect($srv);
    $held = null;
    $body = function (Connection $c) use (&$held) {
        $held = $c->stream('SELECT n FROM t');
        assert_eq(['n' => 1], $held->current());
        $c->close();
    };
    assert_throws(SkaidbException::class, fn () => $db->transaction($body), '/busy streaming/');
    $held = null;
    // close() is terminal: giving the connection up must not make it one
    // that dials again.
    assert_throws(SkaidbException::class, fn () => $db->exec('UPDATE t SET n = 2'), '/^connection is closed$/');
    assert_throws(SkaidbException::class, fn () => $db->exec('UPDATE t SET n = 2'), '/^connection is closed$/');
    assert_eq(1, $srv->connections());
    $srv->stop();
});

test('pool transaction: a connection whose body left a stream open is not pooled with the transaction open', function () {
    $srv = txn_server(txn_stream_script());
    $pool = new Pool(['127.0.0.1', $srv->port, 'ada', 'secret'], 4);
    assert_throws(SkaidbException::class, fn () => $pool->transaction(function (Connection $c) {
        $c->exec('UPDATE t SET n = 1');
        $rows = $c->stream('SELECT n FROM t');
        assert_eq(['n' => 1], $rows->current());
        return $rows;
    }), '/busy streaming/');
    $m = $pool->members()[0];
    assert_eq([0, 0], [$m['in_use'], $m['idle']], 'the connection went back to the pool with its transaction open');
    assert_true(txn_wait(fn () => $srv->hangups() === 1));
    // The next borrower gets a new session: its statement autocommits.
    assert_eq(1, $pool->withConnection(fn (Connection $c) => $c->exec('UPDATE t SET n = 2')));
    assert_eq(2, $srv->connections());
    $pool->close();
    $srv->stop();
});

// ---- a broken connection lets go of its socket ------------------------------

test('transaction: a read timeout in the body closes the socket at once — the server\'s session ends with it', function () {
    $srv = txn_server(['UPDATE t SET n = 2' => [[]]]);   // received, never answered
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret', 'QUORUM', 0.5);
    assert_throws(SkaidbException::class, fn () => $db->transaction(function (Connection $c) {
        $c->exec('UPDATE t SET n = 1');
        $c->exec('UPDATE t SET n = 2');
    }), '/connection timed out/');
    assert_eq(['BEGIN', 'UPDATE t SET n = 1', 'UPDATE t SET n = 2'], txn_statements($srv));
    // No further statement and no close(): the server's session — and the
    // transaction open in it, with the rows it claimed — must not outlive
    // the failure until this process happens to use the connection again.
    assert_true(txn_wait(fn () => $srv->hangups() === 1), 'the timed-out connection still holds its socket open');
    assert_true(!$db->isUsable());
    // The connection object recovers as after any connection loss.
    assert_eq(1, $db->exec('UPDATE t SET n = 3'));
    assert_eq([2, 1], [$srv->connections(), $srv->hangups()]);
    $db->close();
    $srv->stop();
});

test('transport: every way a live connection is marked broken closes its socket at once', function () {
    $srv = txn_server([
        'SELECT slow' => [[]],                                              // never answered
        'SELECT odd' => [chr(42)],                                          // no reply to a stream request
        'SELECT blank' => [''],                                             // an empty frame
        'SELECT endless' => [[F::header(['n']), F::chunk([[1]])]],          // no RowsEnd ever comes
        'SELECT garbled' => [[F::header(['n']), F::chunk([[1]]), chr(42)]], // the framing is lost in the rest
        'SELECT hollow' => [[F::header(['n']), F::chunk([[1]]), '']],       // an empty frame in the rest
    ]);
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret', 'QUORUM', 0.5);
    $breaks = [
        'a read timeout' => [fn () => $db->query('SELECT slow'), '/connection timed out/'],
        'a read timeout on a stream request' => [fn () => iterator_to_array($db->stream('SELECT slow')), '/connection timed out/'],
        'an unexpected reply to a stream request' => [fn () => iterator_to_array($db->stream('SELECT odd')), '/unexpected response tag 42/'],
        'an empty reply to a stream request' => [fn () => iterator_to_array($db->stream('SELECT blank')), '/truncated server message/'],
        'a read timeout mid-stream' => [fn () => iterator_to_array($db->stream('SELECT endless')), '/connection timed out/'],
    ];
    $n = 0;
    foreach ($breaks as $name => [$run, $message]) {
        assert_throws(SkaidbException::class, $run, $message);
        $n++;
        assert_true(!$db->isUsable(), "{$name}: the connection is broken");
        assert_true(txn_wait(fn () => $srv->hangups() === $n), "{$name}: the broken connection still holds its socket open");
        // The next statement re-dials, as it always did.
        assert_eq(1, $db->exec('UPDATE t SET n = 1'), $name);
        assert_eq([$n + 1, $n], [$srv->connections(), $srv->hangups()], $name);
    }
    // An abandoned stream whose drain cannot finish — the rest never comes,
    // or is not a stream's frames: broken, and closed too.
    foreach (['SELECT endless', 'SELECT garbled', 'SELECT hollow'] as $sql) {
        $gen = $db->stream($sql);
        assert_eq(['n' => 1], $gen->current());
        unset($gen);
        $n++;
        assert_true(!$db->isUsable(), "{$sql}: the connection is broken");
        assert_true(txn_wait(fn () => $srv->hangups() === $n), "{$sql}: a failed drain still holds its socket open");
        assert_eq(1, $db->exec('UPDATE t SET n = 1'));
    }
    $db->close();
    $srv->stop();
});

test('transport: a connection the server closed lets go of its socket at once, on a read and on a write', function () {
    // The server's end is gone already, so nothing lingers there; what is
    // left to close is this process's own descriptor.
    $srv = txn_server(['SELECT gone' => [FakeServer::DROP]], 1024 * 1024);
    $db = txn_connect($srv);
    assert_true(txn_holds_socket($db));
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT gone'), '/^connection closed by server$/');
    assert_true(!txn_holds_socket($db), 'end-of-file on a read: the socket is still open');
    assert_eq(1, $db->exec('UPDATE t SET n = 1'));
    assert_true(txn_holds_socket($db));
    // A request far larger than the socket buffers, to a server that closes
    // the connection a megabyte into it: the write itself fails partway.
    assert_throws(
        SkaidbException::class,
        fn () => $db->query('SELECT ' . str_repeat('x', 16 * 1024 * 1024)),
        '/^connection closed by server \(write\)$/'
    );
    assert_true(!txn_holds_socket($db), 'a failed write: the socket is still open');
    assert_eq(1, $db->exec('UPDATE t SET n = 1'));
    assert_eq(3, $srv->connections());
    $db->close();
    $srv->stop();
});

test('transport: a re-dial that fails after its handshake does not leave the new socket open', function () {
    // The session database is entered on every dial; here the second USE is
    // refused, so the re-dial fails with a live, authenticated socket.
    $srv = txn_server([
        'USE "app"' => [F::ddl(), F::error('no such database: app'), F::ddl()],
        'SELECT gone' => [FakeServer::DROP],
    ]);
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret', 'QUORUM', 5.0, 'app');
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT gone'), '/connection closed by server/');
    assert_throws(SkaidbException::class, fn () => $db->exec('UPDATE t SET n = 1'), '/^no such database: app$/');
    assert_true(!$db->isUsable());
    assert_true(txn_wait(fn () => $srv->hangups() === 1), 'the failed re-dial still holds its socket open');
    assert_eq(['USE "app"', 'SELECT gone', 'USE "app"'], txn_statements($srv));
    // Still down, as before: the next statement dials again.
    assert_eq(1, $db->exec('UPDATE t SET n = 1'));
    assert_eq(3, $srv->connections());
    $db->close();
    $srv->stop();
});

test('transport: a re-dial that loses its Hello fails the statement with a transport error; the next one re-dials', function () {
    $srv = (new FakeServer(['handle' => function (array $req) {
        static $hellos = 0;
        if ($req['op'] === 8) {
            return ++$hellos === 2 ? FakeServer::DROP : F::ddl();
        }
        if ($req['op'] === 1) {
            return $req['sql'] === 'SELECT gone' ? FakeServer::DROP : F::mutation(1);
        }
        return null;
    }]))->start();
    $db = txn_connect($srv);
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT gone'), '/connection closed by server/');
    // The second connection authenticates and is dropped on its Hello, an
    // error the dial ignores: the statement finds no socket to travel on.
    assert_throws(ConnectionException::class, fn () => $db->exec('UPDATE t SET n = 1'), '/^connection closed by server \(write\)$/');
    assert_true(!$db->isUsable());
    assert_eq(1, $db->exec('UPDATE t SET n = 1'));
    assert_eq(3, $srv->connections());
    $db->close();
    $srv->stop();
});

test('transport: a lost connection is a ConnectionException, and a statement error is not', function () {
    $srv = txn_server([
        'SELECT gone' => [FakeServer::DROP],
        'SELECT slow' => [[]],
        'SELECT odd' => [chr(42)],
        'prepare SELECT odd WHERE id = ?' => [chr(42)],
        'SELECT blank' => [''],
        'SELECT garbled' => [[F::header(['n']), F::chunk([[1]]), chr(42)]],
        'SELECT nope' => [F::error('no such column: nope')],
        'COMMIT' => [F::error(TXN_CONFLICT)],
    ] + txn_stream_script(), 1024 * 1024);
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret', 'QUORUM', 0.5);
    // Every way the connection is lost under a statement: one type, still a
    // SkaidbException, and the connection re-dials for the next statement.
    $lost = [
        'end-of-file on a read' => [fn () => $db->query('SELECT gone'), '/^connection closed by server$/'],
        'a read timeout' => [fn () => $db->query('SELECT slow'), '/^connection timed out$/'],
        'a reply the driver cannot place' => [fn () => $db->query('SELECT odd'), '/^unknown response tag 42$/'],
        'a reply to a prepare it cannot place' => [fn () => $db->prepare('SELECT odd WHERE id = ?')->execute([1]), '/^unexpected prepare response tag 42$/'],
        'a reply to a stream request it cannot place' => [fn () => iterator_to_array($db->stream('SELECT odd')), '/^unexpected response tag 42 to stream request$/'],
        'an empty reply to a stream request' => [fn () => iterator_to_array($db->stream('SELECT blank')), '/^truncated server message$/'],
        'a frame it cannot place in a stream' => [fn () => iterator_to_array($db->stream('SELECT garbled')), '/^unexpected frame tag 42 in stream$/'],
        'a stream cut off' => [fn () => iterator_to_array($db->stream('SELECT gone')), '/^connection closed by server$/'],
    ];
    foreach ($lost as $name => [$run, $message]) {
        $e = assert_throws(ConnectionException::class, $run, $message);
        assert_true($e instanceof SkaidbException, $name);
        assert_true(!$db->isUsable(), "{$name}: the connection is broken");
        assert_eq(1, $db->exec('UPDATE t SET n = 1'), "{$name}: the next statement re-dials");
    }
    // A failed write: the server closes the connection a megabyte into a
    // request far larger than the socket buffers.
    assert_throws(ConnectionException::class, fn () => $db->query('SELECT ' . str_repeat('x', 16 * 1024 * 1024)), '/^connection closed by server \(write\)$/');
    // Inside transaction(), the statements that are not sent any more.
    $e = assert_throws(ConnectionException::class, fn () => $db->transaction(function (Connection $c) {
        try {
            $c->query('SELECT gone');
        } catch (ConnectionException $e) {
            assert_throws(ConnectionException::class, fn () => $c->exec('UPDATE t SET n = 2'), '/^connection lost inside a transaction/');
            throw $e;
        }
    }), '/^connection closed by server$/');
    // A stream read on after its connection was given up.
    $held = null;
    $body = function (Connection $c) use (&$held) {
        $held = $c->stream('SELECT garbled');
        $held->current();
    };
    $e = assert_throws(SkaidbException::class, fn () => $db->transaction($body), '/^connection is busy streaming/');
    assert_eq(SkaidbException::class, get_class($e), 'the body\'s misuse, not a lost connection');
    assert_throws(ConnectionException::class, fn () => $held->next(), '/^connection lost: the connection was closed with this stream still open$/');
    $held = null;
    // What the server answers is the statement's error, never this type —
    // nor is what the application did to the connection itself.
    $plain = [
        'a statement error' => [fn () => $db->query('SELECT nope'), SkaidbException::class],
        'a refused COMMIT' => [fn () => $db->exec('COMMIT'), TransactionConflictException::class],
        'a statement while a stream is open' => [function () use ($db) {
            $gen = $db->stream('SELECT n FROM t');
            $gen->current();
            $db->query('SELECT 1');
        }, SkaidbException::class],
    ];
    foreach ($plain as $name => [$run, $class]) {
        $e = assert_throws($class, $run);
        assert_true(!$e instanceof ConnectionException, "{$name}: " . get_class($e));
        assert_eq($class, get_class($e), $name);
    }
    $db->close();
    $e = assert_throws(SkaidbException::class, fn () => $db->query('SELECT 1'), '/^connection is closed$/');
    assert_eq(SkaidbException::class, get_class($e), 'closed by the application');
    $srv->stop();

    // A connection that cannot be made.
    $e = assert_throws(ConnectionException::class, fn () => new Connection('127.0.0.1', 1, 'ada', 'secret', 'QUORUM', 0.5), '/^no reachable endpoint in 127\.0\.0\.1:1: /');
    $full = (new FakeServer(['refuse' => fn () => true]))->start();
    assert_throws(ConnectionException::class, fn () => new Connection('127.0.0.1', $full->port, 'ada', 'secret'), '/^connection closed by server$/');
    $full->stop();
    // A capacity refusal that arrives as a denied login is the member being
    // full, with the server's reason as the message (PROTOCOL.md §6).
    $busy = (new FakeServer(['deny' => 'too many connections: 500 of 500']))->start();
    assert_throws(ConnectionException::class, fn () => new Connection('127.0.0.1', $busy->port, 'ada', 'secret'), '/^authentication denied: too many connections: 500 of 500$/');
    $busy->stop();
    // A refused login is not: the same credentials fail everywhere.
    $srv = (new FakeServer())->start();
    $e = assert_throws(SkaidbException::class, fn () => new Connection('127.0.0.1', $srv->port, 'ada', 'wrong'), '/^authentication denied: bad password$/');
    assert_eq(SkaidbException::class, get_class($e));
    $srv->stop();
    $srv = (new FakeServer(['corruptServerSignature' => true]))->start();
    $e = assert_throws(SkaidbException::class, fn () => new Connection('127.0.0.1', $srv->port, 'ada', 'secret'), '/^server signature mismatch/');
    assert_eq(SkaidbException::class, get_class($e));
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
