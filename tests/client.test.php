<?php

declare(strict_types=1);

// Statements over a fake server: framing and opcodes, every response tag,
// prepare/execute with typed parameters, batches, streaming, errors, the
// prepared-statement cache, reconnect and the pool.

use Skaidb\Bytes;
use Skaidb\Connection;
use Skaidb\ConnectionException;
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
                    return F::error('statement kind cannot be prepared');
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

test('query: a ? in a quoted identifier or a comment does not stop a statement sent without parameters', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $statements = ['SELECT id AS "ok?" FROM t', "SELECT id FROM t -- really?", "SELECT 'is it?' AS q"];
    foreach ($statements as $sql) {
        assert_eq($sql, $db->query($sql)->fetchColumn(), $sql);
    }
    $db->prepare($statements[0])->execute();
    assert_eq(0, $db->exec('CREATE TABLE "why?" (PRIMARY KEY (id)) -- because?'));
    // A placeholder with nothing to bind is still refused before anything
    // is sent.
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT ? AS "ok?"'), '/^query has placeholders but no parameters given$/');
    assert_eq(
        [...$statements, $statements[0], 'CREATE TABLE "why?" (PRIMARY KEY (id)) -- because?'],
        array_values(array_map(fn ($r) => $r['sql'], array_filter($srv->requests(), fn ($r) => $r['op'] === 1)))
    );
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

test('desync: a frame the driver cannot place drops the connection — the next statement gets its own reply', function () {
    // Each of these replies is followed by a frame nobody asked for. Left
    // on the socket, it would answer the next statement, whose own reply
    // would answer the one after, and so on.
    $srv = (new FakeServer(['handle' => function (array $req) {
        $leftover = F::rows(['answer_to'], [['a leftover frame']]);
        switch ($req['op']) {
            case 1:
                return $req['sql'] === 'SELECT garbage'
                    ? [chr(42), $leftover]
                    : F::rows(['answer_to'], [[$req['sql']]]);
            case 2:
                return str_starts_with($req['sql'], 'SELECT garbage') ? [chr(42), $leftover] : F::prepared(7, 1);
            case 3:
                return F::rows(['answer_to'], [['execute ' . json_encode($req['params'])]]);
            case 5:
                return [F::header(['n']), F::chunk([[1]]), chr(42), F::chunk([[2]]), F::end()];
        }
        return null;
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $desyncs = [
        'a reply to a statement' => [fn () => $db->query('SELECT garbage'), '/^unknown response tag 42$/'],
        'a reply to a prepare' => [fn () => $db->prepare('SELECT garbage WHERE id = ?')->execute([1]), '/^unexpected prepare response tag 42$/'],
        'a frame in a stream' => [fn () => iterator_to_array($db->stream('SELECT n FROM t')), '/^unexpected frame tag 42 in stream$/'],
    ];
    $n = 1;
    foreach ($desyncs as $name => [$run, $message]) {
        assert_throws(SkaidbException::class, $run, $message);
        $usable = $db->isUsable();
        assert_eq('SELECT after', $db->query('SELECT after')->fetchColumn(), "{$name}: the next statement was answered with a leftover frame");
        assert_true(!$usable, "{$name}: the connection was not at a request boundary any more");
        $st = $db->prepare('SELECT ? AS after');
        $st->execute([$n]);
        assert_eq("execute [{$n}]", $st->fetchColumn(), $name);
        assert_eq(++$n, $srv->connections(), "{$name}: one reconnect");
    }
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
    // The level a request carries is the driver's own state, not the
    // session's: a new connection changes nothing about it, and nothing is
    // sent to put it back.
    $srv->closeAll();
    assert_eq(2, $db->query('SELECT d')->fetch()['consistency']);
    $st->execute();
    assert_eq(1, $st->fetch()['consistency']);
    assert_eq(['SELECT d', 'SELECT c'], array_slice(session_seen($srv), -2));
    assert_eq(2, $srv->connections());
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
    $st = $db->prepare('CREATE INDEX by_name ON t (name) WHERE name <> ?');
    $st->execute(["it's"]);
    $q = array_values(array_filter($srv->requests(), fn ($r) => $r['op'] === 1));
    assert_eq("CREATE INDEX by_name ON t (name) WHERE name <> 'it''s'", $q[0]['sql']);
    $db->close();
    $srv->stop();
});

/**
 * A server that answers OP_PREPARE the way skaidb does, in its words
 * (PROTOCOL.md §6 rule 10). A statement that parses but takes no bound
 * parameters: "statement kind cannot be prepared". A `?` where the grammar
 * wants a literal or a name: the same words, a colon and the parser's error
 * — or, from a server before 0.349 ($before349), the parser's error alone,
 * naming the token Question. A statement that is simply wrong: the parser's
 * error (an OP_QUERY carries the same text behind "parse error: ").
 *
 * Sent as text, a statement is answered Ddl unless it is one of the wrong
 * ones, or puts a string where a name or a keyword belongs: then the
 * parser's error again, which names the string's content only before 0.349.
 */
function declining_server(bool $before349 = false): FakeServer
{
    return (new FakeServer(['handle' => function (array $req) use ($before349) {
        $sql = $req['sql'] ?? '';
        $wrong = [
            'SELEC ' => 'unexpected token "Ident(\"SELEC\")", expected a statement',
            'SELECT FROM' => 'unexpected token "Keyword(From)", expected an expression',
        ];
        foreach ($wrong as $start => $error) {
            if (str_starts_with($sql, $start)) {
                return in_array($req['op'], [1, 2], true) ? F::error(($req['op'] === 1 ? 'parse error: ' : '') . $error) : null;
            }
        }
        if ($req['op'] === 1) {
            $string = fn (string $content) => $before349 ? 'Str(\"' . $content . '\")' : 'a string literal';
            if (preg_match('/^USE \'(.*)\'$/', $sql, $m) === 1) {
                return F::error('parse error: unexpected token "' . $string($m[1]) . '", expected identifier');
            }
            if (preg_match('/^SET CONSISTENCY \'(.*)\'$/', $sql, $m) === 1) {
                return F::error('parse error: SET CONSISTENCY takes ONE, QUORUM, ALL, or DEFAULT — found ' . stripslashes($string($m[1])));
            }
            return F::ddl();
        }
        if ($req['op'] !== 2) {
            return $req['op'] === 3 ? F::mutation(1) : null;
        }
        // The parser stopping at the placeholder.
        $atPlaceholder = fn (string $parser) => F::error(($before349 ? '' : 'statement kind cannot be prepared: ') . $parser);
        if (str_starts_with($sql, 'ALTER TABLE')) {
            return $atPlaceholder('expected a literal value for option ttl, found Question');
        }
        if (str_starts_with($sql, 'USE ?')) {
            return $atPlaceholder('unexpected token "Question", expected identifier');
        }
        if (str_starts_with($sql, 'SET CONSISTENCY ?')) {
            return $atPlaceholder('SET CONSISTENCY takes ONE, QUORUM, ALL, or DEFAULT — found Question');
        }
        if (str_starts_with($sql, 'SET SCAN BUDGET')) {
            return $atPlaceholder('unexpected token "Question", expected SCAN BUDGET ROWS: a positive integer (0 would lift the limit; use DEFAULT to clear)');
        }
        if (str_starts_with($sql, 'CREATE SEQUENCE')) {
            // The prefix is the contract, not the words after it.
            return F::error('statement kind cannot be prepared: a placeholder cannot stand for the START value');
        }
        if (str_starts_with($sql, 'CREATE')) {
            return F::error('statement kind cannot be prepared');
        }
        return F::prepared(1, substr_count($sql, '?'));
    }]))->start();
}

/**
 * What followed each connection's Hello: "prepare <sql>", a text
 * statement's SQL, or "op <n>".
 *
 * @return array<int,string>
 */
function declining_seen(FakeServer $srv): array
{
    $out = [];
    foreach ($srv->requests() as $r) {
        if ($r['op'] === 'connect' || $r['op'] === 8) {
            continue;
        }
        $out[] = match ($r['op']) {
            1 => $r['sql'],
            2 => 'prepare ' . $r['sql'],
            default => "op {$r['op']}",
        };
    }
    return $out;
}

test('prepared: a statement that is simply wrong gets its error once — it is not sent again as text', function () {
    $srv = declining_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    // The prepare's own answer, in the server's words, and nothing after
    // it: the statement cannot be right as text either.
    $wrong = 'SELEC id FROM t WHERE id = ?';
    $e = assert_throws(SkaidbException::class, fn () => $db->prepare($wrong)->execute([1]), '/^unexpected token "Ident\(\\\\"SELEC\\\\"\)", expected a statement$/');
    assert_eq(SkaidbException::class, get_class($e), 'a statement error, not the internal Unpreparable');
    assert_eq(["prepare {$wrong}"], declining_seen($srv));
    assert_true($db->isUsable());
    $e = assert_throws(SkaidbException::class, fn () => $db->prepare($wrong)->executeBatch([[1], [2]]), '/^unexpected token "Ident/');
    assert_eq(SkaidbException::class, get_class($e));
    assert_throws(SkaidbException::class, fn () => $db->prepare('SELECT FROM t WHERE id = ?')->execute([1]), '/^unexpected token "Keyword\(From\)", expected an expression$/');
    assert_eq(["prepare {$wrong}", "prepare {$wrong}", 'prepare SELECT FROM t WHERE id = ?'], declining_seen($srv));
    assert_eq(1, $srv->connections());
    $db->close();
    $srv->stop();
});

test('prepared: the server\'s "statement kind cannot be prepared" is what sends a statement as text, whatever follows it', function () {
    // PROTOCOL.md §6 rule 10: the prefix is the signal. What comes after
    // it is the server's explanation and may be anything.
    $srv = declining_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    assert_true($db->prepare('CREATE SEQUENCE s START ?')->execute([5]));
    assert_true($db->prepare('CREATE INDEX i ON t (v) WHERE v > ?')->execute([1]));
    assert_true($db->prepare('ALTER TABLE t SET (ttl = ?)')->execute([60]));
    assert_eq([
        'prepare CREATE SEQUENCE s START ?', 'CREATE SEQUENCE s START 5',
        'prepare CREATE INDEX i ON t (v) WHERE v > ?', 'CREATE INDEX i ON t (v) WHERE v > 1',
        'prepare ALTER TABLE t SET (ttl = ?)', 'ALTER TABLE t SET (ttl = 60)',
    ], declining_seen($srv));
    $db->close();
    $srv->stop();
    // A server before 0.349 has the prefix only for a statement that
    // parses. Where its parser stops at the placeholder it answers with
    // the parser's error alone, which names the token: Question. That
    // still falls back — it did before the prefix existed — and a parse
    // error that does not name the placeholder still does not.
    $srv = declining_server(true);
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    assert_true($db->prepare('ALTER TABLE t SET (ttl = ?)')->execute([60]));
    assert_true($db->prepare('SET SCAN BUDGET ROWS ?')->execute([9]));
    assert_throws(SkaidbException::class, fn () => $db->prepare('SELEC id FROM t WHERE id = ?')->execute([1]), '/^unexpected token "Ident/');
    assert_eq([
        'prepare ALTER TABLE t SET (ttl = ?)', 'ALTER TABLE t SET (ttl = 60)',
        'prepare SET SCAN BUDGET ROWS ?', 'SET SCAN BUDGET ROWS 9',
        'prepare SELEC id FROM t WHERE id = ?',
    ], declining_seen($srv));
    $db->close();
    $srv->stop();
});

test('prepared: a statement the server will not prepare is sent as text, and the refusal is remembered', function () {
    $srv = declining_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    // Both of the server's ways to say so: the statement's kind takes no
    // bound parameters, or it takes a literal where the placeholder stands.
    $ttl = $db->prepare('ALTER TABLE t SET (ttl = ?)');
    $index = $db->prepare('CREATE INDEX i ON t (v) WHERE v > ?');
    foreach ([60, 120, 180] as $seconds) {
        assert_true($ttl->execute([$seconds]));
        assert_eq('ddl', $ttl->kind());
    }
    assert_true($index->execute([1]));
    assert_true($index->execute([2]));
    assert_eq(0, $ttl->executeBatch([[240], [300]]));
    // One prepare per statement, not one per execute.
    assert_eq([
        'prepare ALTER TABLE t SET (ttl = ?)',
        'ALTER TABLE t SET (ttl = 60)', 'ALTER TABLE t SET (ttl = 120)', 'ALTER TABLE t SET (ttl = 180)',
        'prepare CREATE INDEX i ON t (v) WHERE v > ?',
        'CREATE INDEX i ON t (v) WHERE v > 1', 'CREATE INDEX i ON t (v) WHERE v > 2',
        'ALTER TABLE t SET (ttl = 240)', 'ALTER TABLE t SET (ttl = 300)',
    ], declining_seen($srv));
    // What the memory holds is what THIS connection was told: a new one
    // (another member, another version) is asked again.
    $srv->closeAll();
    assert_true($ttl->execute([360]));
    assert_true($ttl->execute([420]));
    assert_eq(
        ['prepare ALTER TABLE t SET (ttl = ?)', 'ALTER TABLE t SET (ttl = 360)', 'ALTER TABLE t SET (ttl = 420)'],
        array_slice(declining_seen($srv), 9)
    );
    // It is bounded: one statement more than it holds pushes the oldest
    // out, and that one is asked about again.
    $before = count(array_filter(declining_seen($srv), fn ($s) => str_starts_with($s, 'prepare ')));
    for ($i = 0; $i < 240; $i++) {
        $db->prepare("CREATE INDEX i{$i} ON t (v) WHERE v > ?")->execute([$i]);
    }
    $ttl->execute([480]);
    $db->prepare('CREATE INDEX i239 ON t (v) WHERE v > ?')->execute([0]);
    $prepares = count(array_filter(declining_seen($srv), fn ($s) => str_starts_with($s, 'prepare ')));
    assert_eq($before + 241, $prepares, 'the 240 new ones, and the one they pushed out');
    $db->close();
    $srv->stop();
});

test('prepared: a parameter binds a value, never a name — USE ? and SET CONSISTENCY ? are refused in the server\'s words', function () {
    // The server declines to prepare these (the placeholder stands where
    // the grammar wants a name or a keyword), and the text the fallback
    // sends carries a quoted string there, which is no name either. What
    // the application gets is the server's answer to that text: since
    // 0.349 without the string's content, before it with.
    $texts = [
        '0.349' => [
            'parse error: unexpected token "a string literal", expected identifier',
            'parse error: SET CONSISTENCY takes ONE, QUORUM, ALL, or DEFAULT — found a string literal',
            'parse error: unexpected token "a string literal", expected identifier',
        ],
        'before 0.349' => [
            'parse error: unexpected token "Str(\"default\")", expected identifier',
            'parse error: SET CONSISTENCY takes ONE, QUORUM, ALL, or DEFAULT — found Str("ALL")',
            'parse error: unexpected token "Str(\"app\")", expected identifier',
        ],
    ];
    foreach ($texts as $server => [$useDefault, $consistency, $useApp]) {
        $srv = declining_server($server !== '0.349');
        $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
        $use = $db->prepare('USE ?');
        $e = assert_throws(SkaidbException::class, fn () => $use->execute(['default']));
        assert_eq($useDefault, $e->getMessage(), $server);
        assert_eq(SkaidbException::class, get_class($e));
        $e = assert_throws(SkaidbException::class, fn () => $db->prepare('SET CONSISTENCY ?')->execute(['ALL']));
        assert_eq($consistency, $e->getMessage(), $server);
        // Nothing moved: the session is where it was, on the same connection.
        assert_eq(null, $db->sessionDatabase());
        assert_true($db->isUsable());
        $e = assert_throws(SkaidbException::class, fn () => $use->execute(['app']));
        assert_eq($useApp, $e->getMessage(), $server);
        assert_eq([
            'prepare USE ?', "USE 'default'",
            'prepare SET CONSISTENCY ?', "SET CONSISTENCY 'ALL'",
            "USE 'app'",
        ], declining_seen($srv), $server);
        // Where the statement takes a literal the same fallback works.
        assert_true($db->prepare('SET SCAN BUDGET ROWS ?')->execute([1000]));
        assert_eq(['prepare SET SCAN BUDGET ROWS ?', 'SET SCAN BUDGET ROWS 1000'], array_slice(declining_seen($srv), 5), $server);
        assert_eq(1, $srv->connections());
        $db->close();
        $srv->stop();
    }
});

test('prepared: a server from before prepared statements gets every parameterised statement as text', function () {
    $srv = (new FakeServer(['handle' => function (array $req) {
        if ($req['op'] === 1) {
            return F::mutation(1);
        }
        return $req['op'] === 8 ? null : F::error('protocol error: malformed message: unknown opcode');
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $st = $db->prepare('INSERT INTO t (id, name) VALUES (?, ?)');
    assert_true($st->execute([1, "O'Brien"]));
    assert_true($st->execute([2, null]));
    assert_true($db->prepare('DELETE FROM t WHERE id = ?')->execute([1]));
    assert_eq(2, $st->executeBatch([[3, 'a'], [4, 'b']]));
    // Each statement is asked about once, like any the server declines.
    assert_eq([
        'prepare INSERT INTO t (id, name) VALUES (?, ?)',
        "INSERT INTO t (id, name) VALUES (1, 'O''Brien')",
        'INSERT INTO t (id, name) VALUES (2, NULL)',
        'prepare DELETE FROM t WHERE id = ?',
        'DELETE FROM t WHERE id = 1',
        "INSERT INTO t (id, name) VALUES (3, 'a')",
        "INSERT INTO t (id, name) VALUES (4, 'b')",
    ], declining_seen($srv));
    $srv->closeAll();
    assert_true($st->execute([5, 'c']));
    assert_eq(['prepare INSERT INTO t (id, name) VALUES (?, ?)', "INSERT INTO t (id, name) VALUES (5, 'c')"], array_slice(declining_seen($srv), 7));
    $db->close();
    $srv->stop();
});

test('prepared: one statement\'s error never switches the connection to text, whatever the error says', function () {
    // A syntax error that happens to quote the words an old server answers
    // the prepare opcode with. It is that statement's error — sent as text
    // too, it fails there — and every other statement still travels typed.
    $srv = (new FakeServer(['handle' => function (array $req) {
        $odd = str_contains($req['sql'] ?? '', '"unknown opcode"');
        switch ($req['op']) {
            case 1:
                return $odd ? F::error('parse error: unexpected token "QuotedIdent(\"unknown opcode\")", expected end of input') : F::mutation(1);
            case 2:
                return $odd
                    ? F::error('unexpected token "QuotedIdent(\"unknown opcode\")", expected end of input')
                    : F::prepared(1, substr_count($req['sql'], '?'));
            case 3:
                return F::rows(['params'], [[$req['params']]]);
        }
        return null;
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    assert_throws(SkaidbException::class, fn () => $db->prepare('SELECT 1 "unknown opcode" WHERE id = ?')->execute([1]), '/unknown opcode/');
    $ts = new \DateTimeImmutable('2024-02-29T23:59:59.123Z');
    $st = $db->prepare('INSERT INTO t (id, tags, ts) VALUES (?, ?, ?)');
    $st->execute([1, ['a', 'b'], $ts]);
    $sent = $st->fetchColumn();
    assert_eq([1, ['a', 'b']], array_slice($sent, 0, 2), 'the list travelled typed');
    assert_true($sent[2] instanceof \DateTimeImmutable, 'the timestamp arrived as ' . gettype($sent[2]));
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

/**
 * A server with a real statement table (PROTOCOL.md §3.3): per connection,
 * at most $max prepared statements; an id is the lowest free slot, so every
 * connection starts at 0 and a closed id is issued again; OP_CLOSE frees
 * one. OP_EXECUTE answers one row [ran, params]: the SQL that id names on
 * this connection, and the values as they arrived. OP_EXECUTE_BATCH answers
 * Mutation(rows) when that SQL is an UPDATE and Mutation(1000 + rows) for
 * anything else. A text statement answers [ran: 'as text', params: [sql]].
 */
function table_server(int $max = 256): FakeServer
{
    return (new FakeServer(['handle' => function (array $req) use ($max) {
        static $tables = [];
        $tables[$req['conn']] ??= [];
        $table = &$tables[$req['conn']];
        $unknown = fn () => F::error("unknown prepared statement id {$req['id']}");
        switch ($req['op']) {
            case 1:
                return F::rows(['ran', 'params'], [['as text', [$req['sql']]]]);
            case 2:
                if (count($table) >= $max) {
                    return F::error("too many prepared statements (max {$max}); close some first");
                }
                for ($id = 0; isset($table[$id]); $id++) {
                }
                $table[$id] = $req['sql'];
                return F::prepared($id, substr_count($req['sql'], '?'));
            case 3:
                return isset($table[$req['id']])
                    ? F::rows(['ran', 'params'], [[$table[$req['id']], $req['params']]])
                    : $unknown();
            case 4:
                if (!isset($table[$req['id']])) {
                    return $unknown();
                }
                unset($table[$req['id']]);
                return F::ddl();
            case 7:
                return isset($table[$req['id']])
                    ? F::mutation((str_starts_with($table[$req['id']], 'UPDATE') ? 0 : 1000) + count($req['rows']))
                    : $unknown();
        }
        return null;
    }]))->start();
}

/**
 * The most prepared statements a server held open at once, replayed from
 * the requests it logged (every OP_PREPARE taken, every OP_CLOSE honoured).
 */
function table_high_water(FakeServer $srv): int
{
    $open = 0;
    $max = 0;
    foreach ($srv->requests() as $r) {
        if ($r['op'] === 'connect') {
            $open = 0;
        } elseif ($r['op'] === 2) {
            $max = max($max, ++$open);
        } elseif ($r['op'] === 4) {
            $open--;
        }
    }
    return $max;
}

test('prepared: 300 distinct statements on one connection all travel typed — the server\'s table never fills', function () {
    $srv = table_server(256);
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $ts = new \DateTimeImmutable('2024-02-29T23:59:59.123Z');
    // A statement the application holds from before the others.
    $held = $db->prepare('SELECT ? AS held, ? AS list');
    $held->execute([0, ['first']]);
    assert_eq('SELECT ? AS held, ? AS list', $held->fetchColumn());
    for ($i = 0; $i < 300; $i++) {
        // A list has no literal form at all; a timestamp's is a number.
        $sql = "INSERT INTO t (id, a{$i}, tags, ts) VALUES (?, 1, ?, ?)";
        $st = $db->prepare($sql);
        $st->execute([$i, ['a', $i], $ts]);
        $row = $st->fetch();
        assert_eq($sql, $row['ran'], "statement {$i} ran as another, or as text");
        assert_eq([$i, ['a', $i]], array_slice($row['params'], 0, 2), "statement {$i}");
        assert_true($row['params'][2] instanceof \DateTimeImmutable, "statement {$i}: the timestamp arrived as " . gettype($row['params'][2]));
    }
    // The held statement was pushed out of the cache and closed on the
    // server meanwhile; its id may name another statement there by now.
    // It runs as itself again, prepared anew.
    $held->execute([1, ['again']]);
    assert_eq(['ran' => 'SELECT ? AS held, ? AS list', 'params' => [1, ['again']]], $held->fetch());
    // One it used a moment ago is still prepared: no second OP_PREPARE.
    $held->execute([2, []]);
    $requests = $srv->requests();
    $count = fn (int $op) => count(array_filter($requests, fn ($r) => $r['op'] === $op));
    assert_eq(0, $count(1), 'nothing went as text');
    assert_eq(303, $count(3));
    assert_eq(302, $count(2), 'the held statement was prepared twice, every other once');
    assert_true(table_high_water($srv) < 256, 'the server held ' . table_high_water($srv) . ' statements at once');
    assert_eq(1, $srv->connections());
    $db->close();
    $srv->stop();
});

test('prepared: a batch on a full cache is prepared, sent typed and leaves the table bounded', function () {
    $srv = table_server(256);
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    for ($i = 0; $i < 300; $i++) {
        $n = $db->prepare("UPDATE t SET a{$i} = ?, tags = ? WHERE id = ?")->executeBatch([[1, ['x'], 1], [2, [], 2]]);
        assert_eq(2, $n, "batch {$i}");
    }
    $requests = $srv->requests();
    assert_eq(0, count(array_filter($requests, fn ($r) => $r['op'] === 1)), 'no batch fell back to text rows');
    assert_eq(300, count(array_filter($requests, fn ($r) => $r['op'] === 7)));
    assert_true(table_high_water($srv) < 256, 'the server held ' . table_high_water($srv) . ' statements at once');
    $db->close();
    $srv->stop();
});

test('prepared: a prepare refused because the server\'s table is full is the statement\'s error, not a text fallback', function () {
    // A table already full, whoever filled it: the statement could be
    // prepared, so sending it as text would only change how its values are
    // typed (a timestamp as a number, bytes as a hex string).
    $srv = table_server(0);
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $full = '/^too many prepared statements \(max 0\); close some first$/';
    $st = $db->prepare('INSERT INTO t (id, ts) VALUES (?, ?)');
    $e = assert_throws(SkaidbException::class, fn () => $st->execute([1, new \DateTimeImmutable('@0')]), $full);
    assert_eq(SkaidbException::class, get_class($e));
    $e = assert_throws(SkaidbException::class, fn () => $st->executeBatch([[1, 'a'], [2, 'b']]), $full);
    assert_eq(SkaidbException::class, get_class($e));
    assert_true($db->isUsable(), 'a statement error: the connection stays usable');
    assert_eq([2, 2], array_values(array_map(fn ($r) => $r['op'], array_filter($srv->requests(), fn ($r) => is_int($r['op']) && $r['op'] !== 8))), 'two refused prepares, and nothing as text');
    $db->close();
    $srv->stop();
});

test('prepared: after a re-dial a held statement is prepared again — it never runs under an id of the lost connection', function () {
    $a = 'UPDATE t SET v = ? WHERE id = ?';
    $b = 'DELETE FROM t WHERE v = ? AND id = ?';   // the same parameter count, another effect
    $srv = table_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $stA = $db->prepare($a);
    $stB = $db->prepare($b);
    $stA->execute(['x', 1]);   // id 0 on the first connection
    $stB->execute(['y', 2]);   // id 1
    assert_eq([$a, $b], [$stA->fetchColumn(), $stB->fetchColumn()]);
    // The connection is lost while idle. B runs first on the new one, where
    // it takes id 0 — the id A had on the lost connection.
    $srv->closeAll();
    $stB->execute(['y', 3]);
    assert_eq(['ran' => $b, 'params' => ['y', 3]], $stB->fetch());
    $stA->execute(['x', 4]);
    assert_eq(['ran' => $a, 'params' => ['x', 4]], $stA->fetch(), 'A ran under an id issued by the lost connection');
    // A batch through the held statement likewise: 2 as the UPDATE it is,
    // 1002 had it run as the DELETE.
    $srv->closeAll();
    assert_eq(1001, $stB->executeBatch([['y', 5]]));
    assert_eq(2, $stA->executeBatch([['x', 6], ['x', 7]]), 'the batch ran under an id issued by the lost connection');
    // Per connection: prepare before execute, and never an id the server
    // did not issue on that connection.
    $ops = [];
    foreach ($srv->requests() as $r) {
        if ($r['op'] === 'connect') {
            $ops[] = 'connect';
        } elseif ($r['op'] === 2) {
            $ops[] = 'prepare ' . ($r['sql'] === $a ? 'A' : 'B');
        } elseif ($r['op'] === 3 || $r['op'] === 7) {
            $ops[] = ($r['op'] === 3 ? 'execute ' : 'batch ') . $r['id'];
        }
    }
    assert_eq([
        'connect', 'prepare A', 'execute 0', 'prepare B', 'execute 1',
        'connect', 'prepare B', 'execute 0', 'prepare A', 'execute 1',
        'connect', 'prepare B', 'batch 0', 'prepare A', 'batch 1',
    ], $ops);
    $db->close();
    $srv->stop();
});

test('prepared: a statement belongs to its connection — on a pool it runs there, under that connection\'s ids', function () {
    $a = 'UPDATE t SET v = ? WHERE id = ?';
    $b = 'DELETE FROM t WHERE v = ? AND id = ?';
    $srv = table_server();
    $pool = new Pool(['127.0.0.1', $srv->port, 'ada', 'secret'], 4);
    $one = $pool->acquire();
    $two = $pool->acquire();
    assert_true($one !== $two);
    $stA = $one->prepare($a);
    $stA->execute(['x', 1]);                    // id 0 on connection one
    $two->prepare($b)->execute(['y', 2]);       // id 0 on connection two
    $pool->release($one);
    $pool->release($two);
    // A Statement cannot be moved to another connection: there is no call
    // that takes one. Kept after its connection went back to the pool, it
    // still runs on that connection, as itself.
    $stA->execute(['x', 3]);
    assert_eq(['ran' => $a, 'params' => ['x', 3]], $stA->fetch());
    // The same SQL prepared through the other connection gets that
    // connection's own id.
    $other = $two->prepare($a);
    $other->execute(['x', 4]);
    assert_eq(['ran' => $a, 'params' => ['x', 4]], $other->fetch());
    // Once the pool has closed the connection, its statements are closed
    // with it.
    $pool->close();
    assert_throws(SkaidbException::class, fn () => $stA->execute(['x', 5]), '/^connection is closed$/');
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

test('batch: a statement the server declines to prepare runs once per row, quoted client-side', function () {
    // Nothing prepares here, as on a server answering a DDL or session
    // statement — or every statement, before prepared statements existed.
    $srv = (new FakeServer(['handle' => function (array $req) {
        if ($req['op'] === 2) {
            return F::error('statement kind cannot be prepared');
        }
        if ($req['op'] !== 1) {
            return null;
        }
        if (str_contains($req['sql'], "'refused'")) {
            return F::error('constraint violation: refused');
        }
        return str_starts_with($req['sql'], 'INSERT') ? F::mutation(1) : F::ddl();
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    /** What followed the Hello: a statement's text, or its opcode. */
    $sent = fn () => array_values(array_map(
        fn ($r) => $r['op'] === 1 ? $r['sql'] : "op {$r['op']}",
        array_filter($srv->requests(), fn ($r) => !in_array($r['op'], ['connect', 8], true))
    ));

    // The total affected count, as a batch returns it; one statement per
    // row, in order, with the values execute() would have quoted.
    $st = $db->prepare('INSERT INTO t (id, name) VALUES (?, ?)');
    assert_eq(3, $st->executeBatch([[1, "O'Brien"], ['id' => 2, 'name' => null], [3, 2.5]]));
    assert_eq([
        'op 2',
        "INSERT INTO t (id, name) VALUES (1, 'O''Brien')",
        'INSERT INTO t (id, name) VALUES (2, NULL)',
        'INSERT INTO t (id, name) VALUES (3, 2.5)',
    ], $sent());
    // A statement with no affected count adds nothing, as in a server batch.
    assert_eq(0, $db->prepare('ALTER TABLE t SET (ttl = ?)')->executeBatch([[60], [120]]));
    assert_eq(['op 2', 'ALTER TABLE t SET (ttl = 60)', 'ALTER TABLE t SET (ttl = 120)'], array_slice($sent(), 4));

    // The first failing row ends the batch: the error names it and the rows
    // applied before it, and the rows after it are not sent.
    $e = assert_throws(
        SkaidbException::class,
        fn () => $st->executeBatch([[4, 'ok'], [5, 'refused'], [6, 'never']]),
        '/^batch row 1: constraint violation: refused \(1 rows applied before it\)$/'
    );
    assert_eq(SkaidbException::class, get_class($e), 'never the internal Unpreparable');
    assert_eq(
        ["INSERT INTO t (id, name) VALUES (4, 'ok')", "INSERT INTO t (id, name) VALUES (5, 'refused')"],
        array_slice($sent(), 7),
        'the statement was declined once already: no second prepare'
    );
    assert_true($db->isUsable());

    // Every row is bound before the first is sent: a row with the wrong
    // parameter count, or a value with no SQL literal form, applies nothing.
    $declined = 'via client-side binding; the server declined to prepare the statement: statement kind cannot be prepared$/';
    $e = assert_throws(
        SkaidbException::class,
        fn () => $st->executeBatch([[7, 'a'], [8]]),
        '/^batch row 1: more placeholders than parameters ' . $declined
    );
    assert_eq(SkaidbException::class, get_class($e));
    assert_throws(
        SkaidbException::class,
        fn () => $st->executeBatch([[9, 'a'], [10, 'b', 'c']]),
        '/^batch row 1: more parameters than placeholders ' . $declined
    );
    assert_throws(
        SkaidbException::class,
        fn () => $st->executeBatch([[11, ['a', 'list']]]),
        '/^batch row 0: cannot bind value of type array ' . $declined
    );
    assert_eq([], array_slice($sent(), 9), 'no row was sent');
    assert_eq(0, $st->executeBatch([]));
    assert_eq(1, $srv->connections());
    $db->close();
    $srv->stop();
});

test('batch: a connection lost under a row is a ConnectionException that names the row', function () {
    // A batch the server will not prepare runs row by row; the second
    // row's statement is where the connection goes.
    $srv = (new FakeServer(['handle' => function (array $req) {
        if ($req['op'] === 2) {
            return F::error('statement kind cannot be prepared');
        }
        if ($req['op'] !== 1) {
            return null;
        }
        return str_contains($req['sql'], '= 2') ? FakeServer::DROP : F::ddl();
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $e = assert_throws(
        ConnectionException::class,
        fn () => $db->prepare('CREATE INDEX i ON t (v) WHERE v = ?')->executeBatch([[1], [2], [3]]),
        '/^batch row 1: connection closed by server \(1 rows applied before it\)$/'
    );
    assert_true($e->getPrevious() instanceof ConnectionException);
    assert_true(!$db->isUsable());
    $db->close();
    $srv->stop();
});

test('batch: a server from before OP_EXECUTE_BATCH runs the batch as one typed OP_EXECUTE per row', function () {
    // Servers 0.17 to 0.86 prepare and execute, and answer the batch opcode
    // as they answer any they do not know (PROTOCOL.md §3.4).
    $srv = (new FakeServer(['handle' => function (array $req) {
        switch ($req['op']) {
            case 2:
                return F::prepared(3, substr_count($req['sql'], '?'));
            case 3:
                return $req['params'][1] === 'refused'
                    ? F::error('constraint violation: refused')
                    : ($req['params'][1] === 'gone' ? FakeServer::DROP : F::mutation(1));
            case 7:
                return F::error('protocol error: malformed message: unknown opcode');
        }
        return null;
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $ops = fn () => array_values(array_map(
        fn ($r) => $r['op'] === 3 ? 'execute ' . json_encode($r['params']) : "op {$r['op']}",
        array_filter($srv->requests(), fn ($r) => !in_array($r['op'], ['connect', 8], true))
    ));
    $st = $db->prepare('INSERT INTO t (id, tags) VALUES (?, ?)');
    // What a batch promises: the total affected count, the rows in order,
    // each with its values typed — a list has no other way to travel.
    assert_eq(3, $st->executeBatch([[1, ['a']], [2, []], ['id' => 3, 'tags' => ['b', 'c']]]));
    assert_eq(['op 2', 'op 7', 'execute [1,["a"]]', 'execute [2,[]]', 'execute [3,["b","c"]]'], $ops());
    // The connection knows now: the opcode is not tried again.
    assert_eq(2, $st->executeBatch([[4, 'x'], [5, 'y']]));
    assert_eq(['execute [4,"x"]', 'execute [5,"y"]'], array_slice($ops(), 5));
    // The first failing row ends the batch, named with the rows before it.
    $e = assert_throws(
        SkaidbException::class,
        fn () => $st->executeBatch([[6, 'ok'], [7, 'refused'], [8, 'never']]),
        '/^batch row 1: constraint violation: refused \(1 rows applied before it\)$/'
    );
    assert_eq(SkaidbException::class, get_class($e));
    assert_eq(['execute [6,"ok"]', 'execute [7,"refused"]'], array_slice($ops(), 7));
    // A wrong parameter count stops the batch before its first row.
    assert_throws(SkaidbException::class, fn () => $st->executeBatch([[9, 'a'], [10]]), '/^batch row expects 2 parameters, got 1$/');
    assert_eq([], array_slice($ops(), 9));
    assert_true($db->isUsable());
    // A connection lost under a row: the transport error, naming the row.
    assert_throws(
        ConnectionException::class,
        fn () => $st->executeBatch([[11, 'ok'], [12, 'gone']]),
        '/^batch row 1: connection closed by server \(1 rows applied before it\)$/'
    );
    // The new connection is asked again: it may be a newer member.
    assert_eq(1, $st->executeBatch([[13, 'z']]));
    assert_eq(['execute [11,"ok"]', 'execute [12,"gone"]', 'op 2', 'op 7', 'execute [13,"z"]'], array_slice($ops(), 9));
    $db->close();
    $srv->stop();

    // A server that has the opcode answers a failing row itself, and that
    // is never taken for a missing opcode, whatever the row's error says:
    // the rows before it are applied and must not be sent again.
    $srv = (new FakeServer(['handle' => function (array $req) {
        return match ($req['op']) {
            2 => F::prepared(3, 2),
            7 => F::error('batch row 1: no such function: unknown opcode (1 rows applied before it)'),
            3 => F::mutation(1),
            default => null,
        };
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    assert_throws(
        SkaidbException::class,
        fn () => $db->prepare('INSERT INTO t (id, v) VALUES (?, ?)')->executeBatch([[1, 'a'], [2, 'b']]),
        '/^batch row 1: no such function: unknown opcode \(1 rows applied before it\)$/'
    );
    assert_eq([2, 7], array_values(array_filter(array_map(fn ($r) => $r['op'], $srv->requests()), fn ($op) => is_int($op) && $op !== 8)));
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

/**
 * A server for the session's state: USE … answers Ddl (an Error when it
 * names "gone"), SET …, BEGIN …, COMMIT and ROLLBACK answer Ddl (a SET
 * naming BOGUS a parse error), a stream one row, SELECT one row, anything
 * else a one-row mutation. A prepare is taken — of a SET it is declined, as
 * the real server declines one with a placeholder — an execute answers a
 * one-row mutation and a batch one row per row. $tls: `ssl` context
 * options, to listen with TLS.
 *
 * @param array<string,mixed>|null $tls
 */
function session_server(?array $tls = null): FakeServer
{
    return (new FakeServer(['tls' => $tls, 'handle' => function (array $req) {
        switch ($req['op']) {
            case 2:
                return preg_match('/^\s*set\b/i', $req['sql']) === 1
                    ? F::error('unexpected token "Question", expected a positive integer')
                    : F::prepared(1, substr_count($req['sql'], '?'));
            case 3:
                return F::mutation(1);
            case 7:
                return F::mutation(count($req['rows']));
            case 1:
            case 5:
            case 9:
                break;
            default:
                return null;
        }
        if (preg_match('/^\s*use\b/i', $req['sql']) === 1) {
            return str_contains($req['sql'], 'gone') ? F::error('database "gone" does not exist') : F::ddl();
        }
        if (preg_match('/^(\s|--[^\n]*)*(set|begin|commit|rollback)\b/i', $req['sql']) === 1) {
            return str_contains($req['sql'], 'BOGUS') ? F::error('parse error: SET CONSISTENCY takes ONE, QUORUM, ALL, or DEFAULT') : F::ddl();
        }
        if ($req['op'] !== 1) {
            return [F::header(['n']), F::chunk([[3]]), F::end()];
        }
        return str_starts_with($req['sql'], 'SELECT') ? F::rows(['n'], [[3]]) : F::mutation(1);
    }]))->start();
}

/**
 * What a server saw, connection by connection: 'connect', then each
 * statement ("stream <sql>" for a streamed one, "prepare <sql>", "execute
 * <params>", "batch <rows>"). The Hello is left out.
 *
 * @return array<int,string>
 */
function session_seen(FakeServer $srv): array
{
    $out = [];
    foreach ($srv->requests() as $r) {
        switch ($r['op']) {
            case 'connect':
                $out[] = 'connect';
                break;
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
 * Every way a statement is sent, each returning what the session server
 * answers it: [what it is, the call, what the server then saw].
 *
 * @return array<string,array{0:callable(Connection):mixed,1:mixed,2:array<int,string>}>
 */
function idle_statements(): array
{
    return [
        'a read' => [fn (Connection $c) => $c->query('SELECT count(*) FROM notes')->fetchColumn(), 3, ['SELECT count(*) FROM notes']],
        'a write' => [fn (Connection $c) => $c->exec('INSERT INTO notes (id) VALUES (1)'), 1, ['INSERT INTO notes (id) VALUES (1)']],
        'a prepared write' => [function (Connection $c) {
            $st = $c->prepare('INSERT INTO notes (id) VALUES (?)');
            $st->execute([2]);
            return $st->rowCount();
        }, 1, ['prepare INSERT INTO notes (id) VALUES (?)', 'execute [2]']],
        'a batch' => [
            fn (Connection $c) => $c->prepare('INSERT INTO notes (id) VALUES (?)')->executeBatch([[3], [4]]),
            2,
            ['prepare INSERT INTO notes (id) VALUES (?)', 'batch [[3],[4]]'],
        ],
        'a stream' => [fn (Connection $c) => iterator_to_array($c->stream('SELECT n FROM notes')), [['n' => 3]], ['stream SELECT n FROM notes']],
        'a stream with parameters' => [
            fn (Connection $c) => iterator_to_array($c->stream('SELECT n FROM notes WHERE id > ?', [0])),
            [['n' => 3]],
            ['stream SELECT n FROM notes WHERE id > ?'],
        ],
    ];
}

test('idle: a connection the server closed while it sat idle costs no statement — nothing is written to it', function () {
    // Over a real network a write to a connection the peer has closed
    // succeeds, and only the read finds out. The half-closed fake server
    // is that peer: it has sent its end-of-file and still takes whatever
    // the client writes.
    foreach (idle_statements() as $name => [$run, $answer, $seen]) {
        $srv = session_server();
        $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret', 'QUORUM', 5.0, 'app');
        assert_eq(3, $db->query('SELECT 1')->fetchColumn());
        $srv->closeAll(FakeServer::HALF);
        usleep(100_000);
        // No error reaches the application, for a read and a write alike:
        // the statement was not sent on the dead connection, so it runs —
        // once — on a new one, in the session's database.
        assert_eq($answer, $run($db), $name);
        assert_eq(['connect', 'USE "app"', 'SELECT 1', 'connect', 'USE "app"', ...$seen], session_seen($srv), $name);
        usleep(50_000);
        assert_eq(0, $srv->strayBytes(), "{$name}: bytes written to a connection the server had closed");
        assert_true($db->isUsable());
        $db->close();
        $srv->stop();
    }
});

test('idle: a clean close and a reset are found the same way', function () {
    foreach ([FakeServer::CLOSE, FakeServer::RESET] as $how) {
        $srv = session_server();
        $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret', 'QUORUM', 5.0);
        $db->exec('USE app');
        foreach (idle_statements() as $name => [$run, $answer, $seen]) {
            $from = count(session_seen($srv));
            $srv->closeAll($how);
            assert_eq($answer, $run($db), "{$how}: {$name}");
            assert_eq(['connect', 'USE "app"', ...$seen], array_slice(session_seen($srv), $from), "{$how}: {$name}");
        }
        $db->close();
        $srv->stop();
    }
});

test('idle: a live connection is not taken for a dead one, and isUsable() tells the two apart', function () {
    $srv = session_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    foreach (idle_statements() as $name => [$run, $answer]) {
        usleep(20_000);
        assert_true($db->isUsable(), $name);
        assert_eq($answer, $run($db), $name);
    }
    assert_eq(1, $srv->connections(), 'the look at the socket replaced a healthy connection');
    // The same look answers isUsable(): no round trip, and no statement
    // needed to find out.
    $srv->closeAll(FakeServer::HALF);
    assert_true(!$db->isUsable());
    assert_true(
        !is_resource((new \ReflectionProperty(Connection::class, 'sock'))->getValue($db)),
        'found closed: the connection is broken, and lets go of its socket at once'
    );
    assert_eq(3, $db->query('SELECT 1')->fetchColumn());
    assert_true($db->isUsable());
    assert_eq([2, 0], [$srv->connections(), $srv->strayBytes()]);
    // A connection in the middle of a stream is not idle: what is readable
    // on it is the stream, and it is not looked at.
    $srv2 = scripted_server();
    $db2 = new Connection('127.0.0.1', $srv2->port, 'ada', 'secret');
    $n = 0;
    foreach ($db2->stream('SELECT big') as $row) {
        if (++$n % 500 === 0) {
            usleep(5_000);
            assert_true(!$db2->isUsable(), 'busy, as during any stream');
        }
    }
    assert_eq(5000, $n);
    assert_true($db2->isUsable());
    assert_eq(1, $srv2->connections());
    $db2->close();
    $srv2->stop();
    $db->close();
    $srv->stop();
});

test('idle: bytes nobody asked for mean the connection is not at a request boundary — it is replaced', function () {
    // A reply followed by a frame no request is waiting for. Left on the
    // connection it would answer the next statement, and every reply after
    // it would come one statement late.
    $srv = (new FakeServer(['handle' => function (array $req) {
        if ($req['op'] !== 1) {
            return null;
        }
        $reply = F::rows(['answer_to'], [[$req['sql']]]);
        return $req['sql'] === 'SELECT twice' ? [$reply, F::rows(['answer_to'], [['a leftover frame']])] : $reply;
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    assert_eq('SELECT twice', $db->query('SELECT twice')->fetchColumn());
    usleep(50_000);
    assert_eq('SELECT after', $db->query('SELECT after')->fetchColumn(), 'the statement was answered with a leftover frame');
    assert_eq('SELECT again', $db->query('SELECT again')->fetchColumn());
    assert_eq(2, $srv->connections());
    $db->close();
    $srv->stop();
});

test('idle: inside transaction() a connection found dead is a lost transaction, not a re-dial', function () {
    $srv = session_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $lost = '/^connection lost inside a transaction: the statement was not sent/';
    $e = assert_throws(ConnectionException::class, fn () => $db->transaction(function (Connection $c) use ($srv, $lost) {
        $c->exec('INSERT INTO notes (id) VALUES (1)');
        $srv->closeAll(FakeServer::HALF);
        // Found by the look, before anything is written: not sent here,
        // and not on a new connection, where it would autocommit.
        assert_throws(ConnectionException::class, fn () => $c->exec('INSERT INTO notes (id) VALUES (2)'), $lost);
        assert_throws(ConnectionException::class, fn () => $c->prepare('INSERT INTO notes (id) VALUES (?)')->execute([3]), $lost);
        return 'all is well';
    }), '/^connection lost inside a transaction: the COMMIT was not sent, and nothing was committed/');
    usleep(50_000);
    assert_eq(['connect', 'BEGIN', 'INSERT INTO notes (id) VALUES (1)'], session_seen($srv));
    assert_eq(0, $srv->strayBytes());
    // Afterwards it re-dials, like any lost connection.
    assert_eq(1, $db->exec('INSERT INTO notes (id) VALUES (4)'));
    assert_eq(['connect', 'INSERT INTO notes (id) VALUES (4)'], array_slice(session_seen($srv), 3));
    $db->close();
    $srv->stop();
});

test('pool: a connection the server closed while it sat idle in the pool is not handed out', function () {
    foreach ([FakeServer::HALF, FakeServer::CLOSE, FakeServer::RESET] as $how) {
        $srv = session_server();
        $pool = new Pool(['127.0.0.1', $srv->port, 'ada', 'secret', 'database' => 'app'], 4);
        $one = $pool->acquire();
        $two = $pool->acquire();
        $pool->release($one);
        $pool->release($two);
        assert_eq(2, $pool->members()[0]['idle']);
        $srv->closeAll($how);
        // The checkout looks before it hands a connection out: both idle
        // ones are dropped, and the borrower gets one that works.
        $got = $pool->acquire();
        assert_true($got !== $one && $got !== $two, "{$how}: a dead connection was handed out");
        assert_eq(3, $got->query('SELECT count(*) FROM notes')->fetchColumn(), $how);
        $pool->release($got);
        $m = $pool->members()[0];
        assert_eq(['ready', 0, 1], [$m['state'], $m['in_use'], $m['idle']], "{$how}: an idle close is not the member failing");
        assert_eq(1, $pool->withConnection(fn (Connection $c) => $c->exec('INSERT INTO notes (id) VALUES (1)')), $how);
        assert_eq(
            ['connect', 'USE "app"', 'SELECT count(*) FROM notes', 'INSERT INTO notes (id) VALUES (1)'],
            array_slice(session_seen($srv), 4),
            $how
        );
        usleep(50_000);
        assert_eq(0, $srv->strayBytes(), $how);
        // Closed under a borrower who holds it: its next statement re-dials
        // inside the checkout, in the pool's database.
        $pool->withConnection(function (Connection $c) use ($srv, $how) {
            $srv->closeAll($how);
            assert_eq(3, $c->query('SELECT count(*) FROM notes')->fetchColumn(), $how);
        });
        assert_eq(['connect', 'USE "app"', 'SELECT count(*) FROM notes'], array_slice(session_seen($srv), 8), $how);
        $pool->close();
        $srv->stop();
    }
});

test('reconnect: the session database is entered again on the new connection, however it was entered', function () {
    $count = 'SELECT count(*) FROM notes';
    $insert = 'INSERT INTO notes (id) VALUES (1)';
    $open = fn (FakeServer $srv, ?string $database = null) => new Connection('127.0.0.1', $srv->port, 'ada', 'secret', 'QUORUM', 5.0, $database);
    // Each way: what the first connection is sent, then what the new one
    // must be sent before anything else.
    $ways = [
        'the database option' => [fn ($srv) => $open($srv, 'app'), ['USE "app"'], 'USE "app"'],
        'USE through exec()' => [function ($srv) use ($open) {
            $db = $open($srv);
            $db->exec('USE app');
            return $db;
        }, ['USE app'], 'USE "app"'],
        'USE DATABASE, quoted, with a semicolon, through query()' => [function ($srv) use ($open) {
            $db = $open($srv);
            $db->query('  use database "app" ; ');
            return $db;
        }, ['  use database "app" ; '], 'USE "app"'],
        'USE through prepare()->execute()' => [function ($srv) use ($open) {
            $db = $open($srv);
            $db->prepare('USE app')->execute();
            return $db;
        }, ['USE app'], 'USE "app"'],
        'USE through stream()' => [function ($srv) use ($open) {
            $db = $open($srv);
            iterator_to_array($db->stream('USE app'));
            return $db;
        }, ['stream USE app'], 'USE "app"'],
        'a name that needs its quotes' => [function ($srv) use ($open) {
            $db = $open($srv);
            $db->exec('USE "app-2"');
            return $db;
        }, ['USE "app-2"'], 'USE "app-2"'],
        'the option, then a USE elsewhere: the last one counts' => [function ($srv) use ($open) {
            $db = $open($srv, 'other');
            $db->exec('USE app');
            return $db;
        }, ['USE "other"', 'USE app'], 'USE "app"'],
        'a USE the server refused does not count' => [function ($srv) use ($open) {
            $db = $open($srv);
            $db->exec('USE app');
            assert_throws(SkaidbException::class, fn () => $db->exec('USE gone'), '/does not exist/');
            return $db;
        }, ['USE app', 'USE gone'], 'USE "app"'],
    ];
    foreach ($ways as $name => [$enter, $first, $reentered]) {
        $srv = session_server();
        $db = $enter($srv);
        // The connection is lost while idle; the next statement finds out
        // before it is sent, and runs on a new connection.
        $srv->closeAll();
        assert_eq(3, $db->query($count)->fetchColumn(), $name);
        assert_eq(1, $db->exec($insert), $name);
        assert_eq(['connect', ...$first, 'connect', $reentered, $count, $insert], session_seen($srv), $name);
        $db->close();
        $srv->stop();
    }
});

test('reconnect: only a statement that is exactly one USE moves the session database', function () {
    $used = fn (string $sql) => Connection::usedDatabase($sql);
    // USE [DATABASE] <name>, the name bare or in double quotes, an optional ';'.
    assert_eq('app', $used('USE app'));
    assert_eq('app', $used('use app;'));
    assert_eq('App_2', $used("\n\tUse   App_2  ;  \n"), 'the name as written: the server does not fold case');
    assert_eq('app', $used('USE DATABASE app'));
    assert_eq('app', $used('use database "app";'));
    assert_eq('app-2', $used('USE "app-2"'));
    assert_eq('a "quoted" name', $used('USE "a ""quoted"" name"'));
    assert_eq('database', $used('USE database'), 'a database named "database", as the server reads it');
    assert_eq('database', $used('USE DATABASE database'));
    // Anything else is left alone.
    foreach ([
        'SELECT 1', 'USE', 'USE ;', 'USE ?', "USE 'app'", 'USE app other', 'USE app; USE other',
        'USE app.notes', 'USE "app', 'USE app"', 'USE ""', 'USEapp', 'EXPLAIN USE app', 'USE app-2', 'CREATE TABLE use (PRIMARY KEY (id))',
    ] as $sql) {
        assert_eq(null, $used($sql), $sql);
    }
    // What is left alone stays where it was: the database before it is the
    // one a re-dial enters.
    $srv = session_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $db->exec('USE app');
    $db->exec('USE other; USE third');
    $srv->closeAll();
    $db->query('SELECT 1');
    assert_eq(['connect', 'USE "app"', 'SELECT 1'], array_slice(session_seen($srv), 3));
    $db->close();
    $srv->stop();
});

test('reconnect: a USE with a -- comment is read as the server reads it', function () {
    $used = fn (string $sql) => Connection::usedDatabase($sql);
    // The server skips a -- comment to the end of its line, wherever it
    // stands outside a quoted name or string.
    assert_eq('app', $used('USE app -- the app database'));
    assert_eq('app', $used('USE app--no space before it'));
    assert_eq('app', $used("-- which one\nUSE app --\n;"));
    assert_eq('app', $used("USE -- on the next line\n app"));
    assert_eq('app', $used('use database "app" -- quoted; and a ; in the comment'));
    assert_eq('a--b', $used('USE "a--b"'), 'no comment starts inside a quoted name');
    assert_eq('a--b', $used('USE "a--b" -- one after it'));
    // No statement, no name, or not a comment at all.
    foreach ([
        '-- USE app', "-- USE app\n", 'USE -- x', "USE -- x\n", "USE 'a--b'", 'USE "app -- never closed',
        'USE app - - x', 'USE app -- x' . "\n" . 'other', '/* c */ USE app', 'USE app /* c */',
    ] as $sql) {
        assert_eq(null, $used($sql), $sql);
    }
    // End to end: such a USE moves the session like any other.
    $srv = session_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $db->exec('USE app');
    $db->exec('USE other -- the reporting database');
    $srv->closeAll();
    assert_eq(3, $db->query('SELECT count(*) FROM notes')->fetchColumn());
    assert_eq(1, $db->exec('INSERT INTO notes (id) VALUES (1)'));
    assert_eq(
        ['connect', 'USE "other"', 'SELECT count(*) FROM notes', 'INSERT INTO notes (id) VALUES (1)'],
        array_slice(session_seen($srv), 3)
    );
    $db->close();
    $srv->stop();
});

test('reconnect: SET CONSISTENCY and SET SCAN BUDGET are sent again on the new connection, before the statement', function () {
    $count = 'SELECT count(*) FROM notes';
    // Each case: the statements the application sends, then what a new
    // connection must be sent before anything else — after the session
    // database, the consistency first, then the scan budget.
    $cases = [
        'both, and a database' => [
            ['USE app', 'SET SCAN BUDGET ROWS 1', 'SET CONSISTENCY ALL'],
            ['USE "app"', 'SET CONSISTENCY ALL', 'SET SCAN BUDGET ROWS 1'],
        ],
        'only a scan budget' => [['SET SCAN BUDGET BYTES 4096'], ['SET SCAN BUDGET BYTES 4096']],
        'only a consistency' => [['SET CONSISTENCY ONE'], ['SET CONSISTENCY ONE']],
        'as written: case, spaces, a semicolon' => [
            ["  set   consistency\tquorum ;  ", "Set Scan Budget Rows 10 Bytes 4096;"],
            ["set   consistency\tquorum", 'Set Scan Budget Rows 10 Bytes 4096'],
        ],
        'comments are not part of it' => [
            ["-- as agreed\nSET CONSISTENCY ALL -- for the audit", "SET SCAN BUDGET -- tight\n ROWS 2"],
            ['SET CONSISTENCY ALL', "SET SCAN BUDGET \n ROWS 2"],
        ],
        'the last one accepted counts' => [
            ['SET SCAN BUDGET ROWS 1', 'SET CONSISTENCY ALL', 'SET SCAN BUDGET BYTES 100 ROWS 5', 'SET CONSISTENCY ONE'],
            ['SET CONSISTENCY ONE', 'SET SCAN BUDGET BYTES 100 ROWS 5'],
        ],
        'DEFAULT clears a setting: nothing is sent for it' => [
            ['SET SCAN BUDGET ROWS 1', 'SET CONSISTENCY ALL', 'SET SCAN BUDGET DEFAULT'],
            ['SET CONSISTENCY ALL'],
        ],
        'DEFAULT clears the other one too, however it is written' => [
            ['SET SCAN BUDGET ROWS 1', 'SET CONSISTENCY ALL', "set consistency default; -- back"],
            ['SET SCAN BUDGET ROWS 1'],
        ],
        'both cleared' => [['SET SCAN BUDGET ROWS 1', 'SET CONSISTENCY ALL', 'SET CONSISTENCY DEFAULT', 'SET SCAN BUDGET default'], []],
        'a setting the server refused does not count' => [['SET CONSISTENCY ALL', 'SET CONSISTENCY BOGUS'], ['SET CONSISTENCY ALL']],
        'what is not one such statement is left alone' => [
            ['SET SCAN BUDGET ROWS 1', 'SET CONFIG scan.rows = 5', 'SET SCAN BUDGETS ROWS 9', 'SET CONSISTENCYALL', 'SETSCAN BUDGET ROWS 9'],
            ['SET SCAN BUDGET ROWS 1'],
        ],
    ];
    // The connection is lost while it sits idle, and under a statement:
    // either way the next statement finds the settings in place.
    $losses = [
        'closed while idle' => fn (FakeServer $srv, Connection $db) => $srv->closeAll(),
        'half-closed while idle' => fn (FakeServer $srv, Connection $db) => $srv->closeAll(FakeServer::HALF),
        'lost under a statement' => function (FakeServer $srv, Connection $db) {
            assert_throws(ConnectionException::class, fn () => $db->query('SELECT garbage'));
        },
    ];
    foreach ($cases as $name => [$sent, $restored]) {
        foreach ($losses as $how => $lose) {
            $srv = (new FakeServer(['handle' => function (array $req) {
                if ($req['op'] !== 1) {
                    return null;
                }
                if ($req['sql'] === 'SELECT garbage') {
                    return chr(42);
                }
                if (str_contains($req['sql'], 'BOGUS')) {
                    return F::error('parse error: SET CONSISTENCY takes ONE, QUORUM, ALL, or DEFAULT');
                }
                return str_starts_with($req['sql'], 'SELECT') ? F::rows(['n'], [[3]]) : F::ddl();
            }]))->start();
            $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
            foreach ($sent as $sql) {
                try {
                    $db->exec($sql);
                } catch (SkaidbException $e) {
                    assert_match('/^parse error: SET CONSISTENCY takes/', $e->getMessage(), $sql);
                }
            }
            $lose($srv, $db);
            $from = count(session_seen($srv));
            assert_eq(3, $db->query($count)->fetchColumn(), "{$name}, {$how}");
            assert_eq(['connect', ...$restored, $count], array_slice(session_seen($srv), $from), "{$name}, {$how}");
            assert_eq(0, $srv->strayBytes());
            // Once per connection: the next statement costs nothing extra.
            $db->query($count);
            assert_eq([$count], array_slice(session_seen($srv), $from + count($restored) + 2), "{$name}, {$how}");
            $db->close();
            $srv->stop();
        }
    }
});

test('reconnect: a setting is noted however it was sent, and only when the server took it', function () {
    $ways = [
        'exec()' => fn (Connection $c) => $c->exec('SET SCAN BUDGET ROWS 1'),
        'query()' => fn (Connection $c) => $c->query('SET SCAN BUDGET ROWS 1'),
        'prepare()->execute()' => fn (Connection $c) => $c->prepare('SET SCAN BUDGET ROWS 1')->execute(),
        'a parameter, written into the text' => fn (Connection $c) => $c->prepare('SET SCAN BUDGET ROWS ?')->execute([1]),
        'a batch: the last row' => fn (Connection $c) => $c->prepare('SET SCAN BUDGET ROWS ?')->executeBatch([[7], [1]]),
        'stream()' => fn (Connection $c) => iterator_to_array($c->stream('SET SCAN BUDGET ROWS 1')),
    ];
    foreach ($ways as $name => $set) {
        $srv = session_server();
        $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
        $set($db);
        $srv->closeAll();
        $from = count(session_seen($srv));
        $db->query('SELECT count(*) FROM notes');
        assert_eq(['connect', 'SET SCAN BUDGET ROWS 1', 'SELECT count(*) FROM notes'], array_slice(session_seen($srv), $from), $name);
        $db->close();
        $srv->stop();
    }
    // What the driver reads, as the server reads it: exactly one statement
    // whose first words are SET CONSISTENCY or SET SCAN BUDGET, comments
    // and a trailing semicolon aside. The note is that text; DEFAULT is
    // "no note".
    $read = fn (string $sql) => Connection::sessionStatement($sql);
    assert_eq(['consistency', 'SET CONSISTENCY ALL'], $read('SET CONSISTENCY ALL'));
    assert_eq(['consistency', 'set consistency one'], $read(" set consistency one ;\n"));
    assert_eq(['consistency', null], $read('SET CONSISTENCY DEFAULT'));
    assert_eq(['consistency', null], $read("set consistency\n default -- back to the request's own level\n;"));
    assert_eq(['scan', 'SET SCAN BUDGET ROWS 1'], $read('SET SCAN BUDGET ROWS 1'));
    assert_eq(['scan', 'SET SCAN BUDGET BYTES 10 ROWS 2'], $read('SET SCAN BUDGET BYTES 10 ROWS 2; -- both'));
    assert_eq(['scan', null], $read('SET SCAN BUDGET DEFAULT;'));
    assert_eq(['scan', null], $read('set scan budget Default'));
    assert_eq(['scan', 'SET SCAN BUDGET DEFAULTS'], $read('SET SCAN BUDGET DEFAULTS'), 'not the word DEFAULT: kept as it is, for the server to judge');
    foreach ([
        'SET', 'SET CONSISTENCYALL', 'SET CONSISTENCY ALL; SELECT 1', 'SET CONSISTENCY ALL; SET SCAN BUDGET ROWS 1', 'SET SCAN ROWS 1',
        'SET SCAN BUDGETS ROWS 1', 'SET CONFIG a.b = 1', 'SETS CONSISTENCY ALL', '-- SET CONSISTENCY ALL', 'EXPLAIN SET CONSISTENCY ALL',
        'SELECT 1', 'UPDATE t SET consistency = 1', 'INSERT INTO t (id, note) VALUES (1, \'SET CONSISTENCY ALL\')',
    ] as $sql) {
        assert_eq(null, $read($sql), $sql);
    }
});

test('reconnect: a setting that cannot be applied again fails the statement — it never runs without it', function () {
    $srv = (new FakeServer(['handle' => function (array $req) {
        if ($req['op'] !== 1) {
            return null;
        }
        // The client's own SET is taken; the driver's, on the next two
        // dials, is not.
        static $budgets = 0;
        if ($req['sql'] === 'SET SCAN BUDGET ROWS 1') {
            return in_array(++$budgets, [2, 3], true) ? F::error('scan budget: not on this member') : F::ddl();
        }
        return str_starts_with($req['sql'], 'SELECT') ? F::rows(['n'], [[3]]) : F::ddl();
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $db->exec('USE app');
    $db->exec('SET CONSISTENCY ALL');
    $db->exec('SET SCAN BUDGET ROWS 1');
    $srv->closeAll();
    $e = assert_throws(SkaidbException::class, fn () => $db->query('SELECT count(*) FROM notes'), '/^scan budget: not on this member$/');
    assert_true(!$e instanceof ConnectionException);
    assert_true(!$db->isUsable());
    assert_throws(SkaidbException::class, fn () => $db->exec('INSERT INTO notes (id) VALUES (1)'), '/^scan budget: not on this member$/');
    // Neither statement was sent: not under another budget, and not
    // without one.
    $dial = ['connect', 'USE "app"', 'SET CONSISTENCY ALL', 'SET SCAN BUDGET ROWS 1'];
    assert_eq([...$dial, ...$dial], array_slice(session_seen($srv), 4));
    // Once it is taken again the next statement runs, under it.
    assert_eq(3, $db->query('SELECT count(*) FROM notes')->fetchColumn());
    assert_eq([...$dial, 'SELECT count(*) FROM notes'], array_slice(session_seen($srv), 12));
    $db->close();
    $srv->stop();
});

test('pool: a connection re-dialled while a borrower holds it keeps the borrower\'s settings', function () {
    $srv = session_server();
    $pool = new Pool(['127.0.0.1', $srv->port, 'ada', 'secret', 'database' => 'app'], 1);
    $pool->withConnection(function (Connection $c) use ($srv) {
        $c->exec('SET SCAN BUDGET ROWS 1');
        $c->exec('SET CONSISTENCY ALL');
        $srv->closeAll(FakeServer::HALF);
        assert_eq(3, $c->query('SELECT count(*) FROM notes')->fetchColumn());
    });
    assert_eq(
        ['connect', 'USE "app"', 'SET CONSISTENCY ALL', 'SET SCAN BUDGET ROWS 1', 'SELECT count(*) FROM notes'],
        array_slice(session_seen($srv), 4, 5)
    );
    $pool->close();
    $srv->stop();
});

test('reconnect: a session database that cannot be entered again fails the statement — it never runs elsewhere', function () {
    $srv = (new FakeServer(['handle' => function (array $req) {
        if ($req['op'] !== 1) {
            return null;
        }
        // The client's own USE is taken; the driver's, on a re-dial, is not
        // (the database was dropped meanwhile) — until the third dial.
        static $reentries = 0;
        if ($req['sql'] === 'USE "app"') {
            return ++$reentries < 3 ? F::error('database "app" does not exist') : F::ddl();
        }
        return str_starts_with($req['sql'], 'USE') ? F::ddl() : F::rows(['n'], [[3]]);
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $db->exec('USE app');
    $srv->closeAll();
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT count(*) FROM notes'), '/^database "app" does not exist$/');
    assert_true(!$db->isUsable());
    assert_throws(SkaidbException::class, fn () => $db->exec('INSERT INTO notes (id) VALUES (1)'), '/^database "app" does not exist$/');
    // Neither statement reached a session outside "app".
    assert_eq(['connect', 'USE app', 'connect', 'USE "app"', 'connect', 'USE "app"'], session_seen($srv));
    // Once it can be entered again, the next statement runs — in it.
    assert_eq(3, $db->query('SELECT count(*) FROM notes')->fetchColumn());
    assert_eq(['connect', 'USE "app"', 'SELECT count(*) FROM notes'], array_slice(session_seen($srv), 6));
    $db->close();
    $srv->stop();
});

/**
 * A server on which the database "app" was dropped behind the client's
 * back: `USE app` — the client's own — is taken, the quoted `USE "app"` a
 * re-dial sends is refused from the second connection on. `USE gone` is
 * refused always, as is a SET naming BOGUS; `SET CONSISTENCY ALL` and `SET
 * SCAN BUDGET ROWS 1111` are taken on the first connection and refused on
 * every later one, as by a member that will not have them.
 */
function hostage_server(): FakeServer
{
    return (new FakeServer(['handle' => function (array $req) {
        if ($req['op'] !== 1) {
            return null;
        }
        $sql = $req['sql'];
        if ($sql === 'USE "app"' && $req['conn'] > 1) {
            return F::error('database "app" does not exist');
        }
        if (str_contains($sql, 'gone')) {
            return F::error('database "gone" does not exist');
        }
        if (in_array($sql, ['SET CONSISTENCY ALL', 'SET SCAN BUDGET ROWS 1111'], true) && $req['conn'] > 1) {
            return F::error('not on this member');
        }
        if (str_contains($sql, 'BOGUS')) {
            return F::error('parse error: no such setting');
        }
        if (str_starts_with($sql, 'INSERT')) {
            return F::mutation(1);
        }
        return str_starts_with($sql, 'SELECT') ? F::rows(['n'], [[3]]) : F::ddl();
    }]))->start();
}

test('reconnect: a USE is not held hostage by the database it leaves', function () {
    $count = 'SELECT count(*) FROM notes';
    $srv = hostage_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $db->exec('USE app');
    $db->exec('SET CONSISTENCY ONE');
    $db->exec('SET SCAN BUDGET ROWS 5');
    // "app" is dropped from another connection, and this one is lost.
    $srv->closeAll();
    // Any other statement fails at the re-dial's USE, as it must: it would
    // run elsewhere.
    $e = assert_throws(SkaidbException::class, fn () => $db->query($count), '/^database "app" does not exist$/');
    assert_eq(SkaidbException::class, get_class($e));
    assert_eq(['connect', 'USE "app"'], array_slice(session_seen($srv), 4));
    // A USE is the way out, so it is not preceded by the USE it replaces:
    // the settings are put back, then it is sent, and its answer decides.
    assert_eq(0, $db->exec('USE other'));
    assert_eq(['connect', 'SET CONSISTENCY ONE', 'SET SCAN BUDGET ROWS 5', 'USE other'], array_slice(session_seen($srv), 6));
    assert_eq('other', $db->sessionDatabase());
    assert_eq(3, $db->query($count)->fetchColumn());
    assert_eq([$count], array_slice(session_seen($srv), 10), 'on the connection the USE was sent on');
    // The session is settled there: a statement the server refuses now is
    // an ordinary statement error.
    assert_throws(SkaidbException::class, fn () => $db->exec('SET CONSISTENCY BOGUS'), '/^parse error: no such setting$/');
    assert_true($db->isUsable());
    assert_eq(['SET CONSISTENCY BOGUS'], array_slice(session_seen($srv), 11));
    // And "other" is the session's database from here on.
    $srv->closeAll();
    assert_eq(3, $db->query($count)->fetchColumn());
    assert_eq(['connect', 'USE "other"', 'SET CONSISTENCY ONE', 'SET SCAN BUDGET ROWS 5', $count], array_slice(session_seen($srv), 12));
    $db->close();
    $srv->stop();

    // The same on a re-dial the look did not cause, in every spelling the
    // driver reads as a USE.
    foreach (['USE other', 'use database "other";', "USE other -- the reporting database"] as $use) {
        $srv = hostage_server();
        $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret', 'QUORUM', 5.0, 'app');
        $srv->closeAll(FakeServer::HALF);
        assert_throws(SkaidbException::class, fn () => $db->exec('INSERT INTO notes (id) VALUES (1)'), '/^database "app" does not exist$/');
        $db->prepare($use)->execute();
        assert_eq(1, $db->exec('INSERT INTO notes (id) VALUES (1)'));
        assert_eq(['connect', $use, 'INSERT INTO notes (id) VALUES (1)'], array_slice(session_seen($srv), 4), $use);
        $db->close();
        $srv->stop();
    }
});

test('reconnect: a USE that is refused after such a re-dial costs the connection — nothing runs outside the session database', function () {
    $count = 'SELECT count(*) FROM notes';
    // "app" exists here: only the application's new USE is refused.
    $srv = session_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $db->exec('USE app');
    $srv->closeAll();
    // The new connection was not taken into "app" for a USE that was
    // about to leave it. Refused, that USE leaves a connection in no
    // database the session knows: it is given up, not used.
    foreach (['exec' => fn () => $db->exec('USE gone'), 'stream' => fn () => iterator_to_array($db->stream('USE gone'))] as $how => $use) {
        $from = count(session_seen($srv));
        assert_throws(SkaidbException::class, $use, '/^database "gone" does not exist$/');
        assert_eq('app', $db->sessionDatabase());
        assert_true(!$db->isUsable(), "{$how}: the connection is in no database the session knows, and still in use");
        assert_eq(3, $db->query($count)->fetchColumn());
        assert_eq(
            ['connect', ($how === 'stream' ? 'stream ' : '') . 'USE gone', 'connect', 'USE "app"', $count],
            array_slice(session_seen($srv), $from),
            $how
        );
        $srv->closeAll();
    }
    // On a connection that IS in the session database a refused USE
    // changes nothing and costs nothing.
    $db->query($count);
    $connections = $srv->connections();
    assert_throws(SkaidbException::class, fn () => $db->exec('USE gone'), '/^database "gone" does not exist$/');
    assert_true($db->isUsable());
    $db->query($count);
    assert_eq($connections, $srv->connections());
    $db->close();
    $srv->stop();
});

test('reconnect: a setting is not held hostage by the one it replaces either', function () {
    $count = 'SELECT count(*) FROM notes';
    foreach ([
        'the scan budget' => ['SET SCAN BUDGET ROWS 1111', 'SET SCAN BUDGET DEFAULT', 'SET SCAN BUDGET BOGUS', 'SET CONSISTENCY ONE'],
        'the consistency' => ['SET CONSISTENCY ALL', 'SET CONSISTENCY DEFAULT', 'SET CONSISTENCY BOGUS', 'SET SCAN BUDGET ROWS 9'],
    ] as $name => [$set, $clear, $wrong, $other]) {
        $srv = hostage_server();
        $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
        $db->exec($other);
        $db->exec($set);
        $srv->closeAll();
        // The member the connection lands on refuses the noted setting.
        assert_throws(SkaidbException::class, fn () => $db->query($count), '/^not on this member$/');
        // Another statement of the same kind replaces it, so the old one
        // is not sent first — but one that is refused leaves the
        // connection without the setting the session has, and costs it.
        $from = count(session_seen($srv));
        assert_throws(SkaidbException::class, fn () => $db->exec($wrong), '/^parse error: no such setting$/');
        assert_true(!$db->isUsable(), $name);
        assert_throws(SkaidbException::class, fn () => $db->query($count), '/^not on this member$/');
        // Clearing it is accepted, and frees the session.
        assert_eq(0, $db->exec($clear));
        assert_eq(3, $db->query($count)->fetchColumn(), $name);
        // A dial sends the consistency before the scan budget: the other
        // setting is put back either way, and reached before the refused
        // one only when it is the consistency.
        $dial = ['connect', $other];
        $refused = $name === 'the scan budget' ? ['connect', $other, $set] : ['connect', $set];
        assert_eq([...$dial, $wrong, ...$refused, ...$dial, $clear, $count], array_slice(session_seen($srv), $from), $name);
        $db->close();
        $srv->stop();
    }
});

test('reconnect: a re-dial the server turns away in its handshake leaves the connection broken, not closed', function () {
    // A node at capacity closes a new connection unanswered (PROTOCOL.md
    // §6), and a node on its way up or down may do the same: a connection
    // failure like any other, tried again by the next statement.
    $full = tempnam(sys_get_temp_dir(), 'skaidb-full-');
    unlink($full);
    $srv = (new FakeServer([
        'handle' => fn (array $req) => $req['op'] === 1 ? F::rows(['n'], [[1]]) : null,
        'refuse' => fn () => file_exists($full),
    ]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $srv->closeAll();
    touch($full);
    // Each of these dials, reaches the server and is turned away.
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT 1'), '/^connection closed by server/');
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT 1'), '/^connection closed by server/');
    assert_true(!$db->isUsable());
    unlink($full);
    assert_eq(1, $db->query('SELECT 1')->rowCount());
    assert_true($db->isUsable());
    assert_eq(2, $srv->connections(), 'the first connection and the one that got in; nothing leaked in between');
    $db->close();
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT 1'), '/^connection is closed$/');
    $srv->stop();
});

test('reconnect: close() is terminal for a broken connection too — its next statement does not dial', function () {
    $srv = scripted_server();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $srv->closeAll();
    assert_true(!$db->isUsable(), 'found closed by the server: broken, and it would re-dial');
    $db->close();
    assert_throws(SkaidbException::class, fn () => $db->query('SELECT 1'), '/^connection is closed$/');
    assert_throws(SkaidbException::class, fn () => $db->prepare('SELECT ?')->execute([1]), '/^connection is closed$/');
    assert_throws(SkaidbException::class, fn () => iterator_to_array($db->stream('SELECT 1')), '/^connection is closed$/');
    assert_eq(1, $srv->connections());
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
    $c = $pool->acquire(); // $a was closed by the server while it sat idle: not handed out
    assert_true($c !== $a);
    assert_true(!$a->isUsable());
    assert_eq(1, $c->query('SELECT 1')->rowCount());
    assert_throws(SkaidbException::class, fn () => $c->query('SELECT garbage'));
    $pool->release($c);  // broken under its borrower: not pooled
    assert_true(!$c->isUsable());
    $d = $pool->acquire();
    assert_true($d !== $c);
    $pool->release($d);
    $pool->close();
    assert_throws(SkaidbException::class, fn () => $pool->acquire(), '/pool is closed/');
    assert_throws(SkaidbException::class, fn () => new Pool([], 0), '/maxsize/');
    $srv->stop();
});

test('pool: a connection released twice is pooled once — two borrowers never share it', function () {
    $srv = scripted_server();
    $pool = new Pool(['127.0.0.1', $srv->port, 'ada', 'secret'], 4);
    $c = $pool->acquire();
    $pool->release($c);
    $pool->release($c);
    assert_eq([0, 1], [$pool->members()[0]['in_use'], $pool->members()[0]['idle']]);
    // The same slip through withConnection(), which releases on its own.
    $pool->withConnection(fn (Connection $held) => $pool->release($held));
    assert_eq([0, 1], [$pool->members()[0]['in_use'], $pool->members()[0]['idle']]);
    $a = $pool->acquire();
    $b = $pool->acquire();
    assert_true($a !== $b, 'one connection was handed to two borrowers');
    assert_eq(2, $pool->members()[0]['in_use']);
    $pool->release($a);
    $pool->release($b);
    $pool->release($a);
    assert_eq([0, 2], [$pool->members()[0]['in_use'], $pool->members()[0]['idle']]);
    // A connection its borrower closed is not pooled by a late release.
    $d = $pool->acquire();
    $d->close();
    $pool->release($d);
    assert_eq([0, 1], [$pool->members()[0]['in_use'], $pool->members()[0]['idle']]);
    assert_eq(2, $srv->connections());
    $pool->close();
    $srv->stop();
});

test('pool: a re-dialled pooled connection is in the pool\'s database, and in its borrower\'s while it is held', function () {
    $srv = session_server();
    $count = 'SELECT count(*) FROM notes';
    $pool = new Pool(['127.0.0.1', $srv->port, 'ada', 'secret', 'database' => 'app'], 4);
    // A pooled connection lost while a borrower holds it, re-dialled inside
    // the checkout: back in the pool's database before anything else is
    // sent.
    $pool->withConnection(fn (Connection $c) => $c->query($count));
    $pool->withConnection(function (Connection $c) use ($srv, $count) {
        $srv->closeAll();
        assert_eq(3, $c->query($count)->fetchColumn());
    });
    assert_eq(['connect', 'USE "app"', $count, 'connect', 'USE "app"', $count], session_seen($srv));
    assert_eq(1, $pool->members()[0]['idle']);
    // Inside one checkout the borrower's database is the session's: a
    // re-dial there enters it, not the pool's.
    $pool->withConnection(function (Connection $c) use ($srv, $count) {
        $c->exec('USE other');
        $srv->closeAll();
        assert_eq(3, $c->query($count)->fetchColumn());
    });
    assert_eq(['USE other', 'connect', 'USE "other"', $count], array_slice(session_seen($srv), 6, 4));
    $pool->close();
    $srv->stop();
});

/**
 * A server for a pool's session state. $refused maps a statement to the
 * arrivals of it (1 = the first) that are answered with an Error, or to
 * true for all of them: a server that will not take it.
 *
 * @param array<string,array<int,int>|bool> $refused
 */
function pool_state_server(array $refused = []): FakeServer
{
    return (new FakeServer(['handle' => function (array $req) use ($refused) {
        static $arrivals = [];
        if ($req['op'] !== 1) {
            return null;
        }
        $n = $arrivals[$req['sql']] = ($arrivals[$req['sql']] ?? 0) + 1;
        $refuse = $refused[$req['sql']] ?? false;
        if ($refuse === true || (is_array($refuse) && in_array($n, $refuse, true))) {
            return F::error("refused here: {$req['sql']}");
        }
        if (str_starts_with($req['sql'], 'SELECT')) {
            return F::rows(['n'], [[3]]);
        }
        return str_starts_with($req['sql'], 'INSERT') ? F::mutation(1) : F::ddl();
    }]))->start();
}

test('pool: every borrower gets the pool\'s own session state — what the one before changed is put back', function () {
    $count = 'SELECT count(*) FROM notes';
    // A pool of one, so both borrowers get the same connection — with the
    // pool's database given, and with none (the server's "default").
    foreach (['app' => ['USE "app"'], '' => []] as $database => $dialled) {
        $home = $database === '' ? 'USE default' : 'USE "app"';
        $srv = pool_state_server();
        $args = ['127.0.0.1', $srv->port, 'ada', 'secret'] + ($database === '' ? [] : ['database' => $database]);
        $pool = new Pool($args, 1);
        $seen = fn () => array_slice(session_seen($srv), 1 + count($dialled));
        $borrow = fn (string ...$statements) => $pool->withConnection(function (Connection $c) use ($statements) {
            foreach ($statements as $sql) {
                $c->exec($sql);
            }
        });

        // Borrower 1 moves the session and tightens it; borrower 2 just
        // counts. It must run in the pool's database, at the request's own
        // consistency and under no scan budget of anybody else's.
        $borrow('USE other', 'SET SCAN BUDGET ROWS 1', 'SET CONSISTENCY ALL');
        assert_eq(3, $pool->withConnection(fn (Connection $c) => $c->query($count)->fetchColumn()));
        assert_eq([
            'USE other', 'SET SCAN BUDGET ROWS 1', 'SET CONSISTENCY ALL',
            $home, 'SET SCAN BUDGET DEFAULT', 'SET CONSISTENCY DEFAULT',
            $count,
        ], $seen(), "pool database '{$database}'");
        assert_eq([1, 1], [$srv->connections(), $pool->members()[0]['idle']], 'the same connection, kept');

        // Only what is needed: a borrower that changed nothing costs no
        // round trip, and one that changed one thing costs one.
        $from = count($seen());
        $pool->withConnection(fn (Connection $c) => $c->query($count));
        $borrow('SET SCAN BUDGET BYTES 4096');
        $borrow('SET CONSISTENCY ONE');
        $borrow('USE other');
        // What the borrower undid itself needs no undoing.
        $borrow('USE other', 'SET SCAN BUDGET ROWS 1', 'SET CONSISTENCY ALL', $home, 'SET CONSISTENCY DEFAULT', 'SET SCAN BUDGET DEFAULT');
        assert_eq([
            $count,
            'SET SCAN BUDGET BYTES 4096', 'SET SCAN BUDGET DEFAULT',
            'SET CONSISTENCY ONE', 'SET CONSISTENCY DEFAULT',
            'USE other', $home,
            'USE other', 'SET SCAN BUDGET ROWS 1', 'SET CONSISTENCY ALL', $home, 'SET CONSISTENCY DEFAULT', 'SET SCAN BUDGET DEFAULT',
        ], array_slice($seen(), $from), "pool database '{$database}'");
        // A USE of the pool's own database is no move at all.
        $from = count($seen());
        $borrow($database === '' ? 'USE default' : 'USE app');
        assert_eq(1, count($seen()) - $from);

        // The notes went with the state: a re-dial for the NEXT borrower
        // puts the pool's state back, not the last borrower's — whether
        // the connection is lost under that borrower's hands, or replaced
        // at the checkout.
        $borrow('USE other', 'SET SCAN BUDGET ROWS 1', 'SET CONSISTENCY ALL');
        $from = count(session_seen($srv));
        $pool->withConnection(function (Connection $c) use ($srv, $count) {
            $srv->closeAll();
            assert_eq(3, $c->query($count)->fetchColumn());
        });
        assert_eq(['connect', ...$dialled, $count], array_slice(session_seen($srv), $from), "pool database '{$database}'");
        $borrow('USE other', 'SET SCAN BUDGET ROWS 1', 'SET CONSISTENCY ALL');
        $srv->closeAll();
        $from = count(session_seen($srv));
        assert_eq(3, $pool->withConnection(fn (Connection $c) => $c->query($count)->fetchColumn()));
        assert_eq(['connect', ...$dialled, $count], array_slice(session_seen($srv), $from), "pool database '{$database}'");

        // A transaction the borrower left open is rolled back first: the
        // next borrower's statements must not join it.
        $from = count(session_seen($srv));
        $borrow('BEGIN', 'INSERT INTO notes (id) VALUES (1)');
        $borrow('BEGIN', 'INSERT INTO notes (id) VALUES (2)', 'USE other');
        $borrow('INSERT INTO notes (id) VALUES (3)');
        assert_eq([
            'BEGIN', 'INSERT INTO notes (id) VALUES (1)', 'ROLLBACK',
            'BEGIN', 'INSERT INTO notes (id) VALUES (2)', 'USE other', 'ROLLBACK', $home,
            'INSERT INTO notes (id) VALUES (3)',
        ], array_slice(session_seen($srv), $from), "pool database '{$database}'");
        assert_eq([3, 1], [$srv->connections(), $pool->members()[0]['idle']]);
        $pool->close();
        $srv->stop();
    }
});

test('pool: a connection that cannot be put back is closed, and the next borrower gets a new one', function () {
    $count = 'SELECT count(*) FROM notes';
    // Each case: what the borrower does, and the one restoring statement
    // this server refuses — an older server has no SET CONSISTENCY DEFAULT.
    $cases = [
        'SET CONSISTENCY DEFAULT' => ['USE other', 'SET SCAN BUDGET ROWS 1', 'SET CONSISTENCY ALL'],
        'SET SCAN BUDGET DEFAULT' => ['SET SCAN BUDGET ROWS 1', 'SET CONSISTENCY ALL'],
        'USE "app"' => ['USE other', 'SET CONSISTENCY ALL'],
        'ROLLBACK' => ['BEGIN', 'SET CONSISTENCY ALL'],
    ];
    foreach ($cases as $refused => $statements) {
        // The pool's own USE "app" is taken when a connection is dialled
        // (its first and third arrival) and refused in between.
        $srv = pool_state_server([$refused => $refused === 'USE "app"' ? [2] : true]);
        $pool = new Pool(['127.0.0.1', $srv->port, 'ada', 'secret', 'database' => 'app'], 1);
        $held = null;
        $pool->withConnection(function (Connection $c) use ($statements, &$held) {
            $held = $c;
            foreach ($statements as $sql) {
                $c->exec($sql);
            }
        });
        // Not pooled in a state the pool did not choose, and nothing after
        // the refusal was tried on it.
        assert_eq(0, $pool->members()[0]['idle'], $refused);
        assert_throws(SkaidbException::class, fn () => $held->query($count), '/^connection is closed$/');
        $last = session_seen($srv);
        assert_eq($refused, end($last), "{$refused}: the refused statement is the last one that connection was sent");
        assert_eq(3, $pool->withConnection(fn (Connection $c) => $c->query($count)->fetchColumn()), $refused);
        assert_eq(['connect', 'USE "app"', $count], array_slice(session_seen($srv), count($last)), $refused);
        assert_eq([2, 1], [$srv->connections(), $pool->members()[0]['idle']], $refused);
        $pool->close();
        $srv->stop();
    }
    // A connection lost under its borrower with a transaction open, or
    // released from inside transaction(): there is nothing to put back.
    $srv = pool_state_server();
    $pool = new Pool(['127.0.0.1', $srv->port, 'ada', 'secret'], 1);
    $pool->withConnection(function (Connection $c) use ($srv) {
        $c->exec('BEGIN');
        $srv->closeAll(FakeServer::HALF);
    });
    assert_eq(0, $pool->members()[0]['idle']);
    assert_eq(['connect', 'BEGIN'], session_seen($srv));
    assert_throws(SkaidbException::class, fn () => $pool->withConnection(fn (Connection $c) => $c->transaction(function (Connection $c) use ($pool) {
        $c->exec('INSERT INTO notes (id) VALUES (1)');
        $pool->release($c);
        assert_throws(SkaidbException::class, fn () => $c->exec('INSERT INTO notes (id) VALUES (2)'), '/^connection is closed$/');
    }, 3, 'START TRANSACTION')), '/^connection is closed$/');
    assert_eq(0, $pool->members()[0]['idle']);
    assert_eq(['connect', 'START TRANSACTION', 'INSERT INTO notes (id) VALUES (1)'], array_slice(session_seen($srv), 2));
    usleep(50_000);
    assert_eq(0, $srv->strayBytes());
    $pool->close();
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

test('subscribe: a connection closed between two polls is replaced, and the cursor carries on', function () {
    // subscribe() polls with ordinary statements, so between two of them
    // the connection is idle like any other: closed there, it costs the
    // subscription nothing.
    $srv = (new FakeServer(['handle' => function (array $req) {
        $page = fn (array $ids) => F::rows(['id', 'op', 'k', 'ts', 'doc'], array_map(fn ($id) => [$id, 'put', 'k', 1, null], $ids));
        if ($req['op'] === 2) {
            return F::prepared(1, 1);
        }
        if ($req['op'] === 1) {
            return $page(['0001', '0002']);
        }
        if ($req['op'] === 3) {
            return $page(match ($req['params'][0]) {
                '0002' => ['0003'],
                '0003' => ['0004', '0005'],
                default => [],
            });
        }
        return null;
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret', 'QUORUM', 5.0, 'app');
    $got = [];
    foreach ($db->subscribe('orders', null, 0.01) as $ev) {
        $got[] = $ev['id'];
        if (in_array($ev['id'], ['0002', '0003'], true)) {
            $srv->closeAll($ev['id'] === '0002' ? FakeServer::CLOSE : FakeServer::HALF);
        }
        if (count($got) === 5) {
            break;
        }
    }
    assert_eq(['0001', '0002', '0003', '0004', '0005'], $got);
    assert_eq(3, $srv->connections());
    usleep(50_000);
    assert_eq(0, $srv->strayBytes());
    $db->close();
    $srv->stop();
});
