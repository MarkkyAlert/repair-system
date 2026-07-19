<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;

class BrandingController
{
    /**
     * ส่งไฟล์โลโก้องค์กรที่ตั้งค่าไว้แบบ inline (GET) — public endpoint ไม่ต้องล็อกอิน.
     * ผลข้างเคียง: ไม่เขียน DB — resolve path จาก setting('app_logo_path') โดยกัน path traversal (realpath ต้องอยู่ใต้ไดเรกทอรี branding ที่อนุญาต) แล้ว stream ไฟล์ออก; ไม่ตั้งค่า/หาไฟล์ไม่เจอ/หลุดขอบเขต → 404.
     */
    public function showLogo(): void
    {
        $configuredPath = trim((string) setting('app_logo_path', ''));
        if ($configuredPath === '') {
            Response::abort(404, 'ไม่พบโลโก้องค์กร');
        }

        $relativePath = ltrim($configuredPath, '/');
        $candidates = [
            [
                'path' => BASE_PATH . '/' . $relativePath,
                'root' => realpath(BASE_PATH . '/storage/uploads/branding'),
            ],
            [
                'path' => BASE_PATH . '/public/' . $relativePath,
                'root' => realpath(BASE_PATH . '/public/uploads/branding'),
            ],
        ];

        $resolvedPath = null;
        foreach ($candidates as $candidate) {
            $absoluteReal = realpath($candidate['path']);
            $root = $candidate['root'];
            if ($absoluteReal === false || $root === false || !is_file($absoluteReal)) {
                continue;
            }
            if (!str_starts_with($absoluteReal, $root)) {
                continue;
            }
            $resolvedPath = $absoluteReal;
            break;
        }

        if ($resolvedPath === null) {
            Response::abort(404, 'ไม่พบโลโก้องค์กร');
        }

        $content = file_get_contents($resolvedPath);
        if ($content === false) {
            Response::abort(404, 'ไม่พบโลโก้องค์กร');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($resolvedPath) ?: 'application/octet-stream';
        Response::download($content, basename($resolvedPath), $mime, 'inline');
    }
}
