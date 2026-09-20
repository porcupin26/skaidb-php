<?php

declare(strict_types=1);

/**
 * End-to-end test against a REAL skaidb node. Skipped (exit 0) unless
 * SKAIDB_HOST is set, so CI without a server passes; run it by hand:
 *
 *     SKAIDB_HOST=127.0.0.1 SKAIDB_PORT=7000 SKAIDB_USER=admin SKAIDB_PASSWORD=pw \
 *         php tests/live/live.php
 *
 * Optional: SKAIDB_DATABASE (session database), SKAIDB_PREFIX (table-name
 * prefix, default php_). It creates tables under the prefix and drops them.
 */

require __DIR__ . '/../../src/Skaidb.php';

use Skaidb\Bytes;
use Skaidb\Connection;
use Skaidb\Decimal;
use Skaidb\Skaidb;
use Skaidb\SkaidbException;
use Skaidb\Uuid;

$host = getenv('SKAIDB_HOST');
if ($host === false || $host === '') {
    echo "live test skipped: SKAIDB_HOST is not set\n";
    exit(0);
}
$port = (int) (getenv('SKAIDB_PORT') ?: 7000);
$user = getenv('SKAIDB_USER') ?: 'anonymous';
$password = getenv('SKAIDB_PASSWORD') ?: '';
$database = getenv('SKAIDB_DATABASE') ?: null;
$prefix = getenv('SKAIDB_PREFIX') ?: 'php_';
$t = $prefix . 'types';
$big = $prefix . 'big';

$checks = 0;
function check(bool $cond, string $what): void
{
    global $checks;
    $checks++;
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$what}\n");
        exit(1);
    }
    echo "ok   {$what}\n";
}

/** Documents come back with their keys in the server's (sorted) order. */
function ksorted(mixed $v): mixed
{
    if (is_array($v)) {
        if (!array_is_list($v)) {
            ksort($v);
        }
        return array_map('ksorted', $v);
    }
    return $v;
}

$db = new Connection($host, $port, $user, $password, 'QUORUM', 15.0, $database);
check($db->isUsable(), "connect + SCRAM as {$user}@{$host}:{$port}");

function cleanup(Connection $db, string ...$tables): void
{
    foreach ($tables as $tbl) {
        try {
            $db->exec("DROP TABLE IF EXISTS {$tbl}");
        } catch (SkaidbException $e) {
            // best effort
        }
    }
}
cleanup($db, $t, $big);

