<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\ReportService;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// ผลงานทีมช่าง is the one report used to judge PEOPLE, and REPORT-GUIDE makes a specific promise about it:
// the performance columns are credited to "งานที่ช่างคนนั้นปิดจริงในช่วง" — the person who actually resolved it —
// and therefore "ไม่เปลี่ยนย้อนหลัง แม้ภายหลังมีการเปิดซ้ำ/โยกงาน". A promise about someone's record deserves a
// known-answer check rather than a re-read of the query, so this builds a closed month whose per-technician
// answer is arithmetic:
//
//   ช่าง A resolves P 2h after it was raised · the requester closed it and rated 5 · SLA met
//   ช่าง A also resolves R 2h after it was raised · still waiting for the requester · SLA met
//   ช่าง B resolves Q 4h after it was raised · closed and rated 3 · SLA missed
//
// by hand: A → 2 closed, MTTR 2.0, SLA 100.0%, score 5.0 from its single review · B → 1 closed, MTTR 4.0,
// SLA 0.0% (a real miss, not "no data"), score 3.0 · team SLA card = 2 on-time out of the 3 judged = 66.7%.
//
// Then R — the one A resolved that is still reopenable — is reopened and handed to ช่าง B months later. That is
// exactly the move the guide promises will NOT rewrite history: the closure stays credited to A.

function tka_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function tka_tech(string $sfx, string $tag): int
{
    tka_pdo()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, "x", ?, "technician", 1, NOW(), NOW())'
    )->execute(["tka{$tag}_$sfx", "tka{$tag}_$sfx@example.com", "TkaTech$tag-$sfx"]);

    return (int) tka_pdo()->lastInsertId();
}

/** @return array<string, array<string, mixed>> rows keyed by technician name */
function tka_rows(array $filters): array
{
    $page = tvm_container()->get(ReportService::class)->getTechnicianPerformanceReportPage(['id' => 4, 'role' => 'admin'], $filters);
    $byName = [];
    foreach (($page['rows'] ?? []) as $row) {
        $byName[(string) ($row['full_name'] ?? '')] = $row;
    }

    return ['rows' => $byName, 'summary' => $page['summary'] ?? []];
}

