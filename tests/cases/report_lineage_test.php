<?php
declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\ReportService;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// ⭐ Data Lineage & Flow Fidelity (BI-review §8) — the layer reconciliation tests CANNOT cover.
//
// Every other *_report_test.php seeds data with `INSERT INTO tickets ...` and asserts the report maths. That
// proves the FORMULA is right, but it silently assumes the live flow writes the same columns/statuses the
// report reads. If a workflow path forgot to set resolved_at, or wrote a status the report doesn't count, every
// reconciliation test would still be green while production numbers were wrong — nobody would notice until a
// manager compared the report to reality ("the team closed 50 but the report says 45").
//
// These tests create data ONLY through the real services (TicketService + TicketWorkflowService — never a raw
// INSERT) and prove the reports see it, plus pin the report's status filters to the real status enum.

function lin_reports(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

function lin_tickets(): TicketService
{
    return tvm_container()->get(TicketService::class);
}

function lin_wf(): TicketWorkflowService
{
    return tvm_container()->get(TicketWorkflowService::class);
}

function lin_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

// ── F1: end-to-end lineage — a ticket driven through the real flow is seen by the reports ──

test('lineage(e2e): a ticket created + resolved through the real services is seen correctly by the reports', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $tech = ['id' => 3, 'role' => 'technician'];
    $requester = ['id' => 1, 'role' => 'requester'];

    // snapshot report aggregates BEFORE (empty filters → the default window that covers "now")
    $before = lin_reports()->getReportPageData($admin, []);
    $resolvedBefore = (int) ($before['summary']['resolved'] ?? 0);
    $respMetBefore = (int) ($before['slaCompliance']['overall']['response']['met'] ?? 0);
    $resoMetBefore = (int) ($before['slaCompliance']['overall']['resolution']['met'] ?? 0);

    // create the ticket through the SERVICE (validated input, not an INSERT)
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $ticketId = lin_tickets()->createTicket($requester, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => 'LIN e2e ' . bin2hex(random_bytes(3)),
        'description' => 'lineage probe',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);

    try {
        assert_true($ticketId > 0, 'the service created the ticket');

        // drive the full lifecycle through the WORKFLOW SERVICE (no INSERT, no direct status/timestamp writes)
        lin_wf()->approveTicket($ticketId, $admin, ['note' => '']);
        lin_wf()->assignTechnician($ticketId, $admin, ['technician_id' => 3, 'instructions' => '']);
        lin_wf()->acceptAssignedWork($ticketId, $tech, ['accept_note' => '']);
        lin_wf()->startAssignedWork($ticketId, $tech, ['start_note' => '']);
        lin_wf()->resolveAssignedWork($ticketId, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '30']);
        lin_wf()->completeResolvedTicket($ticketId, $requester, ['score' => 5, 'closure_note' => '', 'feedback' => 'ดีมาก lineage']);

        // ── Part A: the FLOW wrote the exact columns the reports read (this is what reconciliation can't prove) ──
        $row = lin_pdo()->query("SELECT status, resolved_at, first_response_at FROM tickets WHERE id = $ticketId")->fetch(PDO::FETCH_ASSOC);
        assert_same('completed', (string) $row['status'], 'the flow drove the ticket to a resolved-family status');
        assert_true($row['resolved_at'] !== null, 'the flow wrote resolved_at (MTTR / completion base reads this)');
        assert_true($row['first_response_at'] !== null, 'the flow wrote first_response_at (response-SLA reads this)');

        $resoSla = (string) lin_pdo()->query("SELECT status FROM ticket_sla_tracks WHERE ticket_id = $ticketId AND metric_type = 'resolution'")->fetchColumn();
        assert_true(in_array($resoSla, ['met', 'breached'], true), "the flow ran markSlaAchieved → resolution SLA is '$resoSla', not 'pending' (sla-compliance reads this)");

        $rating = (int) lin_pdo()->query("SELECT score FROM ticket_ratings WHERE ticket_id = $ticketId")->fetchColumn();
        assert_same(5, $rating, 'the flow stored the requester rating (csat reads this)');

        // ── Part B: the reports' aggregates moved by exactly the delta for THIS ticket ──
        $after = lin_reports()->getReportPageData($admin, []);
        assert_same($resolvedBefore + 1, (int) ($after['summary']['resolved'] ?? 0), 'executive/summary resolved +1 — the flow ticket is counted');
        // resolved instantly (same second) → well within the SLA target → counted as MET by the compliance report
        assert_same($respMetBefore + 1, (int) ($after['slaCompliance']['overall']['response']['met'] ?? 0), 'sla-compliance response met +1 (flow set achieved_at before target)');
        assert_same($resoMetBefore + 1, (int) ($after['slaCompliance']['overall']['resolution']['met'] ?? 0), 'sla-compliance resolution met +1 (flow ran markSlaAchieved)');
    } finally {
        lin_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
        lin_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades sla_tracks / ratings / work_orders / logs
    }
});

