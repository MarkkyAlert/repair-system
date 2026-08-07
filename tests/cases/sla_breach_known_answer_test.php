<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\ReportService;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// วิเคราะห์ SLA เกิน is where leadership goes to find the bottleneck, so its rate has to be countable by hand.
// One department, one closed month, each ticket carrying both a response and a resolution deadline:
//
//   S1 answered on time and fixed on time              → 2 deadlines met
//   S2 answered on time but fixed late                 → 1 met, 1 missed
//   S3 still waiting, nothing due until after the month → neither met nor missed (not concluded)
//   S4 cancelled                                        → out of the SLA population entirely
//
// By hand: response 2 met / 0 missed · resolution 1 met / 1 missed · missed 1 of the 4 CONCLUDED = 25.0%.
// The denominator is what has actually been decided — a queue of not-yet-due work must not dilute the rate.

function sbk_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('slaBreach(known-answer): the miss rate divides by concluded deadlines, not by everything raised', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $tickets = tvm_container()->get(TicketService::class);
    $svc = tvm_container()->get(ReportService::class);
    $pdo = sbk_pdo();
    $sfx = bin2hex(random_bytes(4));
    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $tech = ['id' => 3, 'role' => 'technician'];

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["SBKD-$sfx", "SbkDept-$sfx"]);
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

    /** @param string $shape met|late|pending|cancel */
    $seed = static function (string $tag, string $shape) use (
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
            'title' => "sbk $tag",
            'description' => 'x',
            'priority_id' => (int) $ref['priorities'][0]['id'],
            'ticket_category_id' => (int) $ref['categories'][0]['id'],
            'location_id' => (int) $ref['locations'][0]['id'],
            'impact_level' => 'medium',
            'urgency_level' => 'medium',
        ], []);
        $ids[$tag] = $id;

        if ($shape === 'cancel') {
            $wf->cancelTicket($id, $requester, ['cancel_note' => 'ไม่ต้องการแล้ว']);
        } else {
            $wf->approveTicket($id, $admin, ['note' => '']);
            if ($shape !== 'pending') {
                $wf->assignTechnician($id, $admin, ['technician_id' => 3, 'instructions' => '']);
                $wf->acceptAssignedWork($id, $tech, ['accept_note' => '']);
                $wf->startAssignedWork($id, $tech, ['start_note' => '']);
                $wf->resolveAssignedWork($id, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '10']);
            }
        }

        $raised = $monthStart->modify('+4 days +9 hours');
        $pdo->prepare('UPDATE tickets SET requester_department_id = ?, requested_at = ?, created_at = ? WHERE id = ?')
            ->execute([$deptId, $raised->format('Y-m-d H:i:s'), $raised->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare('UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ?')->execute([$raised->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare('UPDATE ticket_sla_tracks SET created_at = ? WHERE ticket_id = ?')->execute([$raised->format('Y-m-d H:i:s'), $id]);

        $answered = $raised->modify('+1 hour')->format('Y-m-d H:i:s');
        $responseTarget = $raised->modify('+4 hours')->format('Y-m-d H:i:s');

        if ($shape === 'pending') {
            // nothing falls due until well after the month ended → neither metric is concluded
            $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = NULL, breached_at = NULL, status = 'pending' WHERE ticket_id = ?")
                ->execute([$monthEnd->modify('+20 days')->format('Y-m-d H:i:s'), $id]);

            return;
        }

        // answered on time in every non-pending shape
        $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = ?, breached_at = NULL, status = 'met' WHERE ticket_id = ? AND metric_type = 'response'")
            ->execute([$responseTarget, $answered, $id]);

        if ($shape === 'met') {
            $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = ?, breached_at = NULL, status = 'met' WHERE ticket_id = ? AND metric_type = 'resolution'")
                ->execute([$raised->modify('+12 hours')->format('Y-m-d H:i:s'), $raised->modify('+6 hours')->format('Y-m-d H:i:s'), $id]);
        } elseif ($shape === 'late') {
            $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = NULL, breached_at = ?, status = 'breached' WHERE ticket_id = ? AND metric_type = 'resolution'")
                ->execute([$raised->modify('+2 hours')->format('Y-m-d H:i:s'), $raised->modify('+2 hours')->format('Y-m-d H:i:s'), $id]);
        } else { // cancel — its tracks exist but the ticket is out of the population
            $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = NULL, breached_at = ?, status = 'breached' WHERE ticket_id = ?")
                ->execute([$raised->modify('+1 hour')->format('Y-m-d H:i:s'), $raised->modify('+1 hour')->format('Y-m-d H:i:s'), $id]);
        }
    };

    try {
        $seed('S1', 'met');
        $seed('S2', 'late');
        $seed('S3', 'pending');
        $seed('S4', 'cancel');

        $page = $svc->getSlaBreachReportPage($admin, $filters);
        $row = null;
        foreach (($page['rows'] ?? []) as $candidate) {
            if ((string) ($candidate['label'] ?? '') === "SbkDept-$sfx") {
                $row = $candidate;
            }
        }
        assert_true($row !== null, 'the department appears in the SLA-breach table');

        assert_same(2, (int) $row['response']['met'], 'both answered tickets met their response deadline');
        assert_same(0, (int) $row['response']['breached'], 'no response deadline was missed');
        assert_same(1, (int) $row['resolution']['met'], 'one repair finished on time');
        assert_same(1, (int) $row['resolution']['breached'], 'the other finished late');

        assert_same(1, (int) $row['total_breached'], 'one missed deadline in total');
        assert_same(3, (int) $row['total_met'], 'three met');
        assert_same(4, (int) $row['total_concluded'], 'four deadlines had actually been decided by the month end');
        assert_same(
            '25.0%',
            (string) $row['breach_rate_label'],
            'the miss rate is 1 of the 4 CONCLUDED — cancelled work and not-yet-due work never enter the base'
        );
    } finally {
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});
