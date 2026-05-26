<?php

declare(strict_types=1);

$tests = [];

function test(string $name, callable $callback): void
{
    global $tests;
    $tests[] = ['name' => $name, 'callback' => $callback];
}

function fail(string $message): never
{
    throw new RuntimeException($message);
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail($message !== '' ? $message : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue(mixed $actual, string $message = ''): void
{
    if ($actual !== true) {
        fail($message !== '' ? $message : 'Expected true, got ' . var_export($actual, true));
    }
}

function assertFalse(mixed $actual, string $message = ''): void
{
    if ($actual !== false) {
        fail($message !== '' ? $message : 'Expected false, got ' . var_export($actual, true));
    }
}

function assertFalseValue(mixed $actual, string $message = ''): void
{
    assertFalse($actual, $message);
}

function assertNullValue(mixed $actual, string $message = ''): void
{
    if ($actual !== null) {
        fail($message !== '' ? $message : 'Expected null, got ' . var_export($actual, true));
    }
}

function assertNotNullValue(mixed $actual, string $message = ''): void
{
    if ($actual === null) {
        fail($message !== '' ? $message : 'Expected a non-null value');
    }
}

function assertStringContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail($message !== '' ? $message : "Expected string to contain {$needle}");
    }
}

function assertMatches(string $pattern, string $actual, string $message = ''): void
{
    if (preg_match($pattern, $actual) !== 1) {
        fail($message !== '' ? $message : "Expected {$actual} to match {$pattern}");
    }
}

foreach (glob(__DIR__ . '/../tests/*_test.php') ?: [] as $file) {
    require $file;
}

$failed = 0;
foreach ($tests as $index => $test) {
    try {
        $test['callback']();
        fwrite(STDOUT, sprintf("[PASS] %s\n", $test['name']));
    } catch (Throwable $throwable) {
        $failed++;
        fwrite(STDERR, sprintf("[FAIL] %s\n%s\n", $test['name'], $throwable->getMessage()));
    }
}

$passed = count($tests) - $failed;
fwrite(STDOUT, sprintf("%d passed, %d failed, %d total\n", $passed, $failed, count($tests)));

exit($failed === 0 ? 0 : 1);
