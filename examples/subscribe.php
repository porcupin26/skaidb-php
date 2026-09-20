<?php

declare(strict_types=1);

/**
 * Follow a stream: yield its events forever, resuming from a saved position.
 *
 *     php examples/subscribe.php [host] [port] [user] [password] [stream] [after]
 *
 * The stream must exist (CREATE STREAM <name> ON <table> ...). `id` is the
 * position, an opaque string that sorts in log order; save the last one and
 * pass it as the sixth argument to resume exactly there.
 */

require __DIR__ . '/../src/Skaidb.php';

use Skaidb\Connection;

[$host, $port, $user, $password] = [$argv[1] ?? 'localhost', (int) ($argv[2] ?? 7000), $argv[3] ?? 'anonymous', $argv[4] ?? ''];
$stream = $argv[5] ?? 'big_orders';
$after = $argv[6] ?? null;

$db = new Connection($host, $port, $user, $password);
$seen = 0;
foreach ($db->subscribe($stream, $after, 0.5) as $ev) {
    printf("%s %-6s %s %s\n", $ev['id'], $ev['op'], json_encode($ev['k']), json_encode($ev['doc']));
    $after = $ev['id'];      // persist this to resume later
    if (++$seen >= 10) {
        break;               // stop after ten events for the example's sake
    }
}
echo "resume with after={$after}\n";
$db->close();
