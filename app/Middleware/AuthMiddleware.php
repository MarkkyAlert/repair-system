<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Response;
use App\Core\Session;
use App\Services\RememberMeService;

class AuthMiddleware
{
    public static function handle(?string $returnTo = null): void
    {
        $auth = auth();

        $timeoutMinutes = (int) config('session.idle_timeout_minutes', 60);
        if ($timeoutMinutes > 0 && $auth->check() && Session::isIdleExpired($timeoutMinutes)) {
            $auth->logout();
            Session::regenerate();
            $target = $returnTo ?? request_path();
            // ผู้เรียกแบบ JSON/AJAX (เช่น ตัว poll ฟีดการแจ้งเตือน) ต้องได้ 401 JSON ไม่ใช่ 302 ไปยังหน้า login
            // แบบ HTML — 302+HTML จะทำให้ response.json() พังและทำให้ client ไม่มี reference เหลืออยู่
            if (request_wants_json()) {
                self::denyJson();
            }
            flash('error', 'เซสชันหมดอายุเนื่องจากไม่มีการใช้งานเป็นเวลานาน กรุณาเข้าสู่ระบบใหม่');
            Response::redirect('/login?return=' . rawurlencode($target));
        }

        if (!$auth->check()) {
            $rememberMe = app(RememberMeService::class);
            if ($rememberMe instanceof RememberMeService) {
                $rememberMe->attemptRestore();
            }
        }

        if ($auth->refresh()) {
            Session::touchActivity();
            return;
        }

        if (request_wants_json()) {
            self::denyJson();
        }
        $target = $returnTo ?? request_path();
        Response::redirect('/login?return=' . rawurlencode($target));
    }

    /** JSON 401 สำหรับผู้เรียกแบบ AJAX/fetch ที่ยังไม่ได้ยืนยันตัวตน — พก reference ไว้โยงความล้มเหลวเข้ากับบรรทัด log */
    private static function denyJson(): never
    {
        Response::jsonError('เซสชันหมดอายุหรือยังไม่ได้เข้าสู่ระบบ กรุณาเข้าสู่ระบบใหม่', 401, ['reference' => request_id()]);
    }
}
