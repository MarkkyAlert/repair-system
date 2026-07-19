<?php
declare(strict_types=1);

namespace App\Services;

class LoginRateLimiter
{
    // key ใดที่ไม่ถูกแตะนานกว่านี้ ถือว่าเลยทุกช่วง decay ที่ใช้อยู่ (login/reset 900 วินาที, guest
    // 600 วินาที) จึงลบทิ้งได้ตอนเขียนครั้งถัดไป — ไม่งั้น key ต่อคู่ (login|IP) ที่ไม่ซ้ำจะสะสม
    // ไปเรื่อย ๆ ไม่รู้จบ (มีแค่ clear() เท่านั้นที่เคยลบออก) และไฟล์ JSON จะโตไม่มีขอบเขต.
    private const GLOBAL_MAX_AGE_SECONDS = 3600;

    private string $filePath;

    public function __construct(?string $filePath = null)
    {
        $this->filePath = $filePath ?? storage_path('logs/login_rate_limits.json');
    }

    public function tooManyAttempts(string $key, int $maxAttempts = 5, int $decaySeconds = 900): bool
    {
        $attempts = $this->prune($this->read()[$key] ?? [], $decaySeconds);

        return count($attempts) >= $maxAttempts;
    }

    public function hit(string $key, int $decaySeconds = 900): void
    {
        $this->mutate(function (array $payload) use ($key, $decaySeconds): array {
            $attempts = $this->prune($payload[$key] ?? [], $decaySeconds);
            $attempts[] = time();
            $payload[$key] = $attempts;
            return $payload;
        });
    }

    public function clear(string $key): void
    {
        $this->mutate(function (array $payload) use ($key): array {
            unset($payload[$key]);
            return $payload;
        });
    }

    public function availableIn(string $key, int $decaySeconds = 900): int
    {
        $attempts = $this->prune($this->read()[$key] ?? [], $decaySeconds);
        if ($attempts === []) {
            return 0;
        }

        $oldestAttempt = min($attempts);
        $availableIn = ($oldestAttempt + $decaySeconds) - time();

        return $availableIn > 0 ? $availableIn : 0;
    }

    private function read(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }

        $handle = fopen($this->filePath, 'rb');
        if ($handle === false) {
            // การอ่านล้มเหลวหมายความว่าสถานะ throttle (การจำกัดอัตรา) ใช้ไม่ได้ — limiter จะถอยไปเป็น fail-open (ปล่อยผ่านเมื่อพัง) ดังนั้น
            // เหตุผลต้องมองเห็นได้ (เดิมคืนเป็นสถานะว่างเงียบ ๆ).
            $this->logDiag('cannot open ' . $this->filePath . ' for read — throttle state unavailable (fail-open)');
            return [];
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                $this->logDiag('shared lock failed on ' . $this->filePath . ' — throttle state unavailable (fail-open)');
                return [];
            }
            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if ($contents === false) {
            // ตัว read stream เองล้มเหลว (I/O error) — คนละกรณีกับไฟล์ที่ว่าง/ไม่มี. ถ้าถือว่าเป็น
            // ว่างจะทำให้ attempt ที่บันทึกไว้หายไปเงียบ ๆ จึงต้องแสดงให้เห็น.
            $this->logDiag('read failed on ' . $this->filePath . ' — throttle state unavailable (fail-open)');
            return [];
        }

