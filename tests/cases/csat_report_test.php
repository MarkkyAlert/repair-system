<?php
declare(strict_types=1);

use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Tests for the CSAT / satisfaction report (/reports/csat). Base = ticket_ratings, now one row per
// lifecycle CYCLE (F1 Phase 2: UNIQUE(ticket_id, cycle)); this CSAT-BY-PERIOD report windows on
// tr.created_at, so each cycle's rating counts in the period it was given — as-reported. A single-cycle
// ticket still contributes exactly one row (the pre-existing fixtures below). Proves per-dimension
// avg/count/%satisfied/%dissatisfied/tone, per-cycle period immutability, the
// summary average computed as Σscore/Σcount (NOT average-of-averages), worst-first sort, the 1–5 score
// distribution (missing buckets filled with 0), the feedback list (non-empty only, worst-score-first),
// Thai/null technician label, the resolved-total invariant across dimensions, and the exports (CSV/PDF =
// breakdown, Excel = 2 sheets). Every test scopes to a FRESH department (department_id filter) so summary/
// distribution/feedback assertions are exact despite baseline rows in the shared test DB.

function csat_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function csat_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

function csat_department(string $rid): int
{
    csat_pdo()->prepare('INSERT INTO departments (code, name) VALUES (?, ?)')->execute(["CSATD-$rid", "CSAT Dept $rid"]);

    return (int) csat_pdo()->lastInsertId();
}

function csat_location(string $rid, string $suffix = ''): array
{
    $name = "CSAT Loc $rid$suffix";
    csat_pdo()->prepare('INSERT INTO locations (code, name) VALUES (?, ?)')->execute(["CSATL-$rid$suffix", $name]);

    return [(int) csat_pdo()->lastInsertId(), $name];
}

/** Rated ticket in $deptId/$locId + its ticket_ratings row; returns ticket id. score/tech/feedback per test. */
function csat_rate(string $no, int $deptId, int $locId, ?int $tech, int $score, ?string $feedback = null): int
{
    csat_pdo()->prepare(
        "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at)
         VALUES (?, 'x', 'x', 1, ?, ?, 1, 1, ?, 'resolved', NOW())"
    )->execute([$no, $deptId, $locId, $tech]);
    $ticketId = (int) csat_pdo()->lastInsertId();

    csat_pdo()->prepare(
        'INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, score, feedback) VALUES (?, 1, ?, ?, ?)'
    )->execute([$ticketId, $tech, $score, $feedback]);

    return $ticketId;
}

/** Remove every ticket (ratings cascade) + location + department a test created, in FK-safe order. */
function csat_cleanup(array $ticketIds, array $locationIds, int $deptId): void
{
    foreach ($ticketIds as $id) {
        csat_pdo()->prepare('DELETE FROM ticket_ratings WHERE ticket_id = ?')->execute([$id]);
        csat_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
    }
    foreach ($locationIds as $id) {
        csat_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$id]);
    }
    csat_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
}

function csat_page(string $dimension, int $deptId, array $extra = []): array
{
    return csat_service()->getCsatReportPage(['id' => 4, 'role' => 'admin'], ['dimension' => $dimension, 'department_id' => $deptId] + $extra);
}

function csat_row(string $dimension, int $deptId, string $label): ?array
{
    foreach (csat_page($dimension, $deptId)['rows'] as $row) {
        if ($row['label'] === $label) {
            return $row;
        }
    }

    return null;
}

