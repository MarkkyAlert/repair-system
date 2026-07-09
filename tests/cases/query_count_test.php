<?php
declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\TicketService;

// Deterministic N+1 regression guards (query count, not timing). count_queries() (tests/counting_pdo.php)
// swaps the container PDO for a CountingPdo and tallies every execute/query/exec. These lock two hot-path
// strengths the performance review verified: the ticket list is one paginated JOIN (not per-row), and the
// ticket detail batches attachments/comments (query count stays flat as comments grow — no per-comment N+1).

function qc_admin(): array
{
    return ['id' => 4, 'role' => 'admin'];
}

test('query-count(ticket-list): getVisibleTicketsPage issues a constant 2 queries (single JOIN, not per-row)', function (): void {
    $n = count_queries(function (): void {
        tvm_container()->get(TicketReadRepository::class)->getVisibleTicketsPage(qc_admin(), [], 1, 20);
    });

    assert_same(2, $n, 'the ticket list must be one COUNT + one paginated SELECT, regardless of how many rows');
});

test('query-count(ticket-detail): getTicketDetailData stays flat as comments grow (attachments batched, not N+1)', function (): void {
    $detailOfTicket3 = static fn (): mixed => tvm_container()->get(TicketService::class)->getTicketDetailData(3, qc_admin());

    $baseline = count_queries($detailOfTicket3); // ticket #3 as seeded (1 comment)

    // add several comments to the SAME ticket — a per-comment fetch would make the count grow; a batched
    // assembly keeps it identical
    $insertedIds = [];
    try {
        $pdo = tvm_container()->get(PDO::class);
        $stmt = $pdo->prepare(
            'INSERT INTO ticket_comments (ticket_id, user_id, submission_token, body, is_internal, created_at, updated_at)
             VALUES (3, 1, ?, ?, 0, NOW(), NOW())'
        );
        for ($i = 0; $i < 4; $i++) {
            $stmt->execute([bin2hex(random_bytes(32)), "qc extra comment $i"]);
            $insertedIds[] = (int) $pdo->lastInsertId();
        }

        $withFiveComments = count_queries($detailOfTicket3);
        assert_same(
            $baseline,
            $withFiveComments,
            'ticket-detail query count must NOT grow with comment count (no per-comment N+1)'
        );
    } finally {
        if ($insertedIds !== []) {
            $placeholders = implode(',', array_fill(0, count($insertedIds), '?'));
            tvm_container()->get(PDO::class)
                ->prepare("DELETE FROM ticket_comments WHERE id IN ($placeholders)")
                ->execute($insertedIds);
        }
    }
});