        return $this->decode($contents);
    }

    private function mutate(callable $callback): void
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        // RISK MAP: ถ้า storage เขียนไม่ได้ การ throttle login จะเสื่อมสภาพเงียบ ๆ; ตอน deploy ต้องตรวจสิทธิ์การเขียนให้แน่ใจ.
        $handle = fopen($this->filePath, 'c+');
        if ($handle === false) {
            $this->logDiag('cannot open ' . $this->filePath . ' — login throttle DISABLED');
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                $this->logDiag('flock failed on ' . $this->filePath . ' — login throttle DISABLED');
                return;
            }

            rewind($handle);
            $existing = stream_get_contents($handle);
            if ($existing === false) {
                // อ่านสถานะปัจจุบันก่อนเขียนทับไม่ได้ — attempt ที่บันทึกไว้จะหายไป.
                // log ไว้แล้วถือว่าว่าง (จะซ่อมตัวเองเมื่อเขียนครั้งนี้).
                $this->logDiag('read-before-write failed on ' . $this->filePath . ' — prior throttle state may be lost');
                $existing = '';
            }
            $payload = $this->decode($existing);
            $payload = $callback($payload);
            $payload = $this->dropStaleKeys($payload);

            rewind($handle);
            // การเขียนที่ล้มเหลว/ไม่ครบจะทำให้ไฟล์เสีย → การอ่านครั้งถัดไป decode ได้เป็นว่าง (fail-open) โดยไม่มี
            // ร่องรอย. เช็คทุกขั้นตอนการเขียน เพื่อให้ปัญหาดิสก์เต็ม/quota ถูกแสดงออกมา ไม่ใช่ถูกกลืน.
            if (ftruncate($handle, 0) === false) {
                $this->logDiag('ftruncate failed on ' . $this->filePath . ' — throttle state may be left corrupt');
            }
            $encoded = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $written = fwrite($handle, $encoded);
            if ($written === false || $written < strlen($encoded)) {
                // การเขียนที่สั้นเกิน (SHORT write — เขียนได้น้อยไบต์กว่า payload เช่นดิสก์เต็ม) ไม่ได้คืน `false` แต่ก็ยังทิ้ง
                // JSON ที่ถูกตัด/เสียไว้ — ต้องเช็คจำนวนไบต์ ไม่ใช่แค่ false.
                $this->logDiag('fwrite incomplete on ' . $this->filePath . ' — wrote ' . var_export($written, true) . ' of ' . strlen($encoded) . ' bytes (throttle state NOT persisted, fail-open)');
            }
            if (fflush($handle) === false) {
                $this->logDiag('fflush failed on ' . $this->filePath . ' — throttle state may not be persisted');
            }
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * ช่องทาง diagnostic (บันทึกวินิจฉัยปัญหา) เดียวสำหรับทุกความเสื่อมของ storage — นำหน้าด้วย request id เพื่อให้เหตุการณ์ fail-open
     * บนหน้า login โยงไปยังบรรทัด server-log ที่ตรงกันเป๊ะ เหมือนกับ exception logger ที่ใช้ร่วมกัน.
     * limiter throw ไม่ได้ (มันต้องถอยไปเป็น fail-open) ดังนั้นนี่จึงเป็นร่องรอยเดียวที่มี.
     */
    private function logDiag(string $message): void
    {
        $reference = function_exists('request_id') ? request_id() : '--------';
        error_log('[ratelimit][req:' . $reference . '] ' . $message);
    }

    private function decode(string $contents): array
    {
        if (trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            // สถานะเสีย (เขียนไม่ครบ, แก้ด้วยมือ, ดิสก์ error) — ถ้าถือว่าว่างเท่ากับ limiter ลืม
            // ทุก attempt (fail-open). log ไว้เพื่อให้ทีมซัพพอร์ตเห็นสาเหตุ แทนที่จะเป็น throttle ที่รีเซ็ตเงียบ ๆ.
            // — จะซ่อมตัวเองเมื่อเขียนสำเร็จครั้งถัดไป.
            $this->logDiag('corrupt JSON in ' . $this->filePath . ' — throttle state treated as empty (fail-open)');
            return [];
        }

        return $decoded;
    }

    /** ลบ key ที่ attempt ล่าสุดเก่ากว่าทุกช่วง decay — จำกัดไฟล์ให้เหลือแค่ key ที่ยัง active. */
    private function dropStaleKeys(array $payload): array
    {
        foreach ($payload as $key => $attempts) {
            $kept = is_array($attempts) ? $this->prune($attempts, self::GLOBAL_MAX_AGE_SECONDS) : [];
            if ($kept === []) {
                unset($payload[$key]);
            } else {
                $payload[$key] = $kept;
            }
        }

        return $payload;
    }

    private function prune(array $attempts, int $decaySeconds): array
    {
        $cutoff = time() - $decaySeconds;

        return array_values(array_filter($attempts, static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp >= $cutoff));
    }
}