test('csat: a re-rate (cycle 2) does not restate the earlier period CSAT — per-cycle as-reported (F1 Phase 2)', function (): void {
    // Owner-settled as-reported: a re-rate after a reopen APPENDS a new cycle's rating (its own created_at)
    // instead of overwriting the previous cycle's row. So a past period's CSAT — this report windows on the
    // rating date — is immutable: the January review still reads 5.00 even after a February re-rate drops the
    // ticket's current satisfaction to 2. (Reconciliation-style: the two cycles are seeded by direct INSERT.)
    $rid = bin2hex(random_bytes(4));
    $deptId = csat_department($rid);
    [$locId, $locName] = csat_location($rid);
    $ticketId = 0;

    try {
        csat_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, ?, ?, 1, 1, 3, 'resolved', '2021-01-10 09:00:00')"
        )->execute(["CSATC-$rid", $deptId, $locId]);
        $ticketId = (int) csat_pdo()->lastInsertId();
        // cycle 1 rated 5 in January; cycle 2 re-rated 2 in February — both rows survive (append, not overwrite)
        csat_pdo()->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, cycle, score, created_at, updated_at) VALUES (?, 1, 3, 1, 5, ?, ?)')
            ->execute([$ticketId, '2021-01-10 12:00:00', '2021-01-10 12:00:00']);
        csat_pdo()->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, cycle, score, created_at, updated_at) VALUES (?, 1, 3, 2, 2, ?, ?)')
            ->execute([$ticketId, '2021-02-15 12:00:00', '2021-02-15 12:00:00']);

        $janRow = null;
        foreach (csat_page('location', $deptId, ['from_date' => '2021-01-01', 'to_date' => '2021-01-31'])['rows'] as $r) {
            if ($r['label'] === $locName) {
                $janRow = $r;
            }
        }
        assert_true($janRow !== null, 'January window sees the location');
        assert_same(1, $janRow['rating_count'], 'January counts only the cycle-1 rating');
        assert_same('5.00', $janRow['avg_label'], 'January CSAT is frozen at 5.00 — the February re-rate did not restate it');

        $febRow = null;
        foreach (csat_page('location', $deptId, ['from_date' => '2021-02-01', 'to_date' => '2021-02-28'])['rows'] as $r) {
            if ($r['label'] === $locName) {
                $febRow = $r;
            }
        }
        assert_true($febRow !== null, 'February window sees the location');
        assert_same(1, $febRow['rating_count'], 'February counts only the cycle-2 rating');
        assert_same('2.00', $febRow['avg_label'], 'February CSAT reflects the new cycle-2 review (2.00)');
    } finally {
        csat_pdo()->prepare('DELETE FROM ticket_ratings WHERE ticket_id = ?')->execute([$ticketId]);
        csat_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        csat_cleanup([], [$locId], $deptId);
    }
});

test('csat: per-dimension avg / count / %satisfied / %dissatisfied / tone', function (): void {
    $rid = bin2hex(random_bytes(4));
    $deptId = csat_department($rid);
    [$locId, $locName] = csat_location($rid);
    $ids = [];

    try {
        $ids[] = csat_rate("CSAT-$rid-a", $deptId, $locId, 3, 5);              // ≥4 → satisfied
        $ids[] = csat_rate("CSAT-$rid-b", $deptId, $locId, 3, 1, 'แย่มาก');    // ≤2 → dissatisfied

        $row = csat_row('location', $deptId, $locName);
        assert_true($row !== null, 'location appears');
        assert_same(2, $row['rating_count'], '2 reviews');
        assert_same('3.00', $row['avg_label'], 'avg = (5+1)/2 = 3.00');
        assert_same('50.0%', $row['satisfied_pct_label'], '1 of 2 is ≥4★');
        assert_same('50.0%', $row['dissatisfied_pct_label'], '1 of 2 is ≤2★');
        assert_same('warning', $row['csat_tone'], 'avg 3.00 → warning');
    } finally {
        csat_cleanup($ids, [$locId], $deptId);
    }
});

test('csat: summary avg is Σscore/Σcount, not average-of-averages + worst-first sort', function (): void {
    $rid = bin2hex(random_bytes(4));
    $deptId = csat_department($rid);
    [$locId] = csat_location($rid);
    $ids = [];

    try {
        // technician 1: one 2★ ; technician 3: three 4★. avg-of-avg = (2+4)/2 = 3.00; Σscore/Σcount = 14/4 = 3.50.
        $ids[] = csat_rate("CSAT-$rid-1", $deptId, $locId, 1, 2);
        $ids[] = csat_rate("CSAT-$rid-2", $deptId, $locId, 3, 4);
        $ids[] = csat_rate("CSAT-$rid-3", $deptId, $locId, 3, 4);
        $ids[] = csat_rate("CSAT-$rid-4", $deptId, $locId, 3, 4);

        $page = csat_page('technician', $deptId);
        assert_same('3.50', $page['summary']['avg_label'], 'summary avg weights by review count (14/4), not 3.00');
        assert_same(4, $page['summary']['rating_count'], '4 reviews total');

        $rows = $page['rows'];
        assert_true(count($rows) === 2, 'two technician rows');
        assert_true($rows[0]['avg_score'] < $rows[1]['avg_score'], 'lower avg (2.00) sorts above higher (4.00)');
        assert_same('2.00', $rows[0]['avg_label'], 'worst technician on top');
    } finally {
        csat_cleanup($ids, [$locId], $deptId);
    }
});

