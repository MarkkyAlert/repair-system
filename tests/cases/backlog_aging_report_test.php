<?php
declare(strict_types=1);

use App\Core\View;
use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;

// Tests for the Backlog & Aging report (/reports/backlog-aging): open (non-terminal) tickets bucketed by
// age (DATEDIFF(NOW(), requested_at)) into 0-3 / 3-7 / 7-30 / >30 days, pivoted by dimension. Proves the
// bucket boundaries, open-only filter, Thai/null dimension labels, sort by >30 desc, and the invariant
// that total backlog is identical across dimensions. Fresh isolated locations give exact assertions.

function bla_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function bla_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

function bla_location(string $rid, string $suffix = ''): array
{
    $name = "BLA Loc $rid$suffix";
    bla_pdo()->prepare("INSERT INTO locations (code, name) VALUES (?, ?)")->execute(["BLAL-$rid$suffix", $name]);

    return [(int) bla_pdo()->lastInsertId(), $name];
}

/** Open ticket at $locId requested $ageDays ago (default in_progress, assigned/dept nullable). */
function bla_ticket(string $no, int $locId, int $ageDays, string $status = 'in_progress', ?int $tech = 3, ?int $dept = 4): int
{
    bla_pdo()->prepare(
        "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at)
         VALUES (?, 'x', 'x', 1, ?, ?, 1, 1, ?, ?, ?)"
    )->execute([$no, $dept, $locId, $tech, $status, date('Y-m-d H:i:s', strtotime("-$ageDays days"))]);

    return (int) bla_pdo()->lastInsertId();
}

function bla_row(string $dimension, string $label): ?array
{
    $page = bla_service()->getBacklogAgingReportPage(['id' => 4, 'role' => 'admin'], ['dimension' => $dimension]);
    foreach ($page['rows'] as $row) {
        if ($row['label'] === $label) {
            return $row;
        }
    }

    return null;
}

