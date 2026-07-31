<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\ReportService;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// AN-01 rounds 4-5 (2026-07-26) — a PAIR of nets, because neither one is sufficient alone.
//
// Five reviews found five ways a closed period could report wrongly, and a text-scanning guard missed most of
// them because it looked for a shape rather than an outcome: a NOW() in SQL, a time() one layer up, a due date
// from the wrong cycle (no clock at all), an achievement recorded after the cutoff, and a status filter reading
// today. Measured against 8 mutations the scanner caught 2 and this snapshot test caught 3 — so neither is a
// backstop on its own, and saying otherwise (as an earlier version of this comment did) just stops people
// looking.
//
//   SNAPSHOT (first test) catches "changed retroactively": photograph a closed period, drive real workflow
//   actions after it, demand the photograph is identical. Blind by construction to a number that was already
//   wrong in the first photograph and stays wrong.
//
//   RECONCILIATION (second test) catches "constantly wrong": feed data whose correct answer is known by hand and
//   assert the ABSOLUTE values. This exists because an independent review defeated the snapshot test with exactly
//   that — removing `achieved_at <= cutoff` for work finished after the period but before its deadline slipped
//   past the snapshot AND the whole 676-test suite, since both photographs were equally wrong.
//
// Neither reads the source, so a regression cannot hide by using a different clock API — or none at all.
//
// THE LIMIT — a net only guards the OUTPUTS ITS FIXTURE ACTUALLY CALLS. The two tests above call /reports
// (getReportPageData); the third calls /reports/sla-breach. That third one exists because a review mutated the
// SLA-breach query and all 679 tests stayed green — nothing here had ever reached getSlaBreachReportPage.
//
// Coverage map for period-freeze, written from grepping the suite rather than from memory (this comment has been
// wrong three times by describing what the nets were meant to cover instead of what they do):
//
//   already covered ELSEWHERE, by their own as-reported tests — no fixture needed here:
//     trend        ticket_trend_report_test.php:161        reopened ticket stays in its past period
//     technician   technician_performance_report_test.php:115  SLA rate read from the frozen cycle target
//     csat         csat_report_test.php:89                 a re-rate does not restate the earlier period
//     reopen-rate  reopen_rate_report_test.php:151         a reopen after the window does not restate the past
//
//   deliberately NOT frozen — current-state by design, documented in guide.php:89 ("ภาพสถานะปัจจุบัน"):
//     backlog aging, technician live workload, cumulative labour hours
//
//   THIN — covered only by the snapshot test above, which catches "changed retroactively" but is blind to a value
//   that is wrong from the first photograph and stays wrong:
//     problem-hotspot, asset-reliability, executive
//   (executive does pin period SCOPING — executive_summary_report_test.php:142 keeps its breach KPI off the
//   NOW-overdue snapshot — but no test drives an action AFTER the period and re-reads it, and none feeds an
//   after-cutoff known answer.) A period-freeze regression written directly into one of those three queries
//   would not be caught. Adding a known-answer fixture for a page is cheap; assuming it is already covered is not.

function cpi_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** Everything the closed period claims: SLA header, per-row verdicts and the resolved/breach counts. */
function cpi_snapshot(array $filters): array
{
    $page = tvm_container()->get(ReportService::class)->getReportPageData(['id' => 4, 'role' => 'admin'], $filters);
    $rows = [];
    foreach (($page['rows'] ?? []) as $row) {
        $rows[(string) $row['ticket_no']] = [
            'status' => (string) $row['status_label'],
            'sla' => (string) $row['sla_label'],
            'overdue' => (string) $row['sla_overdue_label'],
        ];
    }
    ksort($rows);

    return [
        'summary' => [
            'total' => (int) ($page['summary']['total'] ?? -1),
            'resolved' => (int) ($page['summary']['resolved'] ?? -1),
        ],
        'sla' => [
            'response' => $page['slaCompliance']['overall']['response'] ?? [],
            'resolution' => $page['slaCompliance']['overall']['resolution'] ?? [],
        ],
        'rows' => $rows,
    ];
}

