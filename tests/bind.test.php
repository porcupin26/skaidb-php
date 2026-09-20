<?php

declare(strict_types=1);

// Client-side parameter binding (PROTOCOL.md §5): the fallback path for
// statements the server will not prepare. Quoting is the injection boundary.

use Skaidb\Bytes;
use Skaidb\Connection;
use Skaidb\Decimal;
use Skaidb\SkaidbException;
use Skaidb\Uuid;

test('bindParams quotes every scalar kind', function () {
    $sql = Connection::bindParams(
        'INSERT INTO t (a, b, c, d, e, f, g) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [1, "O'Brien", 2.5, true, false, null, new \DateTimeImmutable('2023-11-14T22:13:20.123Z')]
    );
    assert_eq("INSERT INTO t (a, b, c, d, e, f, g) VALUES (1, 'O''Brien', 2.5, TRUE, FALSE, NULL, 1700000000123)", $sql);
});

test('floats bind in round-trip-safe form, not the 14-digit ini precision', function () {
    assert_eq('SELECT 0.1', Connection::bindParams('SELECT ?', [0.1]));
    assert_eq('SELECT 1.0e+25', Connection::bindParams('SELECT ?', [1e25]));
    assert_eq('SELECT 3.141592653589793', Connection::bindParams('SELECT ?', [M_PI]));
    assert_eq('SELECT 2.0', Connection::bindParams('SELECT ?', [2.0]));
});

test('placeholders inside string literals are left alone', function () {
    assert_eq("SELECT '?' FROM t WHERE a = 1", Connection::bindParams("SELECT '?' FROM t WHERE a = ?", [1]));
    assert_eq("SELECT 'it''s ?' FROM t WHERE a = 'x'", Connection::bindParams("SELECT 'it''s ?' FROM t WHERE a = ?", ['x']));
    assert_eq("SELECT '?'", Connection::bindParams("SELECT '?'", []));
});

test('parameter count must match the placeholders', function () {
    assert_throws(SkaidbException::class, fn () => Connection::bindParams('SELECT ? , ?', [1]), '/more placeholders than parameters/');
    assert_throws(SkaidbException::class, fn () => Connection::bindParams('SELECT ?', [1, 2]), '/more parameters than placeholders/');
    assert_throws(SkaidbException::class, fn () => Connection::bindParams('SELECT ?', []), '/placeholders but no parameters/');
    assert_eq('SELECT 1', Connection::bindParams('SELECT 1', []));
});

test('wrappers and stringables in the fallback', function () {
    assert_eq("SELECT 123.45", Connection::bindParams('SELECT ?', [new Decimal('123.45')]));
    assert_eq("SELECT '00ff'", Connection::bindParams('SELECT ?', [new Bytes("\x00\xff")]));
    assert_eq("SELECT '123e4567-e89b-12d3-a456-426614174000'", Connection::bindParams('SELECT ?', [new Uuid('123E4567E89B12D3A456426614174000')]));
    assert_throws(SkaidbException::class, fn () => Connection::bindParams('SELECT ?', [NAN]), '/NaN/');
    assert_throws(SkaidbException::class, fn () => Connection::bindParams('SELECT ?', [new \stdClass()]), '/cannot bind value of type stdClass/');
    // Arrays and documents have no SQL literal form: only the typed path carries them.
    assert_throws(SkaidbException::class, fn () => Connection::bindParams('SELECT ?', [[1, 2]]), '/cannot bind value of type array/');
});

test('associative parameter arrays bind positionally', function () {
    assert_eq('SELECT 1, 2', Connection::bindParams('SELECT ?, ?', ['x' => 1, 'y' => 2]));
});

test('consistency names and codes resolve; anything else throws', function () {
    assert_eq(0, Connection::resolveConsistency('ONE'));
    assert_eq(1, Connection::resolveConsistency('quorum'));
    assert_eq(2, Connection::resolveConsistency('All'));
    assert_eq(2, Connection::resolveConsistency(2));
    assert_eq(1, Connection::QUORUM);
    assert_throws(SkaidbException::class, fn () => Connection::resolveConsistency('TWO'), '/invalid consistency/');
    assert_throws(SkaidbException::class, fn () => Connection::resolveConsistency(3));
});
