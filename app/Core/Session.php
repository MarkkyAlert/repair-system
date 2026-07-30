<?php
declare(strict_types=1);

namespace App\Core;

class Session
{
    public static function start(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name($config['name'] ?? 'repair_system_session');
        // strict mode: ให้ PHP ปฏิเสธ session id ที่ระบบไม่เคยออกให้ — กัน session fixation ที่คนร้ายยัด id ที่ตัวเองรู้ค่าไว้ให้เหยื่อใช้ (คู่กับ regenerate() ตอน login)
        ini_set('session.use_strict_mode', '1');

        // อายุข้อมูล session ฝั่งเซิร์ฟเวอร์ (gc_maxlifetime) ต้องไม่สั้นกว่าที่ระบบตั้งใจให้ session อยู่ได้จริง.
        // ค่า default ของ PHP คือ 1440 วินาที (24 นาที) — สั้นกว่าทั้งอายุ cookie (7200 = 2 ชม.) และนโยบายหมดเวลา
        // เมื่อไม่ใช้งาน (idle 60 นาที). บนโฮสต์แชร์ที่ตัวเก็บขยะ session ทำงานตาม gc_maxlifetime กลาง ผู้ใช้ที่พัก
        // งานราว 25–30 นาทีอาจถูกลบ session แล้วเด้งออก ทั้งที่ตั้งใจให้อยู่ได้ถึง 60 นาที. ตั้งเป็นค่ามากสุดของทั้งสอง
        // (อย่างน้อยเท่า default เดิม) ให้ PHP ไม่ลบก่อนกำหนด แล้วปล่อยให้ตรรกะ idle ระดับแอป (AuthMiddleware +
        // Session::isIdleExpired) เป็นตัวตัดสินการหมดเวลาตามเดิม — สองชั้นนี้ทำงานร่วมกัน ไม่ทับกัน.
        $cookieLifetime = (int) ($config['lifetime'] ?? 7200);
        $idleSeconds = (int) ($config['idle_timeout_minutes'] ?? 0) * 60;
        ini_set('session.gc_maxlifetime', (string) max($cookieLifetime, $idleSeconds, 1440));

        session_set_cookie_params([
            'lifetime' => $config['lifetime'] ?? 7200,
            'path' => $config['path'] ?? '/',
            'secure' => (bool) ($config['secure'] ?? false),
            'httponly' => (bool) ($config['httponly'] ?? true),
            'samesite' => $config['same_site'] ?? 'Strict',
        ]);

        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(bool $deleteOldSession = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($deleteOldSession);
        }
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function hasFlash(string $key): bool
    {
        return isset($_SESSION['_flash']) && array_key_exists($key, $_SESSION['_flash']);
    }

    public static function pullFlash(string $key, mixed $default = null): mixed
    {
        if (!self::hasFlash($key)) {
            return $default;
        }

        $value = $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);

        if (empty($_SESSION['_flash'])) {
            unset($_SESSION['_flash']);
        }

        return $value;
    }

    public static function clear(): void
    {
        $_SESSION = [];
    }

    public static function isIdleExpired(int $timeoutMinutes): bool
    {
        if ($timeoutMinutes <= 0) {
            return false;
        }

        $lastActivity = (int) ($_SESSION['_last_activity'] ?? 0);
        // ยังไม่เคยบันทึกเวลาใช้งาน (session เพิ่ง login ยังไม่ทัน touchActivity) → ถือว่ายังไม่หมดเวลา ไม่งั้น request แรกหลัง login โดนเตะออกทันที
        if ($lastActivity <= 0) {
            return false;
        }

        return $lastActivity + ($timeoutMinutes * 60) < time();
    }

    public static function touchActivity(): void
    {
        $_SESSION['_last_activity'] = time();
    }
}
