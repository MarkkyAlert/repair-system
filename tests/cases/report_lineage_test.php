<?php
declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\AdminService;
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

test('lineage(e2e): resolver attribution survives a post-resolve reassign — technician report + reopen dimension (F1 Phase 2 Part A)', function (): void {
    // As-reported (Phase 2): resolved credit + reopen "blame" attach to the technician who ACTUALLY resolved
    // (the `ticket_resolved` event actor), NOT the current assignee. Proven through the REAL services: technician
    // 3 resolves, then the ticket is reassigned to a different technician — 3 keeps the credit, the new assignee
    // never gains it, in BOTH the Technician Performance report and the reopen technician dimension.
    $admin = ['id' => 4, 'role' => 'admin'];
    $tech = ['id' => 3, 'role' => 'technician'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $rid = bin2hex(random_bytes(4));
    $today = date('Y-m-d');
    $reopenWin = ['dimension' => 'technician', 'from_date' => $today, 'to_date' => $today];

    $resolverName = (string) lin_pdo()->query('SELECT full_name FROM users WHERE id = 3')->fetchColumn();
    lin_pdo()->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)")
        ->execute(["linp_$rid", "linp_$rid@example.com", "LIN P2 Tech2 $rid"]);
    $tech2 = (int) lin_pdo()->lastInsertId();
    $tech2Name = "LIN P2 Tech2 $rid";

    // resolved-by-technician count in the dedicated Technician Performance report (all-time)
    $techReportResolved = static function (string $name) use ($admin): int {
        foreach (lin_reports()->getTechnicianPerformanceReportPage($admin, [])['rows'] as $r) {
            if ($r['full_name'] === $name) {
                return (int) $r['resolved'];
            }
        }

        return 0;
    };
    // resolved count in the reopen report technician dimension (today window)
    $reopenResolved = static function (string $name) use ($admin, $reopenWin): int {
        foreach (lin_reports()->getReopenRateReportPage($admin, $reopenWin)['rows'] as $r) {
            if ($r['label'] === $name) {
                return (int) $r['resolved'];
            }
        }

        return 0;
    };

    $tech3ReportBefore = $techReportResolved($resolverName);
    $tech3ReopenBefore = $reopenResolved($resolverName);
    $tech2ReportBefore = $techReportResolved($tech2Name);
    $tech2ReopenBefore = $reopenResolved($tech2Name);

    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $ticketId = lin_tickets()->createTicket($requester, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => "LIN attrib e2e $rid",
        'description' => 'resolver attribution lineage probe',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);

    try {
        // resolve through the REAL workflow — technician 3 is the resolve-event actor
        lin_wf()->approveTicket($ticketId, $admin, ['note' => '']);
        lin_wf()->assignTechnician($ticketId, $admin, ['technician_id' => 3, 'instructions' => '']);
        lin_wf()->acceptAssignedWork($ticketId, $tech, ['accept_note' => '']);
        lin_wf()->startAssignedWork($ticketId, $tech, ['start_note' => '']);
        lin_wf()->resolveAssignedWork($ticketId, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '30']);

        assert_same($tech3ReportBefore + 1, $techReportResolved($resolverName), 'tech report: the resolver is credited +1 after the real resolve');
        assert_same($tech3ReopenBefore + 1, $reopenResolved($resolverName), 'reopen dim: the closure is attributed to the technician who resolved it');

        // reassign to a DIFFERENT technician — the current assignee changes, the resolve event does not
        lin_pdo()->prepare('UPDATE tickets SET assigned_technician_id = ? WHERE id = ?')->execute([$tech2, $ticketId]);

        assert_same($tech3ReportBefore + 1, $techReportResolved($resolverName), 'tech report: the resolver keeps the credit after a reassign (as-reported)');
        assert_same($tech2ReportBefore, $techReportResolved($tech2Name), 'tech report: the new assignee did NOT gain the resolved credit');
        assert_same($tech3ReopenBefore + 1, $reopenResolved($resolverName), 'reopen dim: still attributed to the resolver after a reassign');
        assert_same($tech2ReopenBefore, $reopenResolved($tech2Name), 'reopen dim: the new assignee is not blamed for a close it did not do');
    } finally {
        lin_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
        lin_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades logs / sla_tracks / work_orders
        lin_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$tech2]);
    }
});

