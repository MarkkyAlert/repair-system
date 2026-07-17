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
     * DB-free auth probe: reads the session only — no UserRepository, no PDO resolution. The error-page path
     * must render even when the database is unreachable, so it cannot go through auth() (which resolves the
     * repository, whose PDO connects eagerly and would throw during an outage). (ux-review-3 F1)
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
            // Password changed in another session after this one was issued -> force re-login
            $this->logout();
            return false;
        }

        // Refresh cached user data while preserving the original session stamp.
        unset($user['password_hash'], $user['remember_token']);
        Session::put(self::SESSION_KEY, $user);

        return true;
    }
}
