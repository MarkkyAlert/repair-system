<?php

declare(strict_types=1);

use App\Services\ReportService;

// AN-01 follow-up (2026-07-26): the aggregate SLA numbers were frozen to the period end, but the DETAIL TABLE
// underneath them — and every export built from it — recomputed SLA in the service layer against time().
// So the header could say "0 breached this month" while the row right below it was flagged เกินกำหนด, because
// the row was quietly looking at today's clock. The service also skipped only 'cancelled', so a REJECTED ticket
// was excluded from the totals yet still shown as an SLA breach in its own row.
//
// A report that disagrees with itself on one screen is worse than one that is merely wrong: the reader cannot
// tell which half to believe. The row and the header must answer as of the same moment.

function srp_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** @return array{0:int,1:int,2:int,3:int} dept, location, category, priority */
function srp_dims(string $sfx): array
{
    $pdo = srp_pdo();
    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["SRPD-$sfx", "SrpDept-$sfx"]);

    return [
        (int) $pdo->lastInsertId(),
        (int) $pdo->query('SELECT id FROM locations LIMIT 1')->fetchColumn(),
        (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn(),
        (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn(),
    ];
}

function srp_ticket(string $no, array $d, string $status, string $requestedAt, string $targetAt): int
{
    srp_pdo()->prepare(
        'INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id,
            ticket_category_id, priority_id, status, approval_status, requested_at, response_due_at,
            resolution_due_at, created_at, updated_at)
         VALUES (?, ?, "x", 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    )->execute([
        $no, "row parity $status", $d[0], $d[1], $d[2], $d[3], $status,
        $status === 'rejected' ? 'rejected' : 'approved', $requestedAt, $targetAt, $targetAt,
    ]);
    $ticketId = (int) srp_pdo()->lastInsertId();
    srp_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, cycle, target_at, status, created_at)
                        VALUES (?, 'resolution', 1, ?, 'pending', ?)")->execute([$ticketId, $targetAt, $requestedAt]);

    return $ticketId;
}

/** the detail row for a ticket_no, as the screen renders it */
function srp_row(array $filters, string $ticketNo): ?array
{
    $page = tvm_container()->get(ReportService::class)->getReportPageData(['id' => 4, 'role' => 'admin'], $filters);
    foreach (($page['rows'] ?? []) as $row) {
        if ((string) ($row['ticket_no'] ?? '') === $ticketNo) {
            return $row;
        }
    }

    return null;
}

test('sla(row): a deadline that passes AFTER the period end is not flagged overdue in the detail row or export', function (): void {
    $sfx = bin2hex(random_bytes(4));
    $d = srp_dims($sfx);
    $monthStart = (new DateTimeImmutable('first day of this month'))->modify('-6 months');
    $monthEnd = $monthStart->modify('last day of this month');
    $filters = [
        'from_date' => $monthStart->format('Y-m-d'),
        'to_date' => $monthEnd->format('Y-m-d'),
        'department_id' => $d[0],
    ];
    $ticketNo = "SRP-$sfx";

    try {
        // requested inside the closed month; its deadline only arrives a month later, so as of the period end
        // nothing was late — yet by today's clock it is long overdue.
        srp_ticket(
            $ticketNo,
            $d,
            'in_progress',
            $monthStart->modify('+20 days')->format('Y-m-d H:i:s'),
            $monthEnd->modify('+30 days')->format('Y-m-d H:i:s')
        );

        $svc = tvm_container()->get(ReportService::class);
        $page = $svc->getReportPageData(['id' => 4, 'role' => 'admin'], $filters);

        // the header already reports zero breaches for that month — the row must agree
        $headerBreached = 0;
        foreach (['response', 'resolution'] as $metric) {
            $headerBreached += (int) ($page['slaCompliance']['overall'][$metric]['breached'] ?? 0);
        }
        assert_same(0, $headerBreached, 'sanity: the frozen header says nothing breached in that month');

        $row = srp_row($filters, $ticketNo);
        assert_true($row !== null, 'the ticket appears in the detail table');
        assert_false(
            (bool) $row['sla_overdue'],
            'the row must not read เกินกำหนด while the header above it says zero — both answer as of the period end'
        );
        assert_same('ไม่ใช่', (string) $row['sla_overdue_label'], 'and its printed label agrees');

        // the export is built from the same mapper and must carry the same verdict
        $csv = (string) ($svc->exportCsv(['id' => 4, 'role' => 'admin'], $filters)['content'] ?? '');
        $exportedVerdict = null;
        foreach (explode("\n", trim(substr($csv, 3))) as $line) {
            if (!str_contains($line, $ticketNo)) {
                continue;
            }
            // compare the FIELD, not a substring — "ไม่ใช่" contains "ใช่"
            foreach (str_getcsv(trim($line)) as $field) {
                if (in_array(trim((string) $field), ['ใช่', 'ไม่ใช่'], true)) {
                    $exportedVerdict = trim((string) $field);
                    break 2;
                }
            }
        }
        assert_same('ไม่ใช่', $exportedVerdict, 'the exported row carries the same not-overdue verdict as the screen');
    } finally {
        srp_pdo()->prepare('DELETE FROM tickets WHERE ticket_no = ?')->execute([$ticketNo]);
        srp_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$d[0]]);
    }
});