test('lineage(e2e): resolved counts reconcile across executive/trend/technician by grain, completion stays 0–100% (R10-F1)', function (): void {
    // The three "ปิดงาน/resolved" numbers use DIFFERENT grains ON PURPOSE (owner decision "keep the difference"):
    //   • executive summary = current STATE  (a reopened ticket isn't counted until it is re-resolved)
    //   • trend             = per-TICKET throughput (one ticket → one closure per calendar bucket)
    //   • technician report = per-RESOLVER credit (two people each closed it once → +1 each)
    // so after one ticket is closed by tech 3, reopened, reassigned, and re-closed by tech 2 IN THE SAME bucket,
    // executive moves +1 and trend moves +1, but the technician report moves +2 (one credit per resolver). This
    // test drives that whole path through the REAL services and locks the three deltas to their grains. The
    // technician report exposes no per-tech completion % (removed R12 as a non-immutable people-eval metric — see
    // the resolved-credit invariance test below).
    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $rid = bin2hex(random_bytes(4));

    $monthFilter = ['granularity' => 'month', 'from_date' => date('Y-m-01'), 'to_date' => date('Y-m-d')];
    $curKey = date('Y-m');

    // two FRESH technicians so their report state is fully controlled (baseline 0): techA closes cycle 1 and is
    // then reassigned away (its current-assignment drops to 0 — the exact trigger for the old false "-"); techB
    // closes cycle 2. Using isolated technicians makes the completion assertion deterministic (a shared fixture
    // tech carries unrelated assigned tickets that mask the bug).
    lin_pdo()->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)")
        ->execute(["lina_$rid", "lina_$rid@example.com", "LIN R10 TechA $rid"]);
    $techA = (int) lin_pdo()->lastInsertId();
    $techAName = "LIN R10 TechA $rid";
    $techAv = ['id' => $techA, 'role' => 'technician'];
    lin_pdo()->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)")
        ->execute(["linb_$rid", "linb_$rid@example.com", "LIN R10 TechB $rid"]);
    $techB = (int) lin_pdo()->lastInsertId();
    $techBName = "LIN R10 TechB $rid";
    $techBv = ['id' => $techB, 'role' => 'technician'];

    $execResolved = static fn (): int => (int) (lin_reports()->getReportPageData($admin, [])['summary']['resolved'] ?? 0);
    $trendResolved = static function () use ($admin, $monthFilter, $curKey): int {
        foreach (lin_reports()->getTicketTrendReportPage($admin, $monthFilter)['periods'] as $p) {
            if ($p['key'] === $curKey) {
                return (int) $p['resolved'];
            }
        }

        return 0;
    };
    $techRow = static function (string $name) use ($admin): ?array {
        foreach (lin_reports()->getTechnicianPerformanceReportPage($admin, [])['rows'] as $r) {
            if ($r['full_name'] === $name) {
                return $r;
            }
        }

        return null;
    };
    $techResolved = static fn (string $name): int => (int) (($techRow($name)['resolved'] ?? 0));

    $execBefore = $execResolved();
    $trendBefore = $trendResolved();

    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $ticketId = lin_tickets()->createTicket($requester, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => "LIN R10 grain $rid",
        'description' => 'cross-report grain reconciliation probe',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);

    try {
        // cycle 1: techA closes it
        lin_wf()->approveTicket($ticketId, $admin, ['note' => '']);
        lin_wf()->assignTechnician($ticketId, $admin, ['technician_id' => $techA, 'instructions' => '']);
        lin_wf()->acceptAssignedWork($ticketId, $techAv, ['accept_note' => '']);
        lin_wf()->startAssignedWork($ticketId, $techAv, ['start_note' => '']);
        lin_wf()->resolveAssignedWork($ticketId, $techAv, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '30']);

        // reopen → reassign to techB → cycle 2: techB closes it (same calendar bucket). techA is now reassigned
        // away, so its current-assignment count is 0 while it still owns one resolve event.
        lin_wf()->reopenTicket($ticketId, $requester, ['reopen_note' => 'ตรวจซ้ำ']);
        lin_wf()->assignTechnician($ticketId, $admin, ['technician_id' => $techB, 'instructions' => '']);
        lin_wf()->acceptAssignedWork($ticketId, $techBv, ['accept_note' => '']);
        lin_wf()->startAssignedWork($ticketId, $techBv, ['start_note' => '']);
        lin_wf()->resolveAssignedWork($ticketId, $techBv, ['diagnosis_summary' => 'd2', 'resolution_summary' => 'r2', 'labor_minutes' => '5']);

        // ── grain reconciliation: same event, three intended counts ──
        assert_same($execBefore + 1, $execResolved(), 'executive (current state): the re-resolved ticket is counted once');
        assert_same($trendBefore + 1, $trendResolved(), 'trend (per ticket): one closure in the bucket, though two people closed it');
        assert_same(1, $techResolved($techAName), 'technician (per resolver): techA credited for its close (fresh tech, baseline 0)');
        assert_same(1, $techResolved($techBName), 'technician (per resolver): techB credited for its close (fresh tech, baseline 0)');
        // → executive +1, trend +1, but the per-resolver total is +2: the documented grain difference

        // ── both resolvers appear; the report exposes no per-tech completion % (removed R12) ──
        foreach ([$techAName, $techBName] as $name) {
            $row = $techRow($name);
            assert_true($row !== null, "$name appears in the technician report");
            assert_false(isset($row['completion_label']), "$name row has no completion % (removed as a non-immutable metric)");
        }
    } finally {
        lin_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
        lin_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades logs / sla_tracks / work_orders
        lin_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$techA]);
        lin_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$techB]);
    }
});

