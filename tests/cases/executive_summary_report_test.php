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
