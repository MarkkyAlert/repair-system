<?php
declare(strict_types=1);

use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Round-9: the main /reports ticket workbook built its sheet with $sheet->fromArray() directly, bypassing the
// shared numeric writer — so a "เวลาแก้ไข (ชม.)" over 999 hours ("1,200.0", comma from number_format) landed
// as text and couldn't be summed/pivoted. Route it through fillSheet like every other sheet.

function rxn_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function rxn_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

test('reports: the /reports Excel "เวลาแก้ไข (ชม.)" cell over 999h is numeric, not text (round-9)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    rxn_pdo()->prepare('INSERT INTO departments (code, name) VALUES (?, ?)')->execute(["RXND-$rid", "RXN Dept $rid"]);
    $deptId = (int) rxn_pdo()->lastInsertId();
    $ticketId = 0;
    $baselineJobId = (int) rxn_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        // requested 2020-05-01, resolved 2020-06-20 → exactly 1200 hours → label "1,200.0"
        rxn_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, 1, 'resolved', '2020-05-01 00:00:00', '2020-06-20 00:00:00')"
        )->execute(["RXNT-$rid", $deptId]);
        $ticketId = (int) rxn_pdo()->lastInsertId();

        $filters = ['department_id' => $deptId, 'from_date' => '2020-05-01', 'to_date' => '2020-06-30'];
        $tmp = tempnam(sys_get_temp_dir(), 'rxn_') . '.xlsx';
        file_put_contents($tmp, (string) rxn_service()->exportExcel($admin, $filters)['content']);
        $sheet = IOFactory::createReader('Xlsx')->load($tmp)->getSheet(0); // main "รายงาน Ticket" sheet
        @unlink($tmp);

        $rowNum = 0;
        foreach ($sheet->toArray(null, true, false) as $i => $r) { // formatData=false → raw values
            if (($r[0] ?? null) === "RXNT-$rid") {
                $rowNum = $i + 1;
                break;
            }
        }
        assert_true($rowNum > 0, 'the ticket appears in the main Excel sheet');
        // header order: เลขที่(A) หัวข้อ ผู้แจ้ง แผนก หมวดหมู่ ช่าง ความสำคัญ สถานะ วันที่แจ้ง วันที่แก้ไข เวลาแก้ไข(K) …
        $cell = 'K' . $rowNum;
        assert_same(DataType::TYPE_NUMERIC, $sheet->getCell($cell)->getDataType(), '"เวลาแก้ไข (ชม.)" >999h is numeric, not text');
        assert_same(1200.0, $sheet->getCell($cell)->getValue(), '"1,200.0" stored as 1200.0');
        assert_same('#,##0.0', $sheet->getStyle($cell)->getNumberFormat()->getFormatCode(), 'grouped 1-decimal display');
    } finally {
        rxn_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        if ($ticketId > 0) {
            rxn_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        rxn_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});
