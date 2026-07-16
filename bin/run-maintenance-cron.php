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

    $settings = $container->get(SettingsRepository::class);
    if ($settings instanceof SettingsRepository) {
        $now = date('Y-m-d H:i:s');
        $settings->upsert('cron_overdue_check_last_run_at', $now, 'string', false, 0);
        $settings->upsert('cron_email_queue_last_run_at', $now, 'string', false, 0);
        $settings->upsert('cron_maintenance_last_run_at', $now, 'string', false, 0);
    }

    echo 'SLA processed: ' . (int) ($slaResult['processed'] ?? 0) . PHP_EOL;
    echo 'SLA notified: ' . (int) ($slaResult['notified'] ?? 0) . PHP_EOL;
    echo 'SLA notify failed: ' . (int) ($slaResult['notify_failed'] ?? 0) . PHP_EOL;
    echo 'Emails processed: ' . (int) ($mailResult['processed'] ?? 0) . PHP_EOL;
    echo 'Emails sent: ' . (int) ($mailResult['sent'] ?? 0) . PHP_EOL;
    echo 'Emails retried: ' . (int) ($mailResult['retried'] ?? 0) . PHP_EOL;
    echo 'Emails failed: ' . (int) ($mailResult['failed'] ?? 0) . PHP_EOL;

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, (string) $exception . PHP_EOL); // full trace (class + message + file:line) for cron debugging
    exit(1);
}