test('sla(row): a REJECTED ticket is "ไม่คิด SLA" in its row, exactly like a cancelled one', function (): void {
    $sfx = bin2hex(random_bytes(4));
    $d = srp_dims($sfx);
    $requested = date('Y-m-d H:i:s', strtotime('-10 days'));
    $target = date('Y-m-d H:i:s', strtotime('-9 days')); // long past, so the clock would call it breached
    $filters = ['department_id' => $d[0]];

    try {
        srp_ticket("SRPR-$sfx", $d, 'rejected', $requested, $target);
        srp_ticket("SRPC-$sfx", $d, 'cancelled', $requested, $target);

        $rejected = srp_row($filters, "SRPR-$sfx");
        $cancelled = srp_row($filters, "SRPC-$sfx");
        assert_true($rejected !== null && $cancelled !== null, 'both rows are listed');

        assert_same('ไม่คิด SLA', (string) $cancelled['sla_label'], 'baseline: cancelled work is not judged on SLA');
        assert_same(
            'ไม่คิด SLA',
            (string) $rejected['sla_label'],
            'rejected work is not judged either — it is already excluded from the totals, so the row must match'
        );
        assert_false((bool) $rejected['sla_overdue'], 'and it is certainly not counted as a breach');
    } finally {
        srp_pdo()->prepare('DELETE FROM tickets WHERE ticket_no IN (?, ?)')->execute(["SRPR-$sfx", "SRPC-$sfx"]);
        srp_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$d[0]]);
    }
});

// AN-01 verify round 3 (2026-07-26) — the row could still disagree with the header WITHOUT any clock involved.
// getRows handed the service t.response_due_at / t.resolution_due_at / t.first_response_at, which are the CURRENT
// cycle's values. A reopen overwrites them with the new cycle's targets, so a month whose aggregate correctly
// still counted a breach had its own detail row re-judged against a deadline that did not exist yet in that
// month — "ยอดรวม = เกิน, แถว = รอตอบรับ". The as-of moment was right; the deadline was from the wrong cycle.
test('sla(row): after a reopen the row is judged by the cycle that was live in that period, not the new one', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $pdo = srp_pdo();
    $sfx = bin2hex(random_bytes(4));
    $d = srp_dims($sfx);

    $monthStart = (new DateTimeImmutable('first day of this month'))->modify('-6 months');
    $monthEnd = $monthStart->modify('last day of this month');
    $filters = [
        'from_date' => $monthStart->format('Y-m-d'),
        'to_date' => $monthEnd->format('Y-m-d'),
        'department_id' => $d[0],
    ];
    $ticketNo = "SRPRO-$sfx";

    try {
        // a ticket requested in the closed month whose resolution deadline was missed inside that month
        $requested = $monthStart->modify('+2 days')->format('Y-m-d H:i:s');
        $target = $monthStart->modify('+3 days')->format('Y-m-d H:i:s');
        $ticketId = srp_ticket($ticketNo, $d, 'in_progress', $requested, $target);

        $before = srp_row($filters, $ticketNo);
        assert_true((bool) $before['sla_overdue'], 'baseline: inside that month the deadline was already missed');

        // …reopened today. reopenTicket recalculates the ticket's due columns for the NEW cycle and appends cycle 2.
        $newTarget = date('Y-m-d H:i:s', strtotime('+10 days'));
        $pdo->prepare('UPDATE tickets SET response_due_at = ?, resolution_due_at = ?, first_response_at = NULL WHERE id = ?')
            ->execute([$newTarget, $newTarget, $ticketId]);
        $pdo->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, cycle, target_at, status, created_at)
                       VALUES (?, 'resolution', 2, ?, 'pending', NOW())")->execute([$ticketId, $newTarget]);

        // the aggregate for that closed month is unchanged (it reads the cycle that existed then)
        $headerBreached = 0;
        $compliance = $svc->getReportPageData($admin, $filters)['slaCompliance']['overall'] ?? [];
        foreach (['response', 'resolution'] as $metric) {
            $headerBreached += (int) ($compliance[$metric]['breached'] ?? 0);
        }
        assert_true($headerBreached >= 1, 'sanity: the header still records the breach for that month');

        $after = srp_row($filters, $ticketNo);
        assert_true(
            (bool) $after['sla_overdue'],
            'the row must still show the breach — a reopen today cannot re-judge a closed month against tomorrow\'s deadline'
        );
        assert_same('แก้ไขเกินกำหนด', (string) $after['sla_label'], 'and its label still names the missed resolution');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE ticket_no = ?')->execute([$ticketNo]);
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$d[0]]);
    }
});

