<?php
declare(strict_types=1);

// Connectivity + readiness probe for the test database. Used by .githooks/pre-push so a missing/empty
// test DB gives a clear "run tests/setup_test_db.sh" message instead of a cryptic PDO error mid-suite.
// Exits 0 if the test DB is reachable AND has the schema loaded; 1 with a reason on stderr otherwise.
//
// Mirrors how the suite resolves the DB: tests/run.php sets DB_NAME from TEST_DB_NAME (default
// repair_system_test), and config/config.php reads DB_HOST/PORT/USERNAME/PASSWORD/CHARSET via Env::get.

define('BASE_PATH', dirname(__DIR__)); // config/config.php references BASE_PATH (normally set by bootstrap)

require BASE_PATH . '/vendor/autoload.php';
App\Core\Env::load(BASE_PATH . '/.env'); // pick up local DB creds overrides, same as bootstrap does
$_ENV['DB_NAME'] = getenv('TEST_DB_NAME') ?: 'repair_system_test'; // force the TEST db regardless of any .env

/** @var array<string, mixed> $config */
$config = require BASE_PATH . '/config/config.php';
$db = $config['db'];

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']),
        (string) $db['username'],
        (string) $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );

    // Connected — make sure the schema is actually loaded (empty DB would fail the suite confusingly).
    $pdo->query('SELECT 1 FROM tickets LIMIT 1');

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] %s\n", $db['name'], $e->getMessage()));
    exit(1);
}
