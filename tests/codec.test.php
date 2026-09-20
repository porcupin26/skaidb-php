<?php

declare(strict_types=1);

// The value codec (PROTOCOL.md §4): every tag decodes, encodes and round-trips.

use Skaidb\Bytes;
use Skaidb\Connection;
use Skaidb\Decimal;
use Skaidb\Reader;
use Skaidb\SkaidbException;
use Skaidb\Uuid;

$roundtrip = fn (mixed $v): mixed => Connection::decodeValue(new Reader(Connection::encodeValue($v)));
$decode = fn (string $hex): mixed => Connection::decodeValue(new Reader(hex2bin($hex)));
$tag = fn (mixed $v): int => ord(Connection::encodeValue($v)[0]);

test('null, bool, int, float round-trip', function () use ($roundtrip) {
    assert_eq(null, $roundtrip(null));
    assert_eq(true, $roundtrip(true));
    assert_eq(false, $roundtrip(false));
    assert_eq(0, $roundtrip(0));
    assert_eq(-42, $roundtrip(-42));
    assert_eq(PHP_INT_MAX, $roundtrip(PHP_INT_MAX));
    assert_eq(PHP_INT_MIN, $roundtrip(PHP_INT_MIN));
    assert_eq(1.5, $roundtrip(1.5));
    assert_eq(-0.25, $roundtrip(-0.25));
    assert_eq(1e300, $roundtrip(1e300));
});

test('scalar tags and byte layouts (little-endian)', function () use ($tag) {
    assert_eq(0, $tag(null));
    assert_eq(1, $tag(true));
    assert_eq(2, $tag(7));
    assert_eq(3, $tag(7.5));
    assert_eq(5, $tag('x'));
    assert_eq('00', bin2hex(Connection::encodeValue(null)));
    assert_eq('0101', bin2hex(Connection::encodeValue(true)));
    assert_eq('020100000000000000', bin2hex(Connection::encodeValue(1)));
    assert_eq('02ffffffffffffffff', bin2hex(Connection::encodeValue(-1)));
    assert_eq('03000000000000f03f', bin2hex(Connection::encodeValue(1.0)));
    assert_eq('05020000006869', bin2hex(Connection::encodeValue('hi')));
});

test('NaN and Infinity are refused', function () {
    assert_throws(SkaidbException::class, fn () => Connection::encodeValue(NAN), '/NaN/');
    assert_throws(SkaidbException::class, fn () => Connection::encodeValue(INF));
    assert_throws(SkaidbException::class, fn () => Connection::encodeValue(-INF));
});

test('unbindable values are refused with a SkaidbException', function () {
    assert_throws(SkaidbException::class, fn () => Connection::encodeValue(new \stdClass()), '/cannot bind value of type stdClass/');
    assert_throws(SkaidbException::class, fn () => Connection::encodeValue(fopen('php://memory', 'r')), '/cannot bind/');
});

test('string is UTF-8 with a u32 LE length; empty string is fine', function () use ($roundtrip) {
    $s = 'héllo — wörld 🚀';
    assert_eq($s, $roundtrip($s));
    assert_eq('', $roundtrip(''));
    $enc = Connection::encodeValue($s);
    assert_eq(strlen($s), unpack('V', substr($enc, 1, 4))[1]);
});

test('bytes: Bytes binds with tag 6 and decodes to a binary string', function () use ($roundtrip, $decode) {
    $raw = "\x00\x01\x02\xff";
    $enc = Connection::encodeValue(new Bytes($raw));
    assert_eq('0604000000000102ff', bin2hex($enc));
    assert_eq($raw, $roundtrip(new Bytes($raw)));
    assert_eq('', $decode('0600000000'));
    assert_eq("\xff", $decode('0601000000ff'));
});

test('uuid: 16 raw big-endian bytes <-> canonical lowercase 8-4-4-4-12', function () use ($roundtrip, $decode) {
    $u = new Uuid('123E4567-E89B-12D3-A456-426614174000');
    assert_eq('123e4567-e89b-12d3-a456-426614174000', $u->value);
    assert_eq('123e4567-e89b-12d3-a456-426614174000', (string) $u);
    assert_eq('07123e4567e89b12d3a456426614174000', bin2hex(Connection::encodeValue($u)));
    assert_eq('123e4567-e89b-12d3-a456-426614174000', $roundtrip($u));
    assert_eq('123e4567-e89b-12d3-a456-426614174000', $roundtrip(new Uuid('123e4567e89b12d3a456426614174000')));
    assert_eq('00000000-0000-0000-0000-000000000000', $decode('07' . str_repeat('00', 16)));
    assert_throws(SkaidbException::class, fn () => new Uuid('not-a-uuid'), '/not a uuid/');
    assert_throws(SkaidbException::class, fn () => new Uuid('123e4567-e89b-12d3-a456-42661417400'));
});

