<?php
declare(strict_types=1);

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

/**
 * The org logo as a base64 data URI for embedding in PDFs — dompdf runs with isRemoteEnabled=false, so a URL
 * can't be fetched; a data URI works regardless of chroot/remote settings. Returns null if no logo is set or
 * the file is missing/unreadable. (ux-refactor F2)
 */
function branding_logo_data_uri(): ?string
{
    $relative = ltrim(trim((string) setting('app_logo_path', '')), '/');
    if ($relative === '') {
        return null;
    }

    foreach ([__DIR__ . '/../../public/' . $relative, __DIR__ . '/../../' . $relative] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            return null;
        }
        $mime = match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }

    return null;
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

function intended_path(): string
{
    $return = request()?->query['return'] ?? '/dashboard';

    return sanitize_return_path(is_string($return) ? $return : '/dashboard');
}

function sanitize_return_path(string $path): string
{
    $path = trim($path);

    // Hardening: บาง browser normalize "\" เป็น "/" ตอนตีความ URL — แปลงก่อน
    // เพื่อกัน "/\evil.com" หลุดเป็น protocol-relative "//evil.com" (open redirect).
    $path = str_replace('\\', '/', $path);

    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return '/dashboard';
    }

    // ltrim ยุบ leading slash ทั้งหมด (รวม "//" / "/\") เหลือ "/" เดียว → path ภายใน same-origin เสมอ
    $normalizedPath = '/' . ltrim($path, '/');

    return $normalizedPath !== '/' ? rtrim($normalizedPath, '/') : '/';
}
