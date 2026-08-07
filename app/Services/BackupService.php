<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;

/**
 * อ่านสถานะการสำรองฐานข้อมูลอย่างเดียว — ใช้ในแท็บ admin "สำรอง & กู้คืน" และ dashboard cron health.
 * อ่านค่าจริงจาก storage/backups/ กับเวลา `cron_backup_last_run_at` ที่ bin/backup-database.php เขียนไว้.
 * คลาสนี้ไม่สั่งสำรอง/กู้คืนเอง — การสำรองรันผ่าน cron/CLI ส่วนการกู้คืนทำบน server ด้วย mysql CLI.
 */
class BackupService
{
    /** ถือว่า backup "เก่า" ถ้าไม่ได้รันนานเกินค่านี้ (นาที) — เป็นค่ากลางให้ TicketService cron health อ้างด้วย. */
    public const STALE_MINUTES = 60 * 24 * 2; // 2 วัน

    /** จำนวนชุด backup ที่ bin/backup-database.php เก็บไว้ (ค่า rotation เริ่มต้น) — ใช้แค่แสดงผล. */
    public const DEFAULT_RETENTION = 14;

    /**
     * ร่องรอยของปุ่ม "สำรองทันที" — บันทึกตอนเริ่มไว้ก่อน แล้วค่อยบันทึกตอนจบ. ที่ต้องมีสองจังหวะเพราะถ้า PHP ถูก
     * ฆ่ากลางคัน (หมดเวลา/หน่วยความจำ) จะไม่มีโค้ดไหนได้รันต่อเลย — เหลือแค่ "เริ่มแล้วไม่มีตอนจบ" ที่บอกได้ว่าล้ม
     * ไม่งั้นผู้ใช้ได้ไฟล์ขาดครึ่งกลับไปโดยไม่มีอะไรบอกว่าใช้กู้คืนไม่ได้
     */
    public const ONDEMAND_STARTED_KEY = 'admin_backup_ondemand_started_at';
    public const ONDEMAND_FINISHED_KEY = 'admin_backup_ondemand_finished_at';
    public const ONDEMAND_BYTES_KEY = 'admin_backup_ondemand_bytes';
    public const ONDEMAND_ERROR_KEY = 'admin_backup_ondemand_error';

    public function __construct(private SettingsRepository $settings)
    {
    }

