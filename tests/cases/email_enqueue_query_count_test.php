<?php

declare(strict_types=1);

use App\Services\EmailQueueService;

// Pre-ship sweep M-3: ticket-event / comment / SLA-breach emails were enqueued one INSERT per recipient, so a
// single ticket action in an org with M approvers cost 1 (recipient lookup) + M (one INSERT each) queries on the
// hottest write path. The batched multi-row INSERT (enqueueMany) already existed and was used by system
// announcements; these three event methods simply never adopted it. Now they do — enqueuing M recipients is one
// bounded INSERT per 100, so adding recipients within a chunk costs ZERO extra queries.
// Uses count_queries (tests/counting_pdo.php); measured as a delta so per-call constant costs cancel out.

test('query-count(email): ticket-event emails are one batched insert — extra recipients add no queries', function (): void {
    $pdo = tvm_container()->get(PDO::class);
    $active = array_map('intval', $pdo->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 4')->fetchAll(PDO::FETCH_COLUMN));
    assert_true(count($active) >= 3, 'precondition: need at least 3 active users to show a per-recipient difference');

    $small = array_slice($active, 0, 2);
    $big = $active;
    $context = ['id' => 1, 'ticket_no' => 'MT-QC-0001', 'title' => 'qc', 'status' => 'approved'];
    $baseline = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM email_queue')->fetchColumn();

    try {
        // setting() caches branding values process-wide. Warm that cache explicitly so this test measures only
        // recipient fan-out and stays valid when run alone; previously the full suite happened to warm it first,
        // while an isolated run reported a misleading -3 delta.
        tvm_container()->get(EmailQueueService::class)->queueTicketEventEmails(
            $context,
            [reset($active)],
            'ticket.approved',
            'warmup',
            'warmup'
        );
        $pdo->prepare('DELETE FROM email_queue WHERE id > ?')->execute([$baseline]);

        // Resolve the service INSIDE the callback so it binds to the swapped CountingPdo (services are transient).
        $few = count_queries(function () use ($context, $small): void {
            tvm_container()->get(EmailQueueService::class)->queueTicketEventEmails($context, $small, 'ticket.approved', 't', 'm');
        });
        $many = count_queries(function () use ($context, $big): void {
            tvm_container()->get(EmailQueueService::class)->queueTicketEventEmails($context, $big, 'ticket.approved', 't', 'm');
        });

        assert_same(
            0,
            $many - $few,
            'doubling recipients must add NO queries — the enqueue is one multi-row insert (was 1 insert per recipient); delta=' . ($many - $few)
        );

        // "0 delta" must not mean "did nothing": every recipient was really enqueued (count(small) + count(big) rows).
        $inserted = (int) $pdo->query('SELECT COUNT(*) FROM email_queue WHERE id > ' . $baseline)->fetchColumn();
        assert_same(count($small) + count($big), $inserted, 'all recipients across both calls were enqueued');
    } finally {
        $pdo->prepare('DELETE FROM email_queue WHERE id > ?')->execute([$baseline]);
    }
});
