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
     * เช็คสถานะล็อกอินโดยไม่แตะฐานข้อมูล: อ่านจาก session อย่างเดียว ไม่เรียก UserRepository ไม่ resolve PDO
     * หน้า error ต้องเรนเดอร์ได้แม้ตอนต่อฐานข้อมูลไม่ได้ เลยห้ามไปผ่าน auth() เพราะ auth() จะ resolve repository
     * ที่เปิด PDO ต่อฐานข้อมูลทันที แล้วโยน exception ตอนฐานข้อมูลล่ม
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
        // เก็บลง session เฉพาะข้อมูลที่ไม่ลับ ส่วน password hash กับ remember token ให้อยู่ใน DB ที่เดียว
        // ไม่ต้องติดไปกับ session ที่เขียนลงไฟล์ฝั่ง server แล้วหลุดตาม debug/dump ได้ง่ายกว่า
        unset($user['password_hash'], $user['remember_token']);
        Session::put(self::SESSION_KEY, $user);
        Session::put(self::PASSWORD_STAMP_KEY, $passwordStamp);
        // ประทับเวลาใช้งานใหม่ทุกครั้งที่ล็อกอิน — ไม่งั้น _last_activity เก่าจาก session ก่อนหน้า (ในเบราว์เซอร์เดียวกัน/
        // เครื่องใช้ร่วม) จะทำให้ idle-timeout เด้งออกทันทีหลัง login แล้ววนไม่จบ (idle check ใน AuthMiddleware รันก่อน touchActivity)
        Session::touchActivity();
    }

    public function logout(): void
    {
        Session::forget(self::SESSION_KEY);
        Session::forget(self::PASSWORD_STAMP_KEY);
        Session::forget('_last_activity');
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
            // รหัสผ่านโดนเปลี่ยนจาก session อื่นหลังสร้าง session นี้ -> บังคับล็อกอินใหม่
            $this->logout();
            return false;
        }

        // อัปเดตข้อมูลผู้ใช้ที่ cache ไว้ให้สดใหม่ โดยยังเก็บ stamp ของ session เดิมไว้
        unset($user['password_hash'], $user['remember_token']);
        Session::put(self::SESSION_KEY, $user);

        return true;
    }
}
