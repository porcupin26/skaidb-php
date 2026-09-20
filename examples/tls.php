<?php

declare(strict_types=1);

/**
 * Connect over TLS.
 *
 *     php examples/tls.php host port user password [ca.crt]
 *
 * With a CA file the server certificate is verified against it and the
 * SNI / expected name is tlsServerName (default "skaidb", which is what
 * skaidb's own certificates carry). Without one the system trust store is
 * used. For a self-signed dev server only, tlsInsecure: true skips
 * verification.
 */

require __DIR__ . '/../src/Skaidb.php';

use Skaidb\Connection;

if ($argc < 5) {
    fwrite(STDERR, "usage: php examples/tls.php host port user password [ca.crt]\n");
    exit(2);
}
[$host, $port, $user, $password] = [$argv[1], (int) $argv[2], $argv[3], $argv[4]];
$ca = $argv[5] ?? null;

$db = $ca !== null
    ? new Connection($host, $port, $user, $password, tlsCa: $ca)
    : new Connection($host, $port, $user, $password, tls: true);

print_r($db->query('SHOW DATABASES')->fetchAll());
$db->close();
