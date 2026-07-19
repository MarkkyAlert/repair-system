<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AuthManager;
use App\Core\Session;
use App\Repositories\UserRepository;

class RememberMeService
{
    public const COOKIE_NAME = 'remember_me';
    public const LIFETIME_SECONDS = 60 * 60 * 24 * 30; // 30 วัน

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
                // ล้างด้วย HASH ของ token ไม่ใช่ user_id ที่ cookie อ้าง — cookie ปลอมที่พก id ของ user คนอื่น
                // แต่ใช้ token มั่ว ๆ จะ hash ออกมาไม่ตรงกับอะไรเลย จึงเพิกถอน remember-me ของ user คนนั้นไม่ได้.
                // มีแต่เจ้าของ cookie ตัวจริง (ที่ hash ตรงกับ row ที่เก็บไว้) เท่านั้นที่ล้างมันได้.
                $this->users->clearRememberTokenByHash(hash('sha256', $parsed['raw']));
            }
        }

        $this->deleteCookie();
    }

    /**
     * เพิกถอน remember-me ทุก session ของ user คนหนึ่ง ไม่ว่าจะเรียกจากอุปกรณ์ไหน. ใช้ตอนเปลี่ยนรหัสผ่าน:
     * การ NULL token ตัวเดียวที่เก็บไว้จะทำให้ cookie ที่ยังค้างอยู่ทุกอันใช้ไม่ได้ (hash ของมันจะไม่มีทาง
     * ตรงอีก), จากนั้นลบ cookie ของอุปกรณ์ปัจจุบันทิ้งเพื่อไม่ให้มันพยายาม restore ทันที. ต่างจาก
     * clearCurrent() ตรงที่วิธีนี้ไม่ต้องพึ่งว่าอุปกรณ์ที่กำลังทำต้องถือ remember cookie อยู่ — ดังนั้นการ
     * เปลี่ยนรหัสผ่านจาก session ธรรมดา (ที่ไม่ได้ remember) ก็ยังเตะอุปกรณ์ที่ remember ไว้ที่อื่นออกได้.
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

        // การ restore remember-me cookie เท่ากับยืนยันตัวตนให้ session ปัจจุบัน จึงต้องหมุน (rotate) id ก่อน — เป็น
        // ขั้นตอนกัน session fixation ตัวเดียวกับใน AuthService::attemptLogin. ถ้าไม่ทำ, session id ที่ถูกวางไว้ล่วงหน้า
        // (ที่ผู้โจมตีรู้ค่า) จะถูกยกระดับเป็น session ที่ยืนยันตัวตนแล้วในคำขอ protected ครั้งถัดไปของเหยื่อ.
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