test('csat: score distribution buckets 1–5 with missing scores filled as 0', function (): void {
    $rid = bin2hex(random_bytes(4));
    $deptId = csat_department($rid);
    [$locId] = csat_location($rid);
    $ids = [];

    try {
        $ids[] = csat_rate("CSAT-$rid-a", $deptId, $locId, 3, 5);
        $ids[] = csat_rate("CSAT-$rid-b", $deptId, $locId, 3, 5);
        $ids[] = csat_rate("CSAT-$rid-c", $deptId, $locId, 3, 3);
        $ids[] = csat_rate("CSAT-$rid-d", $deptId, $locId, 3, 1);

        $dist = csat_page('location', $deptId)['distribution'];
        $byScore = [];
        foreach ($dist as $b) {
            $byScore[$b['score']] = $b['count'];
        }
        assert_same([5, 4, 3, 2, 1], array_column($dist, 'score'), 'buckets ordered 5→1');
        assert_same(2, $byScore[5], 'two 5★');
        assert_same(0, $byScore[4], 'no 4★ → bucket present as 0');
        assert_same(1, $byScore[3], 'one 3★');
        assert_same(0, $byScore[2], 'no 2★ → 0');
        assert_same(1, $byScore[1], 'one 1★');
        assert_same('50.0%', $dist[0]['pct_label'], '5★ share = 2/4 = 50.0%');
        assert_same(4, array_sum(array_column($dist, 'count')), 'distribution total = review count');
    } finally {
        csat_cleanup($ids, [$locId], $deptId);
    }
});

test('csat: feedback list — non-empty only, worst-score-first', function (): void {
    $rid = bin2hex(random_bytes(4));
    $deptId = csat_department($rid);
    [$locId] = csat_location($rid);
    $ids = [];

    try {
        $ids[] = csat_rate("CSAT-$rid-a", $deptId, $locId, 3, 5, null);        // no feedback → excluded
        $ids[] = csat_rate("CSAT-$rid-b", $deptId, $locId, 3, 4, 'ดีมาก');
        $ids[] = csat_rate("CSAT-$rid-c", $deptId, $locId, 3, 1, 'ช้าและแย่');

        $feedback = csat_page('technician', $deptId)['feedback'];
        assert_same(2, count($feedback), 'only the 2 rows with non-empty feedback appear');
        assert_same(1, $feedback[0]['score'], 'worst score first');
        assert_same('ช้าและแย่', $feedback[0]['feedback'], 'lowest-score comment carried verbatim');
        assert_same('danger', $feedback[0]['tone'], '1★ → danger tone');
        assert_same(4, $feedback[1]['score'], 'higher score after');
    } finally {
        csat_cleanup($ids, [$locId], $deptId);
    }
});

test('csat: null technician → ไม่ระบุช่าง + Σcount/Σscore invariant across dimensions', function (): void {
    $rid = bin2hex(random_bytes(4));
    $deptId = csat_department($rid);
    [$locId] = csat_location($rid);
    $ids = [];

    try {
        $ids[] = csat_rate("CSAT-$rid-a", $deptId, $locId, null, 4); // technician NULL
        $ids[] = csat_rate("CSAT-$rid-b", $deptId, $locId, 3, 2);

        assert_true(csat_row('technician', $deptId, 'ไม่ระบุช่าง') !== null, 'null technician → ไม่ระบุช่าง');

        $totals = [];
        foreach (['technician', 'category', 'priority', 'department', 'location'] as $dim) {
            $page = csat_page($dim, $deptId);
            $totals[$dim] = [$page['summary']['rating_count'], (int) array_sum(array_column($page['rows'], 'score_sum'))];
        }
        assert_same(1, count(array_unique(array_map('json_encode', $totals))), 'Σcount + Σscore identical across all dimensions');
    } finally {
        csat_cleanup($ids, [$locId], $deptId);
    }
});

