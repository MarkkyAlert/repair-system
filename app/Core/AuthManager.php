<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\UserRepository;

class AuthManager
{
    private const SESSION_KEY = '_auth_user';
    private const PASSWORD_STAMP_KEY = '_auth_password_stamp';

    public function __construct(private UserRepository $users)
    {
    }

    public function check(): bool
    {
        return self::checkSession();
    }

    /**
     * ตรวจสถานะล็อกอินแบบไม่แตะฐานข้อมูล: อ่านจาก session อย่างเดียว — ไม่เรียก UserRepository ไม่ resolve PDO.
     * หน้า error ต้องเรนเดอร์ได้แม้ตอนต่อฐานข้อมูลไม่ได้ จึงห้ามไปผ่าน auth() (ซึ่งจะ resolve repository ที่ PDO
     * ต่อฐานข้อมูลทันที และจะโยน exception ตอนฐานข้อมูลล่ม)
     */
    public static function checkSession(): bool
    {
        return is_array(Session::get(self::SESSION_KEY));
    }

    public function user(): ?array
    {
        $user = Session::get(self::SESSION_KEY);

        return is_array($user) ? $user : null;
    }

    public function id(): ?int
    {
        $user = $this->user();

        return isset($user['id']) ? (int) $user['id'] : null;
    }

    public function login(array $user): void
    {
        $passwordStamp = (string) ($user['password_changed_at'] ?? '');
        // เก็บลง session เฉพาะข้อมูลที่ไม่ลับ — password hash / remember token คงอยู่ใน DB ที่เดียว
        // ไม่ติดไปกับ session (ที่ถูกเขียนลงไฟล์ฝั่ง server และโผล่ตาม debug/dump ได้ง่ายกว่า)
        unset($user['password_hash'], $user['remember_token']);
        Session::put(self::SESSION_KEY, $user);
        Session::put(self::PASSWORD_STAMP_KEY, $passwordStamp);
    }

    public function logout(): void
    {
        Session::forget(self::SESSION_KEY);
        Session::forget(self::PASSWORD_STAMP_KEY);
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function refresh(): bool
    {
        $userId = $this->id();
        if ($userId === null) {
            return false;
        }

        $user = $this->users->findById($userId);
        if ($user === null || !(bool) ($user['is_active'] ?? false)) {
            $this->logout();
            return false;
        }

        $storedStamp = (string) (Session::get(self::PASSWORD_STAMP_KEY) ?? '');
        $currentStamp = (string) ($user['password_changed_at'] ?? '');
        if ($storedStamp !== $currentStamp) {
            // รหัสผ่านถูกเปลี่ยนใน session อื่นหลังจาก session นี้ถูกสร้าง -> บังคับให้ล็อกอินใหม่
            $this->logout();
            return false;
        }

        // อัปเดตข้อมูลผู้ใช้ที่ cache ไว้ให้สดใหม่ โดยยังเก็บ stamp ของ session เดิมไว้
        unset($user['password_hash'], $user['remember_token']);
        Session::put(self::SESSION_KEY, $user);

        return true;
    }
}
