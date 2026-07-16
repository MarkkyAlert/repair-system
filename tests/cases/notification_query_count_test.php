<?php

declare(strict_types=1);

use App\Repositories\NotificationRepository;

// perf-review F9: notification fan-out inserts recipients as bounded multi-row INSERTs (one statement per
// chunk of 200), not one execute() per recipient. So within a chunk, adding recipients costs ZERO extra
// queries — a broadcast to the whole org is O(recipients/CHUNK) round-trips, not O(recipients).
// Uses count_queries (tests/counting_pdo.php).

function nqc_payload(): array
{
    return ['type' => 'test', 'title' => 't', 'message' => 'm', 'payload' => null, 'related_type' => null, 'related_id' => null];
}

test('query-count(notification): createNotification batches recipients — more recipients in a chunk cost no extra queries', function (): void {
    $pdo = tvm_container()->get(PDO::class);
    $baseline = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM notifications')->fetchColumn();

    try {
        $oneRecipient = count_queries(function (): void {
            tvm_container()->get(NotificationRepository::class)->createNotification(nqc_payload(), [1]);
        });
        $threeRecipients = count_queries(function (): void {
            tvm_container()->get(NotificationRepository::class)->createNotification(nqc_payload(), [1, 2, 3]);
        });

        // one notification INSERT + one bounded recipient INSERT, regardless of recipient count (within a chunk)
        assert_same(2, $oneRecipient, 'createNotification is one notification insert + one recipient insert');
        assert_same(0, $threeRecipients - $oneRecipient, 'extra recipients within a chunk add NO extra queries (multi-row insert, was one-per-recipient)');
    } finally {
        $pdo->prepare('DELETE FROM notifications WHERE id > ?')->execute([$baseline]);
    }
});
