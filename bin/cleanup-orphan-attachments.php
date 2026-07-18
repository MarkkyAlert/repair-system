<?php
declare(strict_types=1);

/**
 * Orphan attachment file cleanup worker.
 *
 * สแกน storage/uploads/tickets/ หาไฟล์ที่ไม่มีอยู่ใน DB (ticket_attachments)
 * แล้วลบทิ้ง เพื่อกัน disk leak จาก partial-failure (ดู P3 Fix-8)
 *
 * Usage:
 *   php bin/cleanup-orphan-attachments.php             # run จริง grace = 3600s
 *   php bin/cleanup-orphan-attachments.php --dry-run   # list อย่างเดียว ไม่ลบ
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
            // Record delete failures so the dashboard warns even though the job "ran" (a fresh heartbeat alone
            // hid a run that left orphans it couldn't delete). A clean run writes '0', clearing the warning —
            // matching the backup/email/SLA crons.
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

    // Exit 2 = "ran but left delete failures" (distinct from a crash's 1), matching the backup cron so a
    // scheduler/monitor can tell a completed-with-failures run from a hard crash.
    exit((int) $result['errors'] > 0 ? 2 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, (string) $exception . PHP_EOL); // full trace (class + message + file:line) for cron debugging
    exit(1);
}
