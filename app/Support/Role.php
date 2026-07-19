<?php
declare(strict_types=1);

namespace App\Support;

/**
 * แหล่งอ้างอิงเดียวของตัวระบุ role ผู้ใช้ ค่า string ตรงกับ ENUM ของ users.role ใน
 * database/schema.sql และ role ที่เก็บใน session เลยเอา Role::ADMIN มาใช้แทนค่าตรง ๆ
 * 'admin' ได้ทันที จะเพิ่ม role ก็: เพิ่มค่าคงที่, เพิ่มลงใน assignable() แล้วขยาย ENUM ของ users.role
 *
 * GUEST ไม่ใช่ role ที่เก็บใน DB เป็นแค่ค่าสำรองสำหรับ request ที่ไม่มีผู้ใช้ล็อกอินอยู่
 */
final class Role
{
    public const REQUESTER = 'requester';
    public const MANAGER = 'manager';
    public const TECHNICIAN = 'technician';
    public const ADMIN = 'admin';
    public const GUEST = 'guest';

    /**
     * role ที่มอบหมายได้ เรียงลำดับเดียวกับ ENUM ของ users.role ใช้ขับเคลื่อนฟอร์มผู้ใช้ของ admin,
     * การตรวจสอบ role และรายการข้อมูลอ้างอิง
     *
     * @return list<string>
     */
    public static function assignable(): array
    {
        return [self::REQUESTER, self::MANAGER, self::TECHNICIAN, self::ADMIN];
    }

    public static function isValid(string $role): bool
    {
        return in_array($role, self::assignable(), true);
    }
}
