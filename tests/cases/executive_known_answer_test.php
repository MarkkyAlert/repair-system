<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\ReportService;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;

// The executive summary is the report leadership actually reads, and closed_period_immutability_test names it
// THIN by hand: covered only by a snapshot test, which catches "changed retroactively" but is blind to a number
// that was already wrong in the first photograph and stays wrong. This is the missing KNOWN-ANSWER fixture — a
// closed month whose correct KPI values are worked out by arithmetic here, with the data driven through the real
// workflow services, so every card is checked against the answer rather than against itself.
//
// The month holds, inside one throwaway department:
//   A  raised, resolved 2h later, rated 5, SLA met
//   B  raised, resolved 10h later, rated 3, resolution SLA breached inside the month
//   C  raised in the month, still open when it ended
//   D  cancelled inside the month
//   E  rejected inside the month
//
// By hand, therefore:
//   แจ้งซ่อมทั้งหมด 5 · ปิดงาน 2 · อัตราปิดงาน 2/5 = 40.0% · เกิน SLA 1 (cancelled+rejected are outside the SLA
//   population by owner decision) · เวลาซ่อมเฉลี่ย (2h+10h)/2 = 6.0 · คะแนนเฉลี่ย (5+3)/2 = 4.0 จาก 2 รีวิว

function eka_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function eka_kpi(array $page, string $label): array
{
    foreach (($page['kpis'] ?? []) as $kpi) {
        if ((string) ($kpi['label'] ?? '') === $label) {
            return $kpi;
        }
    }

    throw new RuntimeException("executive KPI card not found: $label");
}

/** Create a ticket through the real flow and return its id. */
function eka_create(int $requesterId): int
{
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();

    return tvm_container()->get(TicketService::class)->createTicket(
        ['id' => $requesterId, 'role' => 'requester'],
        [
            'submission_token' => bin2hex(random_bytes(32)),
            'title' => 'eka fixture',
            'description' => 'x',
            'priority_id' => (int) $ref['priorities'][0]['id'],
            'ticket_category_id' => (int) $ref['categories'][0]['id'],
            'location_id' => (int) $ref['locations'][0]['id'],
            'impact_level' => 'medium',
            'urgency_level' => 'medium',
        ],
        []
    );
}

/** Move a ticket (row + its whole activity log) into the closed month at $requestedAt. */
function eka_backdate(int $ticketId, int $deptId, string $requestedAt): void
{
    eka_pdo()->prepare('UPDATE tickets SET requester_department_id = ?, requested_at = ?, created_at = ? WHERE id = ?')
        ->execute([$deptId, $requestedAt, $requestedAt, $ticketId]);
    eka_pdo()->prepare('UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ?')
        ->execute([$requestedAt, $ticketId]);
    eka_pdo()->prepare('UPDATE ticket_sla_tracks SET created_at = ? WHERE ticket_id = ?')
        ->execute([$requestedAt, $ticketId]);
}