test('timestamp: i64 LE unix milliseconds <-> DateTimeImmutable in UTC', function () use ($roundtrip, $decode) {
    $dt = new \DateTimeImmutable('2023-11-14T22:13:20.123Z');
    $out = $roundtrip($dt);
    assert_true($out instanceof \DateTimeImmutable);
    assert_eq('2023-11-14T22:13:20.123000+00:00', $out->format('Y-m-d\TH:i:s.uP'));
    assert_eq('UTC', $out->getTimezone()->getName());
    assert_eq('08' . bin2hex(pack('P', 1700000000123)), bin2hex(Connection::encodeValue($dt)));
    // Microseconds truncate to milliseconds without a float in between.
    assert_eq(1700000000999, unpack('P', substr(Connection::encodeValue(new \DateTimeImmutable('2023-11-14T22:13:20.999999Z')), 1))[1]);
    // A zoned DateTime binds as the same instant.
    $zoned = new \DateTime('2023-11-15T00:13:20.123+02:00');
    assert_eq(1700000000123, unpack('P', substr(Connection::encodeValue($zoned), 1))[1]);
    // Pre-epoch.
    assert_eq('1969-12-31T23:59:59.000000', $decode('08' . bin2hex(pack('P', -1000)))->format('Y-m-d\TH:i:s.u'));
    assert_eq('1969-12-31T23:59:59.999000', $decode('08' . bin2hex(pack('P', -1)))->format('Y-m-d\TH:i:s.u'));
    assert_eq('1970-01-01T00:00:00.000000', $decode('08' . bin2hex(pack('P', 0)))->format('Y-m-d\TH:i:s.u'));
});

test('decimal: i128 LE mantissa + u32 scale <-> exact decimal string', function () use ($roundtrip, $decode) {
    // 12345 scale 2 -> "123.45"
    assert_eq('123.45', $decode('04' . bin2hex(pack('P', 12345) . str_repeat("\0", 8)) . '02000000'));
    // -5 scale 3 -> "-0.005" (two's complement across all 16 bytes)
    assert_eq('-0.005', $decode('04' . bin2hex(pack('P', -5) . str_repeat("\xff", 8)) . '03000000'));
    // scale 0 stays an integer string; zero
    assert_eq('42', $decode('04' . bin2hex(pack('P', 42) . str_repeat("\0", 8)) . '00000000'));
    assert_eq('0', $decode('04' . str_repeat('00', 16) . '00000000'));
    assert_eq('0.00', $decode('04' . str_repeat('00', 16) . '02000000'));
    // full 128-bit range, no bigint extension needed
    assert_eq('170141183460469231731687303715884105727', $decode('04' . str_repeat('ff', 15) . '7f' . '00000000'));
    assert_eq('-170141183460469231731687303715884105728', $decode('04' . str_repeat('00', 15) . '80' . '00000000'));
    assert_eq('-1', $decode('04' . str_repeat('ff', 16) . '00000000'));
    // encode
    foreach (['123.45', '-0.005', '0', '0.00', '42', '-42', '1000000000000000000000.000001',
              '170141183460469231731687303715884105727', '-170141183460469231731687303715884105728'] as $s) {
        assert_eq($s, $roundtrip(new Decimal($s)), "round-trip {$s}");
    }
    assert_eq('04' . bin2hex(pack('P', 12345) . str_repeat("\0", 8)) . '02000000', bin2hex(Connection::encodeValue(new Decimal('123.45'))));
    assert_eq('12.5', $roundtrip(new Decimal('+12.5')));
    assert_eq('12.50', $roundtrip(new Decimal('012.50')));
    assert_throws(SkaidbException::class, fn () => new Decimal('1e3'), '/not a decimal/');
    assert_throws(SkaidbException::class, fn () => new Decimal('abc'));
    assert_throws(SkaidbException::class, fn () => new Decimal(''));
    assert_throws(SkaidbException::class, fn () => Connection::encodeValue(new Decimal('170141183460469231731687303715884105728')), '/does not fit/');
    assert_throws(SkaidbException::class, fn () => Connection::encodeValue(new Decimal('-170141183460469231731687303715884105729')), '/does not fit/');
});

test('arrays (lists) and documents (assoc) nest and keep order', function () use ($roundtrip, $tag) {
    $v = [1, 'two', [3, null], ['a' => true, 'b' => [new Bytes('x')], 'z' => 1.5, 'c' => ['d' => 'e']]];
    $out = $roundtrip($v);
    assert_eq([1, 'two', [3, null], ['a' => true, 'b' => ['x'], 'z' => 1.5, 'c' => ['d' => 'e']]], $out);
    assert_eq(['a', 'b', 'z', 'c'], array_keys($out[3]));
    assert_eq(9, $tag([]));           // an empty PHP array is a list -> Array
    assert_eq(9, $tag([1, 2]));
    assert_eq(10, $tag(['k' => 1]));
    assert_eq(10, $tag([1 => 'x']));  // not a list (keys not 0..n-1) -> Document with key "1"
    assert_eq(['1' => 'x'], $roundtrip([1 => 'x']));
    assert_eq('0900000000', bin2hex(Connection::encodeValue([])));
    assert_eq('0a0100000001000000' . '6b' . '020100000000000000', bin2hex(Connection::encodeValue(['k' => 1])));
});

test('a truncated value or an unknown tag throws, never returns garbage', function () use ($decode) {
    assert_throws(SkaidbException::class, fn () => $decode('02010203'), '/truncated/');
    assert_throws(SkaidbException::class, fn () => $decode('0505000000ab'), '/truncated/');
    assert_throws(SkaidbException::class, fn () => $decode('63'), '/unknown value tag 99/');
    assert_throws(SkaidbException::class, fn () => $decode(''), '/truncated/');
});

test('Reader decodes the little-endian scalars the protocol uses', function () {
    $r = new Reader(hex2bin('ff' . '3412' . '78563412' . 'ffffffffffffffff' . '000000000000f0bf' . '03000000616263'));
    assert_eq(255, $r->u8());
    assert_eq(0x1234, $r->u16());
    assert_eq(0x12345678, $r->u32());
    assert_eq(-1, $r->i64());
    assert_eq(-1.0, $r->f64());
    assert_eq('abc', $r->text());
    assert_throws(SkaidbException::class, fn () => $r->u8(), '/truncated/');
});
