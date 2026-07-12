<?php
declare(strict_types=1);

use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Tests for the Department/Location Problem Hotspot report (/reports/problem-hotspot). Proves: "เกิน SLA"
// is counted at ticket-level (a ticket with any breached SLA track = 1, NOT inflated by the work_orders
// LEFT JOIN, and met tickets excluded), labor sums per area, the composite hotspot score buckets
// correctly, and dimension switching (department/location, null-dept → ไม่ระบุแผนก). Fresh isolated
// locations give exact per-row assertions.

function phs_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function phs_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

function phs_location(string $rid, string $suffix = ''): array
{
    $name = "PHS Loc $rid$suffix";
    phs_pdo()->prepare("INSERT INTO locations (code, name) VALUES (?, ?)")->execute(["PHSL-$rid$suffix", $name]);

    return [(int) phs_pdo()->lastInsertId(), $name];
}

/** in_progress ticket at $locId with a breached resolution SLA track (= overdue). Returns ticket id. */
function phs_overdue_ticket(string $no, int $locId, ?int $deptId = null): int
{
    phs_pdo()->prepare(
        "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, status, requested_at)
         VALUES (?, 'x', 'x', 1, ?, ?, 1, 1, 'in_progress', NOW())"
    )->execute([$no, $deptId, $locId]);
    $id = (int) phs_pdo()->lastInsertId();
    phs_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, breached_at, status) VALUES (?, 'resolution', ?, ?, 'breached')")
        ->execute([$id, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s')]);

    return $id;
}

function phs_row(string $dimension, string $label): ?array
{
    $page = phs_service()->getProblemHotspotReportPage(['id' => 4, 'role' => 'admin'], ['dimension' => $dimension]);
    foreach ($page['rows'] as $row) {
        if ($row['label'] === $label) {
            return $row;
        }
    }

    return null;
}

test('problem hotspot: a same-minute resolution shows avg 0.0, not "-" (Finding F5-rem)', function (): void {
    // avg-resolution presence must come from the resolved COUNT, not the average value.
    $rid = bin2hex(random_bytes(4));
    [$locId, $locName] = phs_location($rid);
    $ticketId = 0;
    $base = date('Y-m-d H:i'); // anchor both timestamps to the current minute → TIMESTAMPDIFF(MINUTE)=0, and in-window

    try {
        phs_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, 'resolved', ?, ?)"
        )->execute(["PHSZ-$rid", $locId, "$base:00", "$base:30"]);
        $ticketId = (int) phs_pdo()->lastInsertId();

        $row = phs_row('location', $locName);
        assert_true($row !== null, 'location appears');
        assert_same(1, $row['ticket_count'], 'ticket_count = 1');
        assert_same('0.0', $row['avg_resolution_hours_label'], 'avg resolution 0.0h (same-minute resolve), not "-"');
    } finally {
        if ($ticketId > 0) {
            phs_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        phs_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('problem hotspot: overdue counted at ticket-level, no fan-out, met ticket excluded', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$locId] = phs_location($rid);
    $ids = [];

    try {
        // A: overdue (breached track) + a work_order (must NOT double the count)
        $a = phs_overdue_ticket("PHSA-$rid", $locId);
        phs_pdo()->prepare("INSERT INTO work_orders (work_order_no, ticket_id, technician_id, assigned_by, status, labor_minutes) VALUES (?, ?, 3, 4, 'in_progress', 60)")
            ->execute(["PHSWO-$rid", $a]);
        $ids[] = $a;
        // B: in_progress with a MET response track → NOT overdue
        phs_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, 'in_progress', NOW())"
        )->execute(["PHSB-$rid", $locId]);
        $b = (int) phs_pdo()->lastInsertId();
        phs_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, achieved_at, status) VALUES (?, 'response', ?, ?, 'met')")
            ->execute([$b, date('Y-m-d H:i:s', time() + 3600), date('Y-m-d H:i:s')]);
        $ids[] = $b;

        $row = phs_row('location', "PHS Loc $rid");
        assert_true($row !== null, 'location appears');
        assert_same(2, $row['ticket_count'], 'ticket_count = 2 (work_order LEFT JOIN must NOT inflate)');
        assert_same(1, $row['overdue_count'], 'overdue = 1 (only the breached ticket; met excluded)');
        assert_same('50.0%', $row['overdue_rate_label'], 'overdue rate = 1/2 = 50%');
        assert_same('1.0', $row['labor_hours_label'], 'labor 60min = 1.0h');
    } finally {
        foreach ($ids as $id) {
            phs_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        phs_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('problem hotspot: CSV export cells reconcile with the on-screen row (screen↔export parity)', function (): void {
    // Screen (mapProblemHotspotRow) and export (hotspotExportRow) format separately — pin the overdue %,
    // counts and score-label a manager reads to the exact cells in the downloaded file. (BI-review #4.)
    $rid = bin2hex(random_bytes(4));
    [$locId] = phs_location($rid);
    $ids = [];
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) phs_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        $a = phs_overdue_ticket("PHPA-$rid", $locId);
        phs_pdo()->prepare("INSERT INTO work_orders (work_order_no, ticket_id, technician_id, assigned_by, status, labor_minutes) VALUES (?, ?, 3, 4, 'in_progress', 60)")
            ->execute(["PHPWO-$rid", $a]);
        $ids[] = $a;
        phs_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, 'in_progress', NOW())"
        )->execute(["PHPB-$rid", $locId]);
        $b = (int) phs_pdo()->lastInsertId();
        phs_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, achieved_at, status) VALUES (?, 'response', ?, ?, 'met')")
            ->execute([$b, date('Y-m-d H:i:s', time() + 3600), date('Y-m-d H:i:s')]);
        $ids[] = $b;

        $screen = phs_row('location', "PHS Loc $rid");
        assert_true($screen !== null, 'location appears on screen');

        $csv = (string) phs_service()->exportProblemHotspotCsv($admin, ['dimension' => 'location'])['content'];
        $exportRow = null;
        foreach (explode("\n", trim(substr($csv, 3))) as $line) { // substr(3) strips the BOM
            $cells = str_getcsv($line);
            if (($cells[0] ?? null) === "PHS Loc $rid") {
                $exportRow = $cells;
                break;
            }
        }
        assert_true($exportRow !== null, 'the same location appears as a CSV row');

        // headers: dim, คะแนนพื้นที่, เหตุผล, แจ้งซ่อม, งานค้าง, เกิน SLA, %เกิน SLA, เวลาซ่อมเฉลี่ย, ชม.แรงงาน
        assert_same($screen['hotspot_label'], $exportRow[1], 'CSV คะแนนพื้นที่ = screen hotspot_label');
        assert_same((string) $screen['ticket_count'], $exportRow[3], 'CSV แจ้งซ่อม = screen ticket_count');
        assert_same((string) $screen['overdue_count'], $exportRow[5], 'CSV เกิน SLA = screen overdue_count');
        assert_same($screen['overdue_rate_label'], $exportRow[6], 'CSV %เกิน SLA = screen overdue_rate_label (50.0%)');
        assert_same($screen['labor_hours_label'], $exportRow[8], 'CSV ชม.แรงงาน = screen labor_hours_label (1.0)');

        // XLSX parity — %เกิน SLA as a real number (screen_pct/100), counts numeric, score label as text
        $xlsxTmp = tempnam(sys_get_temp_dir(), 'phsx_') . '.xlsx';
        file_put_contents($xlsxTmp, (string) phs_service()->exportProblemHotspotExcel($admin, ['dimension' => 'location'])['content']);
        $sheet = IOFactory::createReader('Xlsx')->load($xlsxTmp)->getActiveSheet();
        @unlink($xlsxTmp);
        $xlsxRow = null;
        foreach ($sheet->toArray(null, true, false) as $r) { // formatData=false → raw values
            if (($r[0] ?? null) === "PHS Loc $rid") {
                $xlsxRow = $r;
                break;
            }
        }
        assert_true($xlsxRow !== null, 'the same location appears as an XLSX row');
        assert_same($screen['hotspot_label'], (string) $xlsxRow[1], 'XLSX คะแนนพื้นที่ label = screen');
        assert_same((int) $screen['ticket_count'], (int) $xlsxRow[3], 'XLSX แจ้งซ่อม numeric = screen');
        assert_same((int) $screen['overdue_count'], (int) $xlsxRow[5], 'XLSX เกิน SLA numeric = screen');
        assert_same((float) rtrim($screen['overdue_rate_label'], '%') / 100, (float) $xlsxRow[6], 'XLSX %เกิน SLA = screen rate as a real number (0.5)');
    } finally {
        phs_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        foreach ($ids as $id) {
            phs_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        phs_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('problem hotspot: composite score — high overdue+labor = พื้นที่ปัญหา, quiet = ปกติ', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$hiId, $hiName] = phs_location($rid, 'HI');
    [$loId, $loName] = phs_location($rid, 'LO');
    $ids = [];

    try {
        // HI: 2 overdue tickets (100% → +2) + a big work_order (21.7h ≥ 20h → +1) = 3 → พื้นที่ปัญหา
        $h1 = phs_overdue_ticket("PHSH1-$rid", $hiId);
        $h2 = phs_overdue_ticket("PHSH2-$rid", $hiId);
        phs_pdo()->prepare("INSERT INTO work_orders (work_order_no, ticket_id, technician_id, assigned_by, status, labor_minutes) VALUES (?, ?, 3, 4, 'in_progress', 1300)")
            ->execute(["PHSHWO-$rid", $h1]);
        $ids = [$h1, $h2];

        // LO: 1 resolved ticket, on time, quick → 0 → ปกติ
        phs_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at, resolved_at, resolution_due_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, 'resolved', ?, ?, ?)"
        )->execute(["PHSLO-$rid", $loId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() - 1800), date('Y-m-d H:i:s')]);
        $ids[] = (int) phs_pdo()->lastInsertId();

        $hi = phs_row('location', $hiName);
        assert_true($hi !== null, 'HI location appears');
        assert_same('danger', $hi['hotspot_tone'], 'HI = พื้นที่ปัญหา (danger)');
        assert_same('พื้นที่ปัญหา', $hi['hotspot_label'], 'HI label');
        assert_true(str_contains($hi['hotspot_reason'], 'เกิน SLA'), 'reason names the SLA breaches');
        assert_true(str_contains($hi['hotspot_reason'], 'แรงงาน'), 'reason names the heavy labor');

        $lo = phs_row('location', $loName);
        assert_true($lo !== null, 'LO location appears');
        assert_same('success', $lo['hotspot_tone'], 'LO = ปกติ (success)');
        assert_same(0, $lo['overdue_count'], 'LO has no overdue (resolved on time)');
    } finally {
        foreach ($ids as $id) {
            phs_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        phs_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$hiId]);
        phs_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$loId]);
    }
});

