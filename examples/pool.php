<?php

declare(strict_types=1);

/**
 * A pool for a long-running process: connections are reused between jobs
 * and spread over the cluster's members. With discover: true the pool
 * learns every member from SHOW ENDPOINTS through the one host given,
 * skips members that are resyncing, and ejects ones that fail or turn slow.
 *
 *     php examples/pool.php [host] [port] [user] [password]
 */

require __DIR__ . '/../src/Skaidb.php';

use Skaidb\Connection;
use Skaidb\Pool;

[$host, $port, $user, $password] = [$argv[1] ?? 'localhost', (int) ($argv[2] ?? 7000), $argv[3] ?? 'anonymous', $argv[4] ?? ''];

// Named arguments pass straight through to the Connection constructor, so
// TLS, credentials and the session database apply to every pooled
// connection; the options after maxsize balance the pool.
$pool = new Pool(
    ['host' => $host, 'port' => $port, 'user' => $user, 'password' => $password],
    4,
    loadBalancing: Pool::ROUND_ROBIN,
    discover: true,
);

$pool->withConnection(function (Connection $c) {
    $c->exec('DROP TABLE IF EXISTS jobs');
    $c->exec('CREATE TABLE jobs (PRIMARY KEY (id))');
});

for ($job = 1; $job <= 20; $job++) {
    // Round robin: each job goes to the next member, on that member's idle
    // connection once there is one.
    $pool->withConnection(fn (Connection $c) => $c->prepare('INSERT INTO jobs (id, done) VALUES (?, ?)')->execute([$job, true]));
}

$conn = $pool->acquire();
try {
    echo 'jobs done: ' . $conn->query('SELECT count(*) AS n FROM jobs')->fetchColumn() . "\n";
    $conn->exec('DROP TABLE jobs');
} finally {
    $pool->release($conn);
}

foreach ($pool->members() as $m) { // what the pool sees per member
    printf("%-22s %-11s %6s ms  in use %d  idle %d\n", $m['endpoint'], $m['state'],
        $m['latency_ms'] === null ? '-' : number_format($m['latency_ms'], 2), $m['in_use'], $m['idle']);
}
$pool->close();
