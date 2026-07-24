<?php
declare(strict_types=1);

use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Tests for the standalone Asset Reliability Report (/reports/asset-reliability): heuristic health
// scoring (ควรเปลี่ยน/เฝ้าระวัง/ปกติ), deep metrics (MTBF, downtime, age/warranty), no fan-out from the
// work_orders LEFT JOIN, the asset_status filter, and the dedicated CSV/Excel/PDF export.

function arr_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function arr_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

/** Fetch the mapped report row for a given asset_code from the admin report page (isolated assertions). */
function arr_find_row(string $assetCode): ?array
{
    $page = arr_service()->getAssetReliabilityReportPage(['id' => 4, 'role' => 'admin'], []);
    foreach ($page['rows'] as $row) {
        if ($row['asset_code'] === $assetCode) {
            return $row;
        }
    }

    return null;
}

test('asset reliability: summary counts ALL matching assets, not just the displayed page (Finding F1)', function (): void {
    // The summary cards must reflect every matching asset, even when the table shows only the first N.
    // Isolate with a fresh location + a low display limit so 3 assets exceed it without inserting 500+.
    $container = tvm_container();
    $config = $container->get('config');
    $rid = bin2hex(random_bytes(4));
    arr_pdo()->prepare("INSERT INTO locations (code, name) VALUES (?, ?)")->execute(["ARRF1L-$rid", "ARR F1 Loc $rid"]);
    $locId = (int) arr_pdo()->lastInsertId();
    $assetIds = [];
    $ticketIds = [];

    $capped = $config;
    $capped['reports']['asset_display_limit'] = 2;
    $container->instance('config', $capped);

    try {
        foreach ([0, 1, 2] as $i) {
            arr_pdo()->prepare(
                "INSERT INTO assets (asset_code, name, asset_category_id, location_id, status) VALUES (?, 'ARR F1', 1, ?, 'active')"
            )->execute(["ARRF1-$rid-$i", $locId]);
            $aid = (int) arr_pdo()->lastInsertId();
            $assetIds[] = $aid;
            arr_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at)
                 VALUES (?, 'x', 'x', 1, ?, 1, 1, ?, 'submitted', NOW())"
            )->execute(["ARRF1T-$rid-$i", $locId, $aid]);
            $ticketIds[] = (int) arr_pdo()->lastInsertId();
        }

        $page = arr_service()->getAssetReliabilityReportPage(['id' => 4, 'role' => 'admin'], ['location_id' => $locId]);

        assert_same(3, (int) $page['summary']['assets'], 'summary counts all 3 matching assets, not the capped display');
        assert_same(2, count($page['rows']), 'the table is capped at the display limit (2)');
        assert_same(3, (int) ($page['rowsMeta']['total'] ?? 0), 'rowsMeta.total is the true count');
        assert_true((bool) ($page['rowsMeta']['capped'] ?? false), 'rowsMeta.capped is true when there are more than the display limit');
    } finally {
        $container->instance('config', $config);
        foreach ($ticketIds as $id) {
            arr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        foreach ($assetIds as $id) {
            arr_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]);
        }
        arr_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('asset reliability: a same-minute resolution shows avg 0.0, not "-" (Finding F5-rem)', function (): void {
    // avg-resolution presence must come from the resolved-ticket COUNT, not the average value:
    // a fault fixed within the same clock-minute has a real 0.0h, not "no data".
    $rid = bin2hex(random_bytes(4));
    $assetId = 0;
    $ticketId = 0;

    try {
        arr_pdo()->prepare(
            "INSERT INTO assets (asset_code, name, asset_category_id, location_id, status) VALUES (?, 'ARR F5', 1, 1, 'active')"
        )->execute(["ARRF5-$rid"]);
        $assetId = (int) arr_pdo()->lastInsertId();
        arr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'resolved', '2020-05-10 10:00:00', '2020-05-10 10:00:45')"
        )->execute(["ARRF5T-$rid", $assetId]);
        $ticketId = (int) arr_pdo()->lastInsertId();

        $row = arr_find_row("ARRF5-$rid");
        assert_true($row !== null, 'asset appears');
        assert_same(1, $row['failure_count'], 'failure_count = 1');
        assert_same('0.0', $row['avg_resolution_hours_label'], 'avg resolution 0.0h (same-minute resolve), not "-"');
        // downtime presence follows the resolved-incident count too (F4): a sub-minute repair = real 0.0h, not "-"
        assert_same('0.0', $row['downtime_hours_label'], 'downtime 0.0h (same-minute resolve), not "-"');
    } finally {
        if ($ticketId > 0) {
            arr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        if ($assetId > 0) {
            arr_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        }
    }
});

