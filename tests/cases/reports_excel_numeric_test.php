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

test('reports: XLSX preserves user text while keeping ticket ratings numeric (audit power-proof)', function (): void {
    // The main Ticket sheet does not use ReportService::txt(), while its rating is still passed as a string label.
    // A decimal-looking title/master-data name must stay text; the adjacent rating must be a real number for pivot.
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $ticketNo = "RXTXT-$rid";
    $title = '00' . (string) random_int(100000, 999999) . '.25';
    $departmentName = '00' . (string) random_int(100000, 999999) . '.50';
    $priorityName = '00' . (string) random_int(100000, 999999) . '.75';
    $departmentId = 0;
    $priorityId = 0;
    $ticketId = 0;
    $baselineJobId = (int) rxn_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'rxtxt_') . '.xlsx';

    try {
        rxn_pdo()->prepare('INSERT INTO departments (code, name) VALUES (?, ?)')
            ->execute(["RXTXTD-$rid", $departmentName]);
        $departmentId = (int) rxn_pdo()->lastInsertId();

        $usedLevels = array_map('intval', rxn_pdo()->query('SELECT level FROM priorities')->fetchAll(PDO::FETCH_COLUMN));
        $level = 0;
        for ($candidate = 99; $candidate >= 1; $candidate--) {
            if (!in_array($candidate, $usedLevels, true)) {
                $level = $candidate;
                break;
            }
        }
        assert_true($level > 0, 'a free priority level exists for the isolated fixture');
        rxn_pdo()->prepare(
            'INSERT INTO priorities (code, name, level, response_time_minutes, resolution_time_minutes, sort_order, is_active)
             VALUES (?, ?, ?, 60, 480, ?, 1)'
        )->execute(["RXTXTP-$rid", $priorityName, $level, $level]);
        $priorityId = (int) rxn_pdo()->lastInsertId();

        $requestedAt = date('Y-m-d H:i:s', time() - 3600);
        $respondedAt = date('Y-m-d H:i:s', time() - 3000);
        $resolvedAt = date('Y-m-d H:i:s', time() - 600);
        rxn_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, status, requested_at, first_response_at, resolved_at, completed_at)
             VALUES (?, ?, 'x', 1, ?, 1, 1, ?, 'completed', ?, ?, ?, ?)"
        )->execute([$ticketNo, $title, $departmentId, $priorityId, $requestedAt, $respondedAt, $resolvedAt, $resolvedAt]);
        $ticketId = (int) rxn_pdo()->lastInsertId();

        $targetAt = date('Y-m-d H:i:s', time() + 3600);
        rxn_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, achieved_at, status) VALUES (?, 'response', ?, ?, 'met')")
            ->execute([$ticketId, $targetAt, $respondedAt]);
        rxn_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, achieved_at, status) VALUES (?, 'resolution', ?, ?, 'met')")
            ->execute([$ticketId, $targetAt, $resolvedAt]);
        rxn_pdo()->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, score) VALUES (?, 1, 5)')
            ->execute([$ticketId]);

        // Window from the ticket's OWN requested_at date (not today): a fixture created time()-3600 falls on
        // yesterday when the suite runs in the 00:00–01:00 window, and a hard-coded date('Y-m-d') from_date
        // would exclude it → flaky red across midnight. Deriving the bound from $requestedAt makes it stable.
        $filters = ['department_id' => $departmentId, 'from_date' => substr($requestedAt, 0, 10), 'to_date' => date('Y-m-d')];
        $screenRows = rxn_service()->getReportPageData($admin, $filters)['rows'];
        $screen = $screenRows[0] ?? [];
        assert_same(
            [$ticketNo, $title, $departmentName, $priorityName, '5'],
            [$screen['ticket_no'] ?? null, $screen['title'] ?? null, $screen['department_name'] ?? null, $screen['priority_label'] ?? null, $screen['rating_label'] ?? null],
            'screen retains the exact user-defined text and rating'
        );

        $csv = (string) rxn_service()->exportCsv($admin, $filters)['content'];
        $csvRow = null;
        foreach (preg_split('/\R/', substr($csv, 3)) ?: [] as $line) {
            $cells = str_getcsv((string) $line);
            if (($cells[0] ?? null) === $ticketNo) {
                $csvRow = $cells;
                break;
            }
        }
        assert_same([$title, $departmentName, $priorityName, '5'], [$csvRow[1] ?? null, $csvRow[3] ?? null, $csvRow[6] ?? null, $csvRow[13] ?? null], 'CSV matches screen');

        file_put_contents($tmp, (string) rxn_service()->exportExcel($admin, $filters)['content']);
        $book = IOFactory::createReader('Xlsx')->load($tmp);
        $ticketSheet = $book->getSheet(0);
        $ticketRow = 0;
        foreach ($ticketSheet->toArray(null, true, false) as $i => $row) {
            if (($row[0] ?? null) === $ticketNo) {
                $ticketRow = $i + 1;
                break;
            }
        }
        assert_true($ticketRow > 0, 'ticket appears in the main XLSX sheet');

        $slaSheet = $book->getSheetByName('SLA ตรงตามกำหนด');
        assert_true($slaSheet !== null, 'SLA analytics sheet exists');
        $slaLabelCell = null;
        for ($rowNumber = 2; $rowNumber <= $slaSheet->getHighestDataRow(); $rowNumber++) {
            $candidate = $slaSheet->getCell('A' . $rowNumber);
            if ($candidate->getValue() === $priorityName || $candidate->getValue() === (float) $priorityName) {
                $slaLabelCell = $candidate;
                break;
            }
        }
        assert_true($slaLabelCell !== null, 'custom priority appears in SLA sheet, whether preserved or coerced');

        $actual = [
            'title' => [$ticketSheet->getCell('B' . $ticketRow)->getDataType(), $ticketSheet->getCell('B' . $ticketRow)->getValue()],
            'department' => [$ticketSheet->getCell('D' . $ticketRow)->getDataType(), $ticketSheet->getCell('D' . $ticketRow)->getValue()],
            'priority' => [$ticketSheet->getCell('G' . $ticketRow)->getDataType(), $ticketSheet->getCell('G' . $ticketRow)->getValue()],
            'rating' => [$ticketSheet->getCell('N' . $ticketRow)->getDataType(), $ticketSheet->getCell('N' . $ticketRow)->getValue()],
            'sla_priority' => [$slaLabelCell->getDataType(), $slaLabelCell->getValue()],
        ];
        assert_same([
            'title' => [DataType::TYPE_STRING, $title],
            'department' => [DataType::TYPE_STRING, $departmentName],
            'priority' => [DataType::TYPE_STRING, $priorityName],
            'rating' => [DataType::TYPE_NUMERIC, 5],
            'sla_priority' => [DataType::TYPE_STRING, $priorityName],
        ], $actual, 'XLSX uses semantic types: user text stays exact; rating remains numeric');
        $book->disconnectWorksheets();
    } finally {
        @unlink($tmp);
        rxn_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        if ($ticketId > 0) {
            rxn_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        if ($priorityId > 0) {
            rxn_pdo()->prepare('DELETE FROM priorities WHERE id = ?')->execute([$priorityId]);
        }
        if ($departmentId > 0) {
            rxn_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$departmentId]);
        }
    }
});
