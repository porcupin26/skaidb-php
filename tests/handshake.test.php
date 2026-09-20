<?php

declare(strict_types=1);

// The SCRAM-SHA-256 handshake (PROTOCOL.md §2) against a server that
// computes the real proofs, plus the Hello that follows it.

use Skaidb\Connection;
use Skaidb\Skaidb;
use Skaidb\SkaidbException;
use SkaidbTests\FakeServer;

test('a correct password authenticates and the server signature is verified', function () {
    $srv = (new FakeServer(['password' => 'secret']))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    assert_true($db->isUsable());
    $db->close();
    $reqs = $srv->requests();
    assert_eq('connect', $reqs[0]['op']);
    assert_eq(8, $reqs[1]['op'], 'Hello follows the handshake');
    assert_eq('ada', $reqs[1]['user']);
    $srv->stop();
});

test('a wrong password is denied with the server\'s reason', function () {
    $srv = (new FakeServer(['password' => 'secret']))->start();
    assert_throws(SkaidbException::class, fn () => new Connection('127.0.0.1', $srv->port, 'ada', 'nope'), '/authentication denied: bad password/');
    $srv->stop();
});

test('a server that cannot prove it knows the password is rejected (mutual auth)', function () {
    $srv = (new FakeServer(['password' => 'secret', 'corruptServerSignature' => true]))->start();
    assert_throws(SkaidbException::class, fn () => new Connection('127.0.0.1', $srv->port, 'ada', 'secret'), '/server signature mismatch/');
    $srv->stop();
});

test('an anonymous connection skips the server-signature check', function () {
    // The fake server has password '' for every user; with an empty client
    // password the driver must not verify the signature (§2.3), so even a
    // corrupted one is accepted.
    $srv = (new FakeServer(['password' => '', 'corruptServerSignature' => true]))->start();
    $db = new Connection('127.0.0.1', $srv->port);
    assert_true($db->isUsable());
    $db->close();
    assert_eq('anonymous', $srv->requests()[1]['user']);
    $srv->stop();
});

test('the Hello frame reports client_name php and the package version', function () {
    $srv = (new FakeServer())->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $hellos = $srv->hellos();
    assert_eq(1, count($hellos));
    assert_eq('php', $hellos[0]['name']);
    assert_eq(Skaidb::VERSION, $hellos[0]['version']);
    $db->close();
    $srv->stop();
});

test('an old server answering Hello with Error is ignored', function () {
    $srv = (new FakeServer(['handle' => function (array $req) {
        if ($req['op'] === 8) {
            return \SkaidbTests\F::error('unknown opcode 8');
        }
        return \SkaidbTests\F::rows(['n'], [[1]]);
    }]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    assert_eq([['n' => 1]], $db->query('SELECT 1 AS n')->fetchAll());
    $db->close();
    $srv->stop();
});

test('database at connect issues USE on every dial', function () {
    $srv = (new FakeServer(['handle' => fn () => \SkaidbTests\F::ddl()]))->start();
    $db = new Connection('127.0.0.1', $srv->port, 'ada', 'secret', 'QUORUM', 5.0, 'app "quoted"');
    $ops = array_map(fn ($r) => $r['op'], $srv->requests());
    assert_eq(['connect', 8, 1], $ops);
    assert_eq('USE "app ""quoted"""', $srv->requests()[2]['sql']);
    $db->close();
    $srv->stop();
});

test('no reachable endpoint names every seed tried', function () {
    $e = assert_throws(SkaidbException::class, fn () => new Connection('127.0.0.1', 1, 'a', 'b', 'ONE', 0.5, null, false, null, false, 'skaidb', ['127.0.0.1:1', '127.0.0.1:2']), '/no reachable endpoint in /');
    assert_true(str_contains($e->getMessage(), '127.0.0.1:1') && str_contains($e->getMessage(), '127.0.0.1:2'));
});

test('seeds: the driver lands on the seed that answers', function () {
    $srv = (new FakeServer())->start();
    $db = new Connection('h', 1, 'ada', 'secret', 'QUORUM', 5.0, null, false, null, false, 'skaidb', ['127.0.0.1:1', "127.0.0.1:{$srv->port}"]);
    assert_true($db->isUsable());
    $db->close();
    $srv->stop();
});