test('backlog aging: age buckets 0-3/3-7/7-30/>30 + open-only + oldest', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$locId, $locName] = bla_location($rid);
    $ids = [];

    try {
        $ids[] = bla_ticket("BLA-$rid-a", $locId, 1);   // 0-3
        $ids[] = bla_ticket("BLA-$rid-b", $locId, 5);   // 3-7
        $ids[] = bla_ticket("BLA-$rid-c", $locId, 15);  // 7-30
        $ids[] = bla_ticket("BLA-$rid-d", $locId, 40);  // >30
        $ids[] = bla_ticket("BLA-$rid-e", $locId, 50, 'closed'); // terminal → excluded

        $row = bla_row('location', $locName);
        assert_true($row !== null, 'location appears');
        assert_same(1, $row['bucket_0_3'], '1 ticket in 0-3 days');
        assert_same(1, $row['bucket_3_7'], '1 ticket in 3-7 days');
        assert_same(1, $row['bucket_7_30'], '1 ticket in 7-30 days');
        assert_same(1, $row['bucket_30_plus'], '1 ticket in >30 days');
        assert_same(4, $row['total'], 'total = 4 (closed ticket excluded)');
        assert_same('40 วัน', $row['oldest_label'], 'oldest = 40 days (open only; the 50-day closed one is ignored)');
    } finally {
        foreach ($ids as $id) {
            bla_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        bla_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

// The first test uses mid-bucket ages (1/5/15/40), so an off-by-one in the bucket SQL (< 3 vs <= 3, etc.)
// would pass unnoticed. This pins the exact edges: SQL is <3 / 3..6 / 7..29 / >=30, so a ticket aged
// exactly 3, 7 or 30 days must land in the HIGHER bucket. Ages are whole days back, so DATEDIFF = ageDays.
test('backlog aging: CSV export cells reconcile with the on-screen row (screen↔export parity)', function (): void {
    // Screen (mapBacklogAgingRow) and export (backlogExportRow) format separately — pin every age-bucket
    // count + total + oldest a manager reads to the exact cells in the downloaded file. (BI-review #4.)
    $rid = bin2hex(random_bytes(4));
    [$locId, $locName] = bla_location($rid);
    $ids = [];
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) bla_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        $ids[] = bla_ticket("BLAP1-$rid", $locId, 1);  // 0-3
        $ids[] = bla_ticket("BLAP2-$rid", $locId, 5);  // 3-7
        $ids[] = bla_ticket("BLAP3-$rid", $locId, 40); // >30, oldest

        $screen = bla_row('location', $locName);
        assert_true($screen !== null, 'location appears on screen');

        $csv = (string) bla_service()->exportBacklogAgingCsv($admin, ['dimension' => 'location'])['content'];
        $exportRow = null;
        foreach (explode("\n", trim(substr($csv, 3))) as $line) { // substr(3) strips the BOM
            $cells = str_getcsv($line);
            if (($cells[0] ?? null) === $locName) {
                $exportRow = $cells;
                break;
            }
        }
        assert_true($exportRow !== null, 'the same location appears as a CSV row');

        // headers: dim, 0-3 วัน, 3-7 วัน, 7-30 วัน, >30 วัน, รวม, เก่าสุด (วัน)
        assert_same((string) $screen['bucket_0_3'], $exportRow[1], 'CSV 0-3 วัน = screen');
        assert_same((string) $screen['bucket_3_7'], $exportRow[2], 'CSV 3-7 วัน = screen');
        assert_same((string) $screen['bucket_7_30'], $exportRow[3], 'CSV 7-30 วัน = screen');
        assert_same((string) $screen['bucket_30_plus'], $exportRow[4], 'CSV >30 วัน = screen');
        assert_same((string) $screen['total'], $exportRow[5], 'CSV รวม = screen total');
        assert_same((string) $screen['oldest_days'], $exportRow[6], 'CSV เก่าสุด (วัน) = screen oldest_days (40)');

        // XLSX parity — every age-bucket count + total + oldest is numeric and equal to the screen
        $xlsxTmp = tempnam(sys_get_temp_dir(), 'blax_') . '.xlsx';
        file_put_contents($xlsxTmp, (string) bla_service()->exportBacklogAgingExcel($admin, ['dimension' => 'location'])['content']);
        $sheet = IOFactory::createReader('Xlsx')->load($xlsxTmp)->getActiveSheet();
        @unlink($xlsxTmp);
        $xlsxRow = null;
        foreach ($sheet->toArray(null, true, false) as $r) { // formatData=false → raw numeric values
            if (($r[0] ?? null) === $locName) {
                $xlsxRow = $r;
                break;
            }
        }
        assert_true($xlsxRow !== null, 'the same location appears as an XLSX row');
        assert_same((int) $screen['bucket_0_3'], (int) $xlsxRow[1], 'XLSX 0-3 วัน numeric = screen');
        assert_same((int) $screen['bucket_3_7'], (int) $xlsxRow[2], 'XLSX 3-7 วัน numeric = screen');
        assert_same((int) $screen['bucket_7_30'], (int) $xlsxRow[3], 'XLSX 7-30 วัน numeric = screen');
        assert_same((int) $screen['bucket_30_plus'], (int) $xlsxRow[4], 'XLSX >30 วัน numeric = screen');
        assert_same((int) $screen['total'], (int) $xlsxRow[5], 'XLSX รวม numeric = screen');
        assert_same((int) $screen['oldest_days'], (int) $xlsxRow[6], 'XLSX เก่าสุด numeric = screen');
    } finally {
        bla_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        foreach ($ids as $id) {
            bla_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        bla_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('backlog aging: bucket boundaries land on the correct side at exactly 3 / 7 / 30 days', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$locId, $locName] = bla_location($rid);
    $ids = [];

    try {
        $ids[] = bla_ticket("BLB-$rid-2", $locId, 2);    // 0-2
        $ids[] = bla_ticket("BLB-$rid-3", $locId, 3);    // 3-6 (edge: 3 is NOT 0-2)
        $ids[] = bla_ticket("BLB-$rid-6", $locId, 6);    // 3-6
        $ids[] = bla_ticket("BLB-$rid-7", $locId, 7);    // 7-29 (edge: 7 is NOT 3-6)
        $ids[] = bla_ticket("BLB-$rid-29", $locId, 29);  // 7-29
        $ids[] = bla_ticket("BLB-$rid-30", $locId, 30);  // >=30 (inclusive lower edge)

        $row = bla_row('location', $locName);
        assert_true($row !== null, 'location appears');
        assert_same(1, $row['bucket_0_3'], '0-2 holds only the 2-day ticket (3 days crosses out)');
        assert_same(2, $row['bucket_3_7'], '3-6 holds 3 and 6 days (3 is the inclusive lower edge; 7 is not)');
        assert_same(2, $row['bucket_7_30'], '7-29 holds 7 and 29 days (7 inclusive; 30 is not)');
        assert_same(1, $row['bucket_30_plus'], '>=30 holds the 30-day ticket (inclusive lower edge)');
        assert_same(6, $row['total'], 'each of the 6 edge tickets is counted once');

        $method = new ReflectionMethod(ReportService::class, 'backlogExportHeaders');
        $method->setAccessible(true);
        $headers = $method->invoke(bla_service(), 'location');
        assert_same(
            ['0-2 วัน', '3-6 วัน', '7-29 วัน', '≥30 วัน'],
            array_slice($headers, 1, 4),
            'bucket labels must state the exact non-overlapping integer ranges implemented by the query'
        );
    } finally {
        foreach ($ids as $id) {
            bla_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        bla_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('backlog aging: screen states the inclusive >=30 boundary used by the query', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $page = bla_service()->getBacklogAgingReportPage($admin, ['dimension' => 'priority']);
    $html = View::capture('reports/backlog-aging', $page);
    assert_true(str_contains($html, '≥30 วัน'), 'screen states the inclusive >=30 boundary');
    assert_false(str_contains($html, '&gt;30 วัน'), 'screen must not exclude an exactly-30-day ticket in its table label');
});

test('backlog aging: PDF states the inclusive >=30 boundary used by the query', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) bla_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        $pdf = (string) bla_service()->exportBacklogAgingPdf($admin, ['dimension' => 'priority'])['content'];
        $text = (new Parser())->parseContent($pdf)->getText();
        assert_true(str_contains($text, '≥30 วัน'), 'PDF states the same inclusive >=30 boundary as the query and export headers');
        assert_false(str_contains($text, '>30 วัน'), 'PDF must not describe the inclusive bucket as strictly greater than 30');
    } finally {
        bla_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});

test('backlog aging: a real open ticket aged 0 days shows "0 วัน", not "-"; empty scope shows "-" (round-2 #6)', function (): void {
    // oldest_label gated on oldest_days > 0, so a genuine open ticket created today (age 0) showed "-" —
    // reading as "no backlog" when there IS one. Presence must be total > 0: real 0-day backlog → "0 วัน",
    // truly empty scope → "-". (BI-review round-2 #6.)
    $rid = bin2hex(random_bytes(4));
    [$locId, $locName] = bla_location($rid);
    bla_pdo()->prepare('INSERT INTO departments (code, name) VALUES (?, ?)')->execute(["BLAD-$rid", "BLA Dept $rid"]);
    $deptId = (int) bla_pdo()->lastInsertId();
    bla_pdo()->prepare('INSERT INTO departments (code, name) VALUES (?, ?)')->execute(["BLAE-$rid", "BLA Empty $rid"]);
    $emptyDept = (int) bla_pdo()->lastInsertId();
    $admin = ['id' => 4, 'role' => 'admin'];
    $ids = [];

    try {
        $ids[] = bla_ticket("BLZ-$rid", $locId, 0, 'in_progress', 3, $deptId); // created today → age 0, still open

        $page = bla_service()->getBacklogAgingReportPage($admin, ['dimension' => 'location', 'department_id' => $deptId]);
        $row = null;
        foreach ($page['rows'] as $r) {
            if ($r['label'] === $locName) {
                $row = $r;
                break;
            }
        }
        assert_true($row !== null, 'the fresh backlog location appears');
        assert_same(1, $row['total'], 'one open ticket');
        assert_same(0, (int) $row['oldest_days'], 'aged 0 days');
        assert_same('0 วัน', $row['oldest_label'], 'a real 0-day-old backlog row shows "0 วัน", not "-"');
        assert_same('0 วัน', $page['summary']['oldest_label'], 'summary with 0-day backlog shows "0 วัน", not "-"');

        // truly empty scope (a department with no open tickets) → "-", NOT "0 วัน"
        $empty = bla_service()->getBacklogAgingReportPage($admin, ['dimension' => 'location', 'department_id' => $emptyDept])['summary'];
        assert_same(0, $empty['total'], 'no backlog in the empty scope');
        assert_same('-', $empty['oldest_label'], 'no backlog → "-" (not "0 วัน")');
    } finally {
        foreach ($ids as $id) {
            bla_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        bla_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
        bla_pdo()->prepare('DELETE FROM departments WHERE id IN (?, ?)')->execute([$deptId, $emptyDept]);
    }
});

test('backlog aging: a future requested_at yields age 0, not a negative age (Finding G1)', function (): void {
    // A ticket with a future requested_at (clock skew / bad import) must not produce a negative age —
    // DATEDIFF would go below 0. Age is clamped to 0: the ticket sits in the youngest bucket and never
    // reports a negative "oldest".
    $rid = bin2hex(random_bytes(4));
    [$locId, $locName] = bla_location($rid);
    $ticketId = 0;

    try {
        bla_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, 4, ?, 1, 1, 3, 'in_progress', ?)"
        )->execute(["BLF-$rid", $locId, date('Y-m-d H:i:s', (int) strtotime('+10 days'))]);
        $ticketId = (int) bla_pdo()->lastInsertId();

        $row = bla_row('location', $locName);
        assert_true($row !== null, 'location appears');
        assert_same(1, $row['total'], 'the future ticket is counted');
        assert_same(1, $row['bucket_0_3'], 'a not-yet-aged ticket sits in the youngest bucket');
        assert_same(0, (int) $row['oldest_days'], 'age is clamped to 0 — never negative');
    } finally {
        if ($ticketId > 0) {
            bla_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        bla_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('backlog aging: dimension labels — status→Thai, null technician/department bucketed', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$locId] = bla_location($rid);
    $ticketId = 0;

    try {
        $ticketId = bla_ticket("BLA-$rid", $locId, 10, 'in_progress', null, null); // tech NULL, dept NULL

        assert_true(bla_row('status', 'กำลังดำเนินการ') !== null, 'status dimension shows Thai label');
        $tech = bla_row('technician', 'ยังไม่มอบหมาย');
        assert_true($tech !== null && $tech['total'] >= 1, 'null technician → ยังไม่มอบหมาย');
        $dept = bla_row('department', 'ไม่ระบุแผนก');
        assert_true($dept !== null && $dept['total'] >= 1, 'null department → ไม่ระบุแผนก');
    } finally {
        if ($ticketId > 0) {
            bla_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        bla_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('backlog aging: rows sorted by >30 desc + total invariant across dimensions', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    [$hiId, $hiName] = bla_location($rid, 'HI');
    [$loId, $loName] = bla_location($rid, 'LO');
    $ids = [];

    try {
        $ids[] = bla_ticket("BLAH-$rid-1", $hiId, 40); // >30
        $ids[] = bla_ticket("BLAH-$rid-2", $hiId, 45); // >30
        $ids[] = bla_ticket("BLAL-$rid", $loId, 2);    // 0-3, no >30

        $codes = array_column(bla_service()->getBacklogAgingReportPage($admin, ['dimension' => 'location'])['rows'], 'label');
        $hiPos = array_search($hiName, $codes, true);
        $loPos = array_search($loName, $codes, true);
        assert_true($hiPos !== false && $loPos !== false, 'both fresh locations appear');
        assert_true($hiPos < $loPos, 'location with 2× >30 sorts above the one with none');

        $byPriority = bla_service()->getBacklogAgingReportPage($admin, ['dimension' => 'priority'])['summary']['total'];
        $byLocation = bla_service()->getBacklogAgingReportPage($admin, ['dimension' => 'location'])['summary']['total'];
        assert_same($byPriority, $byLocation, 'total backlog is identical regardless of dimension');
    } finally {
        foreach ($ids as $id) {
            bla_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        bla_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$hiId]);
        bla_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$loId]);
    }
});

test('backlog aging: two technicians with the same full_name stay as separate rows (GROUP BY dim.id, not label)', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$locId] = bla_location($rid);
    $dupName = "ช่างชื่อซ้ำ $rid";
    $ids = [];
    $userIds = [];

    try {
        bla_pdo()->prepare(
            'INSERT INTO users (username, email, password_hash, full_name, role, is_active)
             VALUES (?, ?, "x", ?, "technician", 1), (?, ?, "x", ?, "technician", 1)'
        )->execute(["bu1$rid", "bu1$rid@x.t", $dupName, "bu2$rid", "bu2$rid@x.t", $dupName]);
        $userIds[] = $u1 = (int) bla_pdo()->query("SELECT id FROM users WHERE username = 'bu1$rid'")->fetchColumn();
        $userIds[] = $u2 = (int) bla_pdo()->query("SELECT id FROM users WHERE username = 'bu2$rid'")->fetchColumn();

        $ids[] = bla_ticket("BLD-$rid-1", $locId, 5, 'in_progress', $u1);
        $ids[] = bla_ticket("BLD-$rid-2", $locId, 5, 'in_progress', $u2);

        $rows = bla_service()->getBacklogAgingReportPage(['id' => 4, 'role' => 'admin'], ['dimension' => 'technician'])['rows'];
        $dupRows = array_values(array_filter($rows, static fn (array $r): bool => $r['label'] === $dupName));
        assert_same(2, count($dupRows), 'two same-named technicians produce two distinct rows, not one merged row');
        assert_same(1, $dupRows[0]['total'], 'each technician keeps their own backlog count (not summed into one)');
        assert_same(1, $dupRows[1]['total'], 'second same-named technician is a separate row');
    } finally {
        foreach ($ids as $id) {
            bla_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        foreach ($userIds as $id) {
            bla_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
        bla_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('backlog aging: export xlsx (1 sheet + dimension header) / pdf %PDF- / csv header', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) bla_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'bla_') . '.xlsx';

    try {
        $export = bla_service()->exportBacklogAgingExcel($admin, ['dimension' => 'status']);
        file_put_contents($tmp, (string) $export['content']);
        $book = IOFactory::createReader('Xlsx')->load($tmp);
        assert_same(1, $book->getSheetCount(), 'single sheet');
        assert_same('งานค้างตามอายุ', $book->getSheetNames()[0], 'sheet title');
        assert_same('สถานะ', (string) $book->getActiveSheet()->getCell('A1')->getValue(), 'first header = dimension label (status)');
        assert_same('≥30 วัน', (string) $book->getActiveSheet()->getCell('E1')->getValue(), '≥30 bucket column (label matches the query boundary, R20-F2)');
        $book->disconnectWorksheets();

        $pdf = bla_service()->exportBacklogAgingPdf($admin, ['dimension' => 'priority']);
        assert_same('%PDF-', substr((string) $pdf['content'], 0, 5), 'pdf magic bytes');

        $csv = (string) bla_service()->exportBacklogAgingCsv($admin, ['dimension' => 'technician'])['content'];
        assert_true(str_contains($csv, 'ช่าง'), 'csv first header reflects the technician dimension');
        assert_true(str_contains($csv, 'เก่าสุด (วัน)'), 'csv carries the oldest column');
    } finally {
        @unlink($tmp);
        bla_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});
