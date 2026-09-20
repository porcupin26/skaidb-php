# Pooling

Statements on one `Connection` run one at a time, and opening a connection
costs a TCP (or TLS) handshake plus SCRAM. A `Pool` keeps connections
between uses:

```php
use Skaidb\Connection;
use Skaidb\Pool;

$pool = new Pool(['user' => 'app', 'password' => $pw, 'database' => 'app',
                  'seeds' => ['db1:7000', 'db2:7000'], 'tlsCa' => '/etc/skaidb/skai-ca.crt'], 8);

$n = $pool->withConnection(fn (Connection $c) => $c->query('SELECT count(*) AS n FROM t')->fetchColumn());

$conn = $pool->acquire();
try {
    $conn->prepare('INSERT INTO t (id) VALUES (?)')->execute([1]);
} finally {
    $pool->release($conn);
}

$pool->close();
```

- The first argument is the list of `Connection` constructor arguments,
  positional or named; every pooled connection is built from it, so seeds,
  TLS and the session database all pass through.
- `$maxsize` (default 10) bounds the connections kept **idle**, not the
  number checked out: a burst opens extras and the surplus is closed on
  release.
- `acquire()` returns an idle connection only if it is still usable and
  otherwise opens a new one. A connection the server closed while it sat
  idle looks fine locally; its next statement throws once and re-dials
  after that, like any other connection.
- `release()` closes a connection instead of pooling it when it is broken,
  still streaming, or the pool is full or closed.
- `withConnection()` releases in a `finally`, whatever the callback does.

## When a pool helps

PHP is share-nothing per request: a plain web request opens a connection,
runs its statements and exits, and a pool there lives for exactly one
request. The pool pays off in long-running processes — a worker consuming
a queue, a CLI job, a ReactPHP/Swoole-style server — where the same process
serves many units of work.
