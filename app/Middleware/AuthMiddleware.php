<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Response;
use App\Core\Session;
use App\Services\RememberMeService;

class AuthMiddleware
{
    /**
     * @param bool $touchActivity false = คำขอนี้เป็น background poll ไม่ใช่การใช้งานของคน จึงไม่ต่ออายุ session.
     *                            หน้าเว็บยิง poll เองทุก 20–30 วินาที (กระดิ่งแจ้งเตือน/สถานะ ticket/คอมเมนต์)
     *                            ถ้านับ poll เป็น activity ด้วย session จะถูกต่ออายุราว 120 ครั้งต่อชั่วโมง แปลว่า
     *                            idle timeout 60 นาทีไม่มีวันทำงานตราบใดที่แท็บยังเปิดค้างไว้ — ซึ่งคือสถานการณ์
     *                            "ลุกจากเครื่องโดยไม่ล็อกหน้าจอ" ที่ timeout ตั้งใจจะกันพอดี. poll ยังต้องยืนยันตัวตน
     *                            ตามปกติ และยังโดน idle timeout เตะออกเหมือนเดิม แค่ไม่เลื่อนนาฬิกาให้.
     */
    public static function handle(?string $returnTo = null, bool $touchActivity = true): void
    {
        $auth = auth();

        $timeoutMinutes = (int) config('session.idle_timeout_minutes', 60);
        if ($timeoutMinutes > 0 && $auth->check() && Session::isIdleExpired($timeoutMinutes)) {
            // เพิกถอน remember-me ด้วย ไม่ใช่แค่ล้าง session: ถ้าปล่อย cookie "จำ 30 วัน" ไว้ คำขอถัดไปจะวิ่งเข้า
            // attemptRestore ด้านล่างแล้วล็อกอินกลับให้เองโดยไม่ถามรหัส → idle timeout ไร้ผลกับคนที่ติ๊กจำ (ภัย
            // เครื่องถูกทิ้งไว้). ต้องล้าง token ทั้งฝั่ง cookie และ DB ให้ตรงกับ logout ปกติ (AuthService::logout)
            $rememberMe = app(RememberMeService::class);
            if ($rememberMe instanceof RememberMeService) {
                $rememberMe->clearCurrent();
            }
            $auth->logout();
            Session::regenerate();
            $target = $returnTo ?? request_path();
            // ผู้เรียกแบบ JSON/AJAX (เช่น ตัว poll ฟีดการแจ้งเตือน) ต้องได้ 401 JSON ไม่ใช่ 302 ไปหน้า login
            // แบบ HTML เพราะ 302+HTML จะทำให้ response.json() พัง แล้ว client ก็ไม่เหลือ reference ไว้เลย
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
            if ($touchActivity) {
                Session::touchActivity();
            }

            return;
        }

        if (request_wants_json()) {
            self::denyJson();
        }
        $target = $returnTo ?? request_path();
        Response::redirect('/login?return=' . rawurlencode($target));
    }

    /** JSON 401 สำหรับผู้เรียกแบบ AJAX/fetch ที่ยังไม่ได้ยืนยันตัวตน พก reference ไว้โยงความล้มเหลวเข้ากับบรรทัด log */
    private static function denyJson(): never
    {
        Response::jsonError('เซสชันหมดอายุหรือยังไม่ได้เข้าสู่ระบบ กรุณาเข้าสู่ระบบใหม่', 401, ['reference' => request_id()]);
    }
}
