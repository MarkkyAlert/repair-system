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

/**
 * ที่อยู่เต็มของ PHP CLI สำหรับเอาไปใส่ cron.
 *
 * cron ไม่ได้อ่าน profile ของ shell — PATH มีแค่ /usr/bin:/bin ดังนั้นคำสั่งที่ขึ้นต้นด้วย `php` เฉย ๆ
 * เจอ "command not found" บนโฮสต์ที่ PHP ไม่ได้อยู่ใน path มาตรฐาน (XAMPP/MAMP/PHP หลายเวอร์ชัน) เราจึงต้อง
 * พิมพ์ path เต็มให้ ไม่ใช่ให้ผู้ซื้อเดาเอง.
 *
 * PHP_BINARY ใช้ไม่ได้: ใต้ Apache mod_php มันเป็นค่าว่าง (วัดมาแล้ว) และใต้ php-fpm มันชี้ไปที่ตัว fpm
 * ไม่ใช่ตัว CLI. PHP_BINDIR เป็นค่าคงที่ตอน compile ที่ถูกต้องในทุก SAPI จึงใช้อันนี้เป็นหลัก.
 */
function php_cli_binary(): string
{
    $candidates = [PHP_BINDIR . '/php'];
    if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
        array_unshift($candidates, PHP_BINARY);
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return 'php'; // หาไม่เจอจริง ๆ — ยังดีกว่าพิมพ์ path ที่ไม่มีอยู่จริง
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
 * โลโก้องค์กรในรูป base64 data URI สำหรับฝังลงใน PDF dompdf รันด้วย isRemoteEnabled=false เลยดึงจาก URL
 * ไม่ได้ ต้องใช้ data URI ที่ใช้ได้ไม่ว่าตั้งค่า chroot/remote ยังไง คืน null ถ้ายังไม่ได้ตั้งโลโก้ หรือ
 * ไฟล์หายไป/อ่านไม่ได้
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
    // control byte จะทำให้ PHP ปฏิเสธ header Location ทั้งอัน งานที่สำเร็จแล้วเลยค้างอยู่โดยไม่ถูก redirect ต่อ.
    if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
        return '/dashboard';
    }

    $path = trim($path);

    // กันเหนียว: บาง browser normalize "\" เป็น "/" ตอนตีความ URL เลยแปลงก่อน
    // จะได้ไม่ให้ "/\evil.com" หลุดเป็น protocol-relative "//evil.com" ที่เป็น open redirect
    $path = str_replace('\\', '/', $path);

    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return '/dashboard';
    }

    // ltrim ยุบ leading slash ทั้งหมด (รวม "//" / "/\") เหลือ "/" เดียว → path ภายใน same-origin เสมอ
    $normalizedPath = '/' . ltrim($path, '/');

    return $normalizedPath !== '/' ? rtrim($normalizedPath, '/') : '/';
}