test('problem hotspot: dimension switch + null department bucketed as ไม่ระบุแผนก', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$locId, $locName] = phs_location($rid);
    $ticketId = 0;

    try {
        $ticketId = phs_overdue_ticket("PHSD-$rid", $locId, null); // requester_department_id = NULL

        $byLoc = phs_row('location', $locName);
        assert_true($byLoc !== null && $byLoc['ticket_count'] >= 1, 'grouped by location');

        $byDept = phs_row('department', 'ไม่ระบุแผนก');
        assert_true($byDept !== null, 'null-department ticket bucketed as ไม่ระบุแผนก');
        assert_true($byDept['overdue_count'] >= 1, 'its overdue counted there');
    } finally {
        if ($ticketId > 0) {
            phs_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        phs_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('problem hotspot: export xlsx (1 sheet + dimension header) / pdf %PDF- / csv header', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) phs_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'phs_') . '.xlsx';

    try {
        $export = phs_service()->exportProblemHotspotExcel($admin, ['dimension' => 'department']);
        file_put_contents($tmp, (string) $export['content']);
        $book = IOFactory::createReader('Xlsx')->load($tmp);
        assert_same(1, $book->getSheetCount(), 'single sheet');
        assert_same('พื้นที่ปัญหา', $book->getSheetNames()[0], 'sheet title');
        assert_same('แผนก', (string) $book->getActiveSheet()->getCell('A1')->getValue(), 'first header = dimension label (department)');
        $book->disconnectWorksheets();

        $pdf = phs_service()->exportProblemHotspotPdf($admin, ['dimension' => 'location']);
        assert_same('%PDF-', substr((string) $pdf['content'], 0, 5), 'pdf magic bytes');

        $csv = (string) phs_service()->exportProblemHotspotCsv($admin, ['dimension' => 'location'])['content'];
        assert_true(str_contains($csv, 'สถานที่'), 'csv first header reflects the location dimension');
        assert_true(str_contains($csv, 'คะแนนพื้นที่'), 'csv carries the hotspot-score column');
    } finally {
        @unlink($tmp);
        phs_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});
