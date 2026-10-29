<?php

declare(strict_types=1);

// Streaming requests on the wire (PROTOCOL.md §3.4): the parameterised form
// (OP_QUERY_STREAM_PARAMS, opcode 9) byte for byte, the parameterless form
// still OP_QUERY_STREAM (5) byte for byte, every call form stream() accepted
// before $params existed, values that cannot be bound, and an older server's
// refusal. The byte-exact tests use the conformance harness's one-connection
// server (Conformance.php), which fails on any request it was not scripted
// to receive; the expected bytes are written out here, not encoded by the
// driver.

use Skaidb\Bytes;
use Skaidb\Connection;
use Skaidb\Decimal;
use Skaidb\SkaidbException;
use Skaidb\Uuid;
use SkaidbTests\F;
use SkaidbTests\FakeServer;

/**
 * Run $call on a connection to a server that requires exactly $exchanges —
 * [request bytes, [reply payloads]] in order — and fails on any other
 * request. When both sides fail, both reasons are reported.
 *
 * @param array<int,array{0:string,1:array<int,string>}> $exchanges
 */
function with_exact_server(array $exchanges, callable $call): void
{
    [$port, $join] = cf_fork_server(fn ($s) => cf_serve_conn($s, 'ok', $exchanges));
    $thrown = null;
    try {
        $conn = cf_connect($port);
        try {
            $call($conn);
        } finally {
            $conn->close();
        }
    } catch (\Throwable $e) {
        $thrown = $e;
    }
    $err = $join();
    if ($thrown !== null) {
        if ($err !== '') {
            fail(get_class($thrown) . ': ' . $thrown->getMessage() . "\n     fake server: {$err}");
        }
        throw $thrown;
    }
    if ($err !== '') {
        fail("fake server: {$err}");
    }
}

/** OP_QUERY_STREAM: u8 5, u8 consistency, u32 LE sql_len, sql. */
function req_query_stream(int $consistency, string $sql): string
{
    return chr(5) . chr($consistency) . pack('V', strlen($sql)) . $sql;
}

/** OP_QUERY_STREAM_PARAMS: u8 9, u8 consistency, u32 LE sql_len, sql, then the parameter vector (hex). */
function req_query_stream_params(int $consistency, string $sql, string $vectorHex): string
{
    return chr(9) . chr($consistency) . pack('V', strlen($sql)) . $sql . hex2bin($vectorHex);
}

/** One row [1] under column n: header, chunk, RowsEnd. */
function reply_one_row(): array
{
    return [F::header(['n']), F::chunk([[1]]), F::end()];
}

test('stream: parameters travel in OP_QUERY_STREAM_PARAMS, byte for byte', function () {
    $sql = 'SELECT id FROM t WHERE a = ? AND b = ? AND c = ? AND d = ? AND e = ? AND f = ? AND g = ? AND h = ?';
    // Consistency ONE (0); u16 LE nparams = 8; then each value behind its u32 LE length.
    $request = req_query_stream_params(0, $sql, '0800'
        . '09000000' . '022a00000000000000'                                  // 42 → Int
        . '07000000' . '05020000006162'                                      // 'ab' → String
        . '09000000' . '087b68e5cf8b010000'                                  // 2023-11-14T22:13:20.123Z → Timestamp 1700000000123 ms
        . '14000000' . '0902000000020100000000000000050100000078'            // [1, 'x'] → Array
        . '0c000000' . '0a01000000010000006b0101'                            // ['k' => true] → Document
        . '15000000' . '0419fcffffffffffffffffffffffffffff02000000'          // Decimal -9.99: i128 mantissa -999, scale 2
        . '11000000' . '07123e4567e89b12d3a456426614174000'                  // Uuid
        . '01000000' . '00');                                                // null → Null
    with_exact_server([[$request, [F::header(['id']), F::chunk([[1], [2]]), F::end()]]], function (Connection $conn) use ($sql) {
        $gen = $conn->stream($sql, [
            42,
            'ab',
            new \DateTimeImmutable('2023-11-14T22:13:20.123Z'),
            [1, 'x'],
            ['k' => true],
            new Decimal('-9.99'),
            new Uuid('123e4567-e89b-12d3-a456-426614174000'),
            null,
        ], 'ONE');
        assert_eq([['id' => 1], ['id' => 2]], iterator_to_array($gen));
        assert_eq(['kind' => 'rows', 'columns' => ['id'], 'affected' => 0], $gen->getReturn());
        assert_true($conn->isUsable());
    });
});

