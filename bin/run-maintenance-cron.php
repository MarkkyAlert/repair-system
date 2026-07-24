<?php
declare(strict_types=1);

use App\Repositories\SettingsRepository;
use App\Services\EmailQueueService;
use App\Services\SlaService;

[$container] = require dirname(__DIR__) . '/bootstrap.php';

try {
    $slaService = $container->get(SlaService::class);
    $emailService = $container->get(EmailQueueService::class);

    if (!$slaService instanceof SlaService) {
        throw new RuntimeException('Unable to resolve SLA service.');
    }

    if (!$emailService instanceof EmailQueueService) {
        throw new RuntimeException('Unable to resolve email queue service.');
    }

    $slaResult = $slaService->processOverdueBreaches();
    $mailResult = $emailService->processDueEmails();

    $mailFailed = (int) ($mailResult['failed'] ?? 0);
    $slaNotifyFailed = (int) ($slaResult['notify_failed'] ?? 0);

    $settings = $container->get(SettingsRepository::class);
    if ($settings instanceof SettingsRepository) {
        $now = date('Y-m-d H:i:s');
        $settings->upsert('cron_overdue_check_last_run_at', $now, 'string', false, 0);
        $settings->upsert('cron_email_queue_last_run_at', $now, 'string', false, 0);
        $settings->upsert('cron_maintenance_last_run_at', $now, 'string', false, 0);
        // จำนวน terminal-failure (ล้มเหลวถาวร) เพื่อให้ dashboard เตือนเรื่องความล้มเหลว ไม่ใช่แค่ความเก่า (staleness)
        // คิวอีเมลทำซ้ำทุกรอบ flag จึงกลับเป็น 0 เองได้ — เขียนทับตามปกติ
        $settings->upsert('cron_email_queue_last_failed', (string) $mailFailed, 'string', false, 0);
        // การแจ้งเตือน SLA ที่ล้มไม่ถูก retry จึงต้องค้างสะสม ไม่ให้รอบสะอาดถัดไปลบสัญญาณทิ้ง
        $slaService->recordNotifyFailureFlag($settings, $slaNotifyFailed);
    }

    echo 'SLA processed: ' . (int) ($slaResult['processed'] ?? 0) . PHP_EOL;
    echo 'SLA notified: ' . (int) ($slaResult['notified'] ?? 0) . PHP_EOL;
    echo 'SLA notify failed: ' . $slaNotifyFailed . PHP_EOL;
    echo 'Emails processed: ' . (int) ($mailResult['processed'] ?? 0) . PHP_EOL;
    echo 'Emails sent: ' . (int) ($mailResult['sent'] ?? 0) . PHP_EOL;
    echo 'Emails retried: ' . (int) ($mailResult['retried'] ?? 0) . PHP_EOL;
    echo 'Emails failed: ' . $mailFailed . PHP_EOL;

    // รันจนจบ (heartbeat อัปเดตแล้ว) แต่ terminal email failure หรือการแจ้งเตือน SLA ที่ไม่เคยส่งออกไป
    // เป็นสภาพไม่ปกติ — exit 2 (ต่างจาก 1 ของการ crash) เพื่อให้ตัว monitor cron เห็นมัน
    exit(($mailFailed > 0 || $slaNotifyFailed > 0) ? 2 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, (string) $exception . PHP_EOL); // trace เต็ม (class + message + file:line) สำหรับ debug cron
    exit(1);
}
