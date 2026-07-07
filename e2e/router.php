<?php
// Router for `php -S` so the app runs under the built-in server for E2E:
//  - existing static files under public/ (assets, uploads) are served directly
//  - everything else is dispatched by the front controller (public/index.php)
// SCRIPT_NAME is forced to /index.php so config/config.php resolves an empty base path
// (the app is served at the server root here, not under /maintenance like in XAMPP).

$publicDir = dirname(__DIR__) . '/public';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$candidate = realpath($publicDir . $uri);

if ($uri !== '/' && $candidate !== false && str_starts_with($candidate, $publicDir . DIRECTORY_SEPARATOR) && is_file($candidate)) {
    return false; // let the built-in server serve the static asset as-is
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require $publicDir . '/index.php';
