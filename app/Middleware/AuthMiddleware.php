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
            // A JSON/AJAX caller (e.g. the notification-feed poller) must get a 401 JSON, not a 302 to the HTML
            // login page — a 302+HTML breaks response.json() and leaves the client with no reference.
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

    /** JSON 401 for an unauthenticated AJAX/fetch caller — carries a reference to tie the failure to a log line. */
    private static function denyJson(): never
    {
        Response::jsonError('เซสชันหมดอายุหรือยังไม่ได้เข้าสู่ระบบ กรุณาเข้าสู่ระบบใหม่', 401, ['reference' => request_id()]);
    }
}
