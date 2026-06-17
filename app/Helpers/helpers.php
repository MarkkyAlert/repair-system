<?php
declare(strict_types=1);

use App\Core\AuthManager;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Request;
use App\Core\Session;

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

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return Csrf::field();
}

function csrf_validate(): void
{
    Csrf::validate($_POST['_csrf'] ?? null);
}

function base_path(string $path = ''): string
{
    return rtrim(BASE_PATH . '/' . ltrim($path, '/'), '/');
}

function public_path(string $path = ''): string
{
    return base_path('public/' . ltrim($path, '/'));
}

function storage_path(string $path = ''): string
{
    return base_path('storage/' . ltrim($path, '/'));
}

function app_base_path(): string
{
    $basePath = (string) config('app.base_path', '');

    return $basePath !== '' ? rtrim($basePath, '/') : '';
}

function url(string $path = ''): string
{
    $baseUrl = rtrim((string) config('app.url', ''), '/');
    $basePath = app_base_path();
    $normalizedPath = $path === '' ? '' : '/' . ltrim($path, '/');

    if ($path === '') {
        if ($baseUrl !== '') {
            return $baseUrl;
        }

        return $basePath !== '' ? $basePath : '/';
    }

    if ($baseUrl !== '') {
        return $baseUrl . $normalizedPath;
    }

    return ($basePath !== '' ? $basePath : '') . $normalizedPath;
}

function asset(string $path): string
{
    $url = url('assets/' . ltrim($path, '/'));
    $publicPath = __DIR__ . '/../../public/assets/' . ltrim($path, '/');
    if (is_file($publicPath)) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . filemtime($publicPath);
    }
    return $url;
}

function branding_logo_url(): ?string
{
    $relative = trim((string) setting('app_logo_path', ''));
    if ($relative === '') {
        return null;
    }

    $relative = ltrim($relative, '/');
    $publicPath = __DIR__ . '/../../public/' . $relative;
    if (is_file($publicPath)) {
        $url = url($relative);
        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . filemtime($publicPath);
    }

    $storagePath = __DIR__ . '/../../' . $relative;
    if (!is_file($storagePath)) {
        return null;
    }

    $url = url('/branding/logo');

    return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . filemtime($storagePath);
}

function request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!$path || $path === '') {
        return '/';
    }

    $basePath = app_base_path();
    if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
        $path = substr($path, strlen($basePath));
    }

    $normalizedPath = '/' . ltrim($path, '/');

    return $normalizedPath !== '/' ? rtrim($normalizedPath, '/') : '/';
}

function is_path(string $path): bool
{
    $current = request_path();
    $target = rtrim($path, '/') ?: '/';

    return $current === $target;
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

function flash(string $key, mixed $value): void
{
    Session::flash($key, $value);
}

function old(string $key, mixed $default = null): mixed
{
    $old = Session::get('_old_input', []);

    return is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default;
}

function pull_old_input(): array
{
    $old = Session::get('_old_input', []);
    clear_old_input();

    return is_array($old) ? $old : [];
}

function with_old_input(array $input): void
{
    Session::put('_old_input', $input);
}

function clear_old_input(): void
{
    Session::forget('_old_input');
}

function flash_message(string $key, mixed $default = null): mixed
{
    return Session::pullFlash($key, $default);
}

function has_flash(string $key): bool
{
    return Session::hasFlash($key);
}

function notification_bell_data(): array
{
    $viewer = auth()->user() ?? [];
    if (!is_array($viewer) || (int) ($viewer['id'] ?? 0) <= 0) {
        return [
            'unreadCount' => 0,
            'items' => [],
        ];
    }

    $service = app(\App\Services\NotificationService::class);

    return $service instanceof \App\Services\NotificationService
        ? $service->getBellData($viewer)
        : ['unreadCount' => 0, 'items' => []];
}

function intended_path(): string
{
    $return = request()?->query['return'] ?? '/dashboard';

    return sanitize_return_path(is_string($return) ? $return : '/dashboard');
}

function sanitize_return_path(string $path): string
{
    $path = trim($path);

    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return '/dashboard';
    }

    $normalizedPath = '/' . ltrim($path, '/');

    return $normalizedPath !== '/' ? rtrim($normalizedPath, '/') : '/';
}

function render_partial(string $view, array $data = []): string
{
    $file = rtrim((string) config('paths.views'), '/') . '/' . trim($view, '/') . '.php';

    if (!is_file($file)) {
        return '';
    }

    extract($data, EXTR_SKIP);

    ob_start();
    include $file;
    return (string) ob_get_clean();
}

