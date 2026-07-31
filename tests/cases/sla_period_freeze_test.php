<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\ReportService;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// AN-01 (2026-07-26): a closed reporting period must never rewrite itself.
//
// The period-freeze work (64943f7) snapshotted ticket STATUS at the period cutoff, but the SLA queries kept
// picking the latest cycle with MAX(cycle) and judging "pending but overdue" against NOW(). Reopening an old
// ticket appends a NEW cycle whose track is pending and not yet due, so the SLA queries silently switched to it
// and January's "breached = 1" became "0 / -" months later. A manager who printed January's SLA in February and
// again in August got two different answers with no edit in between — the report appeared to edit its own history,
// and a bad month could be laundered clean simply by reopening a ticket.
//
// The fix evaluates SLA as-of the end of the selected window: only cycles that existed by then are considered,
// and a deadline counts as missed only if it had already passed by then.

function spf_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** Drive a ticket through the REAL workflow to resolved (never a raw INSERT), then return its id. */
function spf_make_resolved_ticket(int $locationId): int
{
    $tickets = tvm_container()->get(TicketService::class);
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $tech = ['id' => 3, 'role' => 'technician'];

    $id = $tickets->createTicket($requester, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => 'sla-period-freeze',
        'description' => 'x',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => $locationId,
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);
    $wf->approveTicket($id, $admin, ['note' => '']);
    $wf->assignTechnician($id, $admin, ['technician_id' => 3, 'instructions' => '']);
    $wf->acceptAssignedWork($id, $tech, ['accept_note' => '']);
    $wf->startAssignedWork($id, $tech, ['start_note' => '']);
    $wf->resolveAssignedWork($id, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '10']);

    return $id;
}

/** Move the whole ticket (and its cycle-1 SLA tracks + activity log) back into the given month, resolved LATE. */
function spf_time_travel_to_breach(int $ticketId, DateTimeImmutable $monthStart): void
{
    $requested = $monthStart->modify('+1 day')->format('Y-m-d H:i:s');
    $target = $monthStart->modify('+2 days')->format('Y-m-d H:i:s');
    $resolvedLate = $monthStart->modify('+9 days')->format('Y-m-d H:i:s'); // a week past the deadline

    $pdo = spf_pdo();
    $pdo->prepare('UPDATE tickets SET requested_at = ?, response_due_at = ?, resolution_due_at = ?, resolved_at = ?, first_response_at = ?, started_at = ? WHERE id = ?')
        ->execute([$requested, $target, $target, $resolvedLate, $requested, $requested, $ticketId]);
    // response was answered on time; only the RESOLUTION deadline was missed → exactly one breach in the month
    $pdo->prepare("UPDATE ticket_sla_tracks SET created_at = ?, target_at = ?, achieved_at = ?, breached_at = NULL, status = 'met' WHERE ticket_id = ? AND cycle = 1 AND metric_type = 'response'")
        ->execute([$requested, $target, $requested, $ticketId]);
    $pdo->prepare("UPDATE ticket_sla_tracks SET created_at = ?, target_at = ?, achieved_at = NULL, breached_at = ?, status = 'breached' WHERE ticket_id = ? AND cycle = 1 AND metric_type = 'resolution'")
        ->execute([$requested, $target, $resolvedLate, $ticketId]);
    $pdo->prepare('UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ?')
        ->execute([$resolvedLate, $ticketId]);
}

/** breached count for the window, from BOTH SLA surfaces. */
function spf_sla_numbers(int $locationId, string $from, string $to): array
{
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $filters = ['from_date' => $from, 'to_date' => $to, 'location_id' => $locationId];

    $breachSummary = $svc->getSlaBreachReportPage($admin, $filters + ['dimension' => 'location'])['summary'] ?? [];

    $compliance = $svc->getReportPageData($admin, $filters)['slaCompliance']['overall'] ?? [];
    $complianceBreached = 0;
    foreach (['response', 'resolution'] as $metric) {
        $complianceBreached += (int) ($compliance[$metric]['breached'] ?? 0);
    }

    return [
        'breach_report' => (int) ($breachSummary['total_breached'] ?? 0),
        'compliance' => $complianceBreached,
    ];
}

