<?php
declare(strict_types=1);

use App\Services\ReportService;
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
