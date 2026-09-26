<?php

declare(strict_types=1);

// TLS client certificates: presenting one (tlsClientCert / tlsClientKey) and
// logging in with it (authMechanism 'certificate', wire mechanism EXTERNAL,
// PROTOCOL.md §2.4). The fake server is a real TLS listener that verifies the
// client certificate against a test CA generated here, reads the peer's
// Common Name the way skaidb does, and checks the AuthStart bytes.

use Skaidb\Connection;
use Skaidb\SkaidbException;
use SkaidbTests\F;

/** A throwaway CA, a server certificate for 'skaidb' and a client certificate for CN=app. */
function cert_fixture(): array
{
    static $fx = null;
    if ($fx !== null) {
        return $fx;
    }
    $dir = sys_get_temp_dir() . '/skaidb-php-certs-' . getmypid();
    @mkdir($dir, 0700, true);
    $cnf = "{$dir}/openssl.cnf";
    file_put_contents($cnf, <<<CNF
        [req]
        default_bits = 2048
        distinguished_name = dn
        [dn]
        [v3_ca]
        basicConstraints = critical,CA:TRUE
        keyUsage = critical,keyCertSign,cRLSign
        subjectKeyIdentifier = hash
        [v3_server]
        basicConstraints = CA:FALSE
        subjectAltName = DNS:skaidb
        extendedKeyUsage = serverAuth
        [v3_client]
        basicConstraints = CA:FALSE
        extendedKeyUsage = clientAuth
        CNF);
    $opts = fn (string $ext) => ['config' => $cnf, 'digest_alg' => 'sha256', 'x509_extensions' => $ext];
    $newKey = fn () => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => $cnf]);

    $caKey = $newKey();
    $caCsr = openssl_csr_new(['commonName' => 'skaidb test CA'], $caKey, $opts('v3_ca'));
    $ca = openssl_csr_sign($caCsr, null, $caKey, 1, $opts('v3_ca'), 1);

    $issue = function (string $cn, string $ext, int $serial) use ($newKey, $opts, $ca, $caKey): array {
        $key = $newKey();
        $csr = openssl_csr_new(['commonName' => $cn], $key, $opts($ext));
        $crt = openssl_csr_sign($csr, $ca, $caKey, 1, $opts($ext), $serial);
        openssl_x509_export($crt, $crtPem);
        openssl_pkey_export($key, $keyPem, null, $opts($ext));
        return [$crtPem, $keyPem];
    };
    openssl_x509_export($ca, $caPem);
    [$srvCrt, $srvKey] = $issue('skaidb', 'v3_server', 2);
    [$cliCrt, $cliKey] = $issue('app', 'v3_client', 3);
    $fx = [
        'ca' => "{$dir}/ca.crt",
        'server' => "{$dir}/server.pem",
        'client_crt' => "{$dir}/app.crt",
        'client_key' => "{$dir}/app.key",
        'client_pem' => "{$dir}/app.pem",
    ];
    file_put_contents($fx['ca'], $caPem);
    file_put_contents($fx['server'], $srvCrt . $srvKey);
    file_put_contents($fx['client_crt'], $cliCrt);
    file_put_contents($fx['client_key'], $cliKey);
    file_put_contents($fx['client_pem'], $cliCrt . $cliKey);
    register_shutdown_function(function () use ($dir) {
        array_map('unlink', glob("{$dir}/*") ?: []);
        @rmdir($dir);
    });
    return $fx;
}

/** A TLS listener that asks for a client certificate and verifies it against the test CA. */
function cert_server(callable $serve): array
{
    $fx = cert_fixture();
    return cf_fork_server($serve, 'tls://127.0.0.1:0', ['ssl' => [
        'local_cert' => $fx['server'],
        'cafile' => $fx['ca'],
        'verify_peer' => true,
        'verify_peer_name' => false,
        'allow_self_signed' => false,
        'capture_peer_cert' => true,
    ]]);
}

/** The verified client certificate's Common Name, or null without one. @param resource $s */
function cert_peer_cn($s): ?string
{
    $peer = stream_context_get_params($s)['options']['ssl']['peer_certificate'] ?? null;
    if ($peer === null) {
        return null;
    }
    return openssl_x509_parse($peer)['subject']['CN'] ?? null;
}

/**
 * The server side of EXTERNAL, as skaidb runs it: the AuthStart must carry
 * mechanism 2, an empty nonce and $wantUser; then the outcome, then one
 * query answered with the authenticated identity.
 *
 * @param resource $s
 */
