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
// Together they cover both failure directions. Neither reads the source, so a regression cannot hide by using a
// different clock API — or none at all.

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
    $monthStart = new DateTimeImmutable(date('Y-m-01', strtotime('-6 months')));
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
        foreach (['A', 'B'] as $tag) {
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
            $ids[$tag] = $id;

            $inPeriod = $monthStart->modify('+3 days')->format('Y-m-d H:i:s');
            // A's deadline falls AFTER the period closed, so at the cutoff it is still only "waiting"; that is the
            // shape that exposes a late ACHIEVEMENT leaking backwards. B's deadline passed inside the period, so it
            // is already a breach at the cutoff; that is the shape that exposes a wrong-CYCLE deadline after reopen.
            $due = $tag === 'A'
                ? $monthEnd->modify('+20 days')->format('Y-m-d H:i:s')
                : $monthStart->modify('+4 days')->format('Y-m-d H:i:s');
            $pdo->prepare('UPDATE tickets SET requester_department_id = ?, requested_at = ?, response_due_at = ?, resolution_due_at = ? WHERE id = ?')
                ->execute([$deptId, $inPeriod, $due, $due, $id]);
            $pdo->prepare('UPDATE ticket_sla_tracks SET created_at = ?, target_at = ? WHERE ticket_id = ?')
                ->execute([$inPeriod, $due, $id]);
            $pdo->prepare('UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ?')->execute([$inPeriod, $id]);
        }

        $before = cpi_snapshot($filters);
        assert_same(2, $before['summary']['total'], 'sanity: the closed period contains both tickets');
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

        $after = cpi_snapshot($filters);

        assert_same(
            $before,
            $after,
            'the closed period must report exactly what it reported before — accepting late, resolving and reopening '
            . 'all happened AFTER it ended and cannot reach back into it'
        );
    } finally {
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
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

    $monthStart = new DateTimeImmutable(date('Y-m-01', strtotime('-6 months')));
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
