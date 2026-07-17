<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\TicketService;

// Extreme-data guards (UX-review "missing evidence #6": the captured screens used a small seed, so scale +
// very long text weren't exercised). These seed thousands of rows / max-length strings into the TEST db and
// assert the app holds up, then clean up in finally (record MAX(id) as a floor, DELETE > floor) so the shared
// test DB is never polluted — the same pattern as query_count_test.

function xd_admin(): array
{
    return ['id' => 4, 'role' => 'admin'];
}

test('extreme(scale): the ticket list stays correct + a flat 2 queries with thousands of rows (no COUNT cap, no N+1)', function (): void {
    $pdo = tvm_container()->get(PDO::class);
    $admin = xd_admin();
    $seed = 2000;

    $baseline = (int) tvm_container()->get(TicketReadRepository::class)->getVisibleTicketsPage($admin, [], 1, 20)['total'];
    $floor = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM tickets')->fetchColumn();

    try {
        // Batched multi-row inserts so seeding thousands of rows is a handful of round-trips, not 2000.
        $chunk = 500;
        $prefix = 'INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at) VALUES ';
        for ($start = 0; $start < $seed; $start += $chunk) {
            $count = min($chunk, $seed - $start);
            $tuples = [];
            $params = [];
            for ($i = 0; $i < $count; $i++) {
                $tuples[] = "(?, 'load test', 'load test', 1, 1, 1, 1, 'submitted', NOW())";
                $params[] = 'BIGLOAD-' . $start . '-' . $i . '-' . bin2hex(random_bytes(3));
            }
            $pdo->prepare($prefix . implode(',', $tuples))->execute($params);
        }

        $result = tvm_container()->get(TicketReadRepository::class)->getVisibleTicketsPage($admin, [], 1, 20);
        // The total COUNTs every row — no silent LIMIT/cap that would under-report at scale.
        assert_same($baseline + $seed, (int) $result['total'], 'the list total must count every row, not a capped subset');
        assert_same(20, count($result['items']), 'a page returns exactly perPage rows');
        assert_true((int) $result['totalPages'] >= 100, 'totalPages scales with the real total (>= 100 for 2000 rows @ 20/page)');

        // A deep page near the end still fills — offset pagination holds at scale, not just page 1.
        $deep = tvm_container()->get(TicketReadRepository::class)->getVisibleTicketsPage($admin, [], (int) $result['totalPages'] - 1, 20);
        assert_same(20, count($deep['items']), 'a deep page still returns a full page of rows');

        // The query count is CONSTANT (COUNT + one paginated SELECT) with 2000 rows present — no per-row N+1.
        $queries = count_queries(fn () => tvm_container()->get(TicketReadRepository::class)->getVisibleTicketsPage($admin, [], 1, 20));
        assert_same(2, $queries, 'listing thousands of rows is still one COUNT + one paginated SELECT');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE id > ?')->execute([$floor]);
    }
});

test('extreme(length): a max-length title + a huge description round-trip and render without error', function (): void {
    $pdo = tvm_container()->get(PDO::class);
    $admin = xd_admin();
    $floor = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM tickets')->fetchColumn();

    $longTitle = str_repeat('ก', 200);                       // exactly VARCHAR(200), full-width Thai
    $hugeDesc = str_repeat('รายละเอียดที่ยาวมากผิดปกติ ', 4000); // tens of thousands of chars into LONGTEXT
    $ticketNo = 'LONGSTR-' . bin2hex(random_bytes(6));

    try {
        $pdo->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at)
             VALUES (?, ?, ?, 1, 1, 1, 1, 'submitted', NOW())"
        )->execute([$ticketNo, $longTitle, $hugeDesc]);
        $id = (int) $pdo->lastInsertId();

        // Round-trip at the DB layer: the full 200-char title and the huge description are stored intact.
        $row = $pdo->query('SELECT title, description FROM tickets WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        assert_same(200, mb_strlen((string) $row['title']), 'a 200-char title stores at full length, not truncated');
        assert_same(mb_strlen($hugeDesc), mb_strlen((string) $row['description']), 'the huge description stores intact in LONGTEXT');

        // List path: the long-titled ticket surfaces and its title is returned unchanged (newest → page 1).
        $page = tvm_container()->get(TicketReadRepository::class)->getVisibleTicketsPage($admin, [], 1, 20);
        $found = array_values(array_filter($page['items'], static fn (array $t): bool => ($t['ticket_no'] ?? '') === $ticketNo));
        assert_same(1, count($found), 'the long-titled ticket appears in the list');
        assert_same($longTitle, (string) $found[0]['title'], 'the list returns the full 200-char title unchanged');
        // Escaping the long title neither corrupts nor drops content (renders safely in a view).
        assert_true(mb_strlen(e($longTitle)) >= 200, 'e() escapes the long title without losing characters');

        // Detail assembly does not choke on a huge description.
        $detail = tvm_container()->get(TicketService::class)->getTicketDetailData($id, $admin);
        assert_true(is_array($detail) && $detail !== [], 'ticket detail assembles for a huge-description ticket without error');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE id > ?')->execute([$floor]);
    }
});
