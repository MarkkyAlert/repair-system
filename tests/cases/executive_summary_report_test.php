<?php
declare(strict_types=1);

use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Tests for the Executive KPI Summary (/reports/executive). Focus: the elapsed-to-date period math
// (this-period vs an equal-length previous period aligned to the prior calendar period), the KPI
// delta/tone direction logic, reconciliation with getSummary, and export. computePeriodWindows and
// execKpiCard are private → exercised via reflection for exact, data-independent assertions.

function exs_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function exs_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

function exs_invoke(string $method, array $args): mixed
{
    $ref = new ReflectionMethod(ReportService::class, $method);
    $ref->setAccessible(true);

    return $ref->invoke(exs_service(), ...$args);
}

test('executive: computePeriodWindows — this vs equal-length previous period (preset + custom)', function (): void {
    // month: this = 1st of this month → today ; prev = 1st of last month → same elapsed
    $m = exs_invoke('computePeriodWindows', ['month', '', '']);
    assert_same(date('Y-m-01'), $m['this']['from'], 'month this.from = 1st of this month');
    assert_same(date('Y-m-d'), $m['this']['to'], 'month this.to = today');
    assert_same(date('Y-m-01', strtotime('first day of last month')), $m['prev']['from'], 'month prev.from = 1st of last month');
    $thisLen = strtotime($m['this']['to']) - strtotime($m['this']['from']);
    $prevLen = strtotime($m['prev']['to']) - strtotime($m['prev']['from']);
    assert_same($thisLen, $prevLen, 'month: this and prev windows are the same length');

    // year: prev is the same YTD span one year earlier
    $y = exs_invoke('computePeriodWindows', ['year', '', '']);
    assert_same(date('Y-01-01'), $y['this']['from'], 'year this.from = Jan 1 this year');
    assert_same(date('Y-01-01', strtotime('-1 year')), $y['prev']['from'], 'year prev.from = Jan 1 last year');

    // invariant: prev window must NEVER overlap the current period (prev.to < this.from) — guards the
    // month-end overshoot where prev-month shorter than elapsed pushed prev.to into the current period.
    foreach (['month', 'quarter', 'year'] as $preset) {
        $w = exs_invoke('computePeriodWindows', [$preset, '', '']);
        assert_true($w['prev']['to'] < $w['this']['from'], "$preset: prev window ends before this window starts (no overlap)");
    }

    // custom: prev is the equal-length window ending the day before this.from
    $custom = exs_invoke('computePeriodWindows', ['custom', '2020-03-01', '2020-03-31']);
    assert_same('2020-03-01', $custom['this']['from'], 'custom this.from');
    assert_same('2020-03-31', $custom['this']['to'], 'custom this.to');
    assert_same('2020-02-29', $custom['prev']['to'], 'custom prev.to = day before this.from (2020 leap)');
    $cThisLen = strtotime($custom['this']['to']) - strtotime($custom['this']['from']);
    $cPrevLen = strtotime($custom['prev']['to']) - strtotime($custom['prev']['from']);
    assert_same($cThisLen, $cPrevLen, 'custom: equal length');
});

test('executive: execKpiCard delta + pct + tone direction', function (): void {
    // up_good, improved → success
    $up = exs_invoke('execKpiCard', ['x', 10.0, 8.0, 'up_good', 0, '', '10']);
    assert_same('success', $up['tone'], 'up_good + increase = success');
    assert_same('+25.0%', $up['pct_label'], 'pct = (10-8)/8 = +25%');
    assert_true(str_contains($up['delta_label'], '↑'), 'up arrow');

    // down_good, decreased → success (e.g. fewer breaches / faster MTTR)
    $down = exs_invoke('execKpiCard', ['x', 5.0, 8.0, 'down_good', 0, '', '5']);
    assert_same('success', $down['tone'], 'down_good + decrease = success');
    assert_true(str_contains($down['delta_label'], '↓'), 'down arrow');

    // up_good, decreased → danger
    $bad = exs_invoke('execKpiCard', ['x', 6.0, 8.0, 'up_good', 0, '', '6']);
    assert_same('danger', $bad['tone'], 'up_good + decrease = danger');

    // no change → default + เท่าเดิม
    $flat = exs_invoke('execKpiCard', ['x', 8.0, 8.0, 'up_good', 0, '', '8']);
    assert_same('default', $flat['tone'], 'no change = default');
    assert_same('เท่าเดิม', $flat['delta_label'], 'no change label');

    // previous = 0 → pct undefined
    $zero = exs_invoke('execKpiCard', ['x', 5.0, 0.0, 'up_good', 0, '', '5']);
    assert_same('—', $zero['pct_label'], 'pct = — when previous is 0');
});

