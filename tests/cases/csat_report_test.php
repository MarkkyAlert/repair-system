<?php
declare(strict_types=1);

use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Tests for the CSAT / satisfaction report (/reports/csat). Base = ticket_ratings (1:1 with tickets,
// UNIQUE(ticket_id) → no fan-out). Proves per-dimension avg/count/%satisfied/%dissatisfied/tone, the
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
        assert_same('feedback', $book->getSheetNames()[1], 'sheet 2 = feedback');
        assert_same('ช่าง', (string) $book->getSheet(0)->getCell('A1')->getValue(), 'breakdown first header = dimension');
        assert_same('Ticket', (string) $book->getSheet(1)->getCell('A1')->getValue(), 'feedback first header = Ticket');
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
