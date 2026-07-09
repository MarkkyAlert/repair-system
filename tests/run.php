<?php
declare(strict_types=1);

// Run the whole suite against an isolated test database — never the dev DB.
// (Env::get reads $_ENV first, and there is no .env to override it.)
$_ENV['DB_NAME'] = getenv('TEST_DB_NAME') ?: 'repair_system_test';

// Entry point: load app autoload (App\ + helpers) + harness, run every tests/cases/*.php.
require __DIR__ . '/../vendor/autoload.php';
// Boot the DI container before any output so bootstrap's Session::start() doesn't warn in CLI.
[$GLOBALS['__container']] = require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/harness.php';
// Shared SAPI-function shadows (is_uploaded_file), declared once before any case → order-independent.
require __DIR__ . '/shadow_functions.php';
// Query counter (CountingPdo + count_queries) for deterministic N+1 regression guards.
require __DIR__ . '/counting_pdo.php';

foreach (glob(__DIR__ . '/cases/*.php') as $case) {
    require $case;
}

$pass = 0;
$failures = [];
foreach ($GLOBALS['__tests'] as [$name, $fn]) {
    try {
        $fn();
        $pass++;
        echo '.';
    } catch (Throwable $e) {
        $failures[] = [$name, $e->getMessage()];
        echo 'F';
    }
}

echo "\n\n";
foreach ($failures as [$name, $message]) {
    echo "FAIL: $name\n  $message\n\n";
}

$total = count($GLOBALS['__tests']);
$failed = count($failures);
echo ($failed === 0 ? "\u{2705} ALL PASS" : "\u{274C} $failed FAILED") . " — $pass passed / $total tests\n";
exit($failed === 0 ? 0 : 1);
