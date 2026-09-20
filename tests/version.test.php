<?php

declare(strict_types=1);

// The version is defined once, in Skaidb::VERSION. These tests pin what
// must agree with it: the Hello frame the driver sends, the CHANGELOG, and
// composer.json (which must NOT carry a version — Composer takes it from the
// git tag, which publish.yml checks against Skaidb::VERSION).

use Skaidb\Skaidb;

$root = dirname(__DIR__);

test('Skaidb::VERSION is a plain semver triple', function () {
    assert_match('/^\d+\.\d+\.\d+$/', Skaidb::VERSION);
    assert_eq('php', Skaidb::CLIENT_NAME);
});

test('the Hello is built from Skaidb::VERSION, not a literal', function () use ($root) {
    $src = file_get_contents($root . '/src/Skaidb.php');
    $hello = substr($src, strpos($src, 'function sendHello'), 600);
    assert_true(str_contains($hello, 'Skaidb::VERSION'), 'sendHello() must reference Skaidb::VERSION');
    assert_true(str_contains($hello, 'Skaidb::CLIENT_NAME'), 'sendHello() must reference Skaidb::CLIENT_NAME');
    assert_true(preg_match("/'\d+\.\d+\.\d+'/", $hello) !== 1, 'sendHello() must not hard-code a version');
});

test('the Hello frame on the wire carries the package version', function () {
    $srv = (new \SkaidbTests\FakeServer())->start();
    $db = new \Skaidb\Connection('127.0.0.1', $srv->port, 'ada', 'secret');
    $db->close();
    $hello = $srv->hellos()[0];
    assert_eq(['name' => 'php', 'version' => Skaidb::VERSION], ['name' => $hello['name'], 'version' => $hello['version']]);
    $srv->stop();
});

test('composer.json carries no version and the expected identity', function () use ($root) {
    $c = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    assert_true(!isset($c['version']), 'composer.json must not pin a version (the git tag is the version)');
    assert_eq('skaidb/skaidb', $c['name']);
    assert_eq('SSPL-1.0', $c['license']);
    assert_eq('https://github.com/porcupin26/skaidb-php', $c['homepage']);
    assert_eq(['src/Skaidb.php'], $c['autoload']['files']);
});

test('CHANGELOG.md has an entry for Skaidb::VERSION', function () use ($root) {
    $log = file_get_contents($root . '/CHANGELOG.md');
    assert_true(str_contains($log, '## [' . Skaidb::VERSION . ']'), 'CHANGELOG.md lacks ## [' . Skaidb::VERSION . ']');
});