test('asset reliability: heuristic health scoring — high-risk asset = ควรเปลี่ยน, fresh asset = ปกติ', function (): void {
    $rid = bin2hex(random_bytes(4));
    $badAsset = 0;
    $goodAsset = 0;
    $ticketIds = [];

    try {
        // High-risk: 6 failures + out of warranty + 9-year-old purchase → score ≥ 4 → ควรเปลี่ยน
        arr_pdo()->prepare(
            "INSERT INTO assets (asset_code, name, asset_category_id, location_id, status, purchase_date, warranty_expires_at)
             VALUES (?, 'ARR Bad Asset', 1, 1, 'active', ?, ?)"
        )->execute(["ARRB-$rid", date('Y-m-d', strtotime('-9 years')), date('Y-m-d', strtotime('-1 year'))]);
        $badAsset = (int) arr_pdo()->lastInsertId();
        for ($i = 0; $i < 6; $i++) {
            arr_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at)
                 VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'submitted', ?)"
            )->execute(["ARRBT-$rid-$i", $badAsset, date('Y-m-d H:i:s', strtotime("-$i days"))]);
            $ticketIds[] = (int) arr_pdo()->lastInsertId();
        }

        // Fresh: 1 failure + in warranty + new → score 0 → ปกติ
        arr_pdo()->prepare(
            "INSERT INTO assets (asset_code, name, asset_category_id, location_id, status, purchase_date, warranty_expires_at)
             VALUES (?, 'ARR Good Asset', 1, 1, 'active', ?, ?)"
        )->execute(["ARRG-$rid", date('Y-m-d', strtotime('-6 months')), date('Y-m-d', strtotime('+2 years'))]);
        $goodAsset = (int) arr_pdo()->lastInsertId();
        arr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'submitted', ?)"
        )->execute(["ARRGT-$rid", $goodAsset, date('Y-m-d H:i:s')]);
        $ticketIds[] = (int) arr_pdo()->lastInsertId();

        $bad = arr_find_row("ARRB-$rid");
        assert_true($bad !== null, 'high-risk asset appears in report');
        assert_same('danger', $bad['health_tone'], 'high-risk asset tone = danger');
        assert_same('ควรเปลี่ยน', $bad['health_label'], 'high-risk asset = ควรเปลี่ยน');
        assert_true(str_contains($bad['health_reason'], 'เสีย 6 ครั้ง'), 'reason names the failure frequency');
        assert_true(str_contains($bad['health_reason'], 'หมดประกัน'), 'reason names the expired warranty');

        $good = arr_find_row("ARRG-$rid");
        assert_true($good !== null, 'fresh asset appears in report');
        assert_same('success', $good['health_tone'], 'fresh asset tone = success');
        assert_same('ปกติ', $good['health_label'], 'fresh asset = ปกติ');
    } finally {
        foreach ($ticketIds as $id) {
            arr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        foreach ([$badAsset, $goodAsset] as $assetId) {
            if ($assetId > 0) {
                arr_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
            }
        }
    }
});

test('asset reliability: no fan-out + downtime = sum of resolution minutes', function (): void {
    $rid = bin2hex(random_bytes(4));
    $assetId = 0;
    $ticketId = 0;

    try {
        arr_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id) VALUES (?, 'ARR NoFan Asset', 1, 1)")
            ->execute(["ARRN-$rid"]);
        $assetId = (int) arr_pdo()->lastInsertId();

        // ONE resolved ticket (120min) with BOTH a work_order and a rating — LEFT JOIN must not double it
        arr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'resolved', ?, ?)"
        )->execute(["ARRNT-$rid", $assetId, date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s')]);
        $ticketId = (int) arr_pdo()->lastInsertId();
        arr_pdo()->prepare(
            "INSERT INTO work_orders (work_order_no, ticket_id, technician_id, assigned_by, status, labor_minutes) VALUES (?, ?, 3, 4, 'completed', 60)"
        )->execute(["ARRWO-$rid", $ticketId]);
        arr_pdo()->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, score) VALUES (?, 1, 5)')->execute([$ticketId]);

        $row = arr_find_row("ARRN-$rid");
        assert_true($row !== null, 'asset appears in report');
        assert_same(1, $row['failure_count'], 'failure_count = 1 (work_order + rating joins must NOT inflate)');
        assert_same('2.0', $row['downtime_hours_label'], 'downtime 120min = 2.0h');
        assert_same('1.0', $row['labor_hours_label'], 'labor 60min = 1.0h');
    } finally {
        if ($ticketId > 0) {
            arr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        if ($assetId > 0) {
            arr_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        }
    }
});

