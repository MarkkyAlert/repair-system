<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\ReportService;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// AN-01 round 4 (2026-07-26) — the behavioural net that replaces pattern-scanning.
//
// Three separate reviews found three separate ways a closed period could rewrite itself, and each time the
// text-scanning guard missed it because it was looking for a shape rather than an outcome: first a NOW() in SQL,
// then a time() one layer up, then a due date from the wrong cycle (no clock involved at all), then an
// achievement recorded after the period closed. Scanning for clock APIs can never be complete — `time ()`,
// `strtotime('now')`, `date('U')`, `NOW() > x` and a wrong-cycle timestamp all slip through any grep.
//
// So this test asserts the PROPERTY instead of the implementation: photograph a closed period's report — header
// AND every detail row — then drive real workflow actions after that period ended, and demand the photograph is
// unchanged. It cannot be defeated by choosing a different API, because it never looks at the code.

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
            'the closed period must report exactly what it reported before — accepting late, reopening and '
            . 'cancelling all happened AFTER it ended and cannot reach back into it'
        );
    } finally {
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});
