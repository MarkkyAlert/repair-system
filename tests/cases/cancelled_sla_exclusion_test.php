<?php
declare(strict_types=1);

use App\Repositories\ReportRepository;

// Round-8 F1: cancelling a ticket only flips its status — its ticket_sla_tracks are left as-is. The per-ticket
// detail shows a cancelled ticket as "ไม่คิด SLA", but the three aggregate SLA surfaces (Executive's
// breached_tickets, SLA compliance, SLA breach) counted the leftover breached track → the reports looked
// worse than reality and contradicted the ticket detail. A cancelled ticket must be SLA-non-applicable
// everywhere; a non-cancelled breached ticket is still counted. (Business decision: cancelled only, not rejected.)

function cse_repo(): ReportRepository
{
    return tvm_container()->get(ReportRepository::class);
}

function cse_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('sla: a cancelled ticket with a breached track is excluded from every aggregate SLA (round-8 F1)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    cse_pdo()->prepare('INSERT INTO departments (code, name) VALUES (?, ?)')->execute(["CSED-$rid", "CSE Dept $rid"]);
    $deptId = (int) cse_pdo()->lastInsertId();
    cse_pdo()->prepare('INSERT INTO locations (code, name) VALUES (?, ?)')->execute(["CSEL-$rid", "CSE Loc $rid"]);
    $locId = (int) cse_pdo()->lastInsertId();
    $ticketId = 0;
    $filters = ['department_id' => $deptId];

    $breachedSum = static function (array $rows): int {
        return (int) array_sum(array_map(static fn (array $r): int => (int) ($r['breached'] ?? 0), $rows));
    };

    try {
        // a CANCELLED ticket that still carries a breached resolution SLA track
        cse_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, ?, ?, 1, 1, 'cancelled', NOW())"
        )->execute(["CSET-$rid", $deptId, $locId]);
        $ticketId = (int) cse_pdo()->lastInsertId();
        cse_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, breached_at, status) VALUES (?, 'resolution', ?, ?, 'breached')")
            ->execute([$ticketId, date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() - 3600)]);

        // Executive "เกิน SLA" (breached_tickets) · SLA compliance · SLA breach — none may count the cancelled ticket
        assert_same(0, (int) cse_repo()->getSummary($admin, $filters)['breached_tickets'], 'cancelled not in breached_tickets (Executive เกิน SLA)');
        assert_same(0, $breachedSum(cse_repo()->getSlaComplianceByPriority($admin, $filters)), 'cancelled not in SLA compliance breached');
        assert_same(0, $breachedSum(cse_repo()->getSlaBreachByDimension($admin, $filters, 'priority')), 'cancelled not in SLA breach');

        // guard not over-broad: the SAME ticket, not cancelled, IS still counted as breached
        cse_pdo()->prepare("UPDATE tickets SET status = 'in_progress' WHERE id = ?")->execute([$ticketId]);
        assert_same(1, (int) cse_repo()->getSummary($admin, $filters)['breached_tickets'], 'a non-cancelled breached ticket is still counted');
        assert_same(1, $breachedSum(cse_repo()->getSlaBreachByDimension($admin, $filters, 'priority')), 'non-cancelled breached ticket counts in SLA breach');
    } finally {
        if ($ticketId > 0) {
            cse_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // sla_tracks cascade
        }
        cse_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
        cse_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});