test('sla(freeze): reopening an old ticket must NOT rewrite the closed period it was breached in', function (): void {
    $pdo = spf_pdo();
    $sfx = bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO locations (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["SPFL-$sfx", "SpfLoc-$sfx"]);
    $locationId = (int) $pdo->lastInsertId();

    // a fully closed month well in the past
    $monthStart = (new DateTimeImmutable('first day of this month'))->modify('-6 months');
    $from = $monthStart->format('Y-m-d');
    $to = $monthStart->modify('last day of this month')->format('Y-m-d');
    $ticketId = 0;

    try {
        $ticketId = spf_make_resolved_ticket($locationId);
        spf_time_travel_to_breach($ticketId, $monthStart);

        $before = spf_sla_numbers($locationId, $from, $to);
        assert_same(1, $before['breach_report'], 'the closed month starts with exactly one recorded SLA breach');
        assert_same(1, $before['compliance'], 'and the compliance panel agrees for the same month');

        // …months later the requester reopens it. This appends SLA cycle 2, pending and not yet due.
        tvm_container()->get(TicketWorkflowService::class)
            ->reopenTicket($ticketId, ['id' => 1, 'role' => 'requester'], ['reopen_note' => 'ยังไม่หาย']);
        assert_true(
            (int) $pdo->query("SELECT MAX(cycle) FROM ticket_sla_tracks WHERE ticket_id = $ticketId")->fetchColumn() >= 2,
            'sanity: the reopen really did append a newer SLA cycle'
        );

        $after = spf_sla_numbers($locationId, $from, $to);
        assert_same(
            1,
            $after['breach_report'],
            'the closed month still reports its breach — a reopen today cannot launder a past SLA miss'
        );
        assert_same(1, $after['compliance'], 'the compliance panel for that month is equally frozen');
    } finally {
        if ($ticketId > 0) {
            $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        $pdo->prepare('DELETE FROM locations WHERE id = ?')->execute([$locationId]);
    }
});

test('sla(freeze): a deadline that passes AFTER the period end is not backdated into that period', function (): void {
    $pdo = spf_pdo();
    $sfx = bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO locations (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["SPFL-$sfx", "SpfLoc-$sfx"]);
    $locationId = (int) $pdo->lastInsertId();

    $monthStart = (new DateTimeImmutable('first day of this month'))->modify('-6 months');
    $monthEnd = $monthStart->modify('last day of this month');
    $catId = (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn();
    $priId = (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn();

    try {
        // requested inside the month, but its deadline lands a month AFTER the period closed
        $requested = $monthStart->modify('+20 days')->format('Y-m-d H:i:s');
        $target = $monthEnd->modify('+30 days')->format('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id,
                priority_id, status, approval_status, requested_at, resolution_due_at, created_at, updated_at)
             VALUES (?, ?, "x", 1, ?, ?, ?, "in_progress", "approved", ?, ?, NOW(), NOW())'
        )->execute(["SPF2-$sfx", 'later deadline', $locationId, $catId, $priId, $requested, $target]);
        $ticketId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, cycle, target_at, status, created_at)
                       VALUES (?, 'resolution', 1, ?, 'pending', ?)")->execute([$ticketId, $target, $requested]);

        $numbers = spf_sla_numbers($locationId, $monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d'));
        assert_same(
            0,
            $numbers['breach_report'],
            'at the end of that month the deadline had not arrived, so the month must not report a breach'
        );

        $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE ticket_no = ?')->execute(["SPF2-$sfx"]);
        $pdo->prepare('DELETE FROM locations WHERE id = ?')->execute([$locationId]);
    }
});
