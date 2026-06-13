<?php
declare(strict_types=1);

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

    echo 'SLA processed: ' . (int) ($slaResult['processed'] ?? 0) . PHP_EOL;
    echo 'Emails processed: ' . (int) ($mailResult['processed'] ?? 0) . PHP_EOL;
    echo 'Emails sent: ' . (int) ($mailResult['sent'] ?? 0) . PHP_EOL;
    echo 'Emails retried: ' . (int) ($mailResult['retried'] ?? 0) . PHP_EOL;
    echo 'Emails failed: ' . (int) ($mailResult['failed'] ?? 0) . PHP_EOL;

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
