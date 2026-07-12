<?php
declare(strict_types=1);

use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Tests for the Ticket Trend report (/reports/trend). The defining behaviour is FLOW-based bucketing:
// "created" is grouped by requested_at period, while "resolved"/MTTR/SLA/CSAT are grouped by resolved_at
// period — so a ticket opened in one month and closed the next lands in DIFFERENT buckets. Also proves
// gap-filling (empty periods = 0), granularity bucket keys, and the export. Tests use a far-past window
// (2020) that has no seed data, so per-bucket assertions are exact.

function ttr_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function ttr_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

function ttr_period(array $page, string $key): ?array
{
    foreach ($page['periods'] as $period) {
        if ($period['key'] === $key) {
            return $period;
        }
    }

    return null;
}

test('ticket trend: an over-limit daily range is rejected clearly, not silently truncated (round-2 #1)', function (): void {
    // trendBucketList capped the expected-bucket list at 400 and silently dropped the tail, so a >400-day
    // daily range lost its most-recent days from the chart/summary/export with no warning. The range is now
    // rejected with an actionable message instead of quietly cutting data. (BI-review round-2 #1.)
    $admin = ['id' => 4, 'role' => 'admin'];
    $to = date('Y-m-d');
    $from = date('Y-m-d', strtotime('-402 days', strtotime($to))); // 403 daily buckets > 400 cap

    $threw = false;
    $msg = '';
    try {
        ttr_service()->getTicketTrendReportPage($admin, ['granularity' => 'day', 'from_date' => $from, 'to_date' => $to]);
    } catch (DomainException $e) {
        $threw = true;
        $msg = $e->getMessage();
    }
    assert_true($threw, 'a >400-bucket daily range throws instead of silently truncating');
    assert_contains_str('ยาวเกินไป', $msg, 'the message explains the range is too long (actionable)');

    // the CSV export shares the same normalize seam → rejected too (a truncated file must never be produced)
    $exportThrew = false;
    try {
        ttr_service()->exportTicketTrendCsv($admin, ['granularity' => 'day', 'from_date' => $from, 'to_date' => $to]);
    } catch (DomainException $e) {
        $exportThrew = true;
    }
    assert_true($exportThrew, 'the CSV export of an over-limit range is rejected too (no silently-truncated file)');

    // the SAME span as weekly is ~58 buckets → must NOT be rejected (the limit is per-bucket, not per-day)
    $weeklyOk = true;
    try {
        ttr_service()->getTicketTrendReportPage($admin, ['granularity' => 'week', 'from_date' => $from, 'to_date' => $to]);
    } catch (\Throwable $e) {
        $weeklyOk = false;
    }
    assert_true($weeklyOk, 'the same long span renders fine as weekly (coarser granularity)');

    // a normal 30-day daily range is unaffected
    $normalOk = true;
    try {
        ttr_service()->getTicketTrendReportPage($admin, ['granularity' => 'day', 'from_date' => date('Y-m-d', strtotime('-29 days')), 'to_date' => $to]);
    } catch (\Throwable $e) {
        $normalOk = false;
    }
    assert_true($normalOk, 'a normal 30-day daily range still works');
});

test('ticket trend: created by requested_at, resolved/SLA/CSAT by resolved_at (cross-month) + gap-fill', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $ticketId = 0;

    try {
        // opened 2020-01-15, resolved 2020-02-20 ON TIME (due 2020-02-25), rated 4
        ttr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at, resolved_at, resolution_due_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, 'resolved', '2020-01-15 09:00:00', '2020-02-20 09:00:00', '2020-02-25 09:00:00')"
        )->execute(["TTR-$rid"]);
        $ticketId = (int) ttr_pdo()->lastInsertId();
        ttr_pdo()->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, score) VALUES (?, 1, 4)')->execute([$ticketId]);

        $page = ttr_service()->getTicketTrendReportPage($admin, [
            'granularity' => 'month', 'from_date' => '2020-01-01', 'to_date' => '2020-03-31',
        ]);

        $jan = ttr_period($page, '2020-01');
        $feb = ttr_period($page, '2020-02');
        $mar = ttr_period($page, '2020-03');

        assert_true($jan !== null && $feb !== null && $mar !== null, 'all 3 month buckets exist (gap-filled)');
        assert_same(1, $jan['created'], 'created counts in the requested_at month (Jan)');
        assert_same(0, $jan['resolved'], 'not resolved in Jan');
        assert_same(0, $feb['created'], 'not created in Feb');
        assert_same(1, $feb['resolved'], 'resolved counts in the resolved_at month (Feb)');
        assert_same('100.0%', $feb['sla_pct_label'], 'Feb SLA on-time = 100%');
        assert_same('4.00', $feb['csat_label'], 'Feb CSAT = 4.00');
        assert_same(0, $mar['created'], 'empty March bucket gap-filled to 0');
        assert_same('-', $mar['sla_pct_label'], 'empty March SLA = -');
    } finally {
        if ($ticketId > 0) {
            ttr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
    }
});

