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
        // record the terminal-failure count so the dashboard can warn on FAILURES, not just staleness (error-review F4)
        $settings->upsert('cron_email_queue_last_failed', (string) $failed, 'string', false, 0);
    }

    echo 'Processed emails: ' . (int) ($result['processed'] ?? 0) . PHP_EOL;
    echo 'Sent: ' . (int) ($result['sent'] ?? 0) . PHP_EOL;
    echo 'Retried: ' . (int) ($result['retried'] ?? 0) . PHP_EOL;
    echo 'Failed: ' . $failed . PHP_EOL;

    // In production the cron log must not carry recipient PII — print the job id, not the email + subject. (error-review-2 F5)
    $isProduction = (string) config('app.env', 'production') === 'production';
    foreach (($result['items'] ?? []) as $item) {
        $who = $isProduction
            ? 'job #' . (int) ($item['id'] ?? 0)
            : (string) ($item['to_email'] ?? '-') . ' :: ' . (string) ($item['subject'] ?? '-');
        echo '- [' . (string) ($item['status'] ?? 'unknown') . '] ' . $who . PHP_EOL;
    }

    // The run completed (heartbeat updated), but a terminal failure — an email that exhausted its retries and
    // will NEVER send — is an unhealthy outcome cron monitoring must see. Distinct exit code (2) so it is not
    // confused with a crash (1). (error-review F4)
    exit($failed > 0 ? 2 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, (string) $exception . PHP_EOL); // full trace (class + message + file:line) for cron debugging
    exit(1);
}
