<?php

declare(strict_types=1);

/**
 * Stream a large result set row by row with bounded memory, and stop early.
 *
 *     php examples/stream.php [host] [port] [user] [password]
 *
 * The abandon rule: the connection is busy until the generator finishes or
 * is abandoned (break, return, exception, or going out of scope); the driver
 * then drains what is left so the next statement reads its own reply.
 */

require __DIR__ . '/../src/Skaidb.php';

use Skaidb\Connection;

[$host, $port, $user, $password] = [$argv[1] ?? 'localhost', (int) ($argv[2] ?? 7000), $argv[3] ?? 'anonymous', $argv[4] ?? ''];

$db = new Connection($host, $port, $user, $password);
$db->exec('DROP TABLE IF EXISTS readings');
$db->exec('CREATE TABLE readings (PRIMARY KEY (id))');

$rows = [];
for ($i = 1; $i <= 5000; $i++) {
    $rows[] = [$i, sin($i / 100)];
}
$db->prepare('INSERT INTO readings (id, v) VALUES (?, ?)')->executeBatch($rows);

// Full scan, one chunk in memory at a time. Name the columns: on a cluster
// a bare `SELECT *` only streams page by page at consistency ONE.
$n = 0;
$sum = 0.0;
foreach ($db->stream('SELECT id, v FROM readings ORDER BY id') as $row) {
    $n++;
    $sum += $row['v'];
}
printf("rows %d  mean %.6f\n", $n, $sum / $n);

// Stop early: break abandons the generator and the driver drains the rest.
foreach ($db->stream('SELECT id, v FROM readings ORDER BY id') as $row) {
    if ($row['id'] >= 3) {
        break;
    }
}
// The connection is at a request boundary again.
echo 'count: ' . $db->query('SELECT count(*) AS n FROM readings')->fetchColumn() . "\n";

$db->exec('DROP TABLE readings');
$db->close();