test('ticket trend: a real 0% SLA and a sub-minute MTTR are chart data, not hidden (Finding F2/F5)', function (): void {
    // Data presence must come from the base/null, not from the aggregated value. A period where SLA is a
    // genuine 0% (all breached) or MTTR rounds to 0.0h (resolved within a minute) is REAL data — it must
    // stay on the chart, not vanish because sum/avg == 0.
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $ticketId = 0;

    try {
        // requested + resolved 2020-05-10: resolution due 10:00:10, resolved 10:00:30 → SLA BREACHED (0%);
        // resolved 30s after request → MTTR = 0 minutes → 0.0h.
        ttr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at, resolved_at, resolution_due_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, 'resolved', '2020-05-10 10:00:00', '2020-05-10 10:00:30', '2020-05-10 10:00:10')"
        )->execute(["TTRZ-$rid"]);
        $ticketId = (int) ttr_pdo()->lastInsertId();

        $page = ttr_service()->getTicketTrendReportPage($admin, [
            'granularity' => 'month', 'from_date' => '2020-05-01', 'to_date' => '2020-05-31',
        ]);
        $may = ttr_period($page, '2020-05');

        assert_true($may !== null, 'the resolved month appears');
        assert_same(0.0, $may['sla_pct'], 'SLA base=1, on-time=0 → a real 0.0%, not null');
        assert_same(0.0, $may['mttr_hours'], 'sub-minute resolution → 0.0h, not null (F5: presence from the resolved base, not the value)');
        assert_true($page['charts']['trendSla']['has_data'], 'a real 0% SLA period is charted, not hidden as "no data" (F2)');
        assert_true($page['charts']['trendMttr']['has_data'], 'a real 0.0h MTTR period is charted (F5)');
    } finally {
        if ($ticketId > 0) {
            ttr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
    }
});

test('ticket trend: periods carry the SLA and rating base counts (Finding F4)', function (): void {
    // A period's "SLA 100%" / "CSAT 4.0" must expose how many concluded tickets / ratings it rests on,
    // or a period with 1 sample looks as trustworthy as one with 100.
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $ticketId = 0;

    try {
        // resolved 2020-07-11 ON TIME (due 2020-07-15) → SLA concluded (base 1) ; rated 4
        ttr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at, resolved_at, resolution_due_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, 'resolved', '2020-07-10 09:00:00', '2020-07-11 09:00:00', '2020-07-15 09:00:00')"
        )->execute(["TTRB-$rid"]);
        $ticketId = (int) ttr_pdo()->lastInsertId();
        ttr_pdo()->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, score) VALUES (?, 1, 4)')->execute([$ticketId]);

        $page = ttr_service()->getTicketTrendReportPage($admin, [
            'granularity' => 'month', 'from_date' => '2020-07-01', 'to_date' => '2020-07-31',
        ]);
        $jul = ttr_period($page, '2020-07');

        assert_true($jul !== null, 'the resolved month appears');
        assert_same(1, $jul['sla_base'] ?? null, 'period exposes sla_base (concluded tickets behind the SLA %)');
        assert_same(1, $jul['rating_count'] ?? null, 'period exposes rating_count (reviews behind the CSAT)');
    } finally {
        if ($ticketId > 0) {
            ttr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
    }
});

test('ticket trend: granularity controls bucket keys (day / week)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];

    $day = ttr_service()->getTicketTrendReportPage($admin, ['granularity' => 'day', 'from_date' => '2020-01-10', 'to_date' => '2020-01-12']);
    assert_same(3, count($day['periods']), 'day granularity → 3 day buckets');
    assert_same('2020-01-10', $day['periods'][0]['key'], 'day bucket key = Y-m-d');

    $week = ttr_service()->getTicketTrendReportPage($admin, ['granularity' => 'week', 'from_date' => '2020-01-06', 'to_date' => '2020-01-19']);
    assert_true(count($week['periods']) >= 2, 'week granularity → multiple week buckets');
    assert_true((bool) preg_match('/^\d{4}-\d{2}$/', $week['periods'][0]['key']), 'week bucket key = ISO year-week');
});

test('ticket trend: Σcreated across periods reconciles with getSummary.total for the same window', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $ids = [];

    try {
        foreach (['2020-05-03 09:00:00', '2020-05-09 09:00:00', '2020-05-21 09:00:00'] as $i => $when) {
            ttr_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at)
                 VALUES (?, 'x', 'x', 1, 1, 1, 1, 'submitted', ?)"
            )->execute(["TTRR-$rid-$i", $when]);
            $ids[] = (int) ttr_pdo()->lastInsertId();
        }

        $filters = ['granularity' => 'month', 'from_date' => '2020-05-01', 'to_date' => '2020-05-31'];
        $page = ttr_service()->getTicketTrendReportPage($admin, $filters);
        $sumCreated = array_sum(array_column($page['periods'], 'created'));
        $total = ttr_service()->getReportPageData($admin, $filters)['summary']['total'];

        assert_same(3, $sumCreated, 'Σcreated = 3 tickets in May 2020');
        assert_same($sumCreated, $total, 'Σcreated reconciles with getSummary.total for the window');
    } finally {
        foreach ($ids as $id) {
            ttr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
    }
});

test('ticket trend: export xlsx (1 sheet + header) / pdf %PDF- / csv header', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) ttr_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'ttr_') . '.xlsx';

    try {
        $export = ttr_service()->exportTicketTrendExcel($admin, ['granularity' => 'month']);
        file_put_contents($tmp, (string) $export['content']);
        $book = IOFactory::createReader('Xlsx')->load($tmp);
        assert_same(1, $book->getSheetCount(), 'single sheet');
        assert_same('แนวโน้ม', $book->getSheetNames()[0], 'sheet title');
        assert_same('ช่วงเวลา', (string) $book->getActiveSheet()->getCell('A1')->getValue(), 'header cell A1');
        $book->disconnectWorksheets();

        $pdf = ttr_service()->exportTicketTrendPdf($admin, ['granularity' => 'week']);
        assert_same('%PDF-', substr((string) $pdf['content'], 0, 5), 'pdf magic bytes');

        $csv = (string) ttr_service()->exportTicketTrendCsv($admin, [])['content'];
        assert_true(str_contains($csv, 'ช่วงเวลา'), 'csv keeps header');
        assert_true(str_contains($csv, 'สุทธิ'), 'csv carries the net column');
    } finally {
        @unlink($tmp);
        ttr_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});
