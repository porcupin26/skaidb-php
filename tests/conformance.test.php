<?php

declare(strict_types=1);

// The shared skaidb wire-protocol conformance suite.
//
// conformance/vectors.json is generated from the server's reference encoders
// (https://skaidb.org/conformance/vectors.json; the contract is
// conformance/README.md). This harness runs it against the driver's PUBLIC
// API through a scripted fake server that sends the reference bytes — never
// bytes this driver encoded — and checks the exact bytes the driver sends.
//
// Every call.method the vectors use has a public API here; a method this
// harness does not know is reported as SKIPPED on stdout.

use Skaidb\Connection;
use Skaidb\Reader;
use Skaidb\SkaidbException;

// ---- running a case's call through the public API ------------------------

/** @param array<int,string> $columns @param array<int,array<string,mixed>> $assoc */
function cf_rows(array $columns, array $assoc): array
{
    $rows = [];
    foreach ($assoc as $row) {
        if (array_keys($row) !== $columns) {
            fail('row keys ' . json_encode(array_keys($row)) . ' differ from columns ' . json_encode($columns));
        }
        $rows[] = array_values($row);
    }
    return ['columns' => $columns, 'rows' => $rows];
}

function cf_statement_result(\Skaidb\Statement $st): array
{
    $sets = $st->resultSets();
    if ($sets !== null && count($sets) > 1) {
        return ['result_sets' => array_map(fn ($s) => cf_rows($s['columns'], $s['rows']), $sets)];
    }
    return match ($st->kind()) {
        'rows' => ['rows' => cf_rows($st->columns(), $st->fetchAll())],
        'mutation' => ['affected' => (string) $st->rowCount()],
        'ddl' => ['ddl' => true],
    };
}

function cf_run_call(Connection $conn, array $call): ?array
{
    $method = $call['method'];
    try {
        switch ($method) {
            case 'sequence':
                $out = [];
                foreach ($call['calls'] as $c) {
                    $r = cf_run_call($conn, $c);
                    if ($r === null) {
                        return null;
                    }
                    $out[] = $r;
                }
                return ['sequence' => $out];
            case 'query':
                $st = $conn->prepare($call['sql'])->setConsistency($call['consistency']);
                $st->execute();
                return cf_statement_result($st);
            case 'query_stream':
                $gen = $conn->stream($call['sql']);
                $rows = [];
                try {
                    foreach ($gen as $row) {
                        $rows[] = $row;
                    }
                } catch (SkaidbException $e) {
                    if ($rows === []) {
                        throw $e;
                    }
                    return ['rows_then_error' => [
                        'rows' => cf_rows(array_keys($rows[0]), $rows),
                        'error' => $e->getMessage(),
                    ]];
                }
                $summary = $gen->getReturn();
                return match ($summary['kind']) {
                    'rows' => ['rows' => cf_rows($summary['columns'], $rows)],
                    'mutation' => ['affected' => (string) $summary['affected']],
                    'ddl' => ['ddl' => true],
                };
            case 'execute_prepared':
                $st = $conn->prepare($call['sql'])->setConsistency($call['consistency']);
                $st->execute(array_map('cf_native', $call['params']));
                return cf_statement_result($st);
            case 'execute_batch':
                $st = $conn->prepare($call['sql'])->setConsistency($call['consistency']);
                $rows = array_map(fn ($r) => array_map('cf_native', $r), $call['rows']);
                return ['affected' => (string) $st->executeBatch($rows)];
        }
    } catch (SkaidbException $e) {
        return ['error' => $e->getMessage()];
    }
    echo "     SKIPPED call.method {$method}: no PHP API for it\n";
    return null;
}

function cf_rows_match(array $want, array $got): bool
{
    if ($want['columns'] !== $got['columns'] || count($want['rows']) !== count($got['rows'])) {
        return false;
    }
    foreach ($want['rows'] as $i => $row) {
        if (!cf_same(array_map('cf_surface', $row), $got['rows'][$i])) {
            return false;
        }
    }
    return true;
}

