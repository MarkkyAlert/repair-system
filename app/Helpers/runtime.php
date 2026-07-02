<?php
declare(strict_types=1);

use App\Core\AuthManager;
use App\Core\Env;
use App\Core\Request;

function app(?string $id = null): mixed
{
    $container = $GLOBALS['app_container'] ?? null;

    if ($id === null) {
        return $container;
    }

    return $container?->get($id);
}

function config(string $key, mixed $default = null): mixed
{
    $config = app('config') ?? [];
    $segments = explode('.', $key);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

function setting(string $key, mixed $default = null): mixed
{
    static $cache = [];

    if ($key === '') {
        return $default;
    }

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $repository = app(\App\Repositories\SettingsRepository::class);
    if (!$repository instanceof \App\Repositories\SettingsRepository) {
        return $default;
    }

    $row = $repository->getByKey($key);
    if (!is_array($row)) {
        $cache[$key] = $default;
        return $default;
    }

    $value = $row['setting_value'] ?? null;
    $type = (string) ($row['value_type'] ?? 'string');

    $resolved = match ($type) {
        'int' => (int) ($value ?? 0),
        'bool' => in_array(strtolower((string) ($value ?? '0')), ['1', 'true', 'yes', 'on'], true),
        'json' => json_decode((string) ($value ?? ''), true) ?? $default,
        default => $value ?? $default,
    };

    $cache[$key] = $resolved;

    return $resolved;
}

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * True when the exception is a unique-constraint / duplicate-key violation (MySQL 23000 / 1062).
 * Shared by CSV import services to report duplicate rows consistently.
 */
function is_duplicate_key_error(\Throwable $exception): bool
{
    if (!$exception instanceof \PDOException) {
        return false;
    }
    $code = (string) $exception->getCode();
    $message = $exception->getMessage();

    return $code === '23000' || str_contains($message, 'Duplicate entry') || str_contains($message, '1062');
}

/** Format-only validators (callers keep their own required/empty guards and error messages). */
function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function valid_phone_format(string $phone): bool
{
    return preg_match('/^[0-9+\-() .]{4,30}$/', $phone) === 1;
}

/** True for a 64-char lowercase-hex submission/idempotency token. */
function is_submission_token(string $token): bool
{
    return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
}

function request(): ?Request
{
    $resolved = app(Request::class);

    return $resolved instanceof Request ? $resolved : null;
}

function auth(): AuthManager
{
    return app(AuthManager::class);
}
