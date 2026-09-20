<?php

declare(strict_types=1);

/**
 * Connect, create a table, insert with typed parameters, query, clean up.
 *
 *     php examples/basic.php [host] [port] [user] [password]
 *
 * Omit user/password for a server with auth disabled (anonymous).
 */

require __DIR__ . '/../src/Skaidb.php';

use Skaidb\Connection;
use Skaidb\SkaidbException;

$host = $argv[1] ?? 'localhost';
$port = isset($argv[2]) ? (int) $argv[2] : 7000;
$user = $argv[3] ?? 'anonymous';
$password = $argv[4] ?? '';

try {
    $db = new Connection($host, $port, $user, $password);
    echo "connected to {$host}:{$port}\n";

    $db->exec('DROP TABLE IF EXISTS people');
    $db->exec('CREATE TABLE people (PRIMARY KEY (id))');

    // Prepared on the server; the values travel typed (the tags array has
    // no SQL literal form, and the apostrophe needs no escaping).
    $insert = $db->prepare('INSERT INTO people (id, name, age, tags) VALUES (?, ?, ?, ?)');
    $insert->execute([1, 'Ada Lovelace', 36, ['math', 'engines']]);
    $insert->execute([2, 'Alan Turing', 41, ['math']]);
    $insert->execute([3, "Grace O'Brien", 28, []]);

    $stmt = $db->prepare('SELECT id, name, age, tags FROM people WHERE age >= ? ORDER BY id');
    $stmt->execute([30]);
    echo 'columns: ' . implode(', ', $stmt->columns()) . "\n";
    foreach ($stmt->fetchAll() as $p) {
        printf("  #%d  %-16s age %d  tags %s\n", $p['id'], $p['name'], $p['age'], json_encode($p['tags']));
    }

    $update = $db->prepare('UPDATE people SET age = ? WHERE id = ?');
    $update->execute([29, 3]);
    echo 'updated rows: ' . $update->rowCount() . "\n";

    echo 'total people: ' . $db->query('SELECT count(*) AS n FROM people')->fetchColumn() . "\n";

    $db->exec('DROP TABLE people');
    $db->close();
    echo "done\n";
} catch (SkaidbException $e) {
    fwrite(STDERR, 'skaidb error: ' . $e->getMessage() . "\n");
    exit(1);
}
