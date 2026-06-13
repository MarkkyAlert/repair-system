<?php
declare(strict_types=1);

use App\Services\SlaService;

[$container] = require dirname(__DIR__) . '/bootstrap.php';

try {
    $service = $container->get(SlaService::class);
    if (!$service instanceof SlaService) {
        throw new RuntimeException('Unable to resolve SLA service.');
    }

    $result = $service->processOverdueBreaches();

    echo 'Processed overdue SLA breaches: ' . (int) ($result['processed'] ?? 0) . PHP_EOL;

    foreach (($result['items'] ?? []) as $item) {
        echo '- Ticket ' . (string) ($item['ticket_no'] ?? $item['ticket_id'] ?? '-') . ' [' . (string) ($item['metric_type'] ?? 'resolution') . ']' . PHP_EOL;
    }

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