function cert_serve_external($s, string $wantUser, ?string $deny = null): void
{
    $cn = cert_peer_cn($s);
    $start = cf_read_frame($s) ?? throw new RuntimeException('no AuthStart');
    $want = Connection::authStartExternal($wantUser);
    // Built independently of the driver: tag 10, str username, str '' nonce, u8 2.
    $independent = chr(10) . pack('V', strlen($wantUser)) . $wantUser . pack('V', 0) . chr(2);
    if ($want !== $independent) {
        throw new RuntimeException('authStartExternal builds ' . bin2hex($want));
    }
    if ($start !== $independent) {
        throw new RuntimeException('AuthStart ' . bin2hex($start) . ', want ' . bin2hex($independent));
    }
    if ($deny !== null) {
        cf_send($s, chr(13) . chr(0) . pack('V', strlen($deny)) . $deny);
        return;
    }
    if ($cn === null) {
        cf_send($s, chr(13) . chr(0) . F::str('no client certificate'));
        return;
    }
    // 32 zero bytes: the client must not try to verify them.
    cf_send($s, chr(13) . chr(1) . str_repeat("\0", 32));
    while (($req = cf_read_frame($s)) !== null) {
        if ($req[0] === "\x08" || $req[0] === "\x04") {
            cf_send($s, chr(2));
            continue;
        }
        cf_send($s, F::rows(['user'], [[$cn]]));
    }
}

function cert_connect(int $port, array $extra): Connection
{
    $fx = cert_fixture();
    return new Connection(...array_merge([
        'host' => '127.0.0.1',
        'port' => $port,
        'timeout' => 5.0,
        'tlsCa' => $fx['ca'],
    ], $extra));
}

function cert_join(callable $join): void
{
    $err = $join();
    if ($err !== '') {
        fail("fake server: {$err}");
    }
}

test('certificate login: no username is claimed by default; the CN is the identity', function () {
    $fx = cert_fixture();
    [$port, $join] = cert_server(fn ($s) => cert_serve_external($s, ''));
    $db = cert_connect($port, ['tlsClientCert' => $fx['client_crt'], 'tlsClientKey' => $fx['client_key'],
        'authMechanism' => 'certificate']);
    assert_eq([['user' => 'app']], $db->query('SELECT current_user')->fetchAll());
    $db->close();
    cert_join($join);
});

test('certificate login: an explicit username is sent as the claim; cert and key may share a file', function () {
    $fx = cert_fixture();
    [$port, $join] = cert_server(fn ($s) => cert_serve_external($s, 'app'));
    $db = cert_connect($port, ['user' => 'app', 'tlsClientCert' => $fx['client_pem'], 'authMechanism' => 'certificate']);
    assert_eq('app', $db->query('SELECT current_user')->fetchColumn());
    $db->close();
    cert_join($join);
});

test('certificate login: a denial surfaces the server\'s reason', function () {
    $fx = cert_fixture();
    $reason = "the username does not match the client certificate's Common Name";
    [$port, $join] = cert_server(fn ($s) => cert_serve_external($s, 'bob', $reason));
    assert_throws(SkaidbException::class, fn () => cert_connect($port, ['user' => 'bob',
        'tlsClientCert' => $fx['client_crt'], 'tlsClientKey' => $fx['client_key'], 'authMechanism' => 'certificate']),
        '/authentication denied: the username does not match/');
    cert_join($join);
});

test('a client certificate alongside SCRAM: presented in TLS, the login stays the password', function () {
    $fx = cert_fixture();
    [$port, $join] = cert_server(function ($s) {
        if (cert_peer_cn($s) !== 'app') {
            throw new RuntimeException('no client certificate presented');
        }
        cf_serve_conn($s, 'ok', []);
    });
    $a = $GLOBALS['__cf']['auth'];
    $db = cert_connect($port, ['user' => $a['username'], 'password' => $a['password'],
        'tlsClientCert' => $fx['client_crt'], 'tlsClientKey' => $fx['client_key']]);
    assert_true($db->isUsable());
    $db->close();
    cert_join($join);
});

test('certificate mechanism: configuration errors are refused before dialling', function () {
    assert_throws(SkaidbException::class, fn () => new Connection('127.0.0.1', 1, authMechanism: 'certificate'),
        '/needs a TLS client certificate/');
    assert_throws(SkaidbException::class, fn () => new Connection('127.0.0.1', 1, tlsClientKey: '/k.pem'),
        '/tlsClientKey needs tlsClientCert/');
    assert_throws(SkaidbException::class, fn () => new Connection('127.0.0.1', 1, authMechanism: 'kerberos'),
        "/unknown authMechanism kerberos/");
});

test('certificate mechanism: accepted spellings', function () {
    $r = new ReflectionMethod(Connection::class, 'resolveAuthMechanism');
    foreach (['certificate', 'EXTERNAL', 'x509'] as $m) {
        assert_true($r->invoke(null, $m), $m);
    }
    foreach (['scram', 'Password'] as $m) {
        assert_eq(false, $r->invoke(null, $m), $m);
    }
});