test('technician(known-answer): each technician is judged on the work they actually closed', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $tickets = tvm_container()->get(TicketService::class);
    $pdo = tka_pdo();
    $sfx = bin2hex(random_bytes(4));
    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];

    $techA = tka_tech($sfx, 'A');
    $techB = tka_tech($sfx, 'B');
    $nameA = "TkaTechA-$sfx";
    $nameB = "TkaTechB-$sfx";

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["TKAD-$sfx", "TkaDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();

    $monthStart = (new DateTimeImmutable('first day of this month'))->modify('-4 months');
    $monthEnd = $monthStart->modify('last day of this month');
    $filters = [
        'from_date' => $monthStart->format('Y-m-d'),
        'to_date' => $monthEnd->format('Y-m-d'),
        'department_id' => $deptId,
    ];
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $ids = [];

    /** Drive one ticket to resolved by $techId, then place the whole story inside the closed month. */
    $seed = static function (string $tag, int $techId, int $hours, ?int $score, bool $slaMet) use (
        $wf,
        $tickets,
        $pdo,
        $admin,
        $requester,
        $ref,
        $deptId,
        $monthStart,
        &$ids
    ): void {
        $id = $tickets->createTicket($requester, [
            'submission_token' => bin2hex(random_bytes(32)),
            'title' => "tka $tag",
            'description' => 'x',
            'priority_id' => (int) $ref['priorities'][0]['id'],
            'ticket_category_id' => (int) $ref['categories'][0]['id'],
            'location_id' => (int) $ref['locations'][0]['id'],
            'impact_level' => 'medium',
            'urgency_level' => 'medium',
        ], []);
        $ids[$tag] = $id;

        $wf->approveTicket($id, $admin, ['note' => '']);
        $wf->assignTechnician($id, $admin, ['technician_id' => $techId, 'instructions' => '']);
        $tech = ['id' => $techId, 'role' => 'technician'];
        $wf->acceptAssignedWork($id, $tech, ['accept_note' => '']);
        $wf->startAssignedWork($id, $tech, ['start_note' => '']);
        $wf->resolveAssignedWork($id, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '30']);
        if ($score !== null) {
            $wf->completeResolvedTicket($id, $requester, ['closure_note' => '', 'score' => $score, 'feedback' => 'x']);
        }

        $raised = $monthStart->modify('+3 days +9 hours');
        $resolved = $raised->modify("+$hours hours");
        $pdo->prepare('UPDATE tickets SET requester_department_id = ?, requested_at = ?, created_at = ? WHERE id = ?')
            ->execute([$deptId, $raised->format('Y-m-d H:i:s'), $raised->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare('UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ?')
            ->execute([$raised->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare("UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ? AND action = 'ticket_resolved'")
            ->execute([$resolved->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare("UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ? AND action = 'ticket_completed'")
            ->execute([$resolved->modify('+1 minute')->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare('UPDATE ticket_ratings SET created_at = ?, updated_at = ? WHERE ticket_id = ?')
            ->execute([$resolved->format('Y-m-d H:i:s'), $resolved->format('Y-m-d H:i:s'), $id]);
        // one judged resolution metric per ticket: met = answered before target, missed = target passed unanswered
        $pdo->prepare('UPDATE ticket_sla_tracks SET created_at = ? WHERE ticket_id = ?')->execute([$raised->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare("DELETE FROM ticket_sla_tracks WHERE ticket_id = ? AND metric_type = 'response'")->execute([$id]);
        if ($slaMet) {
            $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = ?, breached_at = NULL, status = 'met' WHERE ticket_id = ?")
                ->execute([$raised->modify('+8 hours')->format('Y-m-d H:i:s'), $resolved->format('Y-m-d H:i:s'), $id]);
        } else {
            $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = NULL, breached_at = ?, status = 'breached' WHERE ticket_id = ?")
                ->execute([$raised->modify('+1 hour')->format('Y-m-d H:i:s'), $raised->modify('+1 hour')->format('Y-m-d H:i:s'), $id]);
        }
    };

    try {
        $seed('P', $techA, 2, 5, true);   // closed + rated by the requester
        $seed('R', $techA, 2, null, true); // A also resolved this one; it is still waiting for the requester
        $seed('Q', $techB, 4, 3, false);

        $before = tka_rows($filters);
        $a = $before['rows'][$nameA] ?? [];
        $b = $before['rows'][$nameB] ?? [];
        assert_true($a !== [] && $b !== [], 'both technicians appear in the report');

        assert_same(2, (int) $a['resolved'], 'A is credited with both tickets A closed');
        assert_same('2.0', (string) $a['mttr_hours_label'], "A's MTTR is the 2h A actually took");
        assert_same('100.0%', (string) $a['sla_on_time_label'], 'A answered within the deadline');
        assert_same('5.0', (string) $a['avg_rating_label'], "and carries A's 5-star review");
        assert_same(1, (int) $a['rating_count'], 'from a base of one review, stated');

        assert_same(1, (int) $b['resolved'], 'B is credited with the one ticket B closed');
        assert_same('4.0', (string) $b['mttr_hours_label'], "B's MTTR is the 4h B actually took");
        assert_same('0.0%', (string) $b['sla_on_time_label'], 'B missed the deadline — 0%, not "no data"');
        assert_same('3.0', (string) $b['avg_rating_label'], "and carries B's 3-star review");

        assert_same('66.7%', (string) ($before['summary']['sla_on_time_label'] ?? ''), 'the team card is 2 on-time out of the 3 judged');

        // ── the move the guide promises will NOT rewrite anyone's record ──
        // R is handed to B months later (the "ช่างเดิมลาออก" case). A closed it; the credit stays with A.
        $wf->reopenTicket($ids['R'], $requester, ['reopen_note' => 'ยังไม่หาย']);
        $wf->assignTechnician($ids['R'], $admin, ['technician_id' => $techB, 'instructions' => 'ช่างเดิมลาออก']);

        $after = tka_rows($filters);
        assert_same(
            [$a['resolved'], $a['mttr_hours_label'], $a['sla_on_time_label'], $a['avg_rating_label']],
            [
                $after['rows'][$nameA]['resolved'] ?? null,
                $after['rows'][$nameA]['mttr_hours_label'] ?? null,
                $after['rows'][$nameA]['sla_on_time_label'] ?? null,
                $after['rows'][$nameA]['avg_rating_label'] ?? null,
            ],
            "A's record for that month is untouched by a reopen and a reassign that happened after it"
        );
        assert_same(1, (int) ($after['rows'][$nameB]['resolved'] ?? -1), 'and B is not handed a closure B never made');

        // REPORT-GUIDE also promises that a technician who has since left ("ช่างที่ถูกปิดบัญชี/ปลดจากตำแหน่ง")
        // keeps their record — the work happened, and erasing it would quietly flatter the remaining team's numbers.
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$techA]);
        $departed = tka_rows($filters)['rows'][$nameA] ?? [];
        assert_true($departed !== [], 'a departed technician still has a row — their history is not deleted with the account');
        assert_same(2, (int) $departed['resolved'], 'and it still shows the work they actually did');
        assert_same('5.0', (string) $departed['avg_rating_label'], 'including the score their work earned');
        assert_false((bool) $departed['is_active_tech'], 'while being marked as no longer on the active roster');
        // their PAST record stands, but they carry none of today's queue — the live columns must say so rather
        // than keep counting a person who has left against the team's current load
        assert_same(0, (int) $departed['open_now'], 'a departed technician holds no live work');
    } finally {
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
        $pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$techA, $techB]);
    }
});