test('closed period(immutability): later workflow actions cannot change a finished period, header or rows', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $tickets = tvm_container()->get(TicketService::class);
    $pdo = cpi_pdo();
    $sfx = bin2hex(random_bytes(4));

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["CPID-$sfx", "CpiDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();

    // a period that is well and truly over
    $monthStart = (new DateTimeImmutable('first day of this month'))->modify('-6 months');
    $monthEnd = $monthStart->modify('last day of this month');
    $filters = [
        'from_date' => $monthStart->format('Y-m-d'),
        'to_date' => $monthEnd->format('Y-m-d'),
        'department_id' => $deptId,
    ];

    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $tech = ['id' => 3, 'role' => 'technician'];
    $ids = [];

    try {
        // Two tickets created through the REAL flow, then back-dated into the closed period along with the SLA
        // rows the flow produced, so the period contains genuine history rather than hand-written rows.
        foreach (['A', 'B', 'C'] as $tag) {
            $id = $tickets->createTicket($requester, [
                'submission_token' => bin2hex(random_bytes(32)),
                'title' => "cpi-$tag",
                'description' => 'x',
                'priority_id' => (int) $ref['priorities'][0]['id'],
                'ticket_category_id' => (int) $ref['categories'][0]['id'],
                'location_id' => (int) $ref['locations'][0]['id'],
                'impact_level' => 'medium',
                'urgency_level' => 'medium',
            ], []);
            $wf->approveTicket($id, $admin, ['note' => '']);
            $wf->assignTechnician($id, $admin, ['technician_id' => 3, 'instructions' => '']);
            if ($tag === 'C') {
                // C's technician answers INSIDE the period, so the period closes holding a settled "met"
                // verdict. That is the shape that exposes a later reassign rewriting decided history.
                $wf->acceptAssignedWork($id, $tech, ['accept_note' => '']);
            }
            $ids[$tag] = $id;

            $inPeriod = $monthStart->modify('+3 days')->format('Y-m-d H:i:s');
            // A's deadline falls AFTER the period closed, so at the cutoff it is still only "waiting"; that is the
            // shape that exposes a late ACHIEVEMENT leaking backwards. B's deadline passed inside the period, so it
            // is already a breach at the cutoff; that is the shape that exposes a wrong-CYCLE deadline after reopen.
            $due = $tag === 'A'
                ? $monthEnd->modify('+20 days')->format('Y-m-d H:i:s')
                : $monthStart->modify('+4 days')->format('Y-m-d H:i:s');
            $achievedInPeriod = $monthStart->modify('+3 days +1 hour')->format('Y-m-d H:i:s');
            $pdo->prepare('UPDATE tickets SET requester_department_id = ?, requested_at = ?, response_due_at = ?, resolution_due_at = ? WHERE id = ?')
                ->execute([$deptId, $inPeriod, $due, $due, $id]);
            $pdo->prepare('UPDATE ticket_sla_tracks SET created_at = ?, target_at = ? WHERE ticket_id = ?')
                ->execute([$inPeriod, $due, $id]);
            $pdo->prepare('UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ?')->execute([$inPeriod, $id]);
            if ($tag === 'C') {
                // drag the achievement back inside the period too, so the whole verdict belongs to the closed month
                $pdo->prepare("UPDATE ticket_sla_tracks SET achieved_at = ? WHERE ticket_id = ? AND metric_type = 'response'")
                    ->execute([$achievedInPeriod, $id]);
                $pdo->prepare('UPDATE tickets SET first_response_at = ? WHERE id = ?')->execute([$achievedInPeriod, $id]);
            }
        }

        $before = cpi_snapshot($filters);
        assert_same(3, $before['summary']['total'], 'sanity: the closed period contains all three tickets');
        assert_same(
            'met',
            (string) $pdo->query("SELECT status FROM ticket_sla_tracks WHERE ticket_id = {$ids['C']} AND metric_type = 'response'")->fetchColumn(),
            'sanity: C closed the period holding a settled met response'
        );
        // Pin the two starting verdicts. If a future edit makes them identical the test would still "pass" while
        // having stopped discriminating, so the shapes themselves are asserted up front.
        $slaBefore = array_column($before['rows'], 'sla');
        assert_true(in_array('รอตอบรับ', $slaBefore, true), 'sanity: one ticket is merely waiting at the cutoff');
        assert_true(in_array('แก้ไขเกินกำหนด', $slaBefore, true), 'sanity: the other already breached inside the period');

        // ── now the world moves on, months after that period ended ──
        // (1) the technician finally accepts and starts ticket A — an achievement recorded AFTER the cutoff
        $wf->acceptAssignedWork($ids['A'], $tech, ['accept_note' => '']);
        $wf->startAssignedWork($ids['A'], $tech, ['start_note' => '']);
        // (2) …and resolves it, then the requester reopens it, appending a whole new SLA cycle
        $wf->resolveAssignedWork($ids['A'], $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '5']);
        $wf->reopenTicket($ids['A'], $requester, ['reopen_note' => 'ยังไม่หาย']);
        // (3) ticket B is worked, closed and then reopened after the period — the reopen rewrites its due dates
        //     to the new cycle, which must not reach back and un-breach the closed period
        $wf->acceptAssignedWork($ids['B'], $tech, ['accept_note' => '']);
        $wf->startAssignedWork($ids['B'], $tech, ['start_note' => '']);
        $wf->resolveAssignedWork($ids['B'], $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '5']);
        $wf->reopenTicket($ids['B'], $requester, ['reopen_note' => 'ยังไม่หาย']);
        // (4) ticket C is handed to a different technician months later. The reassign resets the response SLA so the
        //     new technician owes a fresh one — but C's response was already ANSWERED and settled inside the closed
        //     period, and that verdict is history. Rewriting it would turn an on-time answer into a breach months
        //     after the fact, and re-arm the overdue cron to raise a second breach for the same metric.
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, email, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, "technician", 1, NOW(), NOW())'
        )->execute(["cpitech_$sfx", password_hash('x', PASSWORD_DEFAULT), "CpiTech $sfx", "cpitech_$sfx@x.test"]);
        $spareTechId = (int) $pdo->lastInsertId();
        $wf->assignTechnician($ids['C'], $admin, ['technician_id' => $spareTechId, 'instructions' => 'ช่างเดิมลาออก']);

        $after = cpi_snapshot($filters);

        assert_same(
            $before,
            $after,
            'the closed period must report exactly what it reported before — accepting late, resolving, reopening '
            . 'and reassigning all happened AFTER it ended and cannot reach back into it'
        );
    } finally {
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        if (isset($spareTechId)) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$spareTechId]);
        }
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