test('asset reliability: CSV export cells reconcile with the on-screen row (screen↔export parity)', function (): void {
    // Screen (mapAssetReliabilityReportRow) and export (assetReportExportRow) format separately — pin the
    // health label, failure count, downtime/labor a manager reads to the exact downloaded cells. (BI-review #4.)
    $rid = bin2hex(random_bytes(4));
    $assetId = 0;
    $ticketId = 0;
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) arr_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        arr_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id) VALUES (?, 'ARP Parity Asset', 1, 1)")
            ->execute(["ARP-$rid"]);
        $assetId = (int) arr_pdo()->lastInsertId();
        arr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'resolved', ?, ?)"
        )->execute(["ARPT-$rid", $assetId, date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s')]);
        $ticketId = (int) arr_pdo()->lastInsertId();
        arr_pdo()->prepare(
            "INSERT INTO work_orders (work_order_no, ticket_id, technician_id, assigned_by, status, labor_minutes) VALUES (?, ?, 3, 4, 'completed', 60)"
        )->execute(["ARPWO-$rid", $ticketId]);

        $screen = arr_find_row("ARP-$rid");
        assert_true($screen !== null, 'asset appears on screen');

        $csv = (string) arr_service()->exportAssetReliabilityCsv($admin, [])['content'];
        $exportRow = null;
        foreach (explode("\n", trim(substr($csv, 3))) as $line) { // substr(3) strips the BOM
            $cells = str_getcsv($line);
            if (($cells[0] ?? null) === "ARP-$rid") {
                $exportRow = $cells;
                break;
            }
        }
        assert_true($exportRow !== null, 'the same asset appears as a CSV row');

        // headers: รหัส, ชื่อ, หมวดหมู่, สถานที่, สถานะ, สุขภาพ, เหตุผล, จำนวนครั้ง, …, Downtime (ชม.), ชม.แรงงาน, …
        assert_same($screen['health_label'], $exportRow[5], 'CSV สุขภาพ = screen health_label');
        assert_same((string) $screen['failure_count'], $exportRow[7], 'CSV จำนวนครั้ง = screen failure_count');
        assert_same($screen['downtime_hours_label'], $exportRow[11], 'CSV Downtime = screen downtime_hours_label (2.0)');
        assert_same($screen['labor_hours_label'], $exportRow[12], 'CSV ชม.แรงงาน = screen labor_hours_label (1.0)');

        // XLSX parity — health label as text, failure count + downtime/labor hours numeric = screen
        $xlsxTmp = tempnam(sys_get_temp_dir(), 'arpx_') . '.xlsx';
        file_put_contents($xlsxTmp, (string) arr_service()->exportAssetReliabilityExcel($admin, [])['content']);
        $sheet = IOFactory::createReader('Xlsx')->load($xlsxTmp)->getActiveSheet();
        @unlink($xlsxTmp);
        $xlsxRow = null;
        foreach ($sheet->toArray(null, true, false) as $r) { // formatData=false → raw values
            if (($r[0] ?? null) === "ARP-$rid") {
                $xlsxRow = $r;
                break;
            }
        }
        assert_true($xlsxRow !== null, 'the same asset appears as an XLSX row');
        assert_same($screen['health_label'], (string) $xlsxRow[5], 'XLSX สุขภาพ label = screen');
        assert_same((int) $screen['failure_count'], (int) $xlsxRow[7], 'XLSX จำนวนครั้ง numeric = screen');
        assert_same((float) $screen['downtime_hours_label'], (float) $xlsxRow[11], 'XLSX Downtime numeric = screen (2.0)');
        assert_same((float) $screen['labor_hours_label'], (float) $xlsxRow[12], 'XLSX ชม.แรงงาน numeric = screen (1.0)');
    } finally {
        arr_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        if ($ticketId > 0) {
            arr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        if ($assetId > 0) {
            arr_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        }
    }
});

