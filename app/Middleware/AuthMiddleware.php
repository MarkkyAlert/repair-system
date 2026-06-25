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
            flash('error', 'เซสชันหมดอายุเนื่องจากไม่มีการใช้งานเป็นเวลานาน กรุณาเข้าสู่ระบบใหม่');
            $target = $returnTo ?? request_path();
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

        $target = $returnTo ?? request_path();
        Response::redirect('/login?return=' . rawurlencode($target));
    }
}