test('technician performance is immutable through reopen + reassign — every metric + both pages (R13 invariance)', function (): void {
    // The comprehensive lock (R13): EVERY remaining technician metric (resolved credit, MTTR, SLA-on-time) is
    // as-reported/immutable, on BOTH the /reports overview mini AND the full technician page, and the two agree.
    // Driven through the REAL workflow: techH resolves → snapshot both pages → reopen + reassign to techJ →
    // techH's numbers are UNCHANGED on both pages and techJ inherits nothing. Power-proof: sourcing any of these
    // from the mutable current status/assignee makes techH's row restate/vanish after the reopen → reddens.
    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $rid = bin2hex(random_bytes(4));

    lin_pdo()->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)")
        ->execute(["linh_$rid", "linh_$rid@example.com", "LIN R13 TechH $rid"]);
    $techH = (int) lin_pdo()->lastInsertId();
    $techHName = "LIN R13 TechH $rid";
    $techHv = ['id' => $techH, 'role' => 'technician'];
    lin_pdo()->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)")
        ->execute(["linj_$rid", "linj_$rid@example.com", "LIN R13 TechJ $rid"]);
    $techJ = (int) lin_pdo()->lastInsertId();
    $techJName = "LIN R13 TechJ $rid";

    // pull a technician's row from the FULL page and, separately, from the /reports OVERVIEW mini
    $pick = static function (array $rows, string $name): array {
        foreach ($rows as $r) {
            if (($r['full_name'] ?? null) === $name) {
                return ['resolved' => (int) $r['resolved'], 'sla' => (string) $r['sla_on_time_label'], 'mttr' => (string) $r['mttr_hours_label']];
            }
        }

        return ['resolved' => 0, 'sla' => '-', 'mttr' => '-'];
    };
    $fullRow = static fn (string $name): array => $pick(lin_reports()->getTechnicianPerformanceReportPage($admin, [])['rows'], $name);
    $ovRow = static fn (string $name): array => $pick(lin_reports()->getReportPageData($admin, [])['technicianPerformance'], $name);

    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $ticketId = lin_tickets()->createTicket($requester, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => "LIN R13 invariance $rid",
        'description' => 'historical invariance probe',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);

    try {
        // techH resolves through the real workflow (concludes the resolution SLA → met)
        lin_wf()->approveTicket($ticketId, $admin, ['note' => '']);
        lin_wf()->assignTechnician($ticketId, $admin, ['technician_id' => $techH, 'instructions' => '']);
        lin_wf()->acceptAssignedWork($ticketId, $techHv, ['accept_note' => '']);
        lin_wf()->startAssignedWork($ticketId, $techHv, ['start_note' => '']);
        lin_wf()->resolveAssignedWork($ticketId, $techHv, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '30']);

        $before = $fullRow($techHName);
        assert_same(1, $before['resolved'], 'techH is credited with the close');
        assert_same('100.0%', $before['sla'], 'techH SLA-on-time = 100% (resolved within the frozen target)');
        assert_same($before, $ovRow($techHName), 'overview mini agrees with the full page (same immutable rows)');

        // reopen + reassign to techJ (NO re-resolve) through the real workflow
        lin_wf()->reopenTicket($ticketId, $requester, ['reopen_note' => 'ตรวจซ้ำ']);
        lin_wf()->assignTechnician($ticketId, $admin, ['technician_id' => $techJ, 'instructions' => '']);

        // techH's numbers are UNCHANGED on BOTH pages; techJ inherited nothing
        assert_same($before, $fullRow($techHName), 'full page: techH resolved/SLA/MTTR unchanged after reopen + reassign');
        assert_same($before, $ovRow($techHName), 'overview mini: techH unchanged AND still agrees with the full page');
        assert_same(0, $fullRow($techJName)['resolved'], 'techJ never gains a close it did not do');
        assert_same(0, $ovRow($techJName)['resolved'], 'techJ not credited on the overview either');
    } finally {
        lin_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
        lin_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        lin_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$techH]);
        lin_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$techJ]);
    }
});

