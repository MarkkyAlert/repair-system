<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\ReportService;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// แนวโน้ม is the page leadership reads to decide whether things are getting better or worse, so a wrong row here
// is a wrong decision. This builds two finished months whose every trend column is arithmetic:
//
//   month 1  T1 raised → resolved 2h later, rated 4, SLA met · T2 and T3 raised and still open
//            → แจ้ง 3 · ปิด 1 · net +2 · SLA 100.0% · MTTR 2.0 · คะแนน 4.00 จาก 1 รีวิว
//   month 2  T4 raised → resolved 4h later, rated 5, SLA met · T5 raised → resolved 6h later, rated 5, SLA missed
//            → แจ้ง 2 · ปิด 2 · net 0 · SLA 50.0% · MTTR 5.0 · คะแนน 5.00 จาก 2 รีวิว
//
// "ปิด" is event-sourced (bucketed by the ticket_resolved event), "แจ้ง" by the day it was raised, and every
// average/rate is gated on its own base — so this also pins that a real 0/low value is reported as data, never
// as "-", and that the two months do not bleed into each other.

function tkn_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** @return array<string, array<string, mixed>> trend rows keyed by bucket label */
function tkn_periods(array $filters): array
{
    $page = tvm_container()->get(ReportService::class)->getTicketTrendReportPage(['id' => 4, 'role' => 'admin'], $filters);
    $byKey = [];
    foreach (($page['periods'] ?? []) as $row) {
        $byKey[(string) ($row['key'] ?? '')] = $row;
    }

    return ['periods' => $byKey, 'summary' => $page['summary'] ?? []];
}