test('csat: excel feedback sheet returns more than the 100-row on-screen cap', function (): void {
    $rid = bin2hex(random_bytes(4));
    $deptId = csat_department($rid);
    [$locId] = csat_location($rid);
    $ids = [];
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) csat_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'csat_') . '.xlsx';

    try {
        // 101 rated tickets each with feedback → page is capped at 100, Excel must carry all 101.
        for ($i = 0; $i < 101; $i++) {
            $ids[] = csat_rate("CSAT-$rid-$i", $deptId, $locId, 3, 3, "ความเห็น $i");
        }
        $filters = ['dimension' => 'technician', 'department_id' => $deptId];

        assert_same(100, count(csat_page('technician', $deptId)['feedback']), 'on-screen feedback capped at 100');

        file_put_contents($tmp, (string) csat_service()->exportCsatExcel($admin, $filters)['content']);
        $book = IOFactory::createReader('Xlsx')->load($tmp);
        $dataRows = $book->getSheet(1)->getHighestDataRow() - 1; // minus header row
        assert_same(101, $dataRows, 'Excel feedback sheet carries all 101 comments (beyond the page cap)');
        $book->disconnectWorksheets();
    } finally {
        @unlink($tmp);
        csat_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        csat_cleanup($ids, [$locId], $deptId);
    }
});

test('csat: date window is inclusive of the whole to_date (23:59:59), exclusive of the next day (round-3 gap B)', function (): void {
    // CSAT filters on tr.created_at with from_datetime/to_datetime, so the window must cover the FULL to_date
    // (…23:59:59), not stop at its midnight. Locks that both edges are inclusive and the next day is out.
    $rid = bin2hex(random_bytes(4));
    $deptId = csat_department($rid);
    [$locId, $locName] = csat_location($rid);
    $day = '2021-06-15';
    $ids = [];

    try {
        $rate_at = function (string $suffix, string $createdAt) use ($rid, $deptId, $locId, &$ids): void {
            csat_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, status, requested_at)
                 VALUES (?, 'x', 'x', 1, ?, ?, 1, 1, 'resolved', ?)"
            )->execute(["CSATB-$rid-$suffix", $deptId, $locId, $createdAt]);
            $tid = (int) csat_pdo()->lastInsertId();
            csat_pdo()->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, score, created_at) VALUES (?, 1, 5, ?)')->execute([$tid, $createdAt]);
            $ids[] = $tid;
        };
        $rate_at('start', "$day 00:00:00");      // exactly at from → in
        $rate_at('end', "$day 23:59:59");        // exactly at to (end of day) → in
        $rate_at('next', '2021-06-16 00:00:00'); // first instant of the next day → out

        $row = null;
        foreach (csat_page('location', $deptId, ['from_date' => $day, 'to_date' => $day])['rows'] as $r) {
            if ($r['label'] === $locName) {
                $row = $r;
                break;
            }
        }
        assert_true($row !== null, 'the location is in-window');
        assert_same(2, $row['rating_count'], 'the 00:00:00 and 23:59:59 ratings are in-window; the next-day 00:00:00 is excluded');
    } finally {
        csat_cleanup($ids, [$locId], $deptId);
    }
});