test('technician performance survives the resolver being deactivated or demoted (R14-F1)', function (): void {
    // Closing an account (a leaver) or changing a role must NOT erase a person's past performance or shrink team
    // totals. The row base is live-technicians ∪ resolvers, so a resolver keeps their immutable numbers even once
    // they drop off the active-technician roster. Driven through the REAL workflow (resolve + complete + rate),
    // then deactivated and, separately, demoted. Power-proof: a live-only row base drops the row after deactivation.
    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $rid = bin2hex(random_bytes(4));

    lin_pdo()->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)")
        ->execute(["linl_$rid", "linl_$rid@example.com", "LIN R14 Tech $rid"]);
    $tech = (int) lin_pdo()->lastInsertId();
    $techName = "LIN R14 Tech $rid";
    $techv = ['id' => $tech, 'role' => 'technician'];

    $pick = static function (array $rows) use ($techName): ?array {
        foreach ($rows as $r) {
            if (($r['full_name'] ?? null) === $techName) {
                return ['resolved' => (int) $r['resolved'], 'sla' => (string) $r['sla_on_time_label'], 'rating' => (string) $r['avg_rating_label']];
            }
        }

        return null;
    };
    $full = static fn (): ?array => $pick(lin_reports()->getTechnicianPerformanceReportPage($admin, [])['rows']);
    $ov = static fn (): ?array => $pick(lin_reports()->getReportPageData($admin, [])['technicianPerformance']);

    $baselineJobId = (int) lin_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $baselineAuditId = (int) lin_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $ticketId = lin_tickets()->createTicket($requester, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => "LIN R14 leaver $rid",
        'description' => 'departed-resolver probe',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);

    try {
        lin_wf()->approveTicket($ticketId, $admin, ['note' => '']);
        lin_wf()->assignTechnician($ticketId, $admin, ['technician_id' => $tech, 'instructions' => '']);
        lin_wf()->acceptAssignedWork($ticketId, $techv, ['accept_note' => '']);
        lin_wf()->startAssignedWork($ticketId, $techv, ['start_note' => '']);
        lin_wf()->resolveAssignedWork($ticketId, $techv, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '30']);
        lin_wf()->completeResolvedTicket($ticketId, $requester, ['score' => 5, 'closure_note' => '', 'feedback' => 'ดี']);

        $before = $full();
        $techniciansBefore = (int) lin_reports()->getTechnicianPerformanceReportPage($admin, [])['summary']['technicians'];
        assert_same(['resolved' => 1, 'sla' => '100.0%', 'rating' => '5.0'], $before, 'active resolver shows resolved/SLA/rating');
        assert_same($before, $ov(), 'overview agrees while active');

        // DEACTIVATE — the leaver drops off the active-technician roster
        tvm_container()->get(AdminService::class)->updateUser($tech, $admin, [
            'full_name' => $techName,
            'email' => "linl_$rid@example.com",
            'phone' => '',
            'role' => 'technician',
            'department_id' => '',
            'is_active' => '0',
        ]);
        assert_same($before, $full(), 'deactivated resolver keeps its full-page performance (not erased)');
        assert_same($before, $ov(), 'deactivated resolver keeps its overview performance');
        $csv = (string) lin_reports()->exportTechnicianPerformanceCsv($admin, [])['content'];
        assert_true(str_contains($csv, $techName), 'the departed resolver is still in the CSV export');

        // DEMOTE — role changed away from technician
        tvm_container()->get(AdminService::class)->updateUser($tech, $admin, [
            'full_name' => $techName,
            'email' => "linl_$rid@example.com",
            'phone' => '',
            'role' => 'requester',
            'department_id' => '',
            'is_active' => '1',
        ]);
        assert_same($before, $full(), 'demoted resolver still shows their past performance');

        // AUDIT POWER-PROOF (intentionally red until fixed): the card is labelled "active technicians" in the
        // view, so retaining this historical resolver in the detail rows must not retain them in that headcount.
        // Current code sets summary.technicians = count(active technicians UNION historical resolvers), so this
        // assertion exposes the semantic overcount without weakening the immutable-history guarantee above.
        $techniciansAfter = (int) lin_reports()->getTechnicianPerformanceReportPage($admin, [])['summary']['technicians'];
        assert_same($techniciansBefore - 1, $techniciansAfter, 'active-technician card drops a deactivated/demoted resolver while their historical row remains');
    } finally {
        lin_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        lin_pdo()->prepare('DELETE FROM audit_logs WHERE id > ?')->execute([$baselineAuditId]);
        lin_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
        lin_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        lin_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$tech]);
    }
});

