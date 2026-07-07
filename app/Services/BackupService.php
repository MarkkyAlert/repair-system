<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;

/**
 * สถานะการสำรองฐานข้อมูล (read-only) — ใช้ในแท็บ admin "สำรอง & กู้คืน" และ dashboard cron health.
 * อ่านข้อมูลจริงจาก storage/backups/ + เวลา `cron_backup_last_run_at` ที่ bin/backup-database.php บันทึกไว้.
 * ไม่สั่งสำรอง/กู้คืนเอง (การสำรองทำผ่าน cron/CLI; การกู้คืนทำบน server ด้วย mysql CLI).
 */
class BackupService
{
    /** ถือว่า backup "เก่า" ถ้าไม่ได้รันเกินช่วงนี้ (นาที) — single source ให้ TicketService cron health อ้างด้วย. */
    public const STALE_MINUTES = 60 * 24 * 2; // 2 วัน

    /** จำนวนชุดที่ bin/backup-database.php เก็บไว้ (rotation default) — ใช้แสดงผลอย่างเดียว. */
    public const DEFAULT_RETENTION = 14;

    public function __construct(private SettingsRepository $settings)
    {
    }

    /** view-model สถานะ backup — ปลอดภัยส่งเข้า view ตรง ๆ. */
    public function getStatus(): array
    {
        // อ่านสดจาก repo (ไม่ผ่าน setting() ที่ static-cache) เพื่อให้หน้าสถานะสะท้อนค่าปัจจุบันเสมอ
        $row = $this->settings->getByKey('cron_backup_last_run_at');
        $lastRunAt = trim((string) ($row['setting_value'] ?? ''));
        $lastTs = $lastRunAt !== '' ? strtotime($lastRunAt) : false;

        $paths = glob(storage_path('backups') . '/db-*.sql.gz') ?: [];
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

        // "เก่า" ดูจากหลักฐานล่าสุดของการสำรองจริง — เวลาที่ cron บันทึก หรือ mtime ของไฟล์ล่าสุด แล้วแต่ตัวไหนใหม่กว่า.
        // ไฟล์สดทำให้ไม่ stale แม้ cron ไม่ได้บันทึกเวลา ; ไม่มีหลักฐานเลย (ไม่มีทั้ง setting และไฟล์) = stale.
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
