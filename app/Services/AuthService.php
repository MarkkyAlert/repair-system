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
    // เพดานจำนวนครั้ง login ล้มเหลวต่อ IP กัน password-spraying คือยิงรหัสเดียวไล่หลายชื่อผู้ใช้จากแหล่งเดียว. ตั้งสูงกว่าเพดานต่อบัญชี (5)
    // โดยตั้งใจ ออฟฟิศที่หลายเครื่องออกเน็ตผ่าน IP เดียว (shared-NAT) จะได้ไม่โดนล็อกเพราะคนไม่ดีคนเดียว แต่ก็ยังต่ำพอจะสกัด
    // การยิงกระจายแบบนี้ที่เพดานต่อบัญชีจับไม่ได้.
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

        // ตาข่ายสองชั้น: เพดานต่อคู่ (บัญชี, IP) กันการเดารหัสรัว ๆ ใส่บัญชีเดียว (brute-force); เพดานต่อ IP กัน
        // password-spraying คือรหัสเดียวไล่หลายชื่อผู้ใช้จากแหล่งเดียว ที่เพดานต่อบัญชีอย่างเดียวจับไม่ได้.
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
            // นับใส่ bucket ของ IP ด้วย — ไม่งั้นถ้ายิงเปลี่ยนค่า login ไปเรื่อย ๆ พร้อมรหัสว่างจาก IP เดียว
            // มันจะสร้างคู่ (login,IP) ใหม่ทุกครั้ง ไม่เคยชนเพดานไหนเลย แถมยังผลิต key เพิ่มไม่หยุด.
            $this->rateLimiter->hit($limiterKey);
            $this->rateLimiter->hit($ipKey, self::ATTEMPT_DECAY_SECONDS);
            $this->logAttempt($login, null, $ipAddress, $userAgent, false, 'empty_credentials');
            throw new DomainException('กรุณากรอกชื่อผู้ใช้หรืออีเมล และรหัสผ่านให้ครบถ้วน');
        }

        $user = $this->users->findByLogin($login);
        // รัน bcrypt verify ทุกครั้งแม้ไม่เจอ user เพื่อให้เวลาตอบกลับพอ ๆ กันทั้งกรณีเจอและไม่เจอบัญชี — ไม่งั้นคนร้ายจับเวลาดูได้ว่า
        // username ไหนมีอยู่จริง (user enumeration). login ที่ไม่รู้จักจะ verify กับ hash ทิ้งที่ได้ false เสมอ แทนที่จะลัดข้าม
        // งาน bcrypt ที่กินเวลา ~100ms. ข้อความ error กลาง ๆ ข้างล่างปิดบังการมีอยู่ของบัญชีในเนื้อหาที่ตอบกลับอยู่แล้ว
        // ตรงนี้แค่ปิดช่องที่ยังรั่วผ่าน "เวลา" ที่ตอบ (timing side-channel) ซึ่งอยู่เบื้องหลัง.
        $storedHash = ($user && isset($user['password_hash']))
            ? (string) $user['password_hash']
            : $this->dummyPasswordHash();
        $passwordValid = password_verify($password, $storedHash);
        if (!$user || !$passwordValid) {
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
            // ใช้ข้อความกลาง ๆ กันคนหยั่งสถานะบัญชีเพื่อไล่เดาว่ามีบัญชีอยู่จริงไหม (user enumeration).
            // เหตุผลจริงถูกบันทึกไว้ใน login_attempts สำหรับแท็บ Security ของ admin.
            throw new DomainException('ชื่อผู้ใช้ อีเมล หรือรหัสผ่านไม่ถูกต้อง');
        }

        $this->rateLimiter->clear($limiterKey);
        // หมุน session id ใหม่ตอนยกระดับสิทธิ์ กัน session fixation — คนร้ายแอบฝัง session id ที่ตัวเองรู้ค่าไว้ก่อน
        // แล้วรอสวมรอยหลังเหยื่อ login ทับ id เดิม. จุดคู่กันอยู่ที่ RememberMeService::attemptRestore
        // ล็อกไว้ด้วย session_fixation_test
        Session::regenerate();
        $this->auth->login($user);

        $this->users->updateLastLoginAt((int) ($user['id'] ?? 0));
        $this->logAttempt($login, (int) ($user['id'] ?? 0), $ipAddress, $userAgent, true);

        if ($remember) {
            $this->rememberMe->issueFor((int) ($user['id'] ?? 0));
        }

        return $this->auth->user() ?? [];
    }

    /**
     * hash bcrypt แบบใช้แล้วทิ้ง มีไว้ให้เวลา bcrypt ตอน login ด้วย login ที่ไม่รู้จักใกล้เคียงกับตอน login จริง
     * (ดู attemptLogin) เวลาตอบกลับจะได้ไม่เผยว่าบัญชีมีอยู่จริงไหม. คำนวณครั้งเดียวต่อ process ที่ค่า cost
     * เริ่มต้นปัจจุบัน ใช้ PASSWORD_BCRYPT ตัวเดียวกับที่ changePassword/resetPassword ใช้ hash cost จึงเท่ากัน
     * เสมอแม้ค่าเริ่มต้นจะเปลี่ยนไป. การ verify กับมันจะได้ false เสมอ.
     */
    private function dummyPasswordHash(): string
    {
        static $hash = null;
        if ($hash === null) {
            $hash = password_hash('timing-equalizer-not-a-real-credential', PASSWORD_BCRYPT);
        }

        return $hash;
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
        // ของที่ผูกกับตัวตนเดิมต้องใช้ต่อไม่ได้ทั้งหมด: ทิ้ง CSRF token เก่า + หมุน session id ใหม่ —
        // ไม่ให้ id/token ของ session ที่เคย login แล้วถูกหยิบไปใช้ซ้ำหลัง logout
        Session::forget('_csrf_token');
        Session::regenerate();
    }

    public function createPasswordReset(string $email): ?string
    {
        $email = trim(strtolower($email));
        if ($email === '') {
            throw new DomainException('กรุณากรอกอีเมล');
        }

        // จำกัดจำนวนคำขอรีเซ็ต 3 มิติ นับทุกมิติเสมอไม่มีเงื่อนไข จะได้ไม่มีมิติไหนเผยว่าอีเมลนั้น
        // มีอยู่จริงไหม: คู่ (email,IP) 3 ครั้ง/15 นาที, ต่อ IP อย่างเดียว 10 ครั้ง/15 นาที (กันแหล่งเดียวยิงกระจาย
        // ไปหลายอีเมล), และต่ออีเมลอย่างเดียว 5 ครั้ง/1 ชม. (กันการถล่มอีเมลกล่องเดียวจากหลาย
        // IP).
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $normalizedIp = $ip !== '' ? $ip : 'unknown';
        $pairKey = 'pwreset:' . sha1($email . '|' . $normalizedIp);
        $ipKey = 'pwreset-ip:' . sha1($normalizedIp);
        $emailKey = 'pwreset-email:' . sha1($email);
        if ($this->rateLimiter->tooManyAttempts($pairKey, 3, 900)
            || $this->rateLimiter->tooManyAttempts($ipKey, 10, 900)
            || $this->rateLimiter->tooManyAttempts($emailKey, 5, 3600)) {
            throw new DomainException('คุณขอรีเซ็ตรหัสผ่านบ่อยเกินไป กรุณาลองใหม่ในภายหลัง');
        }
        $this->rateLimiter->hit($pairKey, 900);
        $this->rateLimiter->hit($ipKey, 900);
        $this->rateLimiter->hit($emailKey, 3600);

        $user = $this->users->findByEmail($email);
        if (!$user || !(bool) $user['is_active']) {
            // กันการไล่เดาบัญชี: อีเมลที่ไม่รู้จักหรือถูกปิดใช้งานจะไม่สร้าง token และไม่ส่งอีเมล — การมีอยู่ของบัญชี
            // จึงไม่รั่วผ่านแถว reset, อีเมลที่ส่ง หรือผลข้างเคียงในคิว. ข้อความที่ตอบกลับเป็นข้อความกลาง ๆ
            // เหมือนกันทั้งสองทาง (AuthController) และ rate-limiter ข้างบนก็นับแบบไม่มีเงื่อนไข. ส่วนต่างของเวลา
            // ที่ยังเหลือ (บัญชีที่ active เขียน DB เพิ่มอีก ~2 ครั้ง) เป็นค่าตกค้างที่ยอมรับได้และถูกจำกัดอัตราแล้ว —
            // สัญญาณจากการเขียน DB นั้นเล็กและมีสัญญาณรบกวน ต่างจาก bcrypt ของ login. ความเท่ากันของผลข้างเคียง
            // ถูกล็อกไว้ด้วย auth_test 'password reset creates a token+email ONLY for active'.
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

        // updatePassword เซ็ต remember token เป็น NULL ไปในตัว (UPDATE เดียวกัน) การเพิกถอนฝั่ง DB จะได้ไม่ค้าง
        // ถ้าการเรียกทีหลังล้มเหลว. จากนั้น revokeAllForUser ลบ cookie ของเครื่องนี้ (แล้วเซ็ต NULL ซ้ำเผื่อไว้).
        // รวมกันแล้ว ทุกเครื่องที่จำ login ไว้ รวมถึงเครื่องที่กำลังใช้อยู่ จะถูกเตะออกหมด.
        $this->users->updatePassword($userId, password_hash($password, PASSWORD_BCRYPT));
        $this->rememberMe->revokeAllForUser($userId);
        Session::regenerate();

        // ออก session ปัจจุบันใหม่พร้อมตราประทับรหัสผ่านใหม่ เครื่องนี้จะได้ยัง login อยู่
        // ส่วน session อื่นของผู้ใช้คนเดียวกันจะถูกเตะออกตอน AuthMiddleware refresh รอบถัดไป.
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

        require_max_length($fullName, 150, 'ชื่อ-นามสกุล'); // users.full_name เป็น VARCHAR(150) (เดิม 200 → เกิด DB error)

        if (!is_valid_email($email)) {
            throw new DomainException('รูปแบบอีเมลไม่ถูกต้อง');
        }

        if ($phone !== '' && !valid_phone_format($phone)) {
            throw new DomainException('รูปแบบเบอร์โทรไม่ถูกต้อง');
        }

        if ($this->users->emailExistsForOtherUser($email, $userId)) {
            throw new DomainException('อีเมลนี้ถูกใช้โดยบัญชีอื่นแล้ว');
        }

        // ต้องยืนยันด้วยรหัสผ่านปัจจุบันเมื่อจะเปลี่ยนอีเมล
        // (อีเมลเป็นทั้งตัวระบุตัวตนสำหรับ login และปลายทางสำหรับรีเซ็ตรหัสผ่าน)
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
            'original_version' => strict_int($input['original_version'] ?? null, 'เวอร์ชันข้อมูล'), // optimistic lock (กันการเขียนทับข้อมูลที่ถูกแก้ไปแล้ว)
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