// The snapshot test above compares BEFORE against AFTER, so by construction it can only catch a number that
// CHANGES. A value that is wrong in the very first photograph — and stays wrong — passes it happily. An
// independent review demonstrated exactly that: removing the repository's `achieved_at <= cutoff` guard for work
// finished AFTER the period but BEFORE its deadline slipped past the snapshot test and the whole 676-test suite,
// because both photographs were equally wrong.
//
// This is the other half of the pair: feed data whose correct answer is known by hand, and assert the ABSOLUTE
// numbers rather than their stability. Snapshot catches "changed retroactively"; reconciliation catches
// "constantly wrong". Neither subsumes the other.
test('closed period(reconciliation): work finished after the cutoff but before its deadline is not counted in the old period', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $pdo = cpi_pdo();
    $sfx = bin2hex(random_bytes(4));

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["CPIR-$sfx", "CpiRecDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();
    $locationId = (int) $pdo->query('SELECT id FROM locations LIMIT 1')->fetchColumn();
    $catId = (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn();
    $priId = (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn();

    $monthStart = (new DateTimeImmutable('first day of this month'))->modify('-6 months');
    $monthEnd = $monthStart->modify('last day of this month');
    $filters = [
        'from_date' => $monthStart->format('Y-m-d'),
        'to_date' => $monthEnd->format('Y-m-d'),
        'department_id' => $deptId,
    ];

    try {
        // Known-answer fixture. Requested inside the closed month; its deadline lands a fortnight AFTER the month
        // ended, and the work was actually finished a week after the month ended — late relative to the period,
        // but comfortably WITHIN its own deadline.
        $requested = $monthStart->modify('+25 days')->format('Y-m-d H:i:s');
        $target = $monthEnd->modify('+14 days')->format('Y-m-d H:i:s');
        $achieved = $monthEnd->modify('+7 days')->format('Y-m-d H:i:s');

        $pdo->prepare(
            'INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id,
                ticket_category_id, priority_id, status, approval_status, requested_at, response_due_at,
                resolution_due_at, first_response_at, created_at, updated_at)
             VALUES (?, ?, "x", 1, ?, ?, ?, ?, "in_progress", "approved", ?, ?, ?, ?, NOW(), NOW())'
        )->execute(["CPIR-$sfx", 'reconciliation', $deptId, $locationId, $catId, $priId, $requested, $target, $target, $achieved]);
        $ticketId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, cycle, target_at, achieved_at, status, created_at)
                       VALUES (?, 'response', 1, ?, ?, 'met', ?)")->execute([$ticketId, $target, $achieved, $requested]);

        // THE KNOWN ANSWER, worked out by hand: as of the last day of that month the deadline had not arrived and
        // nothing had been achieved yet. So the period has NOTHING to judge — no met, no breach, no percentage.
        $panel = $svc->getReportPageData($admin, $filters)['slaCompliance']['overall']['response'] ?? [];
        assert_same(0, (int) ($panel['met'] ?? -1), 'the achievement happened after the period, so it cannot be a met inside it');
        assert_same(0, (int) ($panel['breached'] ?? -1), 'and the deadline had not passed either, so it is not a breach');
        assert_same('-', (string) ($panel['pct_label'] ?? ''), 'nothing was decided in that month, so there is no percentage to show');

        // the detail row must tell the same story
        $row = null;
        foreach (($svc->getReportPageData($admin, $filters)['rows'] ?? []) as $candidate) {
            if ((string) $candidate['ticket_no'] === "CPIR-$sfx") {
                $row = $candidate;
            }
        }
        assert_true($row !== null, 'the ticket is listed in that month');
        assert_same('รอตอบรับ', (string) $row['sla_label'], 'as of the period end it was simply still waiting');
        assert_same('ไม่ใช่', (string) $row['sla_overdue_label'], 'and certainly not overdue');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE ticket_no = ?')->execute(["CPIR-$sfx"]);
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

// AN-01 round 6 (2026-07-26) — the pair above still had a hole, and it was a coverage hole rather than a logic
// one: both nets exercise /reports (getReportPageData). An independent review mutated the SLA-BREACH page's own
// query so work finished after the period counted back into it, and every one of the 679 tests stayed green,
// because no known-answer fixture ever reached getSlaBreachReportPage.
//
// A net only guards the outputs its fixture actually calls. This adds the same known-answer reconciliation on
// that page directly. Production was never wrong here — this closes the door before someone walks through it.
test('sla-breach(reconciliation): work finished after the cutoff is not counted back into the closed period', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $pdo = cpi_pdo();
    $sfx = bin2hex(random_bytes(4));

    $pdo->prepare('INSERT INTO locations (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["CPIB-$sfx", "CpiBreachLoc-$sfx"]);
    $locationId = (int) $pdo->lastInsertId();
    $catId = (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn();
    $priId = (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn();

    $monthStart = (new DateTimeImmutable('first day of this month'))->modify('-6 months');
    $monthEnd = $monthStart->modify('last day of this month');
    $filters = [
        'from_date' => $monthStart->format('Y-m-d'),
        'to_date' => $monthEnd->format('Y-m-d'),
        'dimension' => 'location',
    ];

    try {
        // Same known-answer shape as the /reports fixture: requested inside the month, deadline a fortnight AFTER
        // it ended, work actually finished a week after it ended — on time for its own deadline, but outside the
        // period entirely. As of the last day of that month there was nothing to judge.
        $requested = $monthStart->modify('+25 days')->format('Y-m-d H:i:s');
        $target = $monthEnd->modify('+14 days')->format('Y-m-d H:i:s');
        $achieved = $monthEnd->modify('+7 days')->format('Y-m-d H:i:s');

        $pdo->prepare(
            'INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id,
                priority_id, status, approval_status, requested_at, resolution_due_at, resolved_at, created_at, updated_at)
             VALUES (?, ?, "x", 1, ?, ?, ?, "completed", "approved", ?, ?, ?, NOW(), NOW())'
        )->execute(["CPIB-$sfx", 'breach reconciliation', $locationId, $catId, $priId, $requested, $target, $achieved]);
        $ticketId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, cycle, target_at, achieved_at, status, created_at)
                       VALUES (?, 'resolution', 1, ?, ?, 'met', ?)")->execute([$ticketId, $target, $achieved, $requested]);

        $page = $svc->getSlaBreachReportPage($admin, $filters);
        $row = null;
        foreach (($page['rows'] ?? []) as $candidate) {
            if ((string) ($candidate['label'] ?? '') === "CpiBreachLoc-$sfx") {
                $row = $candidate;
            }
        }

        // THE KNOWN ANSWER: the area has no decided SLA in that month at all — not a met, not a breach, so the
        // page has no rate to quote. This is the assertion the mutation broke: it turned the after-period
        // achievement into a met of 1 and the rate into 0.0%.
        assert_same(0, (int) ($page['summary']['total_breached'] ?? -1), 'the deadline had not passed, so nothing breached');
        assert_same('-', (string) ($page['summary']['breach_rate_label'] ?? ''), 'nothing was decided, so there is no rate — not 0.0%');
        assert_true($row !== null, 'the area is listed for that month');
        assert_same(0, (int) ($row['total_met'] ?? -1), 'an achievement recorded after the period is not a met inside it');
        assert_same(0, (int) ($row['total_breached'] ?? -1), 'and it is not a breach either');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE ticket_no = ?')->execute(["CPIB-$sfx"]);
        $pdo->prepare('DELETE FROM locations WHERE id = ?')->execute([$locationId]);
    }
});

// The coverage map at the top of this file has been wrong three times, always the same way: written from what the
// nets were meant to cover rather than what they measurably do. A prose map also rots the moment someone renames
// or deletes a test it cites, and nothing tells you. So the map cites file:line, and this pins those citations to
// reality — if a cited test disappears, the map is stale and the build says so instead of quietly misleading the
// next reader.
test('closed period(map): the coverage map at the top of this file still matches the suite', function (): void {
    $cited = [
        'ticket_trend_report_test.php' => 'stays in its past period as-reported',
        'technician_performance_report_test.php' => 'from the frozen cycle target',
        'csat_report_test.php' => 'does not restate the earlier period CSAT',
        'reopen_rate_report_test.php' => 'does not restate a past period',
        'executive_summary_report_test.php' => 'period-scoped breach, not the NOW-overdue snapshot',
    ];
    foreach ($cited as $file => $needle) {
        $body = (string) file_get_contents(BASE_PATH . '/tests/cases/' . $file);
        assert_contains_str($needle, $body, "the coverage map cites a test in $file that no longer exists under that name");
    }

    // The two pages the map calls THIN must still lack period-immutability coverage of their own. If one gains it,
    // the map is understating coverage and should be updated — otherwise someone duplicates the work here.
    foreach (['problem_hotspot_report_test.php', 'asset_reliability_report_test.php'] as $file) {
        $body = (string) file_get_contents(BASE_PATH . '/tests/cases/' . $file);
        assert_false(
            str_contains($body, 'as-reported') || str_contains($body, 'restate'),
            "$file now has period-immutability coverage — promote it out of the THIN list in the map above"
        );
    }

    // the backlog exemption is a documented product decision, not an oversight — keep the guide saying so
    $guide = (string) file_get_contents(BASE_PATH . '/app/Views/reports/guide.php');
    assert_contains_str('เป็นภาพสถานะปัจจุบัน', $guide, 'the guide still documents backlog/workload as current-state by design');
});