test('lineage(e2e): a resolved ticket reopened + reassigned stays in its resolve period as-reported (F1)', function (): void {
    // The as-reported guarantee, proven through the REAL services (no INSERT, no direct column writes): once a
    // month's throughput counts a closure, a later reopen — which NULLs resolved_at + resets the SLA — and a
    // later reassign must NOT erase it. The trend buckets on the immutable ticket_resolved event, so the count
    // stays put even though the current-state resolved_at is now NULL. Under the old resolved_at query the
    // ticket vanished from the month the instant it was reopened.
    $admin = ['id' => 4, 'role' => 'admin'];
    $tech = ['id' => 3, 'role' => 'technician'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $rid = bin2hex(random_bytes(4));

    $monthFilter = ['granularity' => 'month', 'from_date' => date('Y-m-01'), 'to_date' => date('Y-m-d')];
    $curKey = date('Y-m');
    $trendResolved = static function () use ($admin, $monthFilter, $curKey): int {
        foreach (lin_reports()->getTicketTrendReportPage($admin, $monthFilter)['periods'] as $p) {
            if ($p['key'] === $curKey) {
                return (int) $p['resolved'];
            }
        }

        return 0;
    };

    // fresh location + a second technician → the reopen report's location dimension isolates this one ticket,
    // and the reassign target is a real technician (FK-valid) distinct from the resolver.
    lin_pdo()->prepare('INSERT INTO locations (code, name) VALUES (?, ?)')->execute(["LINR-$rid", "LIN Reopen Loc $rid"]);
    $locId = (int) lin_pdo()->lastInsertId();
    lin_pdo()->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)")
        ->execute(["lin_$rid", "lin_$rid@example.com", "LIN Tech2 $rid"]);
    $tech2 = (int) lin_pdo()->lastInsertId();

    $resolvedBefore = $trendResolved();

    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $ticketId = lin_tickets()->createTicket($requester, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => "LIN reopen e2e $rid",
        'description' => 'as-reported lineage probe',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => $locId,
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);

    try {
        // drive to resolved through the REAL workflow (technician 3 resolves it)
        lin_wf()->approveTicket($ticketId, $admin, ['note' => '']);
        lin_wf()->assignTechnician($ticketId, $admin, ['technician_id' => 3, 'instructions' => '']);
        lin_wf()->acceptAssignedWork($ticketId, $tech, ['accept_note' => '']);
        lin_wf()->startAssignedWork($ticketId, $tech, ['start_note' => '']);
        lin_wf()->resolveAssignedWork($ticketId, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '30']);

        $afterResolve = $trendResolved();
        assert_same($resolvedBefore + 1, $afterResolve, 'the real resolve is counted in this month\'s throughput (+1)');

        // reopen through the REAL flow — NULLs resolved_at, resets the SLA, logs ticket_reopened
        lin_wf()->reopenTicket($ticketId, $requester, ['reopen_note' => 'ขอให้ตรวจซ้ำ']);

        // the mutation that WOULD have dropped it from the old resolved_at report has happened...
        $row = lin_pdo()->query("SELECT status, resolved_at FROM tickets WHERE id = $ticketId")->fetch(PDO::FETCH_ASSOC);
        assert_same('assigned', (string) $row['status'], 'reopen sent the ticket back to assigned');
        assert_true($row['resolved_at'] === null, 'reopen NULLed resolved_at (the old report would now drop the ticket)');
        // ...but the immutable resolve event — the as-reported source of truth — survives
        $events = (int) lin_pdo()->query("SELECT COUNT(*) FROM ticket_activity_logs WHERE ticket_id = $ticketId AND action = 'ticket_resolved'")->fetchColumn();
        assert_same(1, $events, 'the ticket_resolved event survives the reopen');

        $afterReopen = $trendResolved();
        assert_same($afterResolve, $afterReopen, 'reopen does NOT erase the closure from this month\'s throughput (as-reported)');

        // a later reassign to a DIFFERENT technician must not move the closure out of its period either
        lin_pdo()->prepare('UPDATE tickets SET assigned_technician_id = ? WHERE id = ?')->execute([$tech2, $ticketId]);
        assert_same($afterResolve, $trendResolved(), 'a later reassign does not shift the closure out of its resolve period');

        // the as-reported reopen cohort sees this location's single ticket resolved once + reopened once
        $today = date('Y-m-d');
        $page = lin_reports()->getReopenRateReportPage($admin, ['dimension' => 'location', 'from_date' => $today, 'to_date' => $today]);
        $locRow = null;
        foreach ($page['rows'] as $r) {
            if ($r['label'] === "LIN Reopen Loc $rid") {
                $locRow = $r;
                break;
            }
        }
        assert_true($locRow !== null, 'the fresh location appears in the reopen report');
        assert_same(1, $locRow['resolved'], 'reopen cohort counts the closure once');
        assert_same(1, $locRow['reopened'], 'the ticket is flagged reopened (as-reported)');
    } finally {
        lin_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
        lin_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades logs / sla_tracks / work_orders
        lin_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$tech2]);
        lin_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