test('csat: XLSX breakdown %satisfied/%dissatisfied are numeric so they pivot/sum, not text (Finding #4 — #2 gap)', function (): void {
    // exportCsatExcel built Sheet 1 by hand with fromArray(), bypassing the shared numeric writer, so the
    // CSAT percentage columns landed as text — a manager couldn't pivot/chart %พอใจ. Route it through the
    // same writer as every other export. (BI-review round-2 #4.)
    $rid = bin2hex(random_bytes(4));
    $deptId = csat_department($rid);
    [$locId, $locName] = csat_location($rid);
    $ids = [];
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) csat_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        $ids[] = csat_rate("CSATX-$rid", $deptId, $locId, 3, 5); // one 5★ → satisfied 100.0%, dissatisfied 0.0%

        $tmp = tempnam(sys_get_temp_dir(), 'csatx_') . '.xlsx';
        file_put_contents($tmp, (string) csat_service()->exportCsatExcel($admin, ['dimension' => 'location', 'department_id' => $deptId])['content']);
        $sheet = IOFactory::createReader('Xlsx')->load($tmp)->getSheet(0);
        @unlink($tmp);

        assert_same($locName, (string) $sheet->getCell('A2')->getValue(), 'breakdown row 2 = our location');
        // headers: dim, คะแนนเฉลี่ย, จำนวนรีวิว, %พอใจ(≥4★), %ไม่พอใจ(≤2★) → D=satisfied, E=dissatisfied
        assert_same(DataType::TYPE_NUMERIC, $sheet->getCell('D2')->getDataType(), '%พอใจ is numeric, not text');
        assert_same(1.0, $sheet->getCell('D2')->getValue(), '"100.0%" stored as 1.0');
        assert_same('0.0%', $sheet->getStyle('D2')->getNumberFormat()->getFormatCode(), '%พอใจ displays as a percentage');
        assert_same(DataType::TYPE_NUMERIC, $sheet->getCell('E2')->getDataType(), '%ไม่พอใจ is numeric');
        assert_same(0.0, $sheet->getCell('E2')->getValue(), '"0.0%" stored as 0.0');
        assert_same(5, (int) $sheet->getCell('B2')->getValue(), 'avg stays numeric');
        assert_same(1, (int) $sheet->getCell('C2')->getValue(), 'count stays numeric');
    } finally {
        csat_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        csat_cleanup($ids, [$locId], $deptId);
    }
});

test('csat: empty range shows no score distribution — no misleading 0.0% buckets (round-2 #5)', function (): void {
    // With zero ratings, buildCsatDistribution used to emit 5 buckets at "0.0%" and the PDF rendered them,
    // so a future/empty window looked like a real "everyone rated 0%" distribution. The screen already
    // hid it (rating_count > 0 guard); the PDF did not. Base=0 → no distribution at all. (BI-review round-2 #5.)
    $svc = csat_service();
    $admin = ['id' => 4, 'role' => 'admin'];

    // service: base 0 → empty distribution (not 5× "0.0%")
    assert_same([], call_private($svc, 'buildCsatDistribution', [$admin, [], 0]), 'no ratings → empty distribution');

    // PDF artifact: a far-future window has no ratings → the distribution section must not render
    $baselineJobId = (int) csat_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    try {
        $future = date('Y-m-d', strtotime('+2 years'));
        $pdf = (string) $svc->exportCsatPdf($admin, ['from_date' => $future, 'to_date' => $future])['content'];
        assert_same('%PDF-', substr($pdf, 0, 5), 'a valid PDF is produced for an empty range');
        $text = (new \Smalot\PdfParser\Parser())->parseContent($pdf)->getText();
        assert_true(mb_strpos($text, 'การกระจายคะแนน') === false, 'empty CSAT PDF hides the score-distribution section (no 0.0%×5)');
    } finally {
        csat_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }

    // inverse: a window WITH ratings still shows the distribution — the guard must not over-hide
    $rid = bin2hex(random_bytes(4));
    $deptId = csat_department($rid);
    [$locId] = csat_location($rid);
    $ids = [];
    $base2 = (int) csat_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    try {
        $ids[] = csat_rate("CSATD-$rid", $deptId, $locId, 3, 5);
        $pdf2 = (string) $svc->exportCsatPdf($admin, ['department_id' => $deptId])['content'];
        $text2 = (new \Smalot\PdfParser\Parser())->parseContent($pdf2)->getText();
        assert_true(mb_strpos($text2, 'การกระจายคะแนน') !== false, 'a rated CSAT PDF still renders the distribution (guard did not over-hide)');
    } finally {
        csat_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$base2]);
        csat_cleanup($ids, [$locId], $deptId);
    }
});

