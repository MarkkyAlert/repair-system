<?php

declare(strict_types=1);

use App\Services\TicketService;

// perf-review F4: the ticket-detail live feed polls getNewComments() every 20s. It must fetch only the
// comments newer than the client's last id — NOT the whole thread + every attachment, filtered in PHP.
// The common case (an idle ticket, no new comments) must not even run the attachments read.
//   count_queries (tests/counting_pdo.php) swaps in a CountingPdo and tallies executes — deterministic, not timing.

function cpq_admin(): array
{
    return ['id' => 4, 'role' => 'admin'];
}

function cpq_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('F4 (comment poll): an idle poll issues no attachments read — bounded to 2 queries, not a whole-thread fetch', function (): void {
    $latest = (int) cpq_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM ticket_comments WHERE ticket_id = 3')->fetchColumn();

    $result = null;
    $n = count_queries(function () use (&$result, $latest): void {
        $result = tvm_container()->get(TicketService::class)->getNewComments(3, cpq_admin(), $latest);
    });

    assert_same([], $result, 'nothing is newer than the latest comment id — the poll returns empty');
    // visible-ticket check + one bounded "comments after id" read. The old path also loaded EVERY attachment
    // and EVERY comment on the ticket, so it cost one query more here; reverting getNewComments reds this.
    assert_same(2, $n, 'an idle poll must skip the attachments read entirely');
});

test('F4 (comment poll): a poll after the previous latest returns exactly the new comment', function (): void {
    $before = (int) cpq_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM ticket_comments WHERE ticket_id = 3')->fetchColumn();

    $newId = null;
    try {
        $stmt = cpq_pdo()->prepare(
            'INSERT INTO ticket_comments (ticket_id, user_id, submission_token, body, is_internal, created_at, updated_at)
             VALUES (3, 1, ?, ?, 0, NOW(), NOW())'
        );
        $stmt->execute([bin2hex(random_bytes(32)), 'F4 live-poll new comment']);
        $newId = (int) cpq_pdo()->lastInsertId();

        $new = tvm_container()->get(TicketService::class)->getNewComments(3, cpq_admin(), $before);
        assert_same(1, count($new ?? []), 'only the one comment posted after $before is returned');
        assert_same($newId, (int) ($new[0]['id'] ?? 0), 'and it is exactly the newly posted comment (bounded by id, not filtered whole thread)');
    } finally {
        if ($newId !== null) {
            cpq_pdo()->prepare('DELETE FROM ticket_comments WHERE id = ?')->execute([$newId]);
        }
    }
});
