<?php

declare(strict_types=1);

use App\Repositories\EmailQueueRepository;

// Pre-ship sweep #2: EmailQueueRepository::enqueueMany inserts in chunks of 100 with no transaction. If chunk 2
// failed, chunk 1's 100 rows stayed committed while the caller caught the exception and reported "0 queued" — so
// the queue held 100 real emails that would be SENT, while the app believed nothing was enqueued (half-sent
// broadcast, no record of it). Wrapping every chunk in one transaction makes a mid-batch failure roll back the
// whole batch. Proven with a fault injector (tests/failing_pdo.php) rather than bad data, so it does not depend
// on a particular column constraint or sql_mode.

test('atomicity(email batch): a mid-batch chunk failure rolls back the whole batch — no half-queued rows', function (): void {
    $pdo = tvm_container()->get(PDO::class);
    $marker = 'atomicity-' . bin2hex(random_bytes(6));

    // 150 payloads = two chunks (100 + 50), all valid. The failure is injected on the SECOND email_queue INSERT.
    $payloads = [];
    for ($i = 0; $i < 150; $i++) {
        $payloads[] = [
            'to_email' => sprintf('%s-%03d@example.test', $marker, $i),
            'to_name' => 'atomicity recipient',
            'subject' => 'atomicity coverage',
            'body_text' => 'coverage',
        ];
    }

    try {
        $threw = null;
        try {
            // skip=1 → let chunk 1's INSERT through, throw on chunk 2's INSERT.
            with_failing_pdo('email_queue', function () use ($payloads): void {
                tvm_container()->get(EmailQueueRepository::class)->enqueueMany($payloads);
            }, 1);
        } catch (\Throwable $e) {
            $threw = $e;
        }

        assert_true($threw !== null, 'the injected chunk-2 failure must propagate — the caller must see the batch failed');

        // The whole point: chunk 1's 100 rows must NOT survive a chunk-2 failure.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM email_queue WHERE to_email LIKE ?');
        $stmt->execute([$marker . '-%@example.test']);
        assert_same(
            0,
            (int) $stmt->fetchColumn(),
            'a mid-batch failure must leave ZERO queued rows — not the 100 from the first chunk'
        );
    } finally {
        $pdo->prepare('DELETE FROM email_queue WHERE to_email LIKE ?')->execute([$marker . '-%@example.test']);
    }
});