test('trend(known-answer): every column of two finished months equals the hand-computed value', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $tickets = tvm_container()->get(TicketService::class);
    $pdo = tkn_pdo();
    $sfx = bin2hex(random_bytes(4));
    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $tech = ['id' => 3, 'role' => 'technician'];

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["TKND-$sfx", "TknDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();

    $m1 = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
    $m2 = (new DateTimeImmutable('first day of this month'))->modify('-4 months');
    $filters = [
        'granularity' => 'month',
        'from_date' => $m1->format('Y-m-d'),
        'to_date' => $m2->modify('last day of this month')->format('Y-m-d'),
        'department_id' => $deptId,
    ];
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $ids = [];

    /** Raise a ticket inside $month; optionally resolve it $hours later, rate it, and settle its SLA. */
    $seed = static function (string $tag, DateTimeImmutable $month, ?int $hours, ?int $score, ?bool $slaMet) use (
        $wf,
        $tickets,
        $pdo,
        $admin,
        $requester,
        $tech,
        $ref,
        $deptId,
        &$ids
    ): void {
        $id = $tickets->createTicket($requester, [
            'submission_token' => bin2hex(random_bytes(32)),
            'title' => "tkn $tag",
            'description' => 'x',
            'priority_id' => (int) $ref['priorities'][0]['id'],
            'ticket_category_id' => (int) $ref['categories'][0]['id'],
            'location_id' => (int) $ref['locations'][0]['id'],
            'impact_level' => 'medium',
            'urgency_level' => 'medium',
        ], []);
        $ids[$tag] = $id;
        $wf->approveTicket($id, $admin, ['note' => '']);

        if ($hours !== null) {
            $wf->assignTechnician($id, $admin, ['technician_id' => 3, 'instructions' => '']);
            $wf->acceptAssignedWork($id, $tech, ['accept_note' => '']);
            $wf->startAssignedWork($id, $tech, ['start_note' => '']);
            $wf->resolveAssignedWork($id, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '15']);
            if ($score !== null) {
                $wf->completeResolvedTicket($id, $requester, ['closure_note' => '', 'score' => $score, 'feedback' => 'x']);
            }
        }

        $raised = $month->modify('+4 days +9 hours');
        $pdo->prepare('UPDATE tickets SET requester_department_id = ?, requested_at = ?, created_at = ? WHERE id = ?')
            ->execute([$deptId, $raised->format('Y-m-d H:i:s'), $raised->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare('UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ?')->execute([$raised->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare('UPDATE ticket_sla_tracks SET created_at = ? WHERE ticket_id = ?')->execute([$raised->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare("DELETE FROM ticket_sla_tracks WHERE ticket_id = ? AND metric_type = 'response'")->execute([$id]);

        if ($hours === null) {
            return;
        }

        $resolved = $raised->modify("+$hours hours");
        $pdo->prepare("UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ? AND action = 'ticket_resolved'")
            ->execute([$resolved->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare("UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ? AND action = 'ticket_completed'")
            ->execute([$resolved->modify('+1 minute')->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare('UPDATE ticket_ratings SET created_at = ?, updated_at = ? WHERE ticket_id = ?')
            ->execute([$resolved->format('Y-m-d H:i:s'), $resolved->format('Y-m-d H:i:s'), $id]);
        if ($slaMet === true) {
            $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = ?, breached_at = NULL, status = 'met' WHERE ticket_id = ?")
                ->execute([$raised->modify('+12 hours')->format('Y-m-d H:i:s'), $resolved->format('Y-m-d H:i:s'), $id]);
        } elseif ($slaMet === false) {
            $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = NULL, breached_at = ?, status = 'breached' WHERE ticket_id = ?")
                ->execute([$raised->modify('+1 hour')->format('Y-m-d H:i:s'), $raised->modify('+1 hour')->format('Y-m-d H:i:s'), $id]);
        }
    };

    try {
        $seed('T1', $m1, 2, 4, true);
        $seed('T2', $m1, null, null, null);
        $seed('T3', $m1, null, null, null);
        $seed('T4', $m2, 4, 5, true);
        $seed('T5', $m2, 6, 5, false);

        $result = tkn_periods($filters);
        $p1 = $result['periods'][$m1->format('Y-m')] ?? [];
        $p2 = $result['periods'][$m2->format('Y-m')] ?? [];
        assert_true($p1 !== [] && $p2 !== [], 'both months appear as their own bucket');

        // month 1 — one closure out of three raised
        assert_same(3, (int) $p1['created'], 'month 1 raised three');
        assert_same(1, (int) $p1['resolved'], 'month 1 closed one');
        assert_same(2, (int) $p1['net'], 'month 1 net = 3 raised − 1 closed');
        assert_same('100.0%', (string) $p1['sla_pct_label'], 'its single judged closure was on time');
        assert_same('2.0', (string) $p1['mttr_hours_label'], 'and took 2h');
        assert_same('4.00', (string) $p1['csat_label'], 'rated 4');
        assert_same(1, (int) $p1['rating_count'], 'from one review');

        // month 2 — both raised tickets closed, one of them late
        assert_same(2, (int) $p2['created'], 'month 2 raised two');
        assert_same(2, (int) $p2['resolved'], 'month 2 closed both');
        assert_same(0, (int) $p2['net'], 'month 2 net = 0');
        assert_same('50.0%', (string) $p2['sla_pct_label'], 'one of the two closures missed its deadline — a real 50%, not "-"');
        assert_same('5.0', (string) $p2['mttr_hours_label'], 'MTTR = mean of 4h and 6h');
        assert_same('5.00', (string) $p2['csat_label'], 'both reviews were 5');
        assert_same(2, (int) $p2['rating_count'], 'from two reviews');

        // the two months must not bleed into one another
        assert_same(2, (int) $p1['sla_base'] + (int) $p2['sla_base'] - 1, 'each month judges only its own closures (1 + 2)');

        // the latest-period card compares month 2 against month 1 — both finished, so no elapsed-day equalisation
        $summary = $result['summary'];
        assert_same('2', (string) ($summary['created']['value'] ?? ''), 'the card leads with the latest month, not the whole window');
        assert_contains_str('-1', (string) ($summary['created']['delta_label'] ?? ''), 'and reports the drop from 3 raised to 2');
        assert_same('5.00', (string) ($summary['csat']['value'] ?? ''), 'the card carries the latest month score');
        assert_same(2, (int) ($summary['csat']['sample_count'] ?? -1), 'with the base it rests on');
    } finally {
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});
