<?php

declare(strict_types=1);

/**
 * Insert many rows in one round-trip and bind every typed value.
 *
 *     php examples/batch.php [host] [port] [user] [password]
 */

require __DIR__ . '/../src/Skaidb.php';

use Skaidb\Bytes;
use Skaidb\Connection;
use Skaidb\Decimal;
use Skaidb\Uuid;

[$host, $port, $user, $password] = [$argv[1] ?? 'localhost', (int) ($argv[2] ?? 7000), $argv[3] ?? 'anonymous', $argv[4] ?? ''];

$db = new Connection($host, $port, $user, $password);
$db->exec('DROP TABLE IF EXISTS orders');
$db->exec('CREATE TABLE orders (PRIMARY KEY (id))');

// One request carries every row; each row autocommits on its own, so the
// statement should be idempotent (a re-run overwrites the same keys).
$rows = [];
for ($i = 1; $i <= 1000; $i++) {
    $rows[] = [
        $i,
        'order-' . $i,
        new Decimal(sprintf('%d.%02d', $i, $i % 100)),   // exact money
        new Uuid(sprintf('%08x-0000-4000-8000-%012x', $i, $i)),
        new \DateTimeImmutable("2026-01-01 +{$i} minutes", new \DateTimeZone('UTC')),
        new Bytes(random_bytes(8)),
        ['items' => [$i, $i + 1], 'rush' => $i % 7 === 0],
    ];
}
$n = $db->prepare('INSERT INTO orders (id, ref, total, uuid, at, token, meta) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->executeBatch($rows);
echo "batch inserted {$n} rows\n";

$stmt = $db->prepare('SELECT id, ref, total, uuid, at, token, meta FROM orders WHERE id = ?');
$stmt->execute([7]);
$row = $stmt->fetch();
printf("row 7: total=%s (string) uuid=%s at=%s token=%s meta=%s\n",
    $row['total'], $row['uuid'], $row['at']->format(DATE_ATOM), bin2hex($row['token']), json_encode($row['meta']));

$db->exec('DROP TABLE orders');
$db->close();
