<?php
declare(strict_types=1);

use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Tests for the Reopen / First-Time-Fix report (/reports/reopen-rate). Cohort = tickets with a
// `ticket_resolved` activity log in the window (by r.created_at); reopened = those that also carry a
// `ticket_reopened` log (any time). reopen_rate = reopened/resolved (≤100%), FTF = 100 − rate. Proves
// reopen detection + rate math + tone, the resolve-window filter, COUNT(DISTINCT) against repeated
// resolve events, Thai/null dimension labels, sort by %reopen desc, the resolved-total invariant across
// the two LEFT-JOIN dimensions, and the three export formats. Fresh isolated locations = exact assertions.

function rr_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function rr_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

function rr_location(string $rid, string $suffix = ''): array
{
    $name = "RR Loc $rid$suffix";
    rr_pdo()->prepare('INSERT INTO locations (code, name) VALUES (?, ?)')->execute(["RRL-$rid$suffix", $name]);

    return [(int) rr_pdo()->lastInsertId(), $name];
}

/** Resolved ticket at $locId (status only cosmetic — cohort is driven by the activity log). */
function rr_ticket(string $no, int $locId, ?int $tech = 3, ?int $dept = 4): int
{
    rr_pdo()->prepare(
        "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at)
         VALUES (?, 'x', 'x', 1, ?, ?, 1, 1, ?, 'resolved', NOW())"
    )->execute([$no, $dept, $locId, $tech]);

    return (int) rr_pdo()->lastInsertId();
}

/** Append an activity log row; $createdAt lets a test place a resolve event inside/outside a window. */
function rr_log(int $ticketId, string $action, ?string $createdAt = null): void
{
    [$from, $to] = $action === 'ticket_reopened' ? ['resolved', 'assigned'] : ['in_progress', 'resolved'];
    if ($createdAt === null) {
        rr_pdo()->prepare('INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status) VALUES (?, 3, ?, ?, ?)')
            ->execute([$ticketId, $action, $from, $to]);
    } else {
        rr_pdo()->prepare('INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status, created_at) VALUES (?, 3, ?, ?, ?, ?)')
            ->execute([$ticketId, $action, $from, $to, $createdAt]);
    }
}

function rr_row(string $dimension, string $label, array $extra = []): ?array
{
    $page = rr_service()->getReopenRateReportPage(['id' => 4, 'role' => 'admin'], ['dimension' => $dimension] + $extra);
    foreach ($page['rows'] as $row) {
        if ($row['label'] === $label) {
            return $row;
        }
    }

    return null;
}

test('reopen: detection + rate/FTF math + tone + %reopen-desc sort', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$hiId, $hiName] = rr_location($rid, 'HI');
    [$loId, $loName] = rr_location($rid, 'LO');
    $ids = [];

    try {
        // locHi: one ticket resolved AND reopened → 100% reopen / 0% FTF.
        $ids[] = $a = rr_ticket("RR-$rid-a", $hiId);
        rr_log($a, 'ticket_resolved');
        rr_log($a, 'ticket_reopened');
        // locLo: one ticket resolved, never reopened → 0% reopen / 100% FTF.
        $ids[] = $b = rr_ticket("RR-$rid-b", $loId);
        rr_log($b, 'ticket_resolved');

        $hi = rr_row('location', $hiName);
        assert_true($hi !== null, 'reopened location appears');
        assert_same(1, $hi['resolved'], 'hi: 1 resolved');
        assert_same(1, $hi['reopened'], 'hi: 1 reopened');
        assert_same('100.0%', $hi['reopen_rate_label'], 'hi: 100% reopen');
        assert_same('0.0%', $hi['ftf_label'], 'hi: 0% first-time-fix');
        assert_same('danger', $hi['reopen_tone'], 'hi: high reopen → danger tone');

        $lo = rr_row('location', $loName);
        assert_true($lo !== null, 'clean location appears');
        assert_same(0, $lo['reopened'], 'lo: 0 reopened');
        assert_same('0.0%', $lo['reopen_rate_label'], 'lo: 0% reopen');
        assert_same('100.0%', $lo['ftf_label'], 'lo: 100% first-time-fix');
        assert_same('success', $lo['reopen_tone'], 'lo: 0 reopen → success tone');

        $labels = array_column(rr_service()->getReopenRateReportPage(['id' => 4, 'role' => 'admin'], ['dimension' => 'location'])['rows'], 'label');
        $hiPos = array_search($hiName, $labels, true);
        $loPos = array_search($loName, $labels, true);
        assert_true($hiPos !== false && $loPos !== false && $hiPos < $loPos, '100% reopen sorts above 0%');
    } finally {
        foreach ($ids as $id) {
            rr_pdo()->prepare('DELETE FROM ticket_activity_logs WHERE ticket_id = ?')->execute([$id]);
            rr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        rr_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$hiId]);
        rr_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$loId]);
    }
});

