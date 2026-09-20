# Getting started

## Requirements

- PHP 8.1 or newer with the bundled `hash` extension (and `openssl` for TLS).
- A reachable skaidb node (default client port **7000**). Any node of a
  cluster will do: skaidb is leaderless.

The driver is one file with no dependencies.

## Install

With Composer, add the GitHub repository (the package is not on Packagist
yet) and require `^1.0`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/porcupin26/skaidb-php" }
    ],
    "require": { "skaidb/skaidb": "^1.0" }
}
```

```sh
composer require skaidb/skaidb:^1.0
```

Then `require 'vendor/autoload.php'`. Without Composer, take `src/Skaidb.php`
from a clone or the release zip and `require` it directly.

## Connect and query

```php
<?php
require 'vendor/autoload.php';

use Skaidb\Connection;
use Skaidb\SkaidbException;

try {
    $db = new Connection(
        host: 'localhost', port: 7000,
        user: 'skaidb', password: 'secret',
        database: 'app',                  // optional: selects the session database
    );

    $db->exec('CREATE TABLE IF NOT EXISTS users (PRIMARY KEY (id))');

    $stmt = $db->prepare('INSERT INTO users (id, name, tags) VALUES (?, ?, ?)');
    $stmt->execute([1, 'Ada', ['admin']]);

    $stmt = $db->prepare('SELECT id, name, tags FROM users WHERE id = ?');
    $stmt->execute([1]);
    print_r($stmt->fetch());      // ['id' => 1, 'name' => 'Ada', 'tags' => ['admin']]

    $db->close();
} catch (SkaidbException $e) {
    fwrite(STDERR, "skaidb: {$e->getMessage()}\n");
    exit(1);
}
```

Placeholders are `?`. With parameters the statement is prepared on the
server and the values are sent typed — that is how the array above gets
through; it has no SQL literal form.

## Anonymous connections

A server with authentication disabled accepts user `anonymous` (the
default) with an empty password. The SCRAM handshake still runs; the server
verifies nothing, and the driver skips mutual authentication when the
password is empty.

## TLS

A server with `client_tls = required` refuses plaintext, so one of these is
needed there:

```php
new Connection(..., tlsCa: '/etc/skaidb/skai-ca.crt');   // verify against your CA — recommended
new Connection(..., tls: true);                          // verify against the system trust store
new Connection(..., tlsInsecure: true);                  // encrypt only, no verification — development
```

`tlsServerName` (default `skaidb`) is the SNI name and the name the
certificate is verified against. See [TLS](tls.md).

## Several nodes

```php
new Connection(seeds: ['db1:7000', 'db2:7000', 'db3:7000'], user: 'app', password: $pw);
```

The seeds are shuffled and tried until one connects. The same walk runs
when a connection is lost later: the next statement re-dials,
re-authenticates, re-selects the database and runs. The statement that was in
flight when the connection died throws and is never retried by the driver
(it may have executed).

## Many rows at once

```php
$db->prepare('INSERT INTO users (id, name) VALUES (?, ?)')
   ->executeBatch([[1, 'Ada'], [2, 'Linus']]);
```

One round-trip for all rows; each row autocommits on its own.

## Big results

```php
foreach ($db->stream('SELECT id, name FROM users') as $row) {
    // one row at a time, one chunk in memory
}
```

Read the [abandon rule](streaming.md#the-abandon-rule) before stopping a
stream early: the connection is busy until the generator finishes.

## Long-running processes

Statements on one connection run one at a time. A worker that serves many
jobs keeps a [pool](pooling.md):

```php
$pool = new Skaidb\Pool(['user' => 'app', 'password' => $pw, 'seeds' => ['db1:7000', 'db2:7000']], 8);
$n = $pool->withConnection(fn ($c) => $c->query('SELECT count(*) AS n FROM users')->fetchColumn());
```

## Next

- [API reference](api.md)
- [Types](types.md)
- [Examples](../examples/): `basic.php`, `batch.php`, `stream.php`, `tls.php`, `pool.php`, `subscribe.php`