test('lineage(e2e): a full reopen cycle freezes the first cycle SLA snapshot + appends per-cycle rating (F1 Phase 2 Part B)', function (): void {
    // The per-cycle as-reported guarantee, proven through the REAL services end to end:
    // resolve (cycle 1) → reopen → reassign → re-resolve (cycle 2) → complete+rate. The first cycle's
    // resolution-SLA verdict (met, its target + achieved timestamps) must be FROZEN — a reopen APPENDS a new
    // cycle instead of resetting the row — and the rating is stored against the cycle that was actually rated.
    // NOTE: driven in a single session every event's timestamp is "now", so both closures land in the same
    // calendar bucket; the immutability proven here is at the CYCLE-snapshot level (ticket_sla_tracks.cycle /
    // ticket_ratings.cycle). Calendar-period resolver attribution surviving a reassign is proven separately by
    // the Part A reassign lineage test above.
    $admin = ['id' => 4, 'role' => 'admin'];
    $tech = ['id' => 3, 'role' => 'technician'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $rid = bin2hex(random_bytes(4));

    lin_pdo()->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)")
        ->execute(["linb_$rid", "linb_$rid@example.com", "LIN B Tech2 $rid"]);
    $tech2 = (int) lin_pdo()->lastInsertId();

    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $ticketId = lin_tickets()->createTicket($requester, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => "LIN cycle e2e $rid",
        'description' => 'per-cycle snapshot lineage probe',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);

    $resolutionSla = static fn (): array => lin_pdo()->query(
        "SELECT cycle, status, target_at, achieved_at FROM ticket_sla_tracks WHERE ticket_id = $ticketId AND metric_type = 'resolution' ORDER BY cycle"
    )->fetchAll(PDO::FETCH_ASSOC);

    try {
        // ── cycle 1: resolve through the real workflow (technician 3) ──
        lin_wf()->approveTicket($ticketId, $admin, ['note' => '']);
        lin_wf()->assignTechnician($ticketId, $admin, ['technician_id' => 3, 'instructions' => '']);
        lin_wf()->acceptAssignedWork($ticketId, $tech, ['accept_note' => '']);
        lin_wf()->startAssignedWork($ticketId, $tech, ['start_note' => '']);
        lin_wf()->resolveAssignedWork($ticketId, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '30']);

        $cycle1 = $resolutionSla();
        assert_same(1, count($cycle1), 'exactly one resolution-SLA cycle after the first resolve');
        assert_same('met', $cycle1[0]['status'], 'cycle 1 resolution SLA concluded met');
        $frozenTarget = (string) $cycle1[0]['target_at'];
        $frozenAchieved = (string) $cycle1[0]['achieved_at'];

        // ── reopen → reassign to a different technician → cycle 2 resolve+complete+rate ──
        lin_wf()->reopenTicket($ticketId, $requester, ['reopen_note' => 'ตรวจซ้ำ']);
        $tech2v = ['id' => $tech2, 'role' => 'technician'];
        lin_wf()->assignTechnician($ticketId, $admin, ['technician_id' => $tech2, 'instructions' => '']);
        lin_wf()->acceptAssignedWork($ticketId, $tech2v, ['accept_note' => '']);
        lin_wf()->startAssignedWork($ticketId, $tech2v, ['start_note' => '']);
        lin_wf()->resolveAssignedWork($ticketId, $tech2v, ['diagnosis_summary' => 'd2', 'resolution_summary' => 'r2', 'labor_minutes' => '5']);
        lin_wf()->completeResolvedTicket($ticketId, $requester, ['score' => 3, 'closure_note' => '', 'feedback' => 'ok cycle2']);

        // ── the first cycle's SLA snapshot is UNCHANGED; a second cycle was appended (never reset) ──
        $after = $resolutionSla();
        assert_same(2, count($after), 'reopen APPENDED a second resolution-SLA cycle (did not reset the first)');
        assert_same(1, (int) $after[0]['cycle'], 'the first row is still cycle 1');
        assert_same('met', $after[0]['status'], 'cycle 1 SLA verdict is frozen at met (immutable snapshot)');
        assert_same($frozenTarget, (string) $after[0]['target_at'], 'cycle 1 SLA target_at is unchanged after the reopen cycle');
        assert_same($frozenAchieved, (string) $after[0]['achieved_at'], 'cycle 1 SLA achieved_at is unchanged after the reopen cycle');

        // ── the rating is appended against the cycle that was actually rated (cycle 2), not overwriting cycle 1 ──
        $ratings = lin_pdo()->query("SELECT cycle, score FROM ticket_ratings WHERE ticket_id = $ticketId ORDER BY cycle")->fetchAll(PDO::FETCH_ASSOC);
        assert_same(1, count($ratings), 'exactly one rating — cycle 1 was reopened before it could be rated');
        assert_same(2, (int) $ratings[0]['cycle'], 'the rating is stored against cycle 2 (the completed cycle)');
        assert_same(3, (int) $ratings[0]['score'], 'the cycle-2 rating carries the score the requester gave');

        // ── both immutable resolve events survive: cycle 1 by tech 3, cycle 2 by the reassigned tech ──
        $actors = lin_pdo()->query("SELECT actor_id FROM ticket_activity_logs WHERE ticket_id = $ticketId AND action = 'ticket_resolved' ORDER BY created_at")->fetchAll(PDO::FETCH_COLUMN);
        assert_same([3, $tech2], array_map('intval', $actors), 'the two resolve events record their real resolvers (tech 3 then the reassigned tech)');
    } finally {
        lin_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
        lin_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades logs / sla_tracks / ratings / work_orders
        lin_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$tech2]);
    }
});

