<?php
declare(strict_types=1);

/**
 * ตัวทำงานล้างไฟล์แนบที่กำพร้า (orphan attachment file cleanup worker).
 *
 * สแกน storage/uploads/tickets/ หาไฟล์ที่ไม่มีอยู่ใน DB (ticket_attachments)
 * แล้วลบทิ้ง เพื่อกัน disk leak จาก partial-failure
 *
 * Usage:
 *   php bin/cleanup-orphan-attachments.php             # รันจริง grace = 3600s
 *   php bin/cleanup-orphan-attachments.php --dry-run   # แสดงรายการอย่างเดียว ไม่ลบ
 *   php bin/cleanup-orphan-attachments.php --grace=7200  # ปรับ grace period (วินาที)
 *
 * Recommended cron: รายสัปดาห์ (ไฟล์ orphan ไม่ค่อยเกิด)
 *   0 3 * * 0 /Applications/XAMPP/xamppfiles/bin/php /path/to/bin/cleanup-orphan-attachments.php
 */

use App\Repositories\SettingsRepository;
use App\Services\AttachmentService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'This script must be run from CLI.' . PHP_EOL);
    exit(2);
}

[$container] = require dirname(__DIR__) . '/bootstrap.php';

$dryRun = false;
$grace = 3600;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run' || $arg === '-n') {
        $dryRun = true;
        continue;
    }
    if (str_starts_with($arg, '--grace=')) {
        $grace = max(0, (int) substr($arg, 8));
        continue;
    }
    fwrite(STDERR, 'Unknown argument: ' . $arg . PHP_EOL);
    exit(2);
}

try {
    $service = $container->get(AttachmentService::class);
    if (!$service instanceof AttachmentService) {
        throw new RuntimeException('Unable to resolve AttachmentService.');
    }

    $result = $service->cleanupOrphanFiles($grace, $dryRun);

    if (!$dryRun) {
        $settings = $container->get(SettingsRepository::class);
        if ($settings instanceof SettingsRepository) {
            $settings->upsert('cron_orphan_cleanup_last_run_at', date('Y-m-d H:i:s'), 'string', false, 0);
            // บันทึกความล้มเหลวของการลบ เพื่อให้ dashboard เตือน แม้งานจะ "รัน" ไปแล้ว (ถ้าอัป heartbeat อย่างเดียว
            // จะกลบรอบที่รันแล้วยังเหลือ orphan ลบไม่ออก) รอบที่สะอาดเขียน '0' เคลียร์คำเตือน —
            // ให้ตรงกับ cron ของ backup/email/SLA
            $settings->upsert('cron_orphan_cleanup_last_failed', (string) (int) $result['errors'], 'string', false, 0);
        }
    }

    echo '[cleanup-orphan-attachments] mode=' . ($dryRun ? 'dry-run' : 'live') . ' grace=' . $grace . 's' . PHP_EOL;
    echo 'Scanned:        ' . (int) $result['scanned'] . PHP_EOL;
    echo 'Kept (in DB):   ' . (int) $result['kept'] . PHP_EOL;
    echo 'Skipped recent: ' . (int) $result['skipped_recent'] . PHP_EOL;
    echo 'Orphans found:  ' . (int) $result['orphans'] . PHP_EOL;
    echo 'Deleted:        ' . (int) $result['deleted'] . PHP_EOL;
    echo 'Errors:         ' . (int) $result['errors'] . PHP_EOL;

    if (!empty($result['orphan_paths'])) {
        echo PHP_EOL . 'Orphan paths:' . PHP_EOL;
        foreach ($result['orphan_paths'] as $path) {
            echo '  - ' . $path . PHP_EOL;
        }
    }

    // Exit 2 = "รันแล้วแต่เหลือความล้มเหลวของการลบ" (ต่างจาก 1 ของการ crash) ให้ตรงกับ cron ของ backup เพื่อให้
    // ตัวจัดตารางงาน/ตัว monitor (scheduler/monitor) แยกรอบที่จบแบบมีความล้มเหลว ออกจากการ crash รุนแรงได้.
    exit((int) $result['errors'] > 0 ? 2 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, (string) $exception . PHP_EOL); // trace เต็ม (class + message + file:line) สำหรับ debug cron
    exit(1);
}
