<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\ReportService;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// closed_period_immutability_test's own coverage map lists พื้นที่ปัญหา (problem-hotspot) as THIN: reached only by
// a snapshot test that cannot see a number that was wrong from the start. This is its known-answer fixture.
//
// One department, one closed month:
//   H1 raised → resolved 2h later, deadline MET   (judged, on time)   labour 30m
//   H2 raised → resolved 6h later, deadline MISSED (judged, late)      labour 90m
//   H3 raised → still open at the month end, its deadline only falls due AFTER the month (not judged at all)
//   H4 raised → cancelled (never real workload)
//
// By hand: งาน 3 (the cancelled one is not workload) · ค้าง 1 · เกินกำหนด 1 · ฐานที่ตัดสินได้ 2 ·
// %เกิน SLA = 1 ÷ 2 = 50.0% (NOT 1 ÷ 3 — work that is not yet due is neither numerator nor denominator) ·
// เวลาซ่อมเฉลี่ย = (2h + 6h) / 2 = 4.0 · ชั่วโมงแรงงาน = (30 + 90)/60 = 2.0

function hka_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('hotspot(known-answer): each column of a closed month equals the hand-computed value', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $tickets = tvm_container()->get(TicketService::class);
    $svc = tvm_container()->get(ReportService::class);
    $pdo = hka_pdo();
    $sfx = bin2hex(random_bytes(4));
    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $tech = ['id' => 3, 'role' => 'technician'];

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["HKAD-$sfx", "HkaDept-$sfx"]);
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

    /**
     * @param int|null  $hours   resolve this many hours after it was raised (null = leave it open)
     * @param bool|null $slaMet  true = met inside the month, false = missed inside it, null = not due until after
     */
    $seed = static function (string $tag, ?int $hours, ?bool $slaMet, int $labour, bool $cancel = false) use (
        $wf,
        $tickets,
        $pdo,
        $admin,
        $requester,
        $tech,
        $ref,
        $deptId,
        $monthStart,
        $monthEnd,
        &$ids
    ): void {
        $id = $tickets->createTicket($requester, [
            'submission_token' => bin2hex(random_bytes(32)),
            'title' => "hka $tag",
            'description' => 'x',
            'priority_id' => (int) $ref['priorities'][0]['id'],
            'ticket_category_id' => (int) $ref['categories'][0]['id'],
            'location_id' => (int) $ref['locations'][0]['id'],
            'impact_level' => 'medium',
            'urgency_level' => 'medium',
        ], []);
        $ids[$tag] = $id;

        if ($cancel) {
            $wf->cancelTicket($id, $requester, ['cancel_note' => 'ไม่ต้องการแล้ว']);
        } else {
            $wf->approveTicket($id, $admin, ['note' => '']);
            if ($hours !== null) {
                $wf->assignTechnician($id, $admin, ['technician_id' => 3, 'instructions' => '']);
                $wf->acceptAssignedWork($id, $tech, ['accept_note' => '']);
                $wf->startAssignedWork($id, $tech, ['start_note' => '']);
                $wf->resolveAssignedWork($id, $tech, [
                    'diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => (string) $labour,
                ]);
            }
        }

        $raised = $monthStart->modify('+2 days +9 hours');
        $pdo->prepare('UPDATE tickets SET requester_department_id = ?, requested_at = ?, created_at = ? WHERE id = ?')
            ->execute([$deptId, $raised->format('Y-m-d H:i:s'), $raised->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare('UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ?')->execute([$raised->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare('UPDATE ticket_sla_tracks SET created_at = ? WHERE ticket_id = ?')->execute([$raised->format('Y-m-d H:i:s'), $id]);
        // one judged metric per ticket keeps the SLA base countable by hand
        $pdo->prepare("DELETE FROM ticket_sla_tracks WHERE ticket_id = ? AND metric_type = 'response'")->execute([$id]);

        if ($hours !== null) {
            $resolved = $raised->modify("+$hours hours");
            $pdo->prepare("UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ? AND action = 'ticket_resolved'")
                ->execute([$resolved->format('Y-m-d H:i:s'), $id]);
        }

        if ($slaMet === true) {
            $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = ?, breached_at = NULL, status = 'met' WHERE ticket_id = ?")
                ->execute([
                    $raised->modify('+12 hours')->format('Y-m-d H:i:s'),
                    $raised->modify("+{$hours} hours")->format('Y-m-d H:i:s'),
                    $id,
                ]);
        } elseif ($slaMet === false) {
            $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = NULL, breached_at = ?, status = 'breached' WHERE ticket_id = ?")
                ->execute([$raised->modify('+1 hour')->format('Y-m-d H:i:s'), $raised->modify('+1 hour')->format('Y-m-d H:i:s'), $id]);
        } else {
            // not due until well after the month closed → at the cutoff it is neither met nor missed
            $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = NULL, breached_at = NULL, status = 'pending' WHERE ticket_id = ?")
                ->execute([$monthEnd->modify('+20 days')->format('Y-m-d H:i:s'), $id]);
        }
    };

    try {
        $seed('H1', 2, true, 30);
        $seed('H2', 6, false, 90);
        $seed('H3', null, null, 0);
        $seed('H4', null, null, 0, true);

        $rows = $svc->getProblemHotspotReportPage($admin, $filters)['rows'] ?? [];
        $row = null;
        foreach ($rows as $candidate) {
            if ((string) ($candidate['label'] ?? '') === "HkaDept-$sfx") {
                $row = $candidate;
            }
        }
        assert_true($row !== null, 'the department appears as its own hotspot row');

        assert_same(3, (int) $row['ticket_count'], 'cancelled work is not counted as workload for the area');
        assert_same(1, (int) $row['open_count'], 'one ticket was still open when the month closed');
        assert_same(1, (int) $row['overdue_count'], 'one deadline was missed inside the month');
        assert_same(2, (int) $row['sla_base'], 'two deadlines had actually been judged by then');
        assert_same(
            '50.0%',
            (string) $row['overdue_rate_label'],
            'the miss rate is 1 of the 2 JUDGED (not 1 of 3) — work not yet due is neither numerator nor denominator'
        );
        assert_same('4.0', (string) $row['avg_resolution_hours_label'], 'average repair time = mean of 2h and 6h');
        assert_same('2.0', (string) $row['labor_hours_label'], 'recorded labour = 30m + 90m');
    } finally {
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});