    /** view-model สถานะ backup — ปลอดภัยส่งเข้า view ตรง ๆ. */
    public function getStatus(): array
    {
        // อ่านสดจาก repo ไม่ผ่าน setting() ที่ cache ค่าไว้ หน้าสถานะจะได้เห็นค่าล่าสุดเสมอ
        $row = $this->settings->getByKey('cron_backup_last_run_at');
        $lastRunAt = trim((string) ($row['setting_value'] ?? ''));
        $lastTs = $lastRunAt !== '' ? strtotime($lastRunAt) : false;

        // นับเฉพาะไฟล์ที่กู้คืนได้จริง — คือ gzip จริงที่ไม่ว่างเปล่า. ไฟล์ db-*.sql.gz ที่ขาดครึ่ง/ว่าง/เสีย
        // (เช่นตัวที่ backup พังคาไว้) ต้องไม่ถูกนับเป็น backup ที่สดและใช้ได้.
        $paths = array_values(array_filter(
            glob(storage_path('backups') . '/db-*.sql.gz') ?: [],
            fn (string $path): bool => $this->isRestorableBackup($path)
        ));
        usort($paths, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $files = [];
        $totalBytes = 0;
        foreach ($paths as $path) {
            $bytes = (int) (@filesize($path) ?: 0);
            $totalBytes += $bytes;
            if (count($files) < 10) {
                $files[] = [
                    'name' => basename($path),
                    'size_human' => $this->humanSize($bytes),
                    'date' => date('Y-m-d H:i:s', (int) @filemtime($path)),
                ];
            }
        }

        $newest = $paths[0] ?? null;
        $newestTs = $newest !== null ? (int) @filemtime($newest) : null;

        // ดู "เก่า" จากหลักฐานการสำรองล่าสุด — เวลาที่ cron บันทึก หรือ mtime ของไฟล์ใหม่สุด เอาอันที่ใหม่กว่า.
        // มีไฟล์สดอยู่ก็ยังไม่ stale แม้ cron ไม่ได้บันทึกเวลา. ไม่มีหลักฐานสักอย่าง (ทั้ง setting และไฟล์) ถือว่า stale.
        $evidenceTs = max($lastTs !== false ? $lastTs : 0, $newestTs ?? 0);
        $isStale = $evidenceTs === 0 || $evidenceTs < (time() - self::STALE_MINUTES * 60);

        return [
            'has_backups' => $newest !== null,
            'last_run_at' => $lastRunAt,
            'is_stale' => $isStale,
            'stale_hours' => (int) (self::STALE_MINUTES / 60),
            'file_count' => count($paths),
            'total_size' => $this->humanSize($totalBytes),
            'newest_file' => $newest !== null ? basename($newest) : null,
            'newest_at' => $newestTs !== null ? date('Y-m-d H:i:s', $newestTs) : null,
            'newest_size' => $newest !== null ? $this->humanSize((int) (@filesize($newest) ?: 0)) : null,
            'retention' => self::DEFAULT_RETENTION,
            'backup_dir' => 'storage/backups',
            'files' => $files,
            'restore' => [
                'db_name' => (string) config('db.name', 'repair_system'),
                'db_user' => (string) config('db.username', 'root'),
                'db_charset' => (string) config('db.charset', 'utf8mb4'),
                // path เต็ม ไม่ใช่ path ย่อ: คนที่กำลังกู้ระบบ copy คำสั่งไปวางจากที่ไหนก็ได้ ไม่ต้องเดาว่าต้อง cd ไปไหนก่อน
                'dir' => storage_path('backups'),
                // ชื่อไฟล์ล่าสุดจริง (มี .gz) — view จะตัด .gz เป็นชื่อ .sql สำหรับคำสั่ง import
                'newest_file' => $newest !== null ? basename($newest) : 'db-YYYY-MM-DD_HHMMSS.sql.gz',
            ],
            'on_demand' => $this->onDemandStatus(),
        ];
    }

    /** ผลของการกดปุ่ม "สำรองทันที" ครั้งล่าสุด — สำเร็จ/ล้ม/ไม่จบ พร้อมขนาดไฟล์ที่ส่งออกไปจริง. */
    private function onDemandStatus(): array
    {
        $read = fn (string $key): string => trim((string) ($this->settings->getByKey($key)['setting_value'] ?? ''));
        $startedAt = $read(self::ONDEMAND_STARTED_KEY);
        $finishedAt = $read(self::ONDEMAND_FINISHED_KEY);
        $error = $read(self::ONDEMAND_ERROR_KEY);
        $bytes = (int) $read(self::ONDEMAND_BYTES_KEY);

        if ($startedAt === '') {
            $state = 'never';
        } elseif ($error !== '') {
            $state = 'failed';
        } elseif ($finishedAt === '' || strtotime($finishedAt) < strtotime($startedAt)) {
            // เริ่มแล้วแต่ไม่มีตอนจบ = โดนฆ่ากลางคัน ไฟล์ที่ผู้ใช้ได้ไปคือไฟล์ขาดครึ่ง กู้คืนไม่ได้
            $state = 'interrupted';
        } else {
            $state = 'ok';
        }

        return [
            'state' => $state,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'error' => $error,
            'bytes' => $bytes,
            'size_human' => $this->humanSize($bytes),
        ];
    }

    /** ปักหมุดว่าเริ่มสำรองแล้ว ก่อนส่ง byte แรกออกไป. */
    public function recordOnDemandStart(): void
    {
        $this->settings->upsert(self::ONDEMAND_STARTED_KEY, date('Y-m-d H:i:s'), 'string', false, 0);
        $this->settings->upsert(self::ONDEMAND_FINISHED_KEY, '', 'string', false, 0);
        $this->settings->upsert(self::ONDEMAND_BYTES_KEY, '0', 'string', false, 0);
        $this->settings->upsert(self::ONDEMAND_ERROR_KEY, '', 'string', false, 0);
    }

    /** ปิดงาน: สำเร็จพร้อมขนาดไฟล์ หรือล้มพร้อมเหตุผลที่เอาไปโชว์ได้. */
    public function recordOnDemandResult(bool $succeeded, int $bytes, string $error = ''): void
    {
        $this->settings->upsert(self::ONDEMAND_FINISHED_KEY, date('Y-m-d H:i:s'), 'string', false, 0);
        $this->settings->upsert(self::ONDEMAND_BYTES_KEY, (string) max(0, $bytes), 'string', false, 0);
        $this->settings->upsert(self::ONDEMAND_ERROR_KEY, $succeeded ? '' : ($error !== '' ? $error : 'การสำรองไม่สำเร็จ'), 'string', false, 0);
    }

    /**
     * คืน true เฉพาะไฟล์ที่คลาย gzip ได้จนจบจริง ๆ และมีเนื้อข้อมูล — เป็นด่านคัดไฟล์ db-*.sql.gz ที่ว่าง/ขาดครึ่ง/
     * เสียกลางไฟล์ ออกจากจำนวน "backup ที่ใช้ได้". เดิมดูแค่ magic bytes + ISIZE ใน trailer ซึ่งไฟล์ที่ header/ท้าย
     * ยังอ่านได้แต่เนื้อในเสีย (เช่น backup ที่ตายคาตอนเขียนเพราะดิสก์เต็ม) หลุดผ่านได้. ตอนนี้ไล่ inflate ทั้ง stream,
     * เช็คว่า zlib ปิดที่ ZLIB_STREAM_END และกิน compressed input ครบทุก byte — ไฟล์ขาดครึ่งหรือมีขยะต่อท้ายจึงไม่ผ่าน.
     */
    private function isRestorableBackup(string $path): bool
    {
        $size = (int) (@filesize($path) ?: 0);
        if ($size < 18) { // เล็กกว่า gzip header ขั้นต่ำ (10) + trailer (8)
            return false;
        }
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            return false;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        // คลายทีละก้อน ทิ้งผลลัพธ์ไปเรื่อย ๆ (ไม่ต้องเก็บทั้งไฟล์ในหน่วยความจำ) แค่ยืนยันว่าเนื้อในถูกต้องและครบ
        $producedBytes = false;
        $compressedBytes = 0;
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 1 << 16);
                if ($chunk === false) {
                    return false;
                }
                if ($chunk === '') {
                    continue;
                }
                $compressedBytes += strlen($chunk);
                $decoded = @inflate_add($context, $chunk, ZLIB_NO_FLUSH);
                if ($decoded === false) {
                    return false; // เนื้อ gzip เสีย/ไม่ใช่ gzip — คลายไม่ออก
                }
                if ($decoded !== '') {
                    $producedBytes = true;
                }
            }
        } finally {
            fclose($handle);
        }

        // ต้องมีเนื้อจริง, stream ปิดสมบูรณ์ และ zlib ต้องกินไฟล์ครบ (ไม่หยุดก่อน trailing garbage).
        return $producedBytes
            && inflate_get_status($context) === ZLIB_STREAM_END
            && inflate_get_read_len($context) === $compressedBytes;
    }

    /** bytes → ข้อความอ่านง่าย (B/KB/MB/GB/TB). */
    private function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return ($power === 0 ? (string) $bytes : number_format($bytes / (1024 ** $power), 1)) . ' ' . $units[$power];
    }
}
