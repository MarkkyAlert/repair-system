<?php

declare(strict_types=1);

// Pre-ship sweep M-4 / LM-5: the Problem-Hotspot page and the SLA-Breach page BOTH show a "%เกิน SLA"-style figure,
// but they measure at different granularities ON PURPOSE:
//   * Hotspot  = JOB level  — a ticket counts as overdue if ANY of its SLA cycles breached (owner decision BI-C1:
//                "% ของงานในช่วงที่พลาดกำหนด"). It answers "which area has problem-prone jobs".
//   * Breach   = TRACK level — every response + resolution deadline counted separately. It answers "how many SLA
//                deadlines were missed".
// So for the same data the two percentages legitimately differ (often ~2x, because response SLA is usually met).
// The earlier guide wording said they were "เทียบกันได้" (comparable) — that was the actual defect; the numbers
// were never wrong. This test LOCKS the intentional divergence so nobody later "reconciles" them into one formula
// and silently breaks the owner's chosen job-level hotspot metric, and it pins the guide/label clarifications.
//
// Reuses the slab_* fixtures from sla_breach_report_test.php (same suite, loaded together).

test('reports(M-4): hotspot (job level) and SLA-breach (track level) report the SAME data as different %s — by design', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$catId, $locId, $deptId] = slab_dims($rid);
    $deptLabel = "SLAB Dept $rid";
    $admin = ['id' => 4, 'role' => 'admin'];
    $ticketIds = [];

    try {
        // Two tickets in this department, each with response SLA MET + resolution SLA BREACHED (both concluded).
        for ($i = 0; $i < 2; $i++) {
            slab_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, status, requested_at)
                 VALUES (?, 'x', 'x', 1, ?, ?, ?, 1, 'in_progress', NOW())"
            )->execute(["SLABLVL-$rid-$i", $deptId, $locId, $catId]);
            $tid = (int) slab_pdo()->lastInsertId();
            $ticketIds[] = $tid;
            slab_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, achieved_at, status) VALUES (?, 'response', ?, ?, 'met')")
                ->execute([$tid, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() - 7200)]);
            slab_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, breached_at, status) VALUES (?, 'resolution', ?, ?, 'breached')")
                ->execute([$tid, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s')]);
        }

        // Hotspot, department dimension — job level: both tickets have a breach → 2 overdue / 2 judged = 100%.
        $hotspot = slab_service()->getProblemHotspotReportPage($admin, ['dimension' => 'department']);
        $hRow = null;
        foreach ($hotspot['rows'] as $r) {
            if (($r['label'] ?? '') === $deptLabel) {
                $hRow = $r;
                break;
            }
        }
        assert_true($hRow !== null, 'the seeded department must appear on the hotspot page');
        assert_same('100.0%', (string) $hRow['overdue_rate_label'], 'hotspot is JOB level: every ticket has a breach → 100%');

        // SLA-breach, department dimension — track level: 2 met (response) + 2 breached (resolution) = 2/4 = 50%.
        $breach = slab_service()->getSlaBreachReportPage($admin, ['dimension' => 'department']);
        $bRow = null;
        foreach ($breach['rows'] as $r) {
            if (($r['label'] ?? '') === $deptLabel) {
                $bRow = $r;
                break;
            }
        }
        assert_true($bRow !== null, 'the seeded department must appear on the SLA-breach page');
        assert_same('50.0%', (string) $bRow['breach_rate_label'], 'breach is TRACK level: 2 breached / 4 concluded tracks → 50%');

        // The load-bearing invariant: same department, same window, same data — two different, both-correct numbers.
        // If a future change makes these equal, it has collapsed the two viewpoints and this must fail.
        assert_true(
            (string) $hRow['overdue_rate_label'] !== (string) $bRow['breach_rate_label'],
            'the two pages must remain different levels (job vs track); making them equal breaks the owner-chosen hotspot metric'
        );
    } finally {
        foreach ($ticketIds as $tid) {
            slab_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$tid]);
        }
        slab_cleanup(0, $catId, $locId, $deptId);
    }
});

test('reports(M-4): the guide explains the two SLA pages are different levels, and no longer calls them comparable', function (): void {
    $guide = (string) file_get_contents(BASE_PATH . '/app/Views/reports/guide.php');
    // The formula phrase stays (report_guide_test pins it); only the misleading comparability claim is gone.
    assert_contains_str('งานที่พลาดกำหนดในช่วง ÷ งานที่ตัดสินผลได้แล้ว', $guide, 'the missed÷judged definition stays');
    assert_false(
        str_contains($guide, 'จึงตอบคำถามเดียวกับหน้า "SLA เกินกำหนด" และเทียบกันได้'),
        'the guide must NOT claim the two %เกิน SLA figures are directly comparable'
    );
    assert_contains_str('นับที่ระดับ "ใบงาน"', $guide, 'the guide must state the hotspot rate is job level');
    assert_contains_str('เป็นคนละมุมโดยตั้งใจ', $guide, 'the guide must state the difference is intentional');
});

test('reports(LM-5): the Trend page labels its SLA metric as resolution-only, closed-in-period', function (): void {
    $trend = (string) file_get_contents(BASE_PATH . '/app/Views/reports/trend.php');
    assert_contains_str('SLA แก้ไขตรงเวลา', $trend, 'the trend SLA metric is renamed to signal it is the resolution metric');
    assert_contains_str('เฉพาะงานที่ปิดจริงในงวดนั้น', $trend, 'the trend page explains the metric only counts work closed in the period');
    // and it names why it can diverge from the compliance page
    assert_contains_str('วิเคราะห์ SLA เกินกำหนด', $trend, 'the caption points the reader at the page it may differ from');
});