test('stream: without parameters the request is still OP_QUERY_STREAM, byte for byte', function () {
    $sql = 'SELECT n FROM t';
    with_exact_server([
        [req_query_stream(1, $sql), reply_one_row()],
        [req_query_stream(1, $sql), reply_one_row()],
        [req_query_stream(0, $sql), reply_one_row()],
    ], function (Connection $conn) use ($sql) {
        assert_eq([['n' => 1]], iterator_to_array($conn->stream($sql)));
        assert_eq([['n' => 1]], iterator_to_array($conn->stream($sql, [])), 'an empty $params is no parameters');
        assert_eq([['n' => 1]], iterator_to_array($conn->stream($sql, [], 'ONE')));
    });
});

test('stream: every call form stream() accepted before keeps its meaning', function () {
    $sql = 'SELECT n FROM t';
    $sqlp = 'SELECT n FROM t WHERE n > ?';
    $seven = '0100' . '09000000' . '020700000000000000';   // one parameter: Int 7
    with_exact_server([
        [req_query_stream(0, $sql), reply_one_row()],    // stream($sql, 'ONE')
        [req_query_stream(2, $sql), reply_one_row()],    // stream($sql, Connection::ALL)
        [req_query_stream(0, $sql), reply_one_row()],    // stream($sql, 0)
        [req_query_stream(1, $sql), reply_one_row()],    // stream($sql, 'quorum')
        [req_query_stream(1, $sql), reply_one_row()],    // stream($sql, null)
        [req_query_stream(2, $sql), reply_one_row()],    // stream($sql, consistency: 'ALL')
        [req_query_stream_params(2, $sqlp, $seven), reply_one_row()],   // stream($sqlp, [7], 'ALL')
        [req_query_stream_params(0, $sqlp, $seven), reply_one_row()],   // stream($sqlp, params: ['k' => 7], consistency: ONE)
    ], function (Connection $conn) use ($sql, $sqlp) {
        // A consistency in the second position, the signature before $params.
        assert_eq([['n' => 1]], iterator_to_array($conn->stream($sql, 'ONE')));
        assert_eq([['n' => 1]], iterator_to_array($conn->stream($sql, Connection::ALL)));
        assert_eq([['n' => 1]], iterator_to_array($conn->stream($sql, 0)));
        assert_eq([['n' => 1]], iterator_to_array($conn->stream($sql, 'quorum')));
        assert_eq([['n' => 1]], iterator_to_array($conn->stream($sql, null)), 'null: the connection default');
        assert_eq([['n' => 1]], iterator_to_array($conn->stream($sql, consistency: 'ALL')));
        // The parameterised forms, positional and named; keys are re-indexed as execute() does.
        assert_eq([['n' => 1]], iterator_to_array($conn->stream($sqlp, [7], 'ALL')));
        assert_eq([['n' => 1]], iterator_to_array($conn->stream($sqlp, params: ['k' => 7], consistency: Connection::ONE)));
        // Invalid consistencies throw as they always did, before anything is sent.
        assert_throws(SkaidbException::class, fn () => iterator_to_array($conn->stream($sql, 'TWO')), '/invalid consistency TWO/');
        assert_throws(SkaidbException::class, fn () => iterator_to_array($conn->stream($sql, 3)), '/invalid consistency 3/');
        // A scalar where $params goes AND a consistency is not a form that ever meant anything.
        assert_throws(
            SkaidbException::class,
            fn () => iterator_to_array($conn->stream($sql, 'ONE', 'ALL')),
            '/stream\(\) takes its parameters as an array, got string/'
        );
        assert_true($conn->isUsable());
    });
});

test('stream: a value that cannot be bound throws before anything is sent; the connection stays usable', function () {
    $sqlp = 'SELECT n FROM t WHERE n > ?';
    with_exact_server([
        [req_query_stream_params(1, $sqlp, '0100' . '06000000' . '06010000002a'), reply_one_row()],  // new Bytes('*')
    ], function (Connection $conn) use ($sqlp) {
        assert_throws(SkaidbException::class, fn () => iterator_to_array($conn->stream($sqlp, [new \stdClass()])), '/cannot bind value of type stdClass/');
        assert_throws(SkaidbException::class, fn () => iterator_to_array($conn->stream($sqlp, [NAN])), '/cannot bind NaN/');
        assert_throws(
            SkaidbException::class,
            fn () => iterator_to_array($conn->stream($sqlp, array_fill(0, 65536, 1))),
            '/too many parameters: 65536 \(a request carries at most 65535\)/'
        );
        assert_true($conn->isUsable(), 'nothing was sent, so the connection is at a request boundary');
        assert_eq([['n' => 1]], iterator_to_array($conn->stream($sqlp, [new Bytes('*')])));
    });
});

