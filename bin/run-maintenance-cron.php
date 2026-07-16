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
        // terminal-failure counts so the dashboard warns on failures, not just staleness (error-review F4)
        $settings->upsert('cron_email_queue_last_failed', (string) $mailFailed, 'string', false, 0);
        $settings->upsert('cron_sla_notify_last_failed', (string) $slaNotifyFailed, 'string', false, 0);
    }

    echo 'SLA processed: ' . (int) ($slaResult['processed'] ?? 0) . PHP_EOL;
    echo 'SLA notified: ' . (int) ($slaResult['notified'] ?? 0) . PHP_EOL;
    echo 'SLA notify failed: ' . $slaNotifyFailed . PHP_EOL;
    echo 'Emails processed: ' . (int) ($mailResult['processed'] ?? 0) . PHP_EOL;
    echo 'Emails sent: ' . (int) ($mailResult['sent'] ?? 0) . PHP_EOL;
    echo 'Emails retried: ' . (int) ($mailResult['retried'] ?? 0) . PHP_EOL;
    echo 'Emails failed: ' . $mailFailed . PHP_EOL;

    // ran to completion (heartbeats updated), but a terminal email failure or an SLA alert that never went out
    // is an unhealthy outcome — exit 2 (distinct from a crash's 1) so cron monitoring sees it. (error-review F4)
    exit(($mailFailed > 0 || $slaNotifyFailed > 0) ? 2 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, (string) $exception . PHP_EOL); // full trace (class + message + file:line) for cron debugging
    exit(1);
}