test('executive: KPI "แจ้งซ่อมทั้งหมด" (this period) reconciles with getSummary.total', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $ids = [];

    try {
        foreach (['2020-08-04 09:00:00', '2020-08-11 09:00:00', '2020-08-25 09:00:00'] as $i => $when) {
            exs_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at)
                 VALUES (?, 'x', 'x', 1, 1, 1, 1, 'submitted', ?)"
            )->execute(["EXS-$rid-$i", $when]);
            $ids[] = (int) exs_pdo()->lastInsertId();
        }

        $page = exs_service()->getExecutiveSummaryPage($admin, ['preset' => 'custom', 'from_date' => '2020-08-01', 'to_date' => '2020-08-31']);
        $totalKpi = $page['kpis'][0]; // แจ้งซ่อมทั้งหมด
        $summary = exs_service()->getReportPageData($admin, ['from_date' => '2020-08-01', 'to_date' => '2020-08-31'])['summary'];

        assert_same('3', $totalKpi['value_label'], 'this-period total KPI = 3');
        assert_same(3, $summary['total'], 'getSummary.total = 3 for the same window');
    } finally {
        foreach ($ids as $id) {
            exs_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
    }
});

test('summary: "ปิดงาน" (resolved) counts resolved+completed+closed, matching the technician report + glossary', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $ids = [];

    try {
        // 3 successful-closure statuses (all count as ปิดงาน) + cancelled/submitted (must NOT count).
        $statuses = ['resolved', 'completed', 'closed', 'cancelled', 'submitted'];
        foreach ($statuses as $i => $status) {
            exs_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at)
                 VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, '2020-09-10 09:00:00')"
            )->execute(["EXSC-$rid-$i", $status]);
            $ids[] = (int) exs_pdo()->lastInsertId();
        }

        $summary = exs_service()->getReportPageData($admin, ['from_date' => '2020-09-01', 'to_date' => '2020-09-30'])['summary'];
        assert_same(5, $summary['total'], 'all 5 tickets in the window');
        assert_same(3, $summary['resolved'], 'ปิดงาน = resolved+completed+closed (closed must be included; cancelled/submitted excluded)');
    } finally {
        foreach ($ids as $id) {
            exs_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
    }
});

test('executive: "เกิน SLA" KPI is period-scoped breach, not the NOW-overdue snapshot', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $ticketId = 0;

    try {
        // Ticket in a far-past window that is now CLOSED (terminal) but had a breached SLA track.
        // The NOW-overdue snapshot (status NOT IN terminal) counts 0; the period breach count must count it.
        exs_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, 'closed', '2020-10-10 09:00:00')"
        )->execute(["EXSB-$rid"]);
        $ticketId = (int) exs_pdo()->lastInsertId();
        exs_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, breached_at, status) VALUES (?, 'resolution', '2020-10-11 09:00:00', '2020-10-15 09:00:00', 'breached')")
            ->execute([$ticketId]);

        $filters = ['preset' => 'custom', 'from_date' => '2020-10-01', 'to_date' => '2020-10-31'];
        $slaKpi = null;
        foreach (exs_service()->getExecutiveSummaryPage($admin, $filters)['kpis'] as $k) {
            if ($k['label'] === 'เกิน SLA') {
                $slaKpi = $k;
                break;
            }
        }
        assert_true($slaKpi !== null, 'เกิน SLA KPI present');
        assert_same('1', $slaKpi['value_label'], 'period-scoped breach counts the closed-but-breached ticket');

        $overdue = exs_service()->getReportPageData($admin, ['from_date' => '2020-10-01', 'to_date' => '2020-10-31'])['summary']['overdue'];
        assert_same(0, $overdue, 'NOW-overdue snapshot = 0 (ticket closed) — confirms the exec KPI uses a different, period-scoped metric');
    } finally {
        if ($ticketId > 0) {
            exs_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
    }
});