test('stream: a parameterised stream reads rows, a mutation, an error and an early stop like any stream', function () {
    $srv = (new FakeServer(['handle' => function (array $req) {
        if ($req['op'] === 1) {
            return F::rows(['n'], [[1]]);
        }
        if ($req['op'] !== 9) {
            return null;
        }
        if (str_starts_with($req['sql'], 'DELETE')) {
            return F::mutation(count($req['params']));
        }
        if (str_starts_with($req['sql'], 'SELECT boom')) {
            return F::error('no such column: boom');
        }
        // Echo the bound values back, one chunk per value.
        $out = [F::header(['i', 'v'])];
        foreach ($req['params'] as $i => $v) {
            $out[] = F::chunk([[$i, $v]]);
        }
        $out[] = F::end();
        return $out;
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $params = [5, 'x', null, [1, 2], ['k' => 'v']];
    $gen = $db->stream('SELECT i, v FROM t WHERE a = ? AND b = ? AND c = ? AND d = ? AND e = ?', $params);
    assert_eq(
        [['i' => 0, 'v' => 5], ['i' => 1, 'v' => 'x'], ['i' => 2, 'v' => null], ['i' => 3, 'v' => [1, 2]], ['i' => 4, 'v' => ['k' => 'v']]],
        iterator_to_array($gen)
    );
    assert_eq(['kind' => 'rows', 'columns' => ['i', 'v'], 'affected' => 0], $gen->getReturn());
    $gen = $db->stream('DELETE FROM t WHERE id = ? OR id = ?', [1, 2]);
    assert_eq([], iterator_to_array($gen));
    assert_eq(['kind' => 'mutation', 'columns' => [], 'affected' => 2], $gen->getReturn());
    assert_throws(SkaidbException::class, fn () => iterator_to_array($db->stream('SELECT boom FROM t WHERE id = ?', [1])), '/^no such column: boom$/');
    assert_true($db->isUsable());
    foreach ($db->stream('SELECT i, v FROM t WHERE v IN (?, ?, ?)', ['a', 'b', 'c']) as $row) {
        assert_eq(['i' => 0, 'v' => 'a'], $row);
        break; // the generator's cleanup drains the other two chunks
    }
    assert_true($db->isUsable());
    assert_eq(1, $db->query('SELECT 1')->rowCount());
    $sent = array_values(array_filter($srv->requests(), fn ($r) => $r['op'] === 9));
    assert_eq(4, count($sent));
    assert_eq($params, $sent[0]['params']);
    assert_eq(1, $sent[0]['consistency']);
    $db->close();
    $srv->stop();
});

test('stream: an older server refusing OP_QUERY_STREAM_PARAMS says so; the connection stays usable', function () {
    // A server before 0.330 knows OP_QUERY_STREAM but not opcode 9, and
    // answers the unknown opcode with one ordinary Error frame.
    $old = (new FakeServer(['handle' => fn (array $req) => match ($req['op']) {
        9 => F::error('protocol error: malformed message: unknown opcode'),
        5 => reply_one_row(),
        1 => F::rows(['n'], [[1]]),
        default => null,
    }]))->start();
    $db = new Connection('127.0.0.1', $old->port, 'ada', 'secret');
    assert_throws(
        SkaidbException::class,
        fn () => iterator_to_array($db->stream('SELECT n FROM t WHERE n > ?', [0])),
        '/^server does not support parameterised streaming \(skaidb >= 0\.330\): protocol error: malformed message: unknown opcode$/'
    );
    assert_true($db->isUsable(), 'a refused request leaves the connection usable');
    assert_eq([['n' => 1]], $db->query('SELECT 1')->fetchAll());
    assert_eq([['n' => 1]], iterator_to_array($db->stream('SELECT n FROM t')), 'a parameterless stream still works there');
    $db->close();
    $old->stop();

    // A server without the streaming opcode at all: the parameterless message is unchanged.
    $ancient = (new FakeServer(['handle' => fn (array $req) => in_array($req['op'], [5, 9], true)
        ? F::error('protocol error: malformed message: unknown opcode')
        : null]))->start();
    $db = new Connection('127.0.0.1', $ancient->port, 'ada', 'secret');
    assert_throws(SkaidbException::class, fn () => iterator_to_array($db->stream('SELECT n FROM t')), '/^server does not support streaming: protocol error/');
    assert_true($db->isUsable());
    $db->close();
    $ancient->stop();
});
