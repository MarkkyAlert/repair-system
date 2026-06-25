<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AuthManager;
use App\Core\Session;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use DomainException;

class AuthService
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetRepository $passwordResets,
        private LoginRateLimiter $rateLimiter,
        private AuthManager $auth,
        private EmailQueueService $emails,
    ) {
    }

    public function attemptLogin(string $login, string $password, string $ipAddress): array
    {
        $login = trim($login);
        $limiterKey = $this->limiterKey($login, $ipAddress);

        if ($this->rateLimiter->tooManyAttempts($limiterKey)) {
            $seconds = $this->rateLimiter->availableIn($limiterKey);
            throw new DomainException('คุณพยายามเข้าสู่ระบบเกินกำหนด กรุณาลองใหม่ในอีก ' . max(1, $seconds) . ' วินาที');
        }

        if ($login === '' || $password === '') {
            $this->rateLimiter->hit($limiterKey);
            throw new DomainException('กรุณากรอกชื่อผู้ใช้หรืออีเมล และรหัสผ่านให้ครบถ้วน');
        }

        $user = $this->users->findByLogin($login);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            $this->rateLimiter->hit($limiterKey);
            throw new DomainException('ชื่อผู้ใช้ อีเมล หรือรหัสผ่านไม่ถูกต้อง');
        }

        if (!(bool) $user['is_active']) {
            $this->rateLimiter->hit($limiterKey);
            throw new DomainException('บัญชีนี้ถูกปิดใช้งาน');
        }

        $this->rateLimiter->clear($limiterKey);
        Session::regenerate();
        $this->auth->login($user);

        return $this->auth->user() ?? [];
    }

    public function logout(): void
    {
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

        $user = $this->users->findByEmail($email);
        if (!$user || !(bool) $user['is_active']) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $this->passwordResets->replaceForEmail($email, $tokenHash, $expiresAt);

        $resetUrl = url('/reset-password?email=' . rawurlencode($email) . '&token=' . rawurlencode($token));
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

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('รูปแบบอีเมลไม่ถูกต้อง');
        }

        if ($phone !== '' && !preg_match('/^[0-9+\-() .]{4,30}$/', $phone)) {
            throw new DomainException('รูปแบบเบอร์โทรไม่ถูกต้อง');
        }

        if ($this->users->emailExistsForOtherUser($email, $userId)) {
            throw new DomainException('อีเมลนี้ถูกใช้โดยบัญชีอื่นแล้ว');
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
}
