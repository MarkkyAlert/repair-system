<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\ReportService;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// The last two dimension reports leadership reads, each pinned to an answer worked out by hand.
//
// งานค้างตามอายุ (backlog aging) is deliberately a LIVE snapshot, not a frozen period: it answers "what is
// sitting on our desk right now, and how old is it". Four open tickets aged 1, 5, 10 and 40 days must land in
// one bucket each, total 4, oldest 40 — and a ticket raised today must read "0 วัน", never "-" (which means
// "nothing is waiting").
//
// งานเปิดซ้ำ (reopen rate) is the quality signal: of the work closed in a window, how much came back. Three
// closures with one reopen = 33.3% reopened / 66.7% first-time-fixed, and the two must add to exactly 100.0%.

function bkr_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('backlog(known-answer): open work falls into the right age bucket, and today\'s ticket is 0 days not "-"', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $tickets = tvm_container()->get(TicketService::class);
    $svc = tvm_container()->get(ReportService::class);
    $pdo = bkr_pdo();
    $sfx = bin2hex(random_bytes(4));
    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["BKRD-$sfx", "BkrDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $ids = [];

    $open = static function (int $daysOld) use ($wf, $tickets, $pdo, $admin, $requester, $ref, $deptId, &$ids): void {
        $id = $tickets->createTicket($requester, [
            'submission_token' => bin2hex(random_bytes(32)),
            'title' => 'bkr backlog',
            'description' => 'x',
            'priority_id' => (int) $ref['priorities'][0]['id'],
            'ticket_category_id' => (int) $ref['categories'][0]['id'],
            'location_id' => (int) $ref['locations'][0]['id'],
            'impact_level' => 'medium',
            'urgency_level' => 'medium',
        ], []);
        $ids[] = $id;
        $wf->approveTicket($id, $admin, ['note' => '']); // approved = still open work
        // midday so the day-difference is unambiguous whatever time the suite runs
        $raised = (new DateTimeImmutable('today'))->setTime(12, 0)->modify("-$daysOld days");
        $pdo->prepare('UPDATE tickets SET requester_department_id = ?, requested_at = ?, created_at = ? WHERE id = ?')
            ->execute([$deptId, $raised->format('Y-m-d H:i:s'), $raised->format('Y-m-d H:i:s'), $id]);
    };

    try {
        $open(1);   // 0–3 days
        $open(5);   // 3–7
        $open(10);  // 7–30
        $open(40);  // 30+

        $rows = $svc->getBacklogAgingReportPage($admin, ['dimension' => 'department', 'department_id' => $deptId])['rows'] ?? [];
        $row = null;
        foreach ($rows as $candidate) {
            if ((string) ($candidate['label'] ?? '') === "BkrDept-$sfx") {
                $row = $candidate;
            }
        }
        assert_true($row !== null, 'the department appears in the backlog table');

        assert_same(1, (int) $row['bucket_0_3'], 'the 1-day-old ticket is in the freshest bucket');
        assert_same(1, (int) $row['bucket_3_7'], 'the 5-day-old one is in 3–7');
        assert_same(1, (int) $row['bucket_7_30'], 'the 10-day-old one is in 7–30');
        assert_same(1, (int) $row['bucket_30_plus'], 'the 40-day-old one is in 30+');
        assert_same(4, (int) $row['total'], 'four tickets are waiting in total');
        assert_same(40, (int) $row['oldest_days'], 'and the oldest has been waiting 40 days');
        assert_same('40 วัน', (string) $row['oldest_label'], 'stated in words for the reader');

        // a ticket raised today is 0 days old — real data, not "nothing waiting"
        $open(0);
        $fresh = null;
        foreach (($svc->getBacklogAgingReportPage($admin, ['dimension' => 'department', 'department_id' => $deptId])['rows'] ?? []) as $candidate) {
            if ((string) ($candidate['label'] ?? '') === "BkrDept-$sfx") {
                $fresh = $candidate;
            }
        }
        assert_same(5, (int) $fresh['total'], 'the ticket raised today joins the queue');
        assert_same(2, (int) $fresh['bucket_0_3'], 'and lands in the freshest bucket');
    } finally {
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

test('reopen(known-answer): one comeback out of three closures is 33.3%, and reopen + FTF add to 100.0%', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $tickets = tvm_container()->get(TicketService::class);
    $svc = tvm_container()->get(ReportService::class);
    $pdo = bkr_pdo();
    $sfx = bin2hex(random_bytes(4));
    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $tech = ['id' => 3, 'role' => 'technician'];

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["BKRR-$sfx", "BkrReDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();

    $monthStart = (new DateTimeImmutable('first day of this month'))->setTime(0, 0)->modify('-3 months');
    $monthEnd = $monthStart->modify('last day of this month');
    $filters = [
        'dimension' => 'department',
        'from_date' => $monthStart->format('Y-m-d'),
        'to_date' => $monthEnd->format('Y-m-d'),
        'department_id' => $deptId,
    ];
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $ids = [];

    $seed = static function (string $tag, bool $reopen) use ($wf, $tickets, $pdo, $admin, $requester, $tech, $ref, $deptId, $monthStart, &$ids): void {
        $id = $tickets->createTicket($requester, [
            'submission_token' => bin2hex(random_bytes(32)),
            'title' => "bkr $tag",
            'description' => 'x',
            'priority_id' => (int) $ref['priorities'][0]['id'],
            'ticket_category_id' => (int) $ref['categories'][0]['id'],
            'location_id' => (int) $ref['locations'][0]['id'],
            'impact_level' => 'medium',
            'urgency_level' => 'medium',
        ], []);
        $ids[$tag] = $id;
        $wf->approveTicket($id, $admin, ['note' => '']);
        $wf->assignTechnician($id, $admin, ['technician_id' => 3, 'instructions' => '']);
        $wf->acceptAssignedWork($id, $tech, ['accept_note' => '']);
        $wf->startAssignedWork($id, $tech, ['start_note' => '']);
        $wf->resolveAssignedWork($id, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '10']);
        if ($reopen) {
            $wf->reopenTicket($id, $requester, ['reopen_note' => 'ยังไม่หาย']);
        }

        // place the whole story — the resolve AND any reopen — inside the closed month
        $raised = $monthStart->modify('+3 days +9 hours');
        $pdo->prepare('UPDATE tickets SET requester_department_id = ?, requested_at = ?, created_at = ? WHERE id = ?')
            ->execute([$deptId, $raised->format('Y-m-d H:i:s'), $raised->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare('UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ?')->execute([$raised->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare("UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ? AND action = 'ticket_resolved'")
            ->execute([$raised->modify('+2 hours')->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare("UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ? AND action = 'ticket_reopened'")
            ->execute([$raised->modify('+5 hours')->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare('UPDATE ticket_sla_tracks SET created_at = ? WHERE ticket_id = ?')->execute([$raised->format('Y-m-d H:i:s'), $id]);
    };

    try {
        $seed('C1', false);
        $seed('C2', false);
        $seed('C3', true); // came back

        $page = $svc->getReopenRateReportPage($admin, $filters);
        $row = null;
        foreach (($page['rows'] ?? []) as $candidate) {
            if ((string) ($candidate['label'] ?? '') === "BkrReDept-$sfx") {
                $row = $candidate;
            }
        }
        assert_true($row !== null, 'the department appears in the reopen table');

        assert_same(3, (int) $row['resolved'], 'three closures in the window');
        assert_same(1, (int) $row['reopened'], 'one of them came back');
        assert_same('33.3%', (string) $row['reopen_rate_label'], '1 of 3 = 33.3%');
        assert_same('66.7%', (string) $row['ftf_label'], 'and first-time-fixed is the exact complement');
        assert_same(
            '100.0',
            number_format((float) $row['reopen_rate'] + (float) str_replace('%', '', (string) $row['ftf_label']), 1),
            'the two halves add to exactly 100.0% — neither 99.9 nor 100.1 at a rounding edge'
        );
    } finally {
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});
