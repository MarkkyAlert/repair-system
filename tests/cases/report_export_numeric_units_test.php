<?php
declare(strict_types=1);

use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// F3 (BI-review): asset MTBF/age and technician oldest-backlog were exported as unit-bearing labels
// ("30 วัน", "9.0 ปี", "3 วัน"), so Excel stored them as TEXT and a manager could not pivot/sum them. The unit
// already lives in the column header, so the sheet must carry a bare number. Export the real reports and assert
// the cells are DataType::TYPE_NUMERIC. Power-proof: point the export row back at the *_label field → text → red.

function renu_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function renu_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

/** Load an exported XLSX (first sheet) from a report export result. */
function renu_sheet(array $export): Worksheet
{
    $tmp = tempnam(sys_get_temp_dir(), 'renu_') . '.xlsx';
    file_put_contents($tmp, (string) $export['content']);
    $sheet = IOFactory::createReader('Xlsx')->load($tmp)->getSheet(0);
    @unlink($tmp);

    return $sheet;
}

/** 1-based row number of the first row whose column A equals $key, or 0. */
function renu_find_row(Worksheet $sheet, string $key): int
{
    foreach ($sheet->toArray(null, true, false) as $i => $r) {
        if ((string) ($r[0] ?? '') === $key) {
            return (int) $i + 1;
        }
    }

    return 0;
}

test('export(numeric): asset MTBF + age export as numbers, not "30 วัน"/"9.0 ปี" text (F3)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $code = "RENU-$rid";
    $assetId = 0;
    $ticketIds = [];
    $baseJob = (int) renu_pdo()->query('SELECT COALESCE(MAX(id),0) FROM export_jobs')->fetchColumn();

    try {
        // purchased 9 years ago → age ≈ 9.0 ปี; two failures ~30 days apart → a positive MTBF
        renu_pdo()->prepare(
            "INSERT INTO assets (asset_code, name, asset_category_id, location_id, status, purchase_date)
             VALUES (?, 'RENU asset', 1, 1, 'active', ?)"
        )->execute([$code, date('Y-m-d', strtotime('-9 years'))]);
        $assetId = (int) renu_pdo()->lastInsertId();
        foreach (['-30 days', 'now'] as $i => $when) {
            renu_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at, resolved_at)
                 VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'resolved', ?, ?)"
            )->execute(["RENUT-$rid-$i", $assetId, date('Y-m-d H:i:s', strtotime($when . ' -1 hour')), date('Y-m-d H:i:s', strtotime($when))]);
            $ticketIds[] = (int) renu_pdo()->lastInsertId();
        }

        $sheet = renu_sheet(renu_service()->exportAssetReliabilityExcel($admin, []));
        $row = renu_find_row($sheet, $code);
        assert_true($row > 0, 'the asset appears in the exported sheet');
        // header: code(A) name category location status health reason failure last MTBF(J) avg_res downtime labor age(N) warranty
        assert_same(DataType::TYPE_NUMERIC, $sheet->getCell("J$row")->getDataType(), 'MTBF cell is numeric, not "30 วัน" text');
        assert_same(DataType::TYPE_NUMERIC, $sheet->getCell("N$row")->getDataType(), 'age cell is numeric, not "9.0 ปี" text');
        assert_true(is_numeric($sheet->getCell("J$row")->getValue()) && (float) $sheet->getCell("J$row")->getValue() > 0, 'MTBF is a real positive number');
        assert_true((float) $sheet->getCell("N$row")->getValue() >= 8.5 && (float) $sheet->getCell("N$row")->getValue() <= 9.5, 'age ≈ 9 years, as a number');
    } finally {
        renu_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baseJob]);
        foreach ($ticketIds as $id) {
            renu_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        if ($assetId > 0) {
            renu_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        }
    }
});

test('export(numeric): technician oldest-backlog exports as a number, not "3 วัน" text (F3)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $techName = "RENU Tech $rid";
    $techId = 0;
    $ticketId = 0;
    $baseJob = (int) renu_pdo()->query('SELECT COALESCE(MAX(id),0) FROM export_jobs')->fetchColumn();

    try {
        renu_pdo()->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, 'x', ?, 'technician', 1, NOW(), NOW())")
            ->execute(["renu_$rid", "renu_$rid@x.test", $techName]);
        $techId = (int) renu_pdo()->lastInsertId();
        // one OPEN ticket assigned 3 days ago → oldest-open-age = 3 (days)
        renu_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, assigned_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'in_progress', ?, ?)"
        )->execute(["RENUX-$rid", $techId, date('Y-m-d H:i:s', strtotime('-3 days -1 hour')), date('Y-m-d H:i:s', strtotime('-3 days'))]);
        $ticketId = (int) renu_pdo()->lastInsertId();

        $sheet = renu_sheet(renu_service()->exportTechnicianPerformanceExcel($admin, []));
        $row = renu_find_row($sheet, $techName);
        assert_true($row > 0, 'the technician appears in the exported sheet');
        // header: ช่าง(A) งานค้าง(B) สัดส่วนโหลด(C) ค้างเก่าสุด(D) ...
        assert_same(DataType::TYPE_NUMERIC, $sheet->getCell("D$row")->getDataType(), 'oldest-backlog cell is numeric, not "3 วัน" text');
        assert_same(3.0, (float) $sheet->getCell("D$row")->getValue(), 'oldest-backlog value = 3');
    } finally {
        renu_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baseJob]);
        if ($ticketId > 0) {
            renu_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        if ($techId > 0) {
            renu_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$techId]);
        }
    }
});