test('executive: export xlsx (1 sheet + header) / pdf %PDF- / csv header', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) exs_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'exs_') . '.xlsx';

    try {
        $export = exs_service()->exportExecutiveSummaryExcel($admin, ['preset' => 'month']);
        file_put_contents($tmp, (string) $export['content']);
        $book = IOFactory::createReader('Xlsx')->load($tmp);
        assert_same(1, $book->getSheetCount(), 'single sheet');
        assert_same('สรุปผู้บริหาร', $book->getSheetNames()[0], 'sheet title');
        assert_same('KPI', (string) $book->getActiveSheet()->getCell('A1')->getValue(), 'header cell A1');
        assert_same('งวดนี้', (string) $book->getActiveSheet()->getCell('B1')->getValue(), 'this-period column');
        $book->disconnectWorksheets();

        $pdf = exs_service()->exportExecutiveSummaryPdf($admin, ['preset' => 'quarter']);
        assert_same('%PDF-', substr((string) $pdf['content'], 0, 5), 'pdf magic bytes');

        $csv = (string) exs_service()->exportExecutiveSummaryCsv($admin, [])['content'];
        assert_true(str_contains($csv, 'KPI'), 'csv keeps header');
        assert_true(str_contains($csv, 'งวดก่อน'), 'csv carries the previous-period column');
    } finally {
        @unlink($tmp);
        exs_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});

// Date-window boundary: the report filter is requested_at >= from(00:00:00) AND <= to(23:59:59), i.e.
// inclusive both ends. A ticket exactly on either edge is counted; one a second outside is not — so two
// adjacent windows never double-count or drop an edge ticket.
test('summary: date-window filter includes both edges and excludes just-outside (boundary)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $ids = [];

    try {
        // window = 2020-09-01 .. 2020-09-30
        $moments = [
            '2020-09-01 00:00:00' => true,   // from edge → in
            '2020-09-30 23:59:59' => true,   // to edge → in
            '2020-08-31 23:59:59' => false,  // 1s before from → out
            '2020-10-01 00:00:00' => false,  // 1s after to → out
        ];
        foreach (array_keys($moments) as $i => $when) {
            exs_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at)
                 VALUES (?, 'x', 'x', 1, 1, 1, 1, 'submitted', ?)"
            )->execute(["EXW-$rid-$i", $when]);
            $ids[] = (int) exs_pdo()->lastInsertId();
        }

        $summary = exs_service()->getReportPageData($admin, ['from_date' => '2020-09-01', 'to_date' => '2020-09-30'])['summary'];
        assert_same(2, $summary['total'], 'only the two edge tickets fall in the window (both edges inclusive, just-outside excluded)');
    } finally {
        foreach ($ids as $id) {
            exs_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
    }
});

// Finding C: getSummary's COUNT(*) over `tickets LEFT JOIN ticket_ratings` is correct ONLY because
// ticket_ratings is 1:1 (UNIQUE(ticket_id)). Lock that invariant: a rated ticket must count once, not
// twice — so a future JOIN change (or a dropped unique key) that fans the totals out is caught here.
test('summary: a rated ticket counts once — the ticket_ratings JOIN never inflates totals (Finding C)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $ids = [];

    try {
        foreach ([0, 1] as $i) {
            exs_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at, resolved_at)
                 VALUES (?, 'x', 'x', 1, 1, 1, 1, 'resolved', '2020-07-10 09:00:00', '2020-07-10 10:00:00')"
            )->execute(["EXC-$rid-$i"]);
            $ids[] = (int) exs_pdo()->lastInsertId();
        }
        // rate ONE of the two tickets — the LEFT JOIN must not turn it into a second counted row
        exs_pdo()->prepare(
            "INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, score, feedback, created_at, updated_at)
             VALUES (?, 1, 4, 5, '', '2020-07-10 11:00:00', '2020-07-10 11:00:00')"
        )->execute([$ids[0]]);

        $page = exs_service()->getExecutiveSummaryPage($admin, ['preset' => 'custom', 'from_date' => '2020-07-01', 'to_date' => '2020-07-31']);
        assert_same('2', $page['kpis'][0]['value_label'], 'แจ้งซ่อมทั้งหมด = 2 (the rated ticket is not double-counted by the ratings JOIN)');
        assert_same('2', $page['kpis'][1]['value_label'], 'ปิดงาน = 2 (resolved count not inflated by the ratings JOIN)');
    } finally {
        foreach ($ids as $id) {
            exs_pdo()->prepare('DELETE FROM ticket_ratings WHERE ticket_id = ?')->execute([$id]);
            exs_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
    }
});