function cf_matches(array $expect, array $got): bool
{
    $key = array_key_first($expect);
    $e = $expect[$key];
    if (!array_key_exists($key, $got)) {
        return false;
    }
    $g = $got[$key];
    return match ($key) {
        'error' => str_contains($g, $e),
        'rows' => cf_rows_match($e, $g),
        'affected' => $e === $g,
        'ddl' => $g === true,
        'result_sets' => count($e) === count($g)
            && array_reduce(array_keys($e), fn ($ok, $i) => $ok && cf_rows_match($e[$i], $g[$i]), true),
        'rows_then_error' => cf_rows_match($e['rows'], $g['rows']) && str_contains($g['error'], $e['error']),
        'sequence' => count($e) === count($g)
            && array_reduce(array_keys($e), fn ($ok, $i) => $ok && cf_matches($e[$i], $g[$i]), true),
        default => fail("unknown expect key {$key}"),
    };
}

// ---- 1. pure vectors ---------------------------------------------------------

test('conformance: every value vector decodes to the documented PHP form', function () {
    foreach ($GLOBALS['__cf']['values'] as $v) {
        $got = Connection::decodeValue(new Reader(hex2bin($v['encoded'])));
        $want = cf_surface($v['value']);
        if (!cf_same($want, $got)) {
            fail("{$v['name']}: decoded " . cf_show($got) . ', want ' . cf_show($want));
        }
    }
});

test('conformance: every bindable value vector encodes to the reference bytes', function () {
    foreach ($GLOBALS['__cf']['values'] as $v) {
        if ($v['value'] === ['document' => []]) {
            // PHP has one empty array; bound, it is an empty Array (a list).
            echo "     SKIPPED encode {$v['name']}: an empty PHP array binds as an empty Array\n";
            continue;
        }
        $got = bin2hex(Connection::encodeValue(cf_native($v['value'])));
        assert_eq($v['encoded'], $got, $v['name']);
    }
});

test('conformance: SCRAM auth message, salted password, proof and server signature', function () {
    foreach ($GLOBALS['__cf']['scram'] as $s) {
        $salt = hex2bin($s['salt']);
        $am = Connection::authMessage($s['username'], $s['client_nonce'], $s['server_nonce'], $salt, $s['iterations']);
        assert_eq($s['auth_message'], bin2hex($am), 'auth_message');
        $salted = Connection::saltedPassword($s['password'], $salt, $s['iterations']);
        assert_eq($s['salted_password'], bin2hex($salted), 'salted_password');
        [$proof, $sig] = Connection::scram($s['password'], $salt, $s['iterations'], $am);
        assert_eq($s['client_proof'], bin2hex($proof), 'client_proof');
        assert_eq($s['server_signature'], bin2hex($sig), 'server_signature');
    }
});

// ---- 2. auth outcomes ------------------------------------------------------

foreach ($GLOBALS['__cf']['auth']['outcomes'] as $o) {
    test("conformance: auth outcome {$o['name']}", function () use ($o) {
        [$port, $join] = cf_fork_server(fn ($s) => cf_serve_conn($s, $o['name'], []));
        try {
            if ($o['expect'] === 'connected') {
                cf_connect($port)->close();
            } else {
                $e = assert_throws(SkaidbException::class, fn () => cf_connect($port));
                if (isset($o['reason'])) {
                    assert_true(str_contains($e->getMessage(), $o['reason']), "error {$e->getMessage()} lacks the reason");
                }
            }
        } finally {
            $err = $join();
        }
        if ($err !== '') {
            fail("fake server: {$err}");
        }
    });
}

// ---- 3. cases ---------------------------------------------------------------

foreach ($GLOBALS['__cf']['cases'] as $case) {
    test("conformance: case {$case['name']}", function () use ($case) {
        $exchanges = array_map(
            fn ($e) => [hex2bin($e['request']), array_map('hex2bin', $e['responses'])],
            $case['exchanges']
        );
        [$port, $join] = cf_fork_server(fn ($s) => cf_serve_conn($s, 'ok', $exchanges));
        try {
            $conn = cf_connect($port);
            try {
                $got = cf_run_call($conn, $case['call']);
            } finally {
                $conn->close();
            }
        } finally {
            $err = $join(); // always reap the child, even when the call threw
        }
        if ($got === null) {
            return; // skipped, and said so
        }
        if ($err !== '') {
            fail("fake server: {$err}");
        }
        if (!cf_matches($case['expect'], $got)) {
            fail("expected " . json_encode($case['expect']) . "\n     got      " . cf_show($got));
        }
    });
}
