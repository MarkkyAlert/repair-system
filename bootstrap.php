<?php
declare(strict_types=1);

use App\Core\Container;
use App\Core\Env;
use App\Core\Router;
use App\Core\Session;
use App\Core\AuthManager;
use App\Repositories\UserRepository;

const BASE_PATH = __DIR__;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDirectory = BASE_PATH . '/app/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDirectory . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

$vendorAutoload = BASE_PATH . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}

if (!function_exists('app')) {
    require BASE_PATH . '/app/Helpers/helpers.php';
}

Env::load(BASE_PATH . '/.env');

$config = require BASE_PATH . '/config/config.php';
$configuredTimezone = (string) ($config['app']['timezone'] ?? 'Asia/Bangkok');
if (!in_array($configuredTimezone, timezone_identifiers_list(), true)) {
    $configuredTimezone = 'Asia/Bangkok';
}
date_default_timezone_set($configuredTimezone);
Session::start($config['session']);

$container = new Container();
$container->instance('config', $config);
$container->singleton(PDO::class, static function (Container $container): PDO {
    $database = $container->get('config')['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $database['host'],
        $database['port'],
        $database['name'],
        $database['charset']
    );

    $pdo = new PDO($dsn, $database['username'], $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $timezone = (string) ($container->get('config')['app']['timezone'] ?? 'Asia/Bangkok');

    try {
        $stmt = $pdo->query(
            "SELECT setting_value
             FROM system_settings
             WHERE setting_key = 'default_timezone'
             LIMIT 1"
        );
        $settingTimezone = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($settingTimezone !== '' && in_array($settingTimezone, timezone_identifiers_list(), true)) {
            $timezone = $settingTimezone;
        }
    } catch (Throwable $timezoneException) {
        // ONLY "table doesn't exist" is the expected first-run case (system_settings not created yet) — stay
        // silent there. Any other failure (permissions, a dropped connection) must be logged, not silently
        // swallowed and mistaken for "not installed". (error-review-2 F6)
        if (!is_missing_table_error($timezoneException)) {
            error_log('[bootstrap.timezone] ' . $timezoneException);
        }
    }

    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        $timezone = 'Asia/Bangkok';
    }

    date_default_timezone_set($timezone);
    $offset = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('P');
    $pdo->exec('SET time_zone = ' . $pdo->quote($offset));

    return $pdo;
});
$container->singleton(Router::class, static fn (): Router => new Router());
$container->singleton(AuthManager::class, static fn (Container $container): AuthManager => new AuthManager($container->get(UserRepository::class)));

$GLOBALS['app_container'] = $container;

$router = $container->get(Router::class);
$routes = require BASE_PATH . '/config/routes.php';
$routes($router);

return [$container, $router];
