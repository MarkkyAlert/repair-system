<?php
declare(strict_types=1);

use App\Repositories\SettingsRepository;
use App\Services\SlaService;

[$container] = require dirname(__DIR__) . '/bootstrap.php';

try {
    $service = $container->get(SlaService::class);
    if (!$service instanceof SlaService) {
        throw new RuntimeException('Unable to resolve SLA service.');
    }

    $result = $service->processOverdueBreaches();
    $notifyFailed = (int) ($result['notify_failed'] ?? 0);

    $settings = $container->get(SettingsRepository::class);
    if ($settings instanceof SettingsRepository) {
        $settings->upsert('cron_overdue_check_last_run_at', date('Y-m-d H:i:s'), 'string', false, 0);
        // บันทึกความล้มเหลวของการแจ้งเตือน (notify) เพื่อให้ dashboard เตือน + exit code เป็นค่าที่ไม่ใช่ศูนย์
        // ค้างสะสม ไม่เขียนทับด้วย 0 รอบสะอาด (การแจ้งเตือน SLA ที่ล้มไม่ถูก retry จึงต้องไม่ให้สัญญาณหาย)
        $service->recordNotifyFailureFlag($settings, $notifyFailed);
    }

    echo 'Processed overdue SLA breaches: ' . (int) ($result['processed'] ?? 0) . PHP_EOL;
    echo 'Notified: ' . (int) ($result['notified'] ?? 0) . PHP_EOL;
    echo 'Notify failed: ' . $notifyFailed . PHP_EOL;

    foreach (($result['items'] ?? []) as $item) {
        echo '- Ticket ' . (string) ($item['ticket_no'] ?? $item['ticket_id'] ?? '-') . ' [' . (string) ($item['metric_type'] ?? 'resolution') . ']' . PHP_EOL;
    }

    // รันจนจบ (heartbeat อัปเดตแล้ว) แต่การแจ้งเตือน SLA ที่ไม่เคยส่งออกไปคือสภาพไม่ปกติ — exit 2 เพื่อให้
    // การ monitor cron เห็นมัน (ต่างจาก 1 ของการ crash).
    exit($notifyFailed > 0 ? 2 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, (string) $exception . PHP_EOL); // trace เต็ม (class + message + file:line) สำหรับ debug cron
    exit(1);
}