test('reopen: %reopen + %FTF always reconcile to 100.0% at a .x5 rounding boundary', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$locId, $locName] = rr_location($rid);
    $ids = [];

    try {
        // 1 reopened / 16 resolved = 6.25% → rounds to 6.3%. Independently rounding FTF gave 93.8%
        // (6.3+93.8 = 100.1%); FTF derived from the rounded rate gives 93.7% (sums to 100.0%).
        for ($i = 0; $i < 16; $i++) {
            $ids[] = $t = rr_ticket("RR-$rid-$i", $locId);
            rr_log($t, 'ticket_resolved');
        }
        rr_log($ids[0], 'ticket_reopened');

        $row = rr_row('location', $locName);
        assert_true($row !== null, 'location appears');
        assert_same(16, $row['resolved'], '16 resolved');
        assert_same(1, $row['reopened'], '1 reopened');
        assert_same('6.3%', $row['reopen_rate_label'], '6.25% rounds up to 6.3%');
        assert_same('93.7%', $row['ftf_label'], 'FTF is the exact complement of the rounded rate, not 93.8%');
        $sum = (float) rtrim($row['reopen_rate_label'], '%') + (float) rtrim($row['ftf_label'], '%');
        assert_same(100.0, $sum, '%reopen + %FTF = 100.0% exactly (UI-stated invariant)');
    } finally {
        foreach ($ids as $id) {
            rr_pdo()->prepare('DELETE FROM ticket_activity_logs WHERE ticket_id = ?')->execute([$id]);
            rr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        rr_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('reopen: cohort window filters on the resolve-event date', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$locId, $locName] = rr_location($rid);
    $ticketId = 0;

    try {
        $ticketId = rr_ticket("RR-$rid", $locId);
        rr_log($ticketId, 'ticket_resolved', date('Y-m-d H:i:s', strtotime('-400 days')));

        $recent = ['from_date' => date('Y-m-d', strtotime('-30 days')), 'to_date' => date('Y-m-d')];
        assert_true(rr_row('location', $locName, $recent) === null, 'resolve 400d ago is excluded from a 30-day window');

        $wide = ['from_date' => date('Y-m-d', strtotime('-500 days')), 'to_date' => date('Y-m-d')];
        $row = rr_row('location', $locName, $wide);
        assert_true($row !== null && $row['resolved'] === 1, 'same ticket appears once the window reaches back 500 days');
    } finally {
        rr_pdo()->prepare('DELETE FROM ticket_activity_logs WHERE ticket_id = ?')->execute([$ticketId]);
        rr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        rr_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('reopen: repeated resolve/reopen events count the ticket once (COUNT DISTINCT)', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$locId, $locName] = rr_location($rid);
    $ticketId = 0;

    try {
        // resolved → reopened → resolved again → reopened again: two of each event, still one ticket.
        $ticketId = rr_ticket("RR-$rid", $locId);
        rr_log($ticketId, 'ticket_resolved');
        rr_log($ticketId, 'ticket_reopened');
        rr_log($ticketId, 'ticket_resolved');
        rr_log($ticketId, 'ticket_reopened');

        $row = rr_row('location', $locName);
        assert_true($row !== null, 'location appears');
        assert_same(1, $row['resolved'], 'two resolve events → 1 distinct resolved ticket');
        assert_same(1, $row['reopened'], 'two reopen events → 1 distinct reopened ticket');
        assert_same('100.0%', $row['reopen_rate_label'], 'rate stays ≤100% (no fan-out)');
    } finally {
        rr_pdo()->prepare('DELETE FROM ticket_activity_logs WHERE ticket_id = ?')->execute([$ticketId]);
        rr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        rr_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('reopen: null technician/department labels + resolved-total invariant across LEFT-JOIN dimensions', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    [$locId] = rr_location($rid);
    $ticketId = 0;

    try {
        $ticketId = rr_ticket("RR-$rid", $locId, null, null); // technician NULL, department NULL
        rr_log($ticketId, 'ticket_resolved');

        $tech = rr_row('technician', 'ยังไม่มอบหมาย');
        assert_true($tech !== null && $tech['resolved'] >= 1, 'null technician → ยังไม่มอบหมาย');
        $dept = rr_row('department', 'ไม่ระบุแผนก');
        assert_true($dept !== null && $dept['resolved'] >= 1, 'null department → ไม่ระบุแผนก');

        $byTech = rr_service()->getReopenRateReportPage($admin, ['dimension' => 'technician'])['summary']['resolved'];
        $byDept = rr_service()->getReopenRateReportPage($admin, ['dimension' => 'department'])['summary']['resolved'];
        assert_same($byTech, $byDept, 'resolved total identical across the two LEFT-JOIN dimensions');
    } finally {
        rr_pdo()->prepare('DELETE FROM ticket_activity_logs WHERE ticket_id = ?')->execute([$ticketId]);
        rr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        rr_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('reopen: export xlsx (1 sheet + dimension header) / pdf %PDF- / csv header', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) rr_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'rr_') . '.xlsx';

    try {
        $export = rr_service()->exportReopenRateExcel($admin, ['dimension' => 'technician']);
        file_put_contents($tmp, (string) $export['content']);
        $book = IOFactory::createReader('Xlsx')->load($tmp);
        assert_same(1, $book->getSheetCount(), 'single sheet');
        assert_same('งานเปิดซ้ำ', $book->getSheetNames()[0], 'sheet title');
        assert_same('ช่าง', (string) $book->getActiveSheet()->getCell('A1')->getValue(), 'first header = dimension label (technician)');
        assert_same('%ปิดจบรอบเดียว', (string) $book->getActiveSheet()->getCell('E1')->getValue(), 'FTF column header');
        $book->disconnectWorksheets();

        $pdf = rr_service()->exportReopenRatePdf($admin, ['dimension' => 'priority']);
        assert_same('%PDF-', substr((string) $pdf['content'], 0, 5), 'pdf magic bytes');

        $csv = (string) rr_service()->exportReopenRateCsv($admin, ['dimension' => 'category'])['content'];
        assert_true(str_contains($csv, 'หมวดหมู่'), 'csv first header reflects the category dimension');
        assert_true(str_contains($csv, '%เปิดซ้ำ'), 'csv carries the reopen-rate column');
    } finally {
        @unlink($tmp);
        rr_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});
