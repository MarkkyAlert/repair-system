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
                'dir' => 'storage/backups',
                // ชื่อไฟล์ล่าสุดจริง (มี .gz) — view จะตัด .gz เป็นชื่อ .sql สำหรับคำสั่ง import
                'newest_file' => $newest !== null ? basename($newest) : 'db-YYYY-MM-DD_HHMMSS.sql.gz',
            ],
        ];
    }

    /**
     * คืน true เฉพาะไฟล์ที่คลาย gzip ได้จนจบจริง ๆ และมีเนื้อข้อมูล — เป็นด่านคัดไฟล์ db-*.sql.gz ที่ว่าง/ขาดครึ่ง/
     * เสียกลางไฟล์ ออกจากจำนวน "backup ที่ใช้ได้". เดิมดูแค่ magic bytes + ISIZE ใน trailer ซึ่งไฟล์ที่ header/ท้าย
     * ยังอ่านได้แต่เนื้อในเสีย (เช่น backup ที่ตายคาตอนเขียนเพราะดิสก์เต็ม) หลุดผ่านได้. ตอนนี้ไล่ inflate ทั้ง stream
     * แล้วเช็คว่า zlib ปิดที่ ZLIB_STREAM_END (zlib ตรวจ CRC32 + ISIZE ให้เองตอนถึงท้าย) — ไฟล์ที่ขาดครึ่งจะไม่ถึงจุดนั้น.
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
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 1 << 16);
                if ($chunk === false) {
                    return false;
                }
                if ($chunk === '') {
                    continue;
                }
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

        // ต้องคลายได้เนื้อจริง (gzip ของ input ว่าง → ไม่มีเนื้อ → กู้คืนไม่ได้) และ stream ต้องปิดสมบูรณ์
        return $producedBytes && inflate_get_status($context) === ZLIB_STREAM_END;
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
