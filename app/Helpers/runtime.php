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
        'bool' => truthy_input($value ?? '0'),
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

/** Parse a truthy form/setting input: true for "1"/"true"/"yes"/"on" (case-insensitive). */
function truthy_input(mixed $value): bool
{
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Offset-based pagination: clamp $page into [1, totalPages] and compute the SQL offset.
 * @return array{page:int,offset:int,totalPages:int}
 */
function paginate(int $page, int $perPage, int $total): array
{
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));

    return ['page' => $page, 'offset' => ($page - 1) * $perPage, 'totalPages' => $totalPages];
}

/**
 * Normalize a from/to date-range filter. Validates each side (YYYY-MM-DD → '' if invalid),
 * swaps when reversed so `from` always precedes `to`, then derives inclusive day-bound
 * datetimes (from = 00:00:00, to = 23:59:59). Single source of truth shared by the
 * dashboard and report filters so the two can't drift apart.
 *
 * @return array{from_date:string,to_date:string,from_datetime:string,to_datetime:string}
 */
function normalize_date_range(string $fromRaw, string $toRaw): array
{
    $normalizeDay = static function (string $value): string {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return '';
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    };

    $fromDate = $normalizeDay($fromRaw);
    $toDate = $normalizeDay($toRaw);

    // Reversed range → swap the days first, so datetimes are always derived from the correct end.
    if ($fromDate !== '' && $toDate !== '' && strcmp($fromDate, $toDate) > 0) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }

    return [
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'from_datetime' => $fromDate !== '' ? $fromDate . ' 00:00:00' : '',
        'to_datetime' => $toDate !== '' ? $toDate . ' 23:59:59' : '',
    ];
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
