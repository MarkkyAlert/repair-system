<?php

declare(strict_types=1);

use App\Repositories\EmailQueueRepository;

test('coverage(email batch): 101 recipients cross the chunk boundary without dropped rows', function (): void {
    $pdo = tvm_container()->get(PDO::class);
    $marker = 'pre-ship-chunk-' . bin2hex(random_bytes(6));
    $payloads = [];

    for ($index = 0; $index < 101; $index++) {
        $payloads[] = [
            'to_email' => sprintf('%s-%03d@example.test', $marker, $index),
            'to_name' => 'Chunk boundary recipient',
            'subject' => 'Chunk boundary coverage',
            'body_text' => 'coverage',
        ];
    }

    try {
        $queries = count_queries(function () use ($payloads): void {
            tvm_container()->get(EmailQueueRepository::class)->enqueueMany($payloads);
        });

        assert_same(2, $queries, '101 recipients must be split into exactly two bounded INSERT statements');

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM email_queue WHERE to_email LIKE ?');
        $stmt->execute([$marker . '-%@example.test']);
        assert_same(101, (int) $stmt->fetchColumn(), 'all recipients must be persisted across the chunk boundary');
    } finally {
        $stmt = $pdo->prepare('DELETE FROM email_queue WHERE to_email LIKE ?');
        $stmt->execute([$marker . '-%@example.test']);
    }
});