test('asset reliability: a future incident does not inflate failure_count / MTBF / health (round-8 F3)', function (): void {
    // A future requested_at (clock skew / bad import) must not be treated as a real failure — otherwise it
    // inflates last_failure and MTBF so a shaky asset looks reliable ("ปกติ"). Future incidents are excluded
    // from the reliability aggregation.
    $rid = bin2hex(random_bytes(4));
    $assetId = 0;
    $ids = [];

    try {
        arr_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id) VALUES (?, ?, 1, 1)")
            ->execute(["ARF-$rid", "ARF Asset $rid"]);
        $assetId = (int) arr_pdo()->lastInsertId();
        // one real, past failure
        arr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'resolved', '2020-01-01 09:00:00', '2020-01-01 10:00:00')"
        )->execute(["ARFP-$rid", $assetId]);
        $ids[] = (int) arr_pdo()->lastInsertId();
        // a FUTURE "failure" in 2030 — impossible, must be ignored
        arr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'in_progress', '2030-06-01 09:00:00')"
        )->execute(["ARFF-$rid", $assetId]);
        $ids[] = (int) arr_pdo()->lastInsertId();

        $row = arr_find_row("ARF-$rid");
        assert_true($row !== null, 'asset appears');
        assert_same(1, $row['failure_count'], 'the 2030 incident is not counted as a failure — only the real past one');
        assert_same('-', $row['mtbf_days_label'], 'MTBF stays "-" (one real failure); the 2030 date does not fabricate a huge MTBF');
    } finally {
        foreach ($ids as $id) {
            arr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        if ($assetId > 0) {
            arr_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        }
    }
});

test('asset reliability: MTBF = span / (failures - 1)', function (): void {
    $rid = bin2hex(random_bytes(4));
    $assetId = 0;
    $ticketIds = [];

    try {
        arr_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id) VALUES (?, 'ARR MTBF Asset', 1, 1)")
            ->execute(["ARRM-$rid"]);
        $assetId = (int) arr_pdo()->lastInsertId();

        // 3 failures spanning 60 days (first 60d ago, mid 30d ago, last now) → MTBF = 60 / (3-1) = 30 วัน
        foreach ([60, 30, 0] as $i => $daysAgo) {
            arr_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at)
                 VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'submitted', ?)"
            )->execute(["ARRMT-$rid-$i", $assetId, date('Y-m-d H:i:s', strtotime("-$daysAgo days"))]);
            $ticketIds[] = (int) arr_pdo()->lastInsertId();
        }

        $row = arr_find_row("ARRM-$rid");
        assert_true($row !== null, 'asset appears in report');
        assert_same(3, $row['failure_count'], 'failure_count = 3');
        assert_same('30 วัน', $row['mtbf_days_label'], 'MTBF 60d span / 2 = 30 วัน');
    } finally {
        foreach ($ticketIds as $id) {
            arr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        if ($assetId > 0) {
            arr_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        }
    }
});

test('asset reliability: asset_status filter restricts rows', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $activeAsset = 0;
    $maintAsset = 0;
    $ticketIds = [];

    try {
        foreach (['active', 'maintenance'] as $status) {
            arr_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id, status) VALUES (?, 'ARR Filter Asset', 1, 1, ?)")
                ->execute(["ARRF-$rid-$status", $status]);
            $assetId = (int) arr_pdo()->lastInsertId();
            ${$status === 'active' ? 'activeAsset' : 'maintAsset'} = $assetId;
            arr_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at)
                 VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'submitted', NOW())"
            )->execute(["ARRFT-$rid-$status", $assetId]);
            $ticketIds[] = (int) arr_pdo()->lastInsertId();
        }

        $codes = array_column(
            arr_service()->getAssetReliabilityReportPage($admin, ['asset_status' => 'maintenance'])['rows'],
            'asset_code'
        );
        assert_true(in_array("ARRF-$rid-maintenance", $codes, true), 'maintenance asset present under status=maintenance');
        assert_false(in_array("ARRF-$rid-active", $codes, true), 'active asset filtered out under status=maintenance');
    } finally {
        foreach ($ticketIds as $id) {
            arr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        foreach ([$activeAsset, $maintAsset] as $assetId) {
            if ($assetId > 0) {
                arr_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
            }
        }
    }
});