test('csat: export csv/pdf = breakdown, excel = 2 sheets (breakdown + feedback)', function (): void {
    $rid = bin2hex(random_bytes(4));
    $deptId = csat_department($rid);
    [$locId] = csat_location($rid);
    $ids = [];
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) csat_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'csat_') . '.xlsx';

    try {
        $ids[] = csat_rate("CSAT-$rid-a", $deptId, $locId, 3, 2, 'ปรับปรุงด้วย');
        $filters = ['dimension' => 'technician', 'department_id' => $deptId];

        file_put_contents($tmp, (string) csat_service()->exportCsatExcel($admin, $filters)['content']);
        $book = IOFactory::createReader('Xlsx')->load($tmp);
        assert_same(2, $book->getSheetCount(), 'two sheets');
        assert_same('ความพึงพอใจ', $book->getSheetNames()[0], 'sheet 1 = breakdown');
        assert_same('ความคิดเห็น', $book->getSheetNames()[1], 'sheet 2 = feedback (Thai title)');
        assert_same('ช่าง', (string) $book->getSheet(0)->getCell('A1')->getValue(), 'breakdown first header = dimension');
        assert_same('เลขที่ Ticket', (string) $book->getSheet(1)->getCell('A1')->getValue(), 'feedback first header = ticket number');
        assert_same('ความคิดเห็น', (string) $book->getSheet(1)->getCell('C1')->getValue(), 'feedback carries the comment column');
        $book->disconnectWorksheets();

        $pdf = csat_service()->exportCsatPdf($admin, $filters);
        assert_same('%PDF-', substr((string) $pdf['content'], 0, 5), 'pdf magic bytes');

        $csv = (string) csat_service()->exportCsatCsv($admin, $filters)['content'];
        assert_true(str_contains($csv, 'คะแนนเฉลี่ย'), 'csv breakdown carries the average column');
    } finally {
        @unlink($tmp);
        csat_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        csat_cleanup($ids, [$locId], $deptId);
    }
});

test('csat export: exported breakdown cells equal the on-screen values (parity — Finding G2)', function (): void {
    // Locks that the export carries the SAME numbers as the page (not a separately-recomputed value).
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $deptId = csat_department($rid);
    $deptName = "CSAT Dept $rid";
    [$locId, ] = csat_location($rid);
    $ids = [];
    $tmp = tempnam(sys_get_temp_dir(), 'csatpar_') . '.xlsx';
    $baselineJobId = (int) csat_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        $ids[] = csat_rate("CSATPAR-$rid-1", $deptId, $locId, 3, 4);
        $ids[] = csat_rate("CSATPAR-$rid-2", $deptId, $locId, 3, 5);

        // on-screen values for this department's row
        $row = csat_row('department', $deptId, $deptName);
        assert_true($row !== null, 'department row present on the page');
        assert_same('4.50', $row['avg_label'], 'page avg = (4+5)/2 = 4.50');
        assert_same(2, (int) $row['rating_count'], 'page rating_count = 2');

        // export the SAME view and read the row back (breakdown sheet: col A=label, B=avg, C=count)
        file_put_contents($tmp, (string) csat_service()->exportCsatExcel($admin, ['dimension' => 'department', 'department_id' => $deptId])['content']);
        $sheet = IOFactory::createReader('Xlsx')->load($tmp)->getSheetByName('ความพึงพอใจ');
        $exported = null;
        foreach ($sheet->toArray() as $line) {
            if ((string) ($line[0] ?? '') === $deptName) {
                $exported = $line;
            }
        }
        assert_true($exported !== null, 'department row present in the export');
        assert_same((float) $row['avg_label'], (float) $exported[1], 'export avg cell == page avg');
        assert_same((int) $row['rating_count'], (int) $exported[2], 'export count cell == page rating_count');
    } finally {
        @unlink($tmp);
        csat_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        foreach ($ids as $id) {
            csat_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        csat_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
        csat_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});
