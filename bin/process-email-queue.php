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

    $settings = $container->get(SettingsRepository::class);
    if ($settings instanceof SettingsRepository) {
        $settings->upsert('cron_email_queue_last_run_at', date('Y-m-d H:i:s'), 'string', false, 0);
    }

    echo 'Processed emails: ' . (int) ($result['processed'] ?? 0) . PHP_EOL;
    echo 'Sent: ' . (int) ($result['sent'] ?? 0) . PHP_EOL;
    echo 'Retried: ' . (int) ($result['retried'] ?? 0) . PHP_EOL;
    echo 'Failed: ' . (int) ($result['failed'] ?? 0) . PHP_EOL;

    foreach (($result['items'] ?? []) as $item) {
        echo '- [' . (string) ($item['status'] ?? 'unknown') . '] ' . (string) ($item['to_email'] ?? '-') . ' :: ' . (string) ($item['subject'] ?? '-') . PHP_EOL;
    }

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
