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
        // record notify failures so the dashboard warns + the exit code is non-zero
        $settings->upsert('cron_sla_notify_last_failed', (string) $notifyFailed, 'string', false, 0);
    }

    echo 'Processed overdue SLA breaches: ' . (int) ($result['processed'] ?? 0) . PHP_EOL;
    echo 'Notified: ' . (int) ($result['notified'] ?? 0) . PHP_EOL;
    echo 'Notify failed: ' . $notifyFailed . PHP_EOL;

    foreach (($result['items'] ?? []) as $item) {
        echo '- Ticket ' . (string) ($item['ticket_no'] ?? $item['ticket_id'] ?? '-') . ' [' . (string) ($item['metric_type'] ?? 'resolution') . ']' . PHP_EOL;
    }

    // ran to completion (heartbeat updated), but an SLA alert that never went out is unhealthy — exit 2 so cron
    // monitoring sees it (distinct from a crash's 1).
    exit($notifyFailed > 0 ? 2 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, (string) $exception . PHP_EOL); // full trace (class + message + file:line) for cron debugging
    exit(1);
}
