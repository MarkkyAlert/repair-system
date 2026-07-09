<?php
declare(strict_types=1);

use App\Repositories\NotificationRepository;

// N+1 guard for notification fan-out: createNotification issues one notification insert plus exactly one
// insert per recipient — no hidden per-recipient query (e.g. a lookup). Uses count_queries (tests/counting_pdo.php);
// asserts the per-recipient cost is linear (adding two recipients adds exactly two queries).

function nqc_payload(): array
{
    return ['type' => 'test', 'title' => 't', 'message' => 'm', 'payload' => null, 'related_type' => null, 'related_id' => null];
}

test('query-count(notification): createNotification costs exactly one insert per recipient (no hidden per-recipient query)', function (): void {
    $pdo = tvm_container()->get(PDO::class);
    $baseline = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM notifications')->fetchColumn();

    try {
        $oneRecipient = count_queries(function (): void {
            tvm_container()->get(NotificationRepository::class)->createNotification(nqc_payload(), [1]);
        });
        $threeRecipients = count_queries(function (): void {
            tvm_container()->get(NotificationRepository::class)->createNotification(nqc_payload(), [1, 2, 3]);
        });

        assert_same(2, $threeRecipients - $oneRecipient, 'two extra recipients cost exactly two extra queries (linear, no per-recipient lookup)');
    } finally {
        $pdo->prepare('DELETE FROM notifications WHERE id > ?')->execute([$baseline]);
    }
});
