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
 * Neutralise a CSV/spreadsheet formula-injection cell: prefix a single quote when the first non-whitespace
 * character is = + - or @, so a spreadsheet renders the value as text instead of executing it as a formula.
 * Shared by every export path (AssetService, ReportService) — the single source of truth for this guard.
 */
function sanitize_export_cell(mixed $value): string
{
    $cell = (string) $value;
    $trimmed = ltrim($cell);

    // A NEGATIVE pure number ("-1", "-1.5", "-1,234.0") is a legitimate typed value that a spreadsheet renders as a
    // number, not a formula — so it must stay numeric + byte-equal to the screen (Excel sum/pivot). Only a leading
    // "-" followed by anything else (e.g. "-2+3") is a formula-injection risk. Leading "+ = @" are ALWAYS formula
    // triggers in a spreadsheet (even "+1234" = a formula), so those stay neutralised. (audit F2: negative net.)
    $isNegativeNumber = preg_match('/^-(\d+|\d{1,3}(,\d{3})+)(\.\d+)?$/', $trimmed) === 1;

    if (!$isNegativeNumber && $trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
        return "'" . $cell;
    }

    return $cell;
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

/**
 * True when the error is "table doesn't exist" (MySQL 1146 / SQLSTATE 42S02) — the ONE DB error bootstrap
 * treats as the expected first-run case (schema not loaded yet). Every other DB error must be logged, not
 * silently swallowed and mistaken for "not installed". (error-review-2 F6)
 */
function is_missing_table_error(\Throwable $exception): bool
{
    if (!$exception instanceof \PDOException) {
        return false;
    }
    $code = (string) $exception->getCode();
    $message = $exception->getMessage();

    return $code === '42S02' || str_contains($message, '1146') || str_contains($message, "doesn't exist");
}

/**
 * Log an exception that escaped every controller-level try/catch and reached the entry-point handler.
 * The full class, message, file:line and stack trace go to the server error log (destination set by php.ini —
 * Apache error log / stderr in production) so an unexpected 500 is debuggable. It is never written to the HTTP
 * response — that stays a generic 500 via Response::abort — so nothing sensitive leaks to the client.
 */
function log_uncaught_exception(\Throwable $exception): void
{
    error_log('[req:' . request_id() . '] [uncaught] ' . $exception);
}

/**
 * A short, per-request correlation id — generated once and reused for the whole request. It is emitted as the
 * X-Request-Id response header, shown on the generic 500 page, and prefixed onto every server-log line, so a
 * user's "I hit an error, reference ABC12345" ties straight to the matching log entry. (error-review F8)
 */
function request_id(): string
{
    static $id = null;
    if ($id === null) {
        $id = bin2hex(random_bytes(4)); // 8 hex chars: enough to correlate, carries no PII
    }

    return $id;
}

/**
 * Whether the caller expects a JSON response (an AJAX/fetch call) rather than an HTML page — used so the
 * entry-point 500 handler returns a JSON error (with a reference) instead of an HTML page that would break the
 * client's response.json(). (error-review-2 F3)
 *
 * @param array<string, mixed>|null $server defaults to $_SERVER
 */
function request_wants_json(?array $server = null): bool
{
    $server ??= $_SERVER;
    $accept = strtolower((string) ($server['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($server['HTTP_X_REQUESTED_WITH'] ?? ''));

    return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
}

/**
 * True when the app is configured to leak stack traces to clients: debug mode enabled under a production
 * environment (the entry-point handler rethrows in debug, so a prod error would expose its trace). The web
 * entry point refuses to serve in this state; local dev (APP_ENV=local) is unaffected.
 */
function is_unsafe_production_debug(string $appEnv, bool $appDebug): bool
{
    return $appEnv === 'production' && $appDebug === true;
}

/**
 * Log an exception caught in a best-effort side-effect path (notifications, cleanup, audit) that is
 * deliberately swallowed so it can't fail the main operation. Records the marker, caller-supplied context,
 * and the exception CLASS + message + file:line — enough to debug without a noisy full stack trace.
 *
 * @param array<string, mixed> $context
 */
function log_caught_exception(string $marker, \Throwable $exception, array $context = []): void
{
    $parts = [];
    foreach ($context as $key => $value) {
        $parts[] = $key . '=' . (is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE));
    }
    $suffix = $parts === [] ? '' : ' ' . implode(' ', $parts);

    // Walk to the deepest wrapped cause: services wrap a disk/DB/library failure in a friendly RuntimeException
    // ("สร้างไฟล์ไม่ได้"), so the wrapper alone hides the real reason. Append the root class/message/file:line. (error-review-4 F2)
    $root = $exception;
    while ($root->getPrevious() !== null) {
        $root = $root->getPrevious();
    }
    $cause = $root === $exception
        ? ''
        : sprintf(' <- %s: %s in %s:%d', $root::class, $root->getMessage(), $root->getFile(), $root->getLine());

    error_log(sprintf(
        '[req:%s] [%s]%s %s: %s in %s:%d%s',
        request_id(),
        $marker,
        $suffix,
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $cause
    ));
}

/** Format-only validators (callers keep their own required/empty guards and error messages). */
function is_valid_email(string $email): bool
{
    // Length-bounded to the users.email / *_email column (VARCHAR(190)) so a syntactically valid but
    // over-long address is rejected with a friendly message instead of a raw DB error (strict mode). (F6)
    return (function_exists('mb_strlen') ? mb_strlen($email) : strlen($email)) <= 190
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Return the previewed import rows ONLY when the submitted one-time token matches the batch stored in the
 * session — so opening a second preview in another tab (which replaces the session batch + token) cannot make
 * the first tab's confirm import the second tab's rows. Pure (no session/HTTP), so it is directly testable;
 * the controller owns Session::get/forget. Throws DomainException on a token mismatch or an empty batch.
 * (logic-review R3-F3 / security-review coverage gap)
 *
 * @param mixed $batch The session batch: ['token' => string, 'rows' => array<int, mixed>]
 * @return array<int, mixed>
 */
function verified_import_rows(mixed $batch, string $submittedToken): array
{
    $sessionToken = is_array($batch) ? (string) ($batch['token'] ?? '') : '';
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new \DomainException('การยืนยันนำเข้าไม่ตรงกับไฟล์ที่เพิ่งตรวจสอบ (อาจเปิดไว้หลายแท็บ) กรุณาอัปโหลดและตรวจสอบใหม่');
    }

    $rows = is_array($batch) ? ($batch['rows'] ?? []) : [];
    if (!is_array($rows) || $rows === []) {
        throw new \DomainException('ไม่พบข้อมูลที่ผ่านการตรวจสอบ กรุณาเริ่มกระบวนการนำเข้าใหม่');
    }

    return $rows;
}

/**
 * Reject a value longer than its DB column so the user sees a clear message, not a raw
 * "Data too long" error under MySQL strict mode. No-op for values within the limit. (logic-review F6)
 */
function require_max_length(string $value, int $max, string $label): void
{
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length > $max) {
        throw new \DomainException($label . 'ยาวเกินกำหนด (ไม่เกิน ' . $max . ' ตัวอักษร)');
    }
}

/** Username format: 3–50 chars of a-z, 0-9, dot, dash, underscore. Shared by admin create + CSV import. */
function is_valid_username(string $username): bool
{
    return preg_match('/^[a-z0-9._-]{3,50}$/', $username) === 1;
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
        // strtotime rolls impossible-but-well-formed dates (2026-02-30 → 2026-03-02, 2025-02-29 → 2025-03-01).
        // Reject when the round-trip changes the day, so a bad date is empty, not another day's data.
        if ($timestamp === false || date('Y-m-d', $timestamp) !== $value) {
            return '';
        }

        return $value;
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

/**
 * Parse a form field that must be a whole number. Empty/missing → $default; a non-integer string like
 * "12junk" throws (PHP's (int) cast silently keeps the "12" prefix). Services own input validation, so this
 * throws DomainException for the caller to surface as a friendly message. (round F1 — strict numeric input)
 */
function strict_int(mixed $raw, string $label, int $default = 0): int
{
    if ($raw === null) {
        return $default;
    }
    $value = trim((string) $raw);
    if ($value === '') {
        return $default;
    }
    if (preg_match('/^-?\d+$/', $value) !== 1) {
        throw new \DomainException($label . 'ต้องเป็นตัวเลขจำนวนเต็ม');
    }

    return (int) $value;
}

/** Like strict_int but for a decimal ("abc" throws instead of (float) silently giving 0.0). */
function strict_float(mixed $raw, string $label, float $default = 0.0): float
{
    if ($raw === null) {
        return $default;
    }
    $value = trim((string) $raw);
    if ($value === '') {
        return $default;
    }
    if (!is_numeric($value)) {
        throw new \DomainException($label . 'ต้องเป็นตัวเลข');
    }

    return (float) $value;
}
