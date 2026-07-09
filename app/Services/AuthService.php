<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AuthManager;
use App\Core\Session;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use DomainException;

class AuthService
{
    // Per-IP failed-login cap (password-spraying guard). Deliberately higher than the per-account cap (5) so a
    // shared-NAT office is not locked out by one bad actor, but low enough to blunt spraying one password across
    // many usernames from a single source.
    private const IP_ATTEMPT_CAP = 20;
    private const ATTEMPT_DECAY_SECONDS = 900;

    public function __construct(
        private UserRepository $users,
        private PasswordResetRepository $passwordResets,
        private LoginRateLimiter $rateLimiter,
        private AuthManager $auth,
        private EmailQueueService $emails,
        private RememberMeService $rememberMe,
        private LoginAttemptRepository $loginAttempts,
    ) {
    }

    public function attemptLogin(string $login, string $password, string $ipAddress, bool $remember = false): array
    {
        $login = trim($login);
        $limiterKey = $this->limiterKey($login, $ipAddress);
        $ipKey = $this->ipLimiterKey($ipAddress);
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        // Two nets: the per-(account, IP) cap stops brute-forcing one account; the per-IP cap stops password
        // spraying (one password across many usernames from a single source), which the account cap alone misses.
        if ($this->rateLimiter->tooManyAttempts($limiterKey)
            || $this->rateLimiter->tooManyAttempts($ipKey, self::IP_ATTEMPT_CAP, self::ATTEMPT_DECAY_SECONDS)) {
            $seconds = max(
                $this->rateLimiter->availableIn($limiterKey),
                $this->rateLimiter->availableIn($ipKey, self::ATTEMPT_DECAY_SECONDS)
            );
            $this->logAttempt($login, null, $ipAddress, $userAgent, false, 'rate_limited');
            throw new DomainException('คุณพยายามเข้าสู่ระบบเกินกำหนด กรุณาลองใหม่ในอีก ' . max(1, $seconds) . ' วินาที');
        }

        if ($login === '' || $password === '') {
            $this->rateLimiter->hit($limiterKey);
            $this->logAttempt($login, null, $ipAddress, $userAgent, false, 'empty_credentials');
            throw new DomainException('กรุณากรอกชื่อผู้ใช้หรืออีเมล และรหัสผ่านให้ครบถ้วน');
        }

        $user = $this->users->findByLogin($login);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            $this->rateLimiter->hit($limiterKey);
            $this->rateLimiter->hit($ipKey, self::ATTEMPT_DECAY_SECONDS);
            $this->logAttempt(
                $login,
                $user ? (int) ($user['id'] ?? 0) : null,
                $ipAddress,
                $userAgent,
                false,
                $user ? 'wrong_password' : 'unknown_user'
            );
            throw new DomainException('ชื่อผู้ใช้ อีเมล หรือรหัสผ่านไม่ถูกต้อง');
        }

        if (!(bool) $user['is_active']) {
            $this->rateLimiter->hit($limiterKey);
            $this->rateLimiter->hit($ipKey, self::ATTEMPT_DECAY_SECONDS);
            $this->logAttempt($login, (int) ($user['id'] ?? 0), $ipAddress, $userAgent, false, 'account_disabled');
            // Generic message to prevent user enumeration via account-state probing.
            // Real reason is recorded in login_attempts for admin Security tab.
            throw new DomainException('ชื่อผู้ใช้ อีเมล หรือรหัสผ่านไม่ถูกต้อง');
        }

        $this->rateLimiter->clear($limiterKey);
        Session::regenerate();
        $this->auth->login($user);

        $this->users->updateLastLoginAt((int) ($user['id'] ?? 0));
        $this->logAttempt($login, (int) ($user['id'] ?? 0), $ipAddress, $userAgent, true);

        if ($remember) {
            $this->rememberMe->issueFor((int) ($user['id'] ?? 0));
        }

