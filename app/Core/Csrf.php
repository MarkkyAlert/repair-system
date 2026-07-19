<?php
declare(strict_types=1);

namespace App\Core;

use DomainException;

class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function validate(?string $token): void
    {
        $sessionToken = $_SESSION['_csrf_token'] ?? null;

        // เทียบด้วย hash_equals ไม่ใช่ === เพราะมันใช้เวลาเท่ากันเสมอไม่ว่าตรงกี่ตัว
        // ถ้าใช้ === เวลาจะต่างกันตามจำนวนตัวที่ตรง คนร้ายจับเวลาไล่เดา token ทีละตัวได้; ห้ามเปลี่ยนเป็น === / ==
        if (!is_string($token) || !is_string($sessionToken) || !hash_equals($sessionToken, $token)) {
            // token ไม่ตรงเป็นเรื่องเจอได้ปกติ (หมดอายุ เปิดฟอร์มค้างไว้นาน หรือโดนปลอม) แค่บอกผู้ใช้ให้ลองใหม่ก็พอ
            // ไม่ใช่ระบบพัง เลยโยน DomainException ไม่ให้ปนกับ log error จริงของระบบ
            throw new DomainException('CSRF token ไม่ถูกต้อง');
        }
    }
}