test('lineage(e2e): the trend reads its SLA from the frozen per-cycle track, not the mutable resolution_due_at (F1 Phase 2 Part B2)', function (): void {
    // The as-reported trend now sources each period's SLA verdict from the resolve's CYCLE snapshot
    // (ticket_sla_tracks.target_at), NOT the current t.resolution_due_at that a reopen overwrites. Proven through
    // the REAL resolve flow: technician 3 resolves cycle 1 well within target → the trend period shows 100%; we
    // then mutate t.resolution_due_at into the deep past (standing in for the reopen that rewrites that column)
    // and the SAME period still reads 100% — the frozen cycle-1 track, immune to the current-column mutation.
    // Under the pre-B2 read (which compared the resolve to t.resolution_due_at) this would flip to a false 0%.
    // NOTE (real-flow limitation, see docs/as-reported-analytics.md): driven in one session both closures land in
    // the same calendar bucket, so the CROSS-PERIOD immutability (period 1 keeps cycle 1 while period 2 shows
    // cycle 2) is locked by the ticket_trend_report_test unit test with controlled timestamps + seeded rating
    // cycles; here the E2E proves the SLA per-cycle READ is wired to the real flow and ignores the mutable column.
    $admin = ['id' => 4, 'role' => 'admin'];
    $tech = ['id' => 3, 'role' => 'technician'];
    $requester = ['id' => 1, 'role' => 'requester'];

    $monthFilter = ['granularity' => 'month', 'from_date' => date('Y-m-01'), 'to_date' => date('Y-m-d')];
    $curKey = date('Y-m');
    $trendPeriod = static function () use ($admin, $monthFilter, $curKey): ?array {
        foreach (lin_reports()->getTicketTrendReportPage($admin, $monthFilter)['periods'] as $p) {
            if ($p['key'] === $curKey) {
                return $p;
            }
        }

        return null;
    };

    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $ticketId = lin_tickets()->createTicket($requester, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => 'LIN trend-sla e2e ' . bin2hex(random_bytes(3)),
        'description' => 'per-cycle trend SLA read lineage probe',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);

    try {
        // resolve cycle 1 through the REAL workflow — resolved instantly, well within the SLA target → MET
        lin_wf()->approveTicket($ticketId, $admin, ['note' => '']);
        lin_wf()->assignTechnician($ticketId, $admin, ['technician_id' => 3, 'instructions' => '']);
        lin_wf()->acceptAssignedWork($ticketId, $tech, ['accept_note' => '']);
        lin_wf()->startAssignedWork($ticketId, $tech, ['start_note' => '']);
        lin_wf()->resolveAssignedWork($ticketId, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '30']);

        // the flow wrote a cycle-1 resolution track; the trend reads it → this month's SLA = 100% (met), base 1
        $before = $trendPeriod();
        assert_true($before !== null, 'the current month period exists');
        assert_true((int) $before['sla_base'] >= 1, 'the trend counts the real cycle-1 resolution track as SLA base');
        assert_same('100.0%', $before['sla_pct_label'], 'the real cycle-1 resolve is on time → the trend period shows 100%');

        // simulate the current-column mutation a reopen performs: rewrite t.resolution_due_at into the deep past.
        // A read that (wrongly) used this column would now judge the resolve LATE → a false 0%.
        lin_pdo()->prepare('UPDATE tickets SET resolution_due_at = ? WHERE id = ?')->execute(['2000-01-01 00:00:00', $ticketId]);

        $after = $trendPeriod();
        assert_true($after !== null, 'the current month period still exists after the column mutation');
        assert_same((int) $before['sla_base'], (int) $after['sla_base'], 'SLA base unchanged — sourced from the cycle track, not resolution_due_at');
        assert_same('100.0%', $after['sla_pct_label'], 'the trend still reads the FROZEN cycle-1 target → 100%, immune to the resolution_due_at mutation (pre-B2 would flip to 0%)');
    } finally {
        lin_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
        lin_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades logs / sla_tracks / ratings / work_orders
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

test('lineage(e2e): a guest request converted to a ticket is counted by the reports (R12)', function (): void {
    // The guest→ticket path writes report data through the SAME createTicket the normal flow uses
    // (GuestTicketService::convertToTicket delegates to it), so the report must count the converted ticket. The
    // guest_ticket_requests row is only the precondition (not report data) → seeded directly; the TICKET the
    // report reads is created through the real service. Locks that the guest production path isn't a blind spot.
    $admin = ['id' => 4, 'role' => 'admin'];
    $guest = tvm_container()->get(\App\Services\GuestTicketService::class);
    $rid = bin2hex(random_bytes(4));
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();

    lin_pdo()->prepare(
        "INSERT INTO guest_ticket_requests (request_no, guest_name, title, description, location_id, status)
         VALUES (?, 'Guest R12', 'g title', 'g desc', ?, 'new')"
    )->execute(["GR12-$rid", (int) $ref['locations'][0]['id']]);
    $requestId = (int) lin_pdo()->lastInsertId();
    $ticketId = 0;

    try {
        $before = (int) (lin_reports()->getReportPageData($admin, [])['summary']['total'] ?? 0);
        $ticketId = $guest->convertToTicket(
            $requestId,
            $admin,
            (int) $ref['priorities'][0]['id'],
            (int) $ref['categories'][0]['id'],
            lin_tickets()
        );
        assert_true($ticketId > 0, 'the guest request converted into a real ticket via createTicket');

        $after = (int) (lin_reports()->getReportPageData($admin, [])['summary']['total'] ?? 0);
        assert_same($before + 1, $after, 'the report summary counts the converted guest ticket (+1)');
        assert_same('converted', (string) lin_pdo()->query("SELECT status FROM guest_ticket_requests WHERE id = $requestId")->fetchColumn(), 'the guest request is marked converted');
    } finally {
        if ($ticketId > 0) {
            lin_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
        }
        lin_pdo()->prepare('DELETE FROM guest_ticket_requests WHERE id = ?')->execute([$requestId]);
        if ($ticketId > 0) {
            lin_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
    }
});