        return $this->auth->user() ?? [];
    }

    private function logAttempt(string $login, ?int $userId, string $ipAddress, string $userAgent, bool $success, ?string $reason = null): void
    {
        try {
            $this->loginAttempts->record($login, $userId, $ipAddress, $userAgent !== '' ? $userAgent : null, $success, $reason);
        } catch (\Throwable $e) {
            log_caught_exception('login_attempts', $e);
        }
    }

    public function logout(): void
    {
        $this->rememberMe->clearCurrent();
        $this->auth->logout();
        Session::forget('_csrf_token');
        Session::regenerate();
    }

    public function createPasswordReset(string $email): ?string
    {
        $email = trim(strtolower($email));
        if ($email === '') {
            throw new DomainException('กรุณากรอกอีเมล');
        }

        // Throttle reset requests to prevent email bombing / queue abuse.
        // Keyed on email+ip and hit unconditionally so it never reveals whether the email exists.
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $limiterKey = 'pwreset:' . sha1($email . '|' . ($ip !== '' ? $ip : 'unknown'));
        if ($this->rateLimiter->tooManyAttempts($limiterKey, 3, 900)) {
            throw new DomainException('คุณขอรีเซ็ตรหัสผ่านบ่อยเกินไป กรุณาลองใหม่ในภายหลัง');
        }
        $this->rateLimiter->hit($limiterKey, 900);

        $user = $this->users->findByEmail($email);
        if (!$user || !(bool) $user['is_active']) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $this->passwordResets->replaceForEmail($email, $tokenHash, $expiresAt);

        $resetUrl = url('/reset-password/' . rawurlencode($token) . '?email=' . rawurlencode($email));
        $this->emails->queuePasswordResetEmail($user, $resetUrl, $expiresAt);

        return $resetUrl;
    }

    public function resetPassword(string $email, string $token, string $password, string $passwordConfirmation): void
    {
        $email = trim(strtolower($email));
        $token = trim($token);

        if ($email === '' || $token === '') {
            throw new DomainException('ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้อง');
        }

        if ($password === '' || $passwordConfirmation === '') {
            throw new DomainException('กรุณากรอกรหัสผ่านใหม่ให้ครบถ้วน');
        }

        if ($password !== $passwordConfirmation) {
            throw new DomainException('ยืนยันรหัสผ่านไม่ตรงกัน');
        }

        if (strlen($password) < 8) {
            throw new DomainException('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
        }

        $result = $this->passwordResets->resetPasswordUsingToken(
            $email,
            hash('sha256', $token),
            password_hash($password, PASSWORD_BCRYPT)
        );

        if ($result === 'missing') {
            throw new DomainException('ไม่พบคำขอรีเซ็ตรหัสผ่าน');
        }

        if ($result === 'expired') {
            throw new DomainException('ลิงก์รีเซ็ตรหัสผ่านหมดอายุแล้ว');
        }

        if ($result === 'invalid') {
            throw new DomainException('โทเค็นรีเซ็ตรหัสผ่านไม่ถูกต้อง');
        }
    }

    public function changePassword(array $viewer, string $currentPassword, string $password, string $passwordConfirmation): void
    {
        $userId = (int) ($viewer['id'] ?? 0);
        $user = $userId > 0 ? $this->users->findById($userId) : null;
        if ($user === null) {
            throw new DomainException('ไม่พบบัญชีผู้ใช้งาน');
        }

        if ($currentPassword === '' || $password === '' || $passwordConfirmation === '') {
            throw new DomainException('กรุณากรอกรหัสผ่านให้ครบถ้วน');
        }

        if (!password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
            throw new DomainException('รหัสผ่านปัจจุบันไม่ถูกต้อง');
        }

        if ($password !== $passwordConfirmation) {
            throw new DomainException('ยืนยันรหัสผ่านใหม่ไม่ตรงกัน');
        }

        if (strlen($password) < 8) {
            throw new DomainException('รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร');
        }

        if (password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            throw new DomainException('รหัสผ่านใหม่ต้องไม่เหมือนรหัสผ่านปัจจุบัน');
        }

        $this->users->updatePassword($userId, password_hash($password, PASSWORD_BCRYPT));
        // Password change revokes all remember-me sessions on this device.
        $this->rememberMe->clearCurrent();
        Session::regenerate();

        // Re-issue the current session with the new password stamp so this device stays logged in
        // while other sessions of the same user get kicked out on their next AuthMiddleware refresh.
        $freshUser = $this->users->findById($userId);
        if ($freshUser !== null) {
            $this->auth->login($freshUser);
        }
    }

    public function updateProfile(array $viewer, array $input): array
    {
        $userId = (int) ($viewer['id'] ?? 0);
        $user = $userId > 0 ? $this->users->findById($userId) : null;
        if ($user === null) {
            throw new DomainException('ไม่พบบัญชีผู้ใช้งาน');
        }

        $fullName = trim((string) ($input['full_name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $phone = trim((string) ($input['phone'] ?? ''));

        if ($fullName === '' || $email === '') {
            throw new DomainException('กรุณากรอกชื่อ-นามสกุลและอีเมลให้ครบถ้วน');
        }

        if (mb_strlen($fullName) > 200) {
            throw new DomainException('ชื่อ-นามสกุลยาวเกินกำหนด');
        }

        if (!is_valid_email($email)) {
            throw new DomainException('รูปแบบอีเมลไม่ถูกต้อง');
        }

        if ($phone !== '' && !valid_phone_format($phone)) {
            throw new DomainException('รูปแบบเบอร์โทรไม่ถูกต้อง');
        }

        if ($this->users->emailExistsForOtherUser($email, $userId)) {
            throw new DomainException('อีเมลนี้ถูกใช้โดยบัญชีอื่นแล้ว');
        }

        // Require current password confirmation when changing the email
        // (email is the login identifier + password reset destination)
        $currentEmail = strtolower(trim((string) ($user['email'] ?? '')));
        if ($email !== $currentEmail) {
            $currentPassword = (string) ($input['current_password'] ?? '');
            if ($currentPassword === '') {
                throw new DomainException('การเปลี่ยนอีเมลต้องยืนยันด้วยรหัสผ่านปัจจุบัน');
            }
            if (!password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
                throw new DomainException('รหัสผ่านปัจจุบันไม่ถูกต้อง');
            }
        }

        $this->users->updateProfile($userId, [
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
        ]);

        $this->auth->refresh();

        return $this->auth->user() ?? [];
    }

    private function limiterKey(string $login, string $ipAddress): string
    {
        $normalizedLogin = strtolower(trim($login));
        $normalizedIp = $ipAddress !== '' ? $ipAddress : 'unknown';

        return 'login:' . sha1($normalizedLogin . '|' . $normalizedIp);
    }

    private function ipLimiterKey(string $ipAddress): string
    {
        $normalizedIp = $ipAddress !== '' ? $ipAddress : 'unknown';

        return 'login-ip:' . sha1($normalizedIp);
    }
}