// Finding A: when the CURRENT period has no data, a rate/avg KPI must not fabricate a value or a delta
// against the previous period. Counts (0 is a real value) stay honest; completion/MTTR/rating show "-"
// value, "—" delta, and a neutral tone — so an empty period never reads as "0% completion (crashed)" or
// "MTTR improved". Data: resolved+rated tickets in the PREVIOUS window only; the current window is empty.
test('executive: an empty current period does not fabricate a 0%/delta/tone (Finding A)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $ids = [];

    try {
        // this = [2020-06-10 .. 2020-06-20] (empty); prev = equal-length window ending 2020-06-09 → put data there
        foreach (['2020-06-01 09:00:00', '2020-06-03 09:00:00'] as $i => $when) {
            exs_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at, resolved_at)
                 VALUES (?, 'x', 'x', 1, 1, 1, 1, 'resolved', ?, ?)"
            )->execute(["EXEA-$rid-$i", $when, date('Y-m-d H:i:s', (int) strtotime($when) + 3600)]);
            $tid = (int) exs_pdo()->lastInsertId();
            $ids[] = $tid;
            exs_pdo()->prepare(
                "INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, score, feedback, created_at, updated_at)
                 VALUES (?, 1, 4, 5, '', ?, ?)"
            )->execute([$tid, $when, $when]);
        }

        $page = exs_service()->getExecutiveSummaryPage($admin, ['preset' => 'custom', 'from_date' => '2020-06-10', 'to_date' => '2020-06-20']);
        $kpi = [];
        foreach ($page['kpis'] as $k) {
            $kpi[$k['label']] = $k;
        }

        // counts: 0 is a real value → shown honestly, not dashed
        assert_same('0', $kpi['แจ้งซ่อมทั้งหมด']['value_label'], 'empty period: total shows an honest 0');

        // completion RATE has no base (0 tickets) → "-", no delta, neutral tone (not "0.0% ↓ crashed")
        assert_same('-', $kpi['อัตราปิดงาน']['value_label'], 'empty period: completion shows - not 0.0%');
        assert_same('—', $kpi['อัตราปิดงาน']['delta_label'], 'empty period: completion delta is suppressed');
        assert_same('default', $kpi['อัตราปิดงาน']['tone'], 'empty period: completion tone is neutral (no false red)');

        // MTTR avg: no resolved work this period → must not read as a green "improvement"
        assert_same('—', $kpi['เวลาซ่อมเฉลี่ย (ชม.)']['delta_label'], 'empty period: MTTR delta suppressed (no false improvement)');
        assert_same('default', $kpi['เวลาซ่อมเฉลี่ย (ชม.)']['tone'], 'empty period: MTTR tone neutral');

        // rating avg: no ratings this period → no false red drop
        assert_same('—', $kpi['คะแนนเฉลี่ย']['delta_label'], 'empty period: rating delta suppressed');
        assert_same('default', $kpi['คะแนนเฉลี่ย']['tone'], 'empty period: rating tone neutral');
    } finally {
        foreach ($ids as $id) {
            exs_pdo()->prepare('DELETE FROM ticket_ratings WHERE ticket_id = ?')->execute([$id]);
            exs_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
    }
});

// Finding F5: MTTR presence is the resolved-count base, not the avg value. A period with resolved work
// that averages 0.0h (sub-minute resolution) is real data → the KPI must show "0.0", not "-".
test('executive: a period with sub-minute resolutions shows MTTR 0.0, not "-" (Finding F5)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $id = 0;

    try {
        // resolved 30s after request → avg_resolution_minutes = 0 → MTTR 0.0h, but there IS resolved work
        exs_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, 'resolved', '2020-05-15 10:00:00', '2020-05-15 10:00:30')"
        )->execute(["EXF5-$rid"]);
        $id = (int) exs_pdo()->lastInsertId();

        $page = exs_service()->getExecutiveSummaryPage($admin, ['preset' => 'custom', 'from_date' => '2020-05-01', 'to_date' => '2020-05-31']);
        $mttr = null;
        foreach ($page['kpis'] as $k) {
            if ($k['label'] === 'เวลาซ่อมเฉลี่ย (ชม.)') {
                $mttr = $k;
            }
        }
        assert_true($mttr !== null, 'MTTR KPI present');
        assert_same('0.0', $mttr['value_label'], 'sub-minute MTTR shows 0.0, not "-" (there is resolved work)');
    } finally {
        if ($id > 0) {
            exs_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
    }
});