try {
    // ---- DDL --------------------------------------------------------------
    check($db->exec("CREATE TABLE {$t} (PRIMARY KEY (id))") === 0, "CREATE TABLE {$t}");

    // ---- prepared INSERT with every type ----------------------------------
    $ts = new \DateTimeImmutable('2024-02-29T23:59:59.123Z');
    $uuid = new Uuid('123e4567-e89b-12d3-a456-426614174000');
    $ins = $db->prepare("INSERT INTO {$t} (id, s, f, b, n, arr, doc, dec, u, ts, raw) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([1, "Ada O'Brien 🚀", 2.5, true, null, [1, 'two', [3.0, null]], ['k' => 'v', 'n' => [1, 2], 'd' => ['x' => false]],
                   new Decimal('12345.6789'), $uuid, $ts, new Bytes("\x00\x01\xfe\xff")]);
    check($ins->rowCount() === 1, 'prepared INSERT with 11 typed parameters, rowCount 1');
    $ins->execute([2, 'second', -0.125, false, null, [], [], new Decimal('-0.005'), new Uuid('00000000-0000-0000-0000-000000000001'), new \DateTimeImmutable('1969-12-31T23:59:59Z'), new Bytes('')]);
    $ins->execute([3, 'third', 1e300, true, null, ['a'], ['z' => 1], new Decimal('0'), $uuid, $ts, new Bytes('x')]);

    // ---- SELECT with a bound WHERE and ORDER BY, reading every type back ---
    $sel = $db->prepare("SELECT id, s, f, b, n, arr, doc, dec, u, ts, raw FROM {$t} WHERE id >= ? ORDER BY id");
    $sel->execute([1]);
    $rows = $sel->fetchAll();
    check(count($rows) === 3 && array_column($rows, 'id') === [1, 2, 3], 'SELECT with bound WHERE + ORDER BY returns 3 ordered rows');
    $r = $rows[0];
    check($r['id'] === 1, 'int round-trips');
    check($r['s'] === "Ada O'Brien 🚀", 'string round-trips (quote, UTF-8)');
    check($r['f'] === 2.5, 'float round-trips');
    check($r['b'] === true && $rows[1]['b'] === false, 'bool round-trips');
    check(array_key_exists('n', $r) && $r['n'] === null, 'null round-trips');
    check($r['arr'] === [1, 'two', [3.0, null]], 'array round-trips (nested, typed)');
    check(ksorted($r['doc']) === ksorted(['k' => 'v', 'n' => [1, 2], 'd' => ['x' => false]]), 'document round-trips (nested; the server stores keys sorted)');
    check($r['dec'] === '12345.6789' && $rows[1]['dec'] === '-0.005' && $rows[2]['dec'] === '0', 'decimal round-trips exactly as a string');
    check($r['u'] === '123e4567-e89b-12d3-a456-426614174000', 'uuid round-trips as canonical string');
    check($r['ts'] instanceof \DateTimeImmutable && $r['ts']->format('Y-m-d\TH:i:s.v') === '2024-02-29T23:59:59.123', 'timestamp round-trips at ms precision');
    check($rows[1]['ts']->format('U') === '-1', 'pre-epoch timestamp round-trips');
    check($r['raw'] === "\x00\x01\xfe\xff" && $rows[1]['raw'] === '', 'bytes round-trip (binary-safe)');
    check($rows[1]['arr'] === [] && $rows[1]['doc'] === [], 'empty array and empty document round-trip');

    // ---- UPDATE row count ---------------------------------------------------
    $upd = $db->prepare("UPDATE {$t} SET s = ? WHERE id = ?");
    $upd->execute(['renamed', 2]);
    check($upd->rowCount() === 1, 'UPDATE reports rowCount 1');
    $sel->execute([2]);
    check($sel->fetch()['s'] === 'renamed', 'UPDATE took effect');
    check($db->exec("UPDATE {$t} SET f = 0.0 WHERE id >= 1") === 3, 'exec(UPDATE) returns 3 affected');

    // ---- batch insert ---------------------------------------------------------
    check($db->exec("CREATE TABLE {$big} (PRIMARY KEY (id))") === 0, "CREATE TABLE {$big}");
    $batch = [];
    for ($i = 1; $i <= 2500; $i++) {
        $batch[] = [$i, 'row' . $i, $i / 100];
    }
    $n = $db->prepare("INSERT INTO {$big} (id, name, v) VALUES (?, ?, ?)")->executeBatch($batch);
    check($n === 2500, 'batch INSERT of 2500 rows in one round-trip reports 2500');
    check($db->query("SELECT count(*) AS n FROM {$big}")->fetchColumn() === 2500, 'count(*) sees 2500 rows');

    // ---- streamed large result ------------------------------------------------
    $count = 0;
    $sum = 0.0;
    $last = 0;
    foreach ($db->stream("SELECT id, name, v FROM {$big} ORDER BY id") as $row) {
        $count++;
        $sum += $row['v'];
        $last = $row['id'];
    }
    check($count === 2500 && $last === 2500, 'stream() yields all 2500 rows in order');
    check(abs($sum - 2500 * 2501 / 200) < 1e-6, 'streamed values are intact');
    foreach ($db->stream("SELECT id FROM {$big} ORDER BY id") as $row) {
        if ($row['id'] >= 10) {
            break; // abandon: the driver drains the rest
        }
    }
    check($db->isUsable() && $db->query("SELECT count(*) AS n FROM {$big}")->fetchColumn() === 2500, 'abandoned stream leaves the connection usable');

    // ---- errors -----------------------------------------------------------------
    try {
        $db->query("SELECT nope FROM {$prefix}does_not_exist");
        check(false, 'a statement error throws');
    } catch (SkaidbException $e) {
        check($e->getMessage() !== '', 'statement error surfaces as SkaidbException: ' . $e->getMessage());
    }
    check($db->isUsable() && $db->query('SELECT 1 AS one')->fetchColumn() === 1, 'connection usable after a statement error');
    try {
        $db->prepare("INSERT INTO {$t} (id) VALUES (?)")->execute([]);
        check(false, 'missing parameters throw');
    } catch (SkaidbException $e) {
        check(true, 'missing parameters throw: ' . $e->getMessage());
    }

    // ---- client identification ----------------------------------------------------
    sleep(1);
    $drivers = $db->query('SELECT client_name, client_version FROM drivers')->fetchAll();
    $mine = array_values(array_filter($drivers, fn ($d) => $d['client_name'] === 'php'));
    check($mine !== [], 'drivers table lists a php client');
    $versions = array_unique(array_column($mine, 'client_version'));
    check(in_array(Skaidb::VERSION, $versions, true), 'drivers table shows client_version ' . Skaidb::VERSION . ' (rows: ' . json_encode($mine) . ')');
    echo 'drivers rows: ' . json_encode($mine) . "\n";
} finally {
    cleanup($db, $t, $big);
    echo "dropped {$t}, {$big}\n";
    $db->close();
}

echo "\nlive test passed: {$checks} checks against {$host}:{$port}\n";