// A future to_date is selectable in the UI (the date inputs are not capped). The service clamped its as-of moment
// to "now" while the repository used the future date verbatim, so the two halves of one screen judged SLA at
// different moments: the header called both metrics breached while the row said รอแก้ไข.
test('sla(row): a FUTURE to_date is clamped the same way in both layers', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $pdo = srp_pdo();
    $sfx = bin2hex(random_bytes(4));
    $d = srp_dims($sfx);
    $ticketNo = "SRPF-$sfx";

    try {
        // deadline lands a week from now — nothing is late yet, whichever way you look at it
        $requested = date('Y-m-d H:i:s', strtotime('-2 days'));
        $target = date('Y-m-d H:i:s', strtotime('+7 days'));
        srp_ticket($ticketNo, $d, 'in_progress', $requested, $target);

        // …but the user picks an end date a month into the future
        $filters = [
            'from_date' => date('Y-m-d', strtotime('-30 days')),
            'to_date' => date('Y-m-d', strtotime('+30 days')),
            'department_id' => $d[0],
        ];

        $page = $svc->getReportPageData($admin, $filters);
        $headerBreached = 0;
        foreach (['response', 'resolution'] as $metric) {
            $headerBreached += (int) ($page['slaCompliance']['overall'][$metric]['breached'] ?? 0);
        }
        assert_same(0, $headerBreached, 'the header must not treat a future date as already elapsed');

        $row = srp_row($filters, $ticketNo);
        assert_false((bool) $row['sla_overdue'], 'and the row agrees — neither layer may read the calendar ahead of today');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE ticket_no = ?')->execute([$ticketNo]);
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$d[0]]);
    }
});

// AN-01 round 5 (2026-07-26) — the SLA panel filtered on the CURRENT status while the totals and the table beside
// it filtered as-of the period end. Open a closed month with a status filter (say "assigned"), let the technician
// accept the work today, and the panel empties out while the header and rows correctly stay put: one screen,
// three components, two different answers about what that month contained.
test('sla(panel): the SLA panel obeys the same as-of status filter as the totals and the table', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $pdo = srp_pdo();
    $sfx = bin2hex(random_bytes(4));
    $d = srp_dims($sfx);

    $monthStart = (new DateTimeImmutable('first day of this month'))->modify('-6 months');
    $monthEnd = $monthStart->modify('last day of this month');
    // the reader is looking at work that sat "assigned" during that closed month
    $filters = [
        'from_date' => $monthStart->format('Y-m-d'),
        'to_date' => $monthEnd->format('Y-m-d'),
        'department_id' => $d[0],
        'status' => 'assigned',
    ];
    $ticketNo = "SRPP-$sfx";

    try {
        $requested = $monthStart->modify('+2 days')->format('Y-m-d H:i:s');
        $target = $monthStart->modify('+3 days')->format('Y-m-d H:i:s');
        $ticketId = srp_ticket($ticketNo, $d, 'assigned', $requested, $target);
        // it also needs an activity log so the as-of status snapshot can see it was 'assigned' back then
        $pdo->prepare("INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status, created_at)
                       VALUES (?, 4, 'technician_assigned', 'approved', 'assigned', ?)")->execute([$ticketId, $requested]);

        $panelBefore = $svc->getReportPageData($admin, $filters)['slaCompliance']['overall'] ?? [];
        $breachedBefore = (int) ($panelBefore['resolution']['breached'] ?? 0);
        assert_true($breachedBefore >= 1, 'sanity: during that month the assigned work had already missed its deadline');

        // today the technician finally accepts it — t.status stops being 'assigned'
        $pdo->prepare("UPDATE tickets SET status = 'accepted', first_response_at = NOW() WHERE id = ?")->execute([$ticketId]);
        $pdo->prepare("INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status, created_at)
                       VALUES (?, 3, 'work_accepted', 'assigned', 'accepted', NOW())")->execute([$ticketId]);

        $panelAfter = $svc->getReportPageData($admin, $filters)['slaCompliance']['overall'] ?? [];
        assert_same(
            $breachedBefore,
            (int) ($panelAfter['resolution']['breached'] ?? -1),
            'the panel must still describe what that month looked like — accepting today does not remove the '
            . 'ticket from a month in which it WAS assigned'
        );
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE ticket_no = ?')->execute([$ticketNo]);
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$d[0]]);
    }
});