test('asset reliability: export xlsx (1 sheet + header) / pdf %PDF- / csv raw with header', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) arr_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'arr_') . '.xlsx';

    try {
        $export = arr_service()->exportAssetReliabilityExcel($admin, []);
        file_put_contents($tmp, (string) $export['content']);
        $book = IOFactory::createReader('Xlsx')->load($tmp);
        assert_same(1, $book->getSheetCount(), 'single sheet (no analytics sheets on this report)');
        assert_same('สุขภาพทรัพย์สิน', $book->getSheetNames()[0], 'sheet title');
        assert_same('รหัส', (string) $book->getActiveSheet()->getCell('A1')->getValue(), 'header cell A1');
        $book->disconnectWorksheets();

        $pdf = arr_service()->exportAssetReliabilityPdf($admin, []);
        assert_same('%PDF-', substr((string) $pdf['content'], 0, 5), 'pdf magic bytes');

        $csv = (string) arr_service()->exportAssetReliabilityCsv($admin, [])['content'];
        assert_true(str_contains($csv, 'รหัส'), 'csv keeps header');
        assert_true(str_contains($csv, 'สุขภาพ'), 'csv carries the health column');
    } finally {
        @unlink($tmp);
        arr_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});

test('asset reliability export: a leading-zero asset code stays text and matches screen/CSV (audit power-proof)', function (): void {
    // Asset codes are identifiers (VARCHAR), not quantities. The shared XLSX writer currently coerces every
    // digit-only string to an integer, so a valid code such as "00123456" becomes 123456 in Excel even though
    // the screen and CSV retain the exact code. This assertion is intentionally red until numeric coercion
    // distinguishes typed metrics from identifier strings.
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $assetCode = '00' . (string) random_int(10000000, 99999999);
    $locationId = 0;
    $assetId = 0;
    $ticketId = 0;
    $baselineJobId = (int) arr_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'arrzero_') . '.xlsx';

    try {
        arr_pdo()->prepare('INSERT INTO locations (code, name) VALUES (?, ?)')
            ->execute(["ARRZL-$rid", "ARR Leading Zero $rid"]);
        $locationId = (int) arr_pdo()->lastInsertId();

        arr_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id, status) VALUES (?, 'Leading-zero asset', 1, ?, 'active')")
            ->execute([$assetCode, $locationId]);
        $assetId = (int) arr_pdo()->lastInsertId();

        arr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, ?, 'submitted', NOW())"
        )->execute(["ARRZLT-$rid", $locationId, $assetId]);
        $ticketId = (int) arr_pdo()->lastInsertId();

        $filters = ['location_id' => $locationId];
        $screenRows = arr_service()->getAssetReliabilityReportPage($admin, $filters)['rows'];
        assert_same($assetCode, (string) ($screenRows[0]['asset_code'] ?? ''), 'screen retains the exact leading-zero asset code');

        file_put_contents($tmp, (string) arr_service()->exportAssetReliabilityExcel($admin, $filters)['content']);
        $cell = IOFactory::createReader('Xlsx')->load($tmp)->getActiveSheet()->getCell('A2');
        $csv = (string) arr_service()->exportAssetReliabilityCsv($admin, $filters)['content'];
        $lines = preg_split('/\R/', trim(substr($csv, 3))) ?: [];
        $csvRow = str_getcsv((string) ($lines[1] ?? ''));

        assert_same(
            ['xlsx_type' => DataType::TYPE_STRING, 'xlsx_value' => $assetCode, 'csv_value' => $assetCode],
            ['xlsx_type' => $cell->getDataType(), 'xlsx_value' => $cell->getValue(), 'csv_value' => $csvRow[0] ?? null],
            'identifier is byte-equal across screen/CSV/XLSX and remains text in Excel'
        );
    } finally {
        @unlink($tmp);
        arr_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        if ($ticketId > 0) {
            arr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        if ($assetId > 0) {
            arr_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        }
        if ($locationId > 0) {
            arr_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locationId]);
        }
    }
});

