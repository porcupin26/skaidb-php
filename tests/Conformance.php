<?php

declare(strict_types=1);

// Helpers for the conformance harness (conformance.test.php) and the
// certificate-login tests: the vectors, tagged-JSON <-> PHP conversion, and a
// scripted fake server that serves ONE connection in a forked child.

use Skaidb\Bytes;
use Skaidb\Connection;
use Skaidb\Decimal;
use Skaidb\Reader;
use Skaidb\SkaidbException;
use Skaidb\Uuid;

$GLOBALS['__cf'] = json_decode(
    file_get_contents(__DIR__ . '/../conformance/vectors.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);

// ---- tagged JSON <-> PHP -----------------------------------------------------

function cf_float(array $t): float
{
    return unpack('E', hex2bin($t['float_bits']))[1];
}

/** mantissa / 10^scale as the exact decimal string the driver surfaces. */
function cf_decimal_text(string $mantissa, int $scale): string
{
    $neg = $mantissa[0] === '-';
    $digits = ltrim($mantissa, '-');
    if ($scale === 0) {
        return $mantissa;
    }
    $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
    return ($neg ? '-' : '') . substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
}

function cf_timestamp(string $ms): DateTimeImmutable
{
    $ms = (int) $ms;
    $sec = intdiv($ms, 1000);
    $rem = $ms % 1000;
    if ($rem < 0) {
        $sec--;
        $rem += 1000;
    }
    return (new DateTimeImmutable('@' . $sec))->modify('+' . ($rem * 1000) . ' microseconds');
}

/** A tagged value as the PHP value the driver binds as a parameter. */
function cf_native(array $t): mixed
{
    return match (array_key_first($t)) {
        'null' => null,
        'bool' => $t['bool'],
        'int' => cf_int($t['int']),
        'float' => cf_float($t),
        'decimal' => new Decimal(cf_decimal_text($t['decimal']['mantissa'], $t['decimal']['scale'])),
        'string' => $t['string'],
        'bytes' => new Bytes(hex2bin($t['bytes'])),
        'uuid' => new Uuid($t['uuid']),
        'timestamp_ms' => cf_timestamp($t['timestamp_ms']),
        'array' => array_map('cf_native', $t['array']),
        'document' => cf_document($t['document'], 'cf_native'),
        default => fail('unknown tagged value ' . json_encode($t)),
    };
}

/** A tagged value in the driver's documented result form (docs/types.md). */
function cf_surface(array $t): mixed
{
    return match (array_key_first($t)) {
        'decimal' => cf_decimal_text($t['decimal']['mantissa'], $t['decimal']['scale']),
        'bytes' => hex2bin($t['bytes']),
        'uuid' => strtolower($t['uuid']),
        'array' => array_map('cf_surface', $t['array']),
        'document' => cf_document($t['document'], 'cf_surface'),
        default => cf_native($t),
    };
}

function cf_int(string $s): int
{
    $i = (int) $s;
    if ((string) $i !== $s) {
        fail("int vector {$s} does not fit a PHP int");
    }
    return $i;
}

function cf_document(array $entries, callable $conv): array
{
    $out = [];
    foreach ($entries as $e) {
        $out[$e['key']] = $conv($e['value']);
    }
    return $out;
}

/** Driver value vs expected value: floats by bits, timestamps by millis, arrays in order. */
function cf_same(mixed $want, mixed $got): bool
{
    if (is_float($want)) {
        return is_float($got) && pack('E', $want) === pack('E', $got);
    }
    if ($want instanceof DateTimeInterface) {
        return $got instanceof DateTimeInterface
            && $want->format('U.u') === $got->format('U.u')
            && $got->getOffset() === 0;
    }
    if (is_array($want)) {
        if (!is_array($got) || array_keys($want) !== array_keys($got)) {
            return false;
        }
        foreach ($want as $k => $v) {
            if (!cf_same($v, $got[$k])) {
                return false;
            }
        }
        return true;
    }
    return $want === $got;
}

function cf_show(mixed $v): string
{
    if ($v instanceof DateTimeInterface) {
        return 'DateTime(' . $v->format('Y-m-d\TH:i:s.uP') . ')';
    }
    if (is_float($v)) {
        return 'float(' . bin2hex(pack('E', $v)) . ')';
    }
    if (is_array($v)) {
        $parts = [];
        foreach ($v as $k => $x) {
            $parts[] = var_export($k, true) . ' => ' . cf_show($x);
        }
        return '[' . implode(', ', $parts) . ']';
    }
    return dump($v);
}

// ---- the scripted fake server ------------------------------------------------

/** @param resource $s */
function cf_read_exact($s, int $n): ?string
{
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = fread($s, $n - strlen($buf));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $buf .= $chunk;
    }
    return $buf;
}

/** @param resource $s */
function cf_read_frame($s): ?string
{
    $head = cf_read_exact($s, 4);
    if ($head === null) {
        return null;
    }
    $n = unpack('N', $head)[1];
    return $n === 0 ? '' : cf_read_exact($s, $n);
}

/** @param resource $s */
function cf_send($s, string $payload): void
{
    fwrite($s, pack('N', strlen($payload)) . $payload);
}

/**
 * The fake server's side of one connection: the handshake per $outcome,
 * then $exchanges. Throws on any deviation.
 *
 * @param resource $s
 * @param array<int,array{0:string,1:array<int,string>}> $exchanges
 */
function cf_serve_conn($s, string $outcome, array $exchanges): void
{
    $auth = $GLOBALS['__cf']['auth'];
    $start = cf_read_frame($s) ?? throw new RuntimeException('no AuthStart');
    $r = new Reader($start);
    if ($r->u8() !== 10) {
        throw new RuntimeException('expected AuthStart');
    }
    $user = $r->text();
    $clientNonce = $r->text();
    $salt = hex2bin($auth['challenge']['salt']);
    $iterations = $auth['challenge']['iterations'];
    $serverNonce = $clientNonce . $auth['challenge']['server_nonce_suffix'];
    cf_send($s, chr(11) . pack('V', strlen($salt)) . $salt . pack('V', $iterations)
        . pack('V', strlen($serverNonce)) . $serverNonce);

    $finish = cf_read_frame($s) ?? throw new RuntimeException('no AuthFinish');
    if ($finish === '' || ord($finish[0]) !== 12) {
        throw new RuntimeException('expected AuthFinish');
    }
    // Verified independently of the driver's code: plain hash primitives.
    $am = implode("\0", [$user, $clientNonce, $serverNonce, bin2hex($salt), (string) $iterations]);
    $salted = hash_pbkdf2('sha256', $auth['password'], $salt, $iterations, 32, true);
    $clientKey = hash_hmac('sha256', 'Client Key', $salted, true);
    $clientSig = hash_hmac('sha256', $am, hash('sha256', $clientKey, true), true);
    if (substr($finish, 1, 32) !== ($clientKey ^ $clientSig) || strlen($finish) !== 33) {
        throw new RuntimeException('client proof did not verify');
    }
    if ($user !== $auth['username']) {
        throw new RuntimeException("AuthStart username {$user}, want {$auth['username']}");
    }
    $serverSig = hash_hmac('sha256', $am, hash_hmac('sha256', 'Server Key', $salted, true), true);
    if ($outcome === 'bad_server_signature') {
        cf_send($s, chr(13) . chr(1) . str_repeat("\xaa", 32));
        return;
    }
    if ($outcome === 'denied') {
        foreach ($auth['outcomes'] as $o) {
            if ($o['name'] === 'denied') {
                cf_send($s, hex2bin($o['payload']));
            }
        }
        return;
    }
    cf_send($s, chr(13) . chr(1) . $serverSig);

    $ddl = hex2bin($GLOBALS['__cf']['ignorable_requests']['ddl_payload']);
    while (true) {
        $req = cf_read_frame($s);
        if ($req === null) {
            if ($exchanges !== []) {
                throw new RuntimeException('never received request ' . bin2hex($exchanges[0][0]));
            }
            return;
        }
        if ($req !== '' && ($req[0] === "\x04" || $req[0] === "\x08")) {
            cf_send($s, $ddl);
            continue;
        }
        if ($exchanges === []) {
            throw new RuntimeException('unexpected extra request ' . bin2hex($req));
        }
        [$want, $responses] = array_shift($exchanges);
        if ($req !== $want) {
            throw new RuntimeException("request mismatch:\n       got  " . bin2hex($req) . "\n       want " . bin2hex($want));
        }
        foreach ($responses as $resp) {
            cf_send($s, $resp);
        }
    }
}

/**
 * Serve one connection in a forked child. Returns a join closure that waits
 * for the child and returns its error ('' when the script went as planned).
 *
 * @param callable($resource):void $serve
 * @return array{0:int,1:Closure():string}
 */
function cf_fork_server(callable $serve, string $listen = 'tcp://127.0.0.1:0', array $ctx = []): array
{
    $errno = 0;
    $errstr = '';
    $listener = stream_socket_server($listen, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create($ctx));
    if ($listener === false) {
        throw new RuntimeException("cannot listen: {$errstr}");
    }
    $name = stream_socket_get_name($listener, false);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    $result = tempnam(sys_get_temp_dir(), 'skaidb-conf-');
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('pcntl_fork failed');
    }
    if ($pid === 0) {
        $err = '';
        try {
            $s = @stream_socket_accept($listener, 10);
            if ($s === false) {
                throw new RuntimeException('no connection within 10 s');
            }
            stream_set_timeout($s, 10);
            $serve($s);
            fclose($s);
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }
        file_put_contents($result, $err === '' ? 'OK' : $err);
        // Never return into the test runner, and skip the parent's destructors.
        posix_kill(posix_getpid(), SIGKILL);
        exit(1);
    }
    fclose($listener);
    $join = function () use ($pid, $result): string {
        $deadline = microtime(true) + 15;
        while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            if (microtime(true) > $deadline) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                @unlink($result);
                return 'fake server did not finish';
            }
            usleep(5_000);
        }
        $out = (string) @file_get_contents($result);
        @unlink($result);
        return $out === 'OK' ? '' : ($out === '' ? 'fake server died' : $out);
    };
    return [$port, $join];
}

function cf_connect(int $port): Connection
{
    $a = $GLOBALS['__cf']['auth'];
    return new Connection('127.0.0.1', $port, $a['username'], $a['password'], 'QUORUM', 5.0);
}