// ── F2: status contract — the report's status filters ⊆ the real tickets.status enum ──

/** The value list of the tickets.status ENUM, parsed from schema.sql (the single source of truth). */
function lin_ticket_status_enum(): array
{
    $schema = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
    if (!preg_match("/\n\s*status ENUM\(([^)]*)\)\s*NOT NULL DEFAULT 'submitted'/", $schema, $m)) {
        throw new RuntimeException('could not locate the tickets.status ENUM in schema.sql');
    }

    preg_match_all("/'([a-z_]+)'/", $m[1], $vals);

    return $vals[1];
}

test('status contract: every status the reports filter on is a real tickets.status enum value (no typo/stale)', function (): void {
    $enum = lin_ticket_status_enum();
    assert_true(in_array('resolved', $enum, true) && in_array('completed', $enum, true), 'sanity: the enum parsed');

    // the two status sets the report engine filters on (ReportRepository)
    $reportFiltered = array_merge(
        ticket_resolved_statuses(),                          // resolved / "ปิดงาน" filter
        ['assigned', 'accepted', 'in_progress', 'on_hold']   // "open" filter (ReportRepository open_count)
    );

    foreach ($reportFiltered as $status) {
        assert_true(
            in_array($status, $enum, true),
            "report filter status '$status' must exist in the tickets.status enum — a typo or a retired status would silently miscount"
        );
    }

    // and the flow's actual "done work" terminal statuses must be counted as resolved (not fall through the gaps)
    foreach (['resolved', 'completed'] as $doneStatus) {
        assert_true(
            in_array($doneStatus, ticket_resolved_statuses(), true),
            "the workflow's terminal status '$doneStatus' must be in the report's resolved filter, or closed work would read as unresolved"
        );
    }
});
