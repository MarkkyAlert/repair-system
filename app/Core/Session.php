<?php
declare(strict_types=1);

namespace App\Core;

class Session
{
    public static function start(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name($config['name'] ?? 'repair_system_session');
        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'lifetime' => $config['lifetime'] ?? 7200,
            'path' => $config['path'] ?? '/',
            'secure' => (bool) ($config['secure'] ?? false),
            'httponly' => (bool) ($config['httponly'] ?? true),
            'samesite' => $config['same_site'] ?? 'Strict',
        ]);

        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(bool $deleteOldSession = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($deleteOldSession);
        }
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function hasFlash(string $key): bool
    {
        return isset($_SESSION['_flash']) && array_key_exists($key, $_SESSION['_flash']);
    }

    public static function pullFlash(string $key, mixed $default = null): mixed
    {
        if (!self::hasFlash($key)) {
            return $default;
        }

        $value = $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);

        if (empty($_SESSION['_flash'])) {
            unset($_SESSION['_flash']);
        }

        return $value;
    }

    public static function clear(): void
    {
        $_SESSION = [];
    }
}
