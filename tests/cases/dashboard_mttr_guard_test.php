<?php
declare(strict_types=1);

use App\Repositories\TicketReadRepository;

// bug-hunt R5-2: getDashboardMonthlyResolutionAverages averaged TIMESTAMPDIFF(requested_at → resolved_at) with NO
// `resolved_at >= requested_at` clamp — the guard every report query applies. A ticket whose resolved_at is BEFORE
// its requested_at (bad import / clock skew / an admin editing requested_at) contributed a NEGATIVE duration, so
// the month's average was dragged low (or negative) and the dashboard chart disagreed with the report for the same
// work. The query now clamps like the report layer. A far-future year isolates the two seeded tickets.

function dmg_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function dmg_seed_resolved(string $requestedAt, string $resolvedAt): int
{
    $loc = (int) dmg_pdo()->query('SELECT COALESCE((SELECT id FROM locations LIMIT 1), 1)')->fetchColumn();
    dmg_pdo()->prepare(
        "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at, resolved_at)
         VALUES (?, 'x', 'x', 1, ?, 1, 1, 'resolved', ?, ?)"
    )->execute(['DMG-' . bin2hex(random_bytes(5)), $loc, $requestedAt, $resolvedAt]);

    return (int) dmg_pdo()->lastInsertId();
}

test('dashboard MTTR: a backwards-timestamp ticket does not drag/negate the monthly resolution average — R5-2', function (): void {
    $repo = tvm_container()->get(TicketReadRepository::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $year = 2091; // far future → only the seeded tickets resolve in this window
    $ids = [];

    try {
        // June: one legit 120-minute resolution + one backwards ticket (resolved 60 min BEFORE it was requested)
        $ids[] = dmg_seed_resolved("$year-06-10 09:00:00", "$year-06-10 11:00:00"); // +120 min
        $ids[] = dmg_seed_resolved("$year-06-15 10:00:00", "$year-06-15 09:00:00"); // -60 min (backwards)

        $rows = $repo->getDashboardMonthlyResolutionAverages($admin, [], $year);
        $june = null;
        foreach ($rows as $r) {
            if ((int) ($r['month_no'] ?? 0) === 6) {
                $june = $r;
            }
        }

        assert_true($june !== null, 'June has resolved tickets in the isolated year');
        assert_same(120.0, (float) $june['avg_minutes'], 'the month average counts only the valid +120 row, not avg(120, -60) = 30');
    } finally {
        foreach ($ids as $id) {
            dmg_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
    }
});
