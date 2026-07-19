<?php
declare(strict_types=1);

use App\Repositories\SettingsRepository;
use App\Services\EmailQueueService;

[$container] = require dirname(__DIR__) . '/bootstrap.php';

try {
    $service = $container->get(EmailQueueService::class);
    if (!$service instanceof EmailQueueService) {
        throw new RuntimeException('Unable to resolve email queue service.');
    }

    $limit = isset($argv[1]) ? max(1, (int) $argv[1]) : null;
    $result = $service->processDueEmails($limit);

    $failed = (int) ($result['failed'] ?? 0);
    $settings = $container->get(SettingsRepository::class);
    if ($settings instanceof SettingsRepository) {
        $settings->upsert('cron_email_queue_last_run_at', date('Y-m-d H:i:s'), 'string', false, 0);
        // บันทึกจำนวน terminal-failure (ล้มเหลวถาวร) เพื่อให้ dashboard เตือนเรื่องความล้มเหลว ไม่ใช่แค่ความเก่า (staleness)
        $settings->upsert('cron_email_queue_last_failed', (string) $failed, 'string', false, 0);
    }

    echo 'Processed emails: ' . (int) ($result['processed'] ?? 0) . PHP_EOL;
    echo 'Sent: ' . (int) ($result['sent'] ?? 0) . PHP_EOL;
    echo 'Retried: ' . (int) ($result['retried'] ?? 0) . PHP_EOL;
    echo 'Failed: ' . $failed . PHP_EOL;

    // ใน production, log ของ cron ต้องไม่พก PII ของผู้รับ (recipient PII, ข้อมูลส่วนบุคคล) — พิมพ์ job id แทน email + subject.
    $isProduction = (string) config('app.env', 'production') === 'production';
    foreach (($result['items'] ?? []) as $item) {
        $who = $isProduction
            ? 'job #' . (int) ($item['id'] ?? 0)
            : (string) ($item['to_email'] ?? '-') . ' :: ' . (string) ($item['subject'] ?? '-');
        echo '- [' . (string) ($item['status'] ?? 'unknown') . '] ' . $who . PHP_EOL;
    }

    // การรันเสร็จสมบูรณ์ (heartbeat อัปเดตแล้ว) แต่ terminal failure — email ที่ใช้ retry จนหมดแล้วและ
    // จะไม่มีวันส่ง — คือผลลัพธ์ที่ไม่ปกติซึ่งการ monitor cron ต้องเห็น. exit code แยกต่างหาก (2) เพื่อไม่ให้
    // สับสนกับการ crash (1).
    exit($failed > 0 ? 2 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, (string) $exception . PHP_EOL); // trace เต็ม (class + message + file:line) สำหรับ debug cron
    exit(1);
}