function lucide(string $name, string $classes = 'icon'): string
{
    $icons = [
        'wrench' => '<path d="M14.7 6.3a4 4 0 0 0 5 5l-8.4 8.4a2 2 0 1 1-2.8-2.8l8.4-8.4a4 4 0 0 1-5-5z"/><path d="M16 8l3-3"/>',
        'layout-dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'clipboard-list' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
        'qr-code' => '<rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/>',
        'bar-chart-3' => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 1.76l-.12.9a2 2 0 0 1-1.46 1.66l-.87.25a2 2 0 0 1-2.1-.55l-.64-.64a2 2 0 0 0-2.83 0l-.3.3a2 2 0 0 0 0 2.83l.64.64a2 2 0 0 1 .55 2.1l-.25.87A2 2 0 0 1 3.76 14l-.9.12a2 2 0 0 0-1.76 2v.44a2 2 0 0 0 1.76 2l.9.12a2 2 0 0 1 1.66 1.46l.25.87a2 2 0 0 1-.55 2.1l-.64.64a2 2 0 0 0 0 2.83l.3.3a2 2 0 0 0 2.83 0l.64-.64a2 2 0 0 1 2.1-.55l.87.25a2 2 0 0 1 1.46 1.66l.12.9a2 2 0 0 0 2 1.76h.44a2 2 0 0 0 2-1.76l.12-.9a2 2 0 0 1 1.46-1.66l.87-.25a2 2 0 0 1 2.1.55l.64.64a2 2 0 0 0 2.83 0l.3-.3a2 2 0 0 0 0-2.83l-.64-.64a2 2 0 0 1-.55-2.1l.25-.87a2 2 0 0 1 1.66-1.46l.9-.12a2 2 0 0 0 1.76-2v-.44a2 2 0 0 0-1.76-2l-.9-.12a2 2 0 0 1-1.66-1.46l-.25-.87a2 2 0 0 1 .55-2.1l.64-.64a2 2 0 0 0 0-2.83l-.3-.3a2 2 0 0 0-2.83 0l-.64.64a2 2 0 0 1-2.1.55l-.87-.25a2 2 0 0 1-1.46-1.66l-.12-.9a2 2 0 0 0-2-1.76z"/><circle cx="12" cy="12" r="3"/>',
        'bell' => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.674C19.41 13.854 18 12.086 18 8A6 6 0 0 0 6 8c0 4.086-1.41 5.854-2.738 7.326"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
        'moon' => '<path d="M12 3a6 6 0 1 0 9 9 9 9 0 1 1-9-9z"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'check-circle' => '<path d="M22 11.1V12a10 10 0 1 1-5.9-9.1"/><path d="m9 11 3 3L22 4"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'triangle-alert' => '<path d="m21.7 18-8-14a2 2 0 0 0-3.4 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'layers' => '<path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
        'chevrons-left' => '<path d="m11 17-5-5 5-5"/><path d="m18 17-5-5 5-5"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'copy' => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'log-out' => '<path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M21 19V5a2 2 0 0 0-2-2h-6"/>',
        'menu' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h8"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'message-circle' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/>',
        'zap' => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'key-round' => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
        'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'trending-up' => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'pencil' => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'paperclip' => '<path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 17.99 8.84l-8.57 8.57a2 2 0 0 1-2.83-2.83l8.49-8.49"/>',
        'star' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'filter' => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
        'calendar' => '<rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/>',
        'map-pin' => '<path d="M20 10c0 7-8 13-8 13s-8-6-8-13a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'building' => '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'tag' => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8 8a2 2 0 0 0 2.828 0l7.172-7.172a2 2 0 0 0 0-2.828z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'printer' => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/>',
        'refresh-cw' => '<path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
    ];

    $missing = !isset($icons[$name]);

    if ($missing) {
        // Loud fallback: red dashed circle + "?" so missing icons are visible,
        // not a silent empty circle that hides the bug.
        error_log(sprintf('[lucide] missing icon: %s', $name));

        $paths = '<circle cx="12" cy="12" r="10" stroke-dasharray="3 3"/>'
            . '<path d="M9.1 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>'
            . '<path d="M12 17h.01"/>';

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" class="%s lucide-missing" data-missing-icon="%s" role="img" aria-label="missing icon: %s" title="missing icon: %s" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">%s</svg>',
            e($classes),
            e($name),
            e($name),
            e($name),
            $paths
        );
    }

    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" class="%s" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
        e($classes),
        $icons[$name]
    );
}

if (!function_exists('human_date')) {
    /**
     * Format a datetime to a human-friendly Thai string.
     * - "เมื่อสักครู่"           (< 60s)
     * - "5 นาทีที่แล้ว"          (< 60m)
     * - "3 ชม. ที่แล้ว"          (< 24h, same day)
     * - "เมื่อวาน 14:20"
     * - "06 มิ.ย. 2026 14:20"
     * Returns "-" if input is empty/invalid.
     */
    function human_date(?string $value, bool $withTime = true): string
    {
        if ($value === null || trim((string) $value) === '' || $value === '-') {
            return '-';
        }
        $ts = strtotime((string) $value);
        if ($ts === false || $ts <= 0) {
            return '-';
        }

        $now = time();
        $diff = $now - $ts;

        if ($diff >= 0 && $diff < 60) {
            return 'เมื่อสักครู่';
        }
        if ($diff >= 60 && $diff < 3600) {
            return (int) floor($diff / 60) . ' นาทีที่แล้ว';
        }

        $today = strtotime(date('Y-m-d', $now));
        $valueDate = strtotime(date('Y-m-d', $ts));

        if ($diff >= 3600 && $valueDate === $today) {
            return (int) floor($diff / 3600) . ' ชม. ที่แล้ว';
        }
        if ($valueDate === $today - 86400) {
            return 'เมื่อวาน' . ($withTime ? ' ' . date('H:i', $ts) : '');
        }

        $thaiMonths = [
            1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
            'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.',
        ];
        $monthLabel = $thaiMonths[(int) date('n', $ts)] ?? '';
        $year = (int) date('Y', $ts);
        // Convert to Buddhist year if value looks like Gregorian (year < 2500)
        $yearLabel = $year < 2500 ? (string) ($year + 543) : (string) $year;

        $datePart = sprintf('%s %s %s', date('d', $ts), $monthLabel, $yearLabel);
        if ($withTime) {
            $datePart .= ' ' . date('H:i', $ts);
        }
        return $datePart;
    }
}

if (!function_exists('human_date_short')) {
    function human_date_short(?string $value): string
    {
        return human_date($value, false);
    }
}
