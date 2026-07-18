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

        if (!is_string($token) || !is_string($sessionToken) || !hash_equals($sessionToken, $token)) {
            // A bad/expired/forged token is an EXPECTED condition (flash + retry), not an operational failure —
            // DomainException keeps it out of the operational error log.
            throw new DomainException('CSRF token ไม่ถูกต้อง');
        }
    }
}