test('asset reliability export: a decimal-looking asset code stays text and matches screen/CSV (audit power-proof)', function (): void {
    // The R16 fix protects digit-only identifiers, but the shared writer still infers numeric type from DECIMAL
    // string syntax. Asset codes are unrestricted VARCHAR identifiers, so "001234.25" is valid and must not be
    // coerced to 1234.25. This assertion is intentionally red until ALL strings are text unless the export row
    // explicitly supplies a typed numeric metric (or equivalent column metadata).
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $assetCode = '00' . (string) random_int(100000, 999999) . '.25';
    $locationId = 0;
    $assetId = 0;
    $ticketId = 0;
    $baselineJobId = (int) arr_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'arrdecimal_') . '.xlsx';

    try {
        arr_pdo()->prepare('INSERT INTO locations (code, name) VALUES (?, ?)')
            ->execute(["ARRDL-$rid", "ARR Decimal Code $rid"]);
        $locationId = (int) arr_pdo()->lastInsertId();

        arr_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id, status) VALUES (?, 'Decimal-looking code', 1, ?, 'active')")
            ->execute([$assetCode, $locationId]);
        $assetId = (int) arr_pdo()->lastInsertId();

        arr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, ?, 'submitted', NOW())"
        )->execute(["ARRDLT-$rid", $locationId, $assetId]);
        $ticketId = (int) arr_pdo()->lastInsertId();

        $filters = ['location_id' => $locationId];
        $screenRows = arr_service()->getAssetReliabilityReportPage($admin, $filters)['rows'];
        assert_same($assetCode, (string) ($screenRows[0]['asset_code'] ?? ''), 'screen retains the exact decimal-looking asset code');

        file_put_contents($tmp, (string) arr_service()->exportAssetReliabilityExcel($admin, $filters)['content']);
        $cell = IOFactory::createReader('Xlsx')->load($tmp)->getActiveSheet()->getCell('A2');
        $csv = (string) arr_service()->exportAssetReliabilityCsv($admin, $filters)['content'];
        $lines = preg_split('/\R/', trim(substr($csv, 3))) ?: [];
        $csvRow = str_getcsv((string) ($lines[1] ?? ''));

        assert_same(
            ['xlsx_type' => DataType::TYPE_STRING, 'xlsx_value' => $assetCode, 'csv_value' => $assetCode],
            ['xlsx_type' => $cell->getDataType(), 'xlsx_value' => $cell->getValue(), 'csv_value' => $csvRow[0] ?? null],
            'decimal-looking identifier is byte-equal across screen/CSV/XLSX and remains text in Excel'
        );
    } finally {
        @unlink($tmp);
        arr_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        if ($ticketId > 0) {
            arr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        if ($assetId > 0) {
            arr_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        }
        if ($locationId > 0) {
            arr_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locationId]);
        }
    }
});

// bug-hunt A5 (2nd pass): the /reports asset-reliability PANEL (getAssetReliabilityRows) lacked the
// 't.requested_at <= NOW()' clamp that the full report (getAssetReliabilityReport) got in R8-F3, so a
// future-dated ticket (clock skew / bad import) inflated the panel's failure_count above the full report's
// for the same asset. The panel now applies the same clamp.
test('asset reliability panel A5: the /reports panel excludes future-dated tickets, matching the full report', function (): void {
    $repo = tvm_container()->get(App\Repositories\ReportRepository::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(3));
    arr_pdo()->prepare('INSERT INTO locations (code, name) VALUES (?, ?)')->execute(["A5L-$rid", "A5 Loc $rid"]);
    $locId = (int) arr_pdo()->lastInsertId();
    arr_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id, status, created_at, updated_at) VALUES (?, 'A5 Asset', 1, ?, 'active', NOW(), NOW())")->execute(["A5A-$rid", $locId]);
    $assetId = (int) arr_pdo()->lastInsertId();
    $tids = [];
    try {
        // one real (past) failure + one future-dated ticket (clock skew / bad import)
        arr_pdo()->prepare("INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at) VALUES (?, 'x','x',1,?,1,1,?, 'submitted', DATE_SUB(NOW(), INTERVAL 1 DAY))")->execute(["A5T1-$rid", $locId, $assetId]);
        $tids[] = (int) arr_pdo()->lastInsertId();
        arr_pdo()->prepare("INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at) VALUES (?, 'x','x',1,?,1,1,?, 'submitted', DATE_ADD(NOW(), INTERVAL 10 DAY))")->execute(["A5T2-$rid", $locId, $assetId]);
        $tids[] = (int) arr_pdo()->lastInsertId();

        $rows = $repo->getAssetReliabilityRows($admin, ['location_id' => $locId]);
        $row = null;
        foreach ($rows as $r) {
            if ((int) ($r['id'] ?? 0) === $assetId) {
                $row = $r;
                break;
            }
        }
        assert_true($row !== null, 'the asset appears in the reliability panel');
        assert_same(1, (int) $row['failure_count'], 'the panel counts only the past failure, not the future-dated ticket (matches the full report)');
    } finally {
        foreach ($tids as $id) {
            arr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        arr_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        arr_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});