test('executive(known-answer): every KPI of a closed month equals the hand-computed value', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $svc = tvm_container()->get(ReportService::class);
    $pdo = eka_pdo();
    $sfx = bin2hex(random_bytes(4));
    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $tech = ['id' => 3, 'role' => 'technician'];

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["EKAD-$sfx", "EkaDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();

    // a month that is well and truly over (anchored to the 1st so month-end run dates cannot overflow it)
    $monthStart = (new DateTimeImmutable('first day of this month'))->modify('-6 months');
    $monthEnd = $monthStart->modify('last day of this month');
    $filters = [
        'preset' => 'custom',
        'from_date' => $monthStart->format('Y-m-d'),
        'to_date' => $monthEnd->format('Y-m-d'),
        'department_id' => $deptId,
    ];
    $ids = [];
    $baselineJobId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        // ── A: resolved 2h after it was raised, rated 5, SLA met ──
        $ids['A'] = eka_create(1);
        $wf->approveTicket($ids['A'], $admin, ['note' => '']);
        $wf->assignTechnician($ids['A'], $admin, ['technician_id' => 3, 'instructions' => '']);
        $wf->acceptAssignedWork($ids['A'], $tech, ['accept_note' => '']);
        $wf->startAssignedWork($ids['A'], $tech, ['start_note' => '']);
        $wf->resolveAssignedWork($ids['A'], $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '30']);
        $wf->completeResolvedTicket($ids['A'], $requester, ['closure_note' => '', 'score' => 5, 'feedback' => 'ok']);

        $aRaised = $monthStart->modify('+2 days +9 hours');
        eka_backdate($ids['A'], $deptId, $aRaised->format('Y-m-d H:i:s'));
        $aResolved = $aRaised->modify('+2 hours');
        $pdo->prepare("UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ? AND action = 'ticket_resolved'")
            ->execute([$aResolved->format('Y-m-d H:i:s'), $ids['A']]);
        $pdo->prepare("UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ? AND action = 'ticket_completed'")
            ->execute([$aResolved->modify('+1 minute')->format('Y-m-d H:i:s'), $ids['A']]);
        $pdo->prepare('UPDATE ticket_ratings SET created_at = ?, updated_at = ? WHERE ticket_id = ?')
            ->execute([$aResolved->format('Y-m-d H:i:s'), $aResolved->format('Y-m-d H:i:s'), $ids['A']]);
        // both metrics answered before their targets → met, entirely inside the month
        $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = ?, breached_at = NULL, status = 'met' WHERE ticket_id = ?")
            ->execute([$aRaised->modify('+8 hours')->format('Y-m-d H:i:s'), $aResolved->format('Y-m-d H:i:s'), $ids['A']]);

        // ── B: resolved 10h after it was raised, rated 3, resolution SLA breached inside the month ──
        $ids['B'] = eka_create(1);
        $wf->approveTicket($ids['B'], $admin, ['note' => '']);
        $wf->assignTechnician($ids['B'], $admin, ['technician_id' => 3, 'instructions' => '']);
        $wf->acceptAssignedWork($ids['B'], $tech, ['accept_note' => '']);
        $wf->startAssignedWork($ids['B'], $tech, ['start_note' => '']);
        $wf->resolveAssignedWork($ids['B'], $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '30']);
        $wf->completeResolvedTicket($ids['B'], $requester, ['closure_note' => '', 'score' => 3, 'feedback' => 'meh']);

        $bRaised = $monthStart->modify('+5 days +9 hours');
        eka_backdate($ids['B'], $deptId, $bRaised->format('Y-m-d H:i:s'));
        $bResolved = $bRaised->modify('+10 hours');
        $pdo->prepare("UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ? AND action = 'ticket_resolved'")
            ->execute([$bResolved->format('Y-m-d H:i:s'), $ids['B']]);
        $pdo->prepare("UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ? AND action = 'ticket_completed'")
            ->execute([$bResolved->modify('+1 minute')->format('Y-m-d H:i:s'), $ids['B']]);
        $pdo->prepare('UPDATE ticket_ratings SET created_at = ?, updated_at = ? WHERE ticket_id = ?')
            ->execute([$bResolved->format('Y-m-d H:i:s'), $bResolved->format('Y-m-d H:i:s'), $ids['B']]);
        $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = ?, breached_at = NULL, status = 'met' WHERE ticket_id = ? AND metric_type = 'response'")
            ->execute([$bRaised->modify('+8 hours')->format('Y-m-d H:i:s'), $bRaised->modify('+1 hour')->format('Y-m-d H:i:s'), $ids['B']]);
        // the resolution deadline passed INSIDE the month and was never achieved → exactly one breached ticket
        $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = NULL, breached_at = ?, status = 'breached' WHERE ticket_id = ? AND metric_type = 'resolution'")
            ->execute([
                $bRaised->modify('+4 hours')->format('Y-m-d H:i:s'),
                $bRaised->modify('+4 hours')->format('Y-m-d H:i:s'),
                $ids['B'],
            ]);

        // ── C: raised in the month, still open when it ended ──
        $ids['C'] = eka_create(1);
        $wf->approveTicket($ids['C'], $admin, ['note' => '']);
        $cRaised = $monthStart->modify('+9 days +9 hours');
        eka_backdate($ids['C'], $deptId, $cRaised->format('Y-m-d H:i:s'));
        // its deadlines only fall due AFTER the month closed, so at the cutoff it was merely waiting
        $pdo->prepare("UPDATE ticket_sla_tracks SET target_at = ?, achieved_at = NULL, breached_at = NULL, status = 'pending' WHERE ticket_id = ?")
            ->execute([$monthEnd->modify('+10 days')->format('Y-m-d H:i:s'), $ids['C']]);

        // ── D: cancelled inside the month ──
        $ids['D'] = eka_create(1);
        $wf->cancelTicket($ids['D'], $requester, ['cancel_note' => 'ไม่ต้องการแล้ว']);
        eka_backdate($ids['D'], $deptId, $monthStart->modify('+12 days +9 hours')->format('Y-m-d H:i:s'));

        // ── E: rejected inside the month ──
        $ids['E'] = eka_create(1);
        $wf->rejectTicket($ids['E'], $admin, ['note' => 'ไม่อยู่ในขอบเขต']);
        eka_backdate($ids['E'], $deptId, $monthStart->modify('+15 days +9 hours')->format('Y-m-d H:i:s'));

        // ── the answer sheet ──
        $page = $svc->getExecutiveSummaryPage($admin, $filters);

        assert_same('5', (string) eka_kpi($page, 'แจ้งซ่อมทั้งหมด')['value_label'], 'total = the five tickets raised in the month');
        assert_same('2', (string) eka_kpi($page, 'ปิดงาน')['value_label'], 'closed = A and B only (C open, D cancelled, E rejected)');
        assert_same('40.0%', (string) eka_kpi($page, 'อัตราปิดงาน')['value_label'], 'completion = 2 ÷ 5 as the guide defines it (denominator = everything raised)');
        assert_same('1', (string) eka_kpi($page, 'เกิน SLA')['value_label'], 'breached = B only; cancelled and rejected work is outside the SLA population');
        assert_same('6.0', (string) eka_kpi($page, 'เวลาซ่อมเฉลี่ย (ชม.)')['value_label'], 'MTTR = mean of 2h and 10h');

        $rating = eka_kpi($page, 'คะแนนเฉลี่ย');
        assert_same('4.0', (string) $rating['value_label'], 'CSAT = mean of 5 and 3');
        assert_contains_str('จาก 2 รีวิว', (string) ($rating['sample_label'] ?? ''), 'and the card discloses the two-review base behind it');

        // the previous month held nothing for this department: rates/averages must read as "no data", never a fake 0
        assert_same('0', (string) eka_kpi($page, 'แจ้งซ่อมทั้งหมด')['prev_value_label'], 'a count of zero is a real zero');
        assert_same('-', (string) eka_kpi($page, 'อัตราปิดงาน')['prev_value_label'], 'an empty previous month has no completion rate to compare');
        assert_same('-', (string) eka_kpi($page, 'เวลาซ่อมเฉลี่ย (ชม.)')['prev_value_label'], 'and no MTTR');
        assert_same('-', (string) eka_kpi($page, 'คะแนนเฉลี่ย')['prev_value_label'], 'and no score');

        // ── every surface the executive can reach must carry the SAME answer sheet ──
        // (the three exports read the same kpis array as the screen, but that is exactly the wiring worth pinning:
        //  a leader who prints the PDF must not get different numbers from the dashboard they just read)
        $csv = (string) $svc->exportExecutiveSummaryCsv($admin, $filters)['content'];
        foreach (['40.0%', '6.0', '4.0', 'จาก 2 รีวิว'] as $needle) {
            assert_contains_str($needle, $csv, "the CSV carries the same figure as the screen: $needle");
        }

        $xlsxPath = tempnam(sys_get_temp_dir(), 'eka_') . '.xlsx';
        file_put_contents($xlsxPath, (string) $svc->exportExecutiveSummaryExcel($admin, $filters)['content']);
        $sheetRows = IOFactory::createReader('Xlsx')->load($xlsxPath)->getActiveSheet()->toArray();
        @unlink($xlsxPath);
        $byLabel = [];
        foreach ($sheetRows as $line) {
            $byLabel[(string) ($line[0] ?? '')] = array_map('strval', $line);
        }
        assert_same('5', (string) ($byLabel['แจ้งซ่อมทั้งหมด'][1] ?? ''), 'XLSX total matches the screen (and stays a real number)');
        assert_same('2', (string) ($byLabel['ปิดงาน'][1] ?? ''), 'XLSX closed count matches');
        assert_same('1', (string) ($byLabel['เกิน SLA'][1] ?? ''), 'XLSX breach count matches');
        assert_same('40.0%', (string) ($byLabel['อัตราปิดงาน'][1] ?? ''), 'XLSX completion rate matches');
        // the decimal metrics are stored as REAL numbers so Excel can sum/pivot them ("6.0" → 6), which is why
        // these compare numerically rather than to the formatted screen string
        assert_same(6.0, (float) ($byLabel['เวลาซ่อมเฉลี่ย (ชม.)'][1] ?? 0), 'XLSX MTTR matches the screen value, as a number');
        assert_same(4.0, (float) ($byLabel['คะแนนเฉลี่ย'][1] ?? 0), 'XLSX CSAT matches the screen value, as a number');
        assert_true(in_array('จาก 2 รีวิว', $byLabel['คะแนนเฉลี่ย'] ?? [], true), 'XLSX keeps the CSAT sample size next to the score');

        $pdfText = (new Parser())->parseContent((string) $svc->exportExecutiveSummaryPdf($admin, $filters)['content'])->getText();
        foreach (['40.0%', '6.0', '4.0', 'จาก 2 รีวิว'] as $needle) {
            assert_contains_str($needle, $pdfText, "the printed PDF carries the same figure as the screen: $needle");
        }

        // ── the month is closed: nothing that happens now may rewrite it ──
        $before = $page['kpis'];
        // C is finally picked up and closed TODAY — months after the month it was raised in. Its closure belongs
        // to today, not to that month: the closed month must still read 5 raised / 2 closed / MTTR 6.0.
        $wf->assignTechnician($ids['C'], $admin, ['technician_id' => 3, 'instructions' => '']);
        $wf->acceptAssignedWork($ids['C'], $tech, ['accept_note' => '']);
        $wf->startAssignedWork($ids['C'], $tech, ['start_note' => '']);
        $wf->resolveAssignedWork($ids['C'], $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '10']);
        $wf->completeResolvedTicket($ids['C'], $requester, ['closure_note' => '', 'score' => 1, 'feedback' => 'late']);

        $after = $svc->getExecutiveSummaryPage($admin, $filters)['kpis'];
        assert_same($before, $after, 'a reopen and a late resolution AFTER the month cannot reach back and restate it');
    } finally {
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});
