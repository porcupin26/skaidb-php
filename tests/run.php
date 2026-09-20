<?php

declare(strict_types=1);

/**
 * The driver's test runner: dependency-free, one process, plain assertions.
 *
 *     php tests/run.php                 # every tests/*.test.php
 *     php tests/run.php codec stream    # only files whose name contains a word
 *
 * A test file calls test('name', fn () => ...) any number of times; a test
 * passes when its closure returns without throwing. The runner exits 1 on
 * any failure so CI sees it.
 */

require __DIR__ . '/../src/Skaidb.php';
require __DIR__ . '/FakeServer.php';

final class AssertionFailed extends \Exception
{
}

/** @var array<int,array{0:string,1:callable}> */
$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

function fail(string $msg): never
{
    throw new AssertionFailed($msg);
}

function assert_true(mixed $cond, string $msg = 'expected true'): void
{
    if ($cond !== true) {
        fail($msg);
    }
}

function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        fail(($msg === '' ? '' : $msg . ': ') . 'expected ' . dump($expected) . ', got ' . dump($actual));
    }
}

/** Loose numeric comparison for floats. */
function assert_close(float $expected, float $actual, float $eps = 1e-9): void
{
    if (abs($expected - $actual) > $eps) {
        fail("expected {$expected} ± {$eps}, got {$actual}");
    }
}

function assert_match(string $regex, string $actual, string $msg = ''): void
{
    if (preg_match($regex, $actual) !== 1) {
        fail(($msg === '' ? '' : $msg . ': ') . dump($actual) . " does not match {$regex}");
    }
}

/** Run $fn and require it to throw $class (optionally with a message matching $regex). */
function assert_throws(string $class, callable $fn, ?string $regex = null): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!$e instanceof $class) {
            fail('expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
        }
        if ($regex !== null && preg_match($regex, $e->getMessage()) !== 1) {
            fail("expected message matching {$regex}, got: " . $e->getMessage());
        }
        return $e;
    }
    fail("expected {$class} to be thrown");
}

function dump(mixed $v): string
{
    if (is_string($v) && preg_match('/[^\x20-\x7e]/', $v) === 1) {
        return 'bytes(' . bin2hex($v) . ')';
    }
    return var_export($v, true);
}

// ---- discover, run, report -------------------------------------------------

$filters = array_slice($argv, 1);
$files = glob(__DIR__ . '/*.test.php') ?: [];
sort($files);
foreach ($files as $file) {
    if ($filters !== []) {
        $keep = false;
        foreach ($filters as $f) {
            if (str_contains(basename($file), $f)) {
                $keep = true;
            }
        }
        if (!$keep) {
            continue;
        }
    }
    require $file;
}

$passed = 0;
$failed = [];
$start = microtime(true);
foreach ($GLOBALS['__tests'] as [$name, $fn]) {
    $t0 = microtime(true);
    try {
        $fn();
        $passed++;
        printf("ok   %-64s %5.0f ms\n", $name, (microtime(true) - $t0) * 1000);
    } catch (\Throwable $e) {
        $failed[] = $name;
        printf("FAIL %s\n     %s: %s\n     at %s:%d\n", $name, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    }
}
printf("\n%d passed, %d failed, %.1f s\n", $passed, count($failed), microtime(true) - $start);
exit($failed === [] ? 0 : 1);
