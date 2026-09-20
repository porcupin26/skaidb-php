<?php

declare(strict_types=1);

/**
 * A pool for a long-running process: connections are reused between jobs.
 *
 *     php examples/pool.php [host] [port] [user] [password]
 */

require __DIR__ . '/../src/Skaidb.php';

use Skaidb\Connection;
use Skaidb\Pool;

[$host, $port, $user, $password] = [$argv[1] ?? 'localhost', (int) ($argv[2] ?? 7000), $argv[3] ?? 'anonymous', $argv[4] ?? ''];

// Named arguments pass straight through to the Connection constructor, so
// seeds, TLS and the session database all apply to every pooled connection.
$pool = new Pool(['host' => $host, 'port' => $port, 'user' => $user, 'password' => $password], 4);

$pool->withConnection(function (Connection $c) {
    $c->exec('DROP TABLE IF EXISTS jobs');
    $c->exec('CREATE TABLE jobs (PRIMARY KEY (id))');
});

for ($job = 1; $job <= 20; $job++) {
    // The same idle connection serves every iteration; a burst would open more.
    $pool->withConnection(fn (Connection $c) => $c->prepare('INSERT INTO jobs (id, done) VALUES (?, ?)')->execute([$job, true]));
}

$conn = $pool->acquire();
try {
    echo 'jobs done: ' . $conn->query('SELECT count(*) AS n FROM jobs')->fetchColumn() . "\n";
    $conn->exec('DROP TABLE jobs');
} finally {
    $pool->release($conn);
}
$pool->close();
