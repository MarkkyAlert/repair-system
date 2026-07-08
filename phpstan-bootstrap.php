<?php
declare(strict_types=1);

/**
 * PHPStan bootstrap — sets up just enough of the runtime environment for static analysis, WITHOUT the side
 * effects of the real bootstrap.php (which builds the DI container and opens a DB connection).
 *
 *  - BASE_PATH: the one global constant app code references (normally defined in bootstrap.php).
 *  - Composer autoloader: PSR-4 for App\ + the "files" autoload (app/Helpers/helpers.php → all global
 *    helpers: config(), url(), valid_roles(), e(), …). Pure function definitions, no DB, no I/O.
 */
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once __DIR__ . '/vendor/autoload.php';
