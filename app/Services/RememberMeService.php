<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AuthManager;
use App\Core\Session;
use App\Repositories\UserRepository;

class RememberMeService
{
    public const COOKIE_NAME = 'remember_me';
    public const LIFETIME_SECONDS = 60 * 60 * 24 * 30; // 30 days

    public function __construct(
        private UserRepository $users,
        private AuthManager $auth,
    ) {
    }

    public function issueFor(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $expiresAt = date('Y-m-d H:i:s', time() + self::LIFETIME_SECONDS);
        $this->users->updateRememberToken($userId, $hash, $expiresAt);
        $this->writeCookie($userId, $raw);
    }

    public function clearCurrent(): void
    {
        $cookie = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');
        if ($cookie !== '') {
            $parsed = $this->parseCookie($cookie);
            if ($parsed !== null) {
                $this->users->updateRememberToken($parsed['user_id'], null);
            }
        }

        $this->deleteCookie();
    }

    /**
     * Revoke EVERY remember-me session for a user, regardless of which device is calling. Used on a password
     * change: NULLing the single stored token invalidates any outstanding cookie (its hash can no longer
     * match), then the current device's cookie is dropped so it does not immediately try to restore. Unlike
     * clearCurrent(), this does not depend on the acting device holding a remember cookie — so a password
     * change from a plain (non-remembered) session still kicks out a remembered device elsewhere.
     */
    public function revokeAllForUser(int $userId): void
    {
        $this->users->updateRememberToken($userId, null);
        $this->deleteCookie();
    }

    public function attemptRestore(): bool
    {
        if ($this->auth->check()) {
            return true;
        }

        $cookie = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');
        if ($cookie === '') {
            return false;
        }

        $parsed = $this->parseCookie($cookie);
        if ($parsed === null) {
            $this->deleteCookie();
            return false;
        }

        $user = $this->users->findByRememberToken(hash('sha256', $parsed['raw']));
        if ($user === null || (int) ($user['id'] ?? 0) !== $parsed['user_id']) {
            $this->deleteCookie();
            return false;
        }

        // Restoring a remember-me cookie authenticates the current session, so rotate the id first — same
        // anti-session-fixation step as AuthService::attemptLogin. Without it, a pre-planted (attacker-known)
        // session id gets elevated to an authenticated one on the victim's next protected request.
        Session::regenerate();
        $this->auth->login($user);
        $this->issueFor((int) $user['id']);

        return true;
    }

    private function writeCookie(int $userId, string $raw): void
    {
        $value = $userId . '|' . $raw;
        $expires = time() + self::LIFETIME_SECONDS;
        $sessionConfig = (array) config('session', []);
        $path = (string) ($sessionConfig['path'] ?? '/');
        $secure = (bool) ($sessionConfig['secure'] ?? false);

        setcookie(self::COOKIE_NAME, $value, [
            'expires' => $expires,
            'path' => $path,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $_COOKIE[self::COOKIE_NAME] = $value;
    }

    private function deleteCookie(): void
    {
        $sessionConfig = (array) config('session', []);
        $path = (string) ($sessionConfig['path'] ?? '/');
        $secure = (bool) ($sessionConfig['secure'] ?? false);

        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => $path,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    private function parseCookie(string $cookie): ?array
    {
        if (!str_contains($cookie, '|')) {
            return null;
        }

        [$userIdRaw, $raw] = explode('|', $cookie, 2);
        $userId = (int) $userIdRaw;
        $raw = trim($raw);
        if ($userId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $raw)) {
            return null;
        }

        return ['user_id' => $userId, 'raw' => $raw];
    }
}
