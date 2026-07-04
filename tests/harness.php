<?php
declare(strict_types=1);

/**
 * Minimal zero-dependency test harness (composer/PHPUnit not installed in this env).
 * Register cases with test('name', fn); assert with the assert_* helpers (throw on failure).
 * Run: php tests/run.php  —  structured so it ports to PHPUnit later with little change.
 */

$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

function assert_same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            "assert_same failed" . ($msg !== '' ? " — $msg" : '') .
            "\n    expected: " . var_export($expected, true) .
            "\n    actual:   " . var_export($actual, true)
        );
    }
}

function assert_true(mixed $value, string $msg = ''): void
{
    if ($value !== true) {
        throw new RuntimeException("assert_true failed" . ($msg !== '' ? " — $msg" : '') . " (got " . var_export($value, true) . ")");
    }
}

function assert_false(mixed $value, string $msg = ''): void
{
    if ($value !== false) {
        throw new RuntimeException("assert_false failed" . ($msg !== '' ? " — $msg" : '') . " (got " . var_export($value, true) . ")");
    }
}

function assert_count(int $expected, array $arr, string $msg = ''): void
{
    if (count($arr) !== $expected) {
        throw new RuntimeException("assert_count failed" . ($msg !== '' ? " — $msg" : '') . " (expected $expected, got " . count($arr) . ")");
    }
}

function assert_contains_str(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException("assert_contains_str failed" . ($msg !== '' ? " — $msg" : '') . " ('$needle' not in '" . mb_substr($haystack, 0, 120) . "')");
    }
}

/** Invoke a private/protected method for unit-testing pure logic in isolation. */
function call_private(object $obj, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);

    return $ref->invoke($obj, ...$args);
}
