<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for the user role identifiers. The string values match the users.role ENUM in
 * database/schema.sql and the role stored on the session — so Role::ADMIN is a drop-in for the literal
 * 'admin'. To add a role: add a constant, add it to assignable(), and extend the users.role ENUM.
 *
 * GUEST is not a stored role — it is the fallback for a request with no signed-in user.
 */
final class Role
{
    public const REQUESTER = 'requester';
    public const MANAGER = 'manager';
    public const TECHNICIAN = 'technician';
    public const ADMIN = 'admin';
    public const GUEST = 'guest';

    /**
     * The assignable roles, in the same order as the users.role ENUM. Drives the admin user form,
     * role validation, and the reference-data list.
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
