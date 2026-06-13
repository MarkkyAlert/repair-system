<?php
declare(strict_types=1);

namespace App\Core;

class AuthManager
{
    private const SESSION_KEY = '_auth_user';

    public function check(): bool
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
        unset($user['password_hash'], $user['remember_token']);
        Session::put(self::SESSION_KEY, $user);
    }

    public function logout(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    public function guest(): bool
    {
        return !$this->check();
    }
}
