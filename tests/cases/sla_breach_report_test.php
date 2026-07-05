<?php
declare(strict_types=1);

use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Tests for the SLA Breach Analysis report (/reports/sla-breach): breach counting per (dimension ×
// metric_type), the pending-past-due = breach rule, dimension switching (category/department/…),
// null-department bucketing, and the dedicated CSV/Excel/PDF export. Each test isolates itself with a
// FRESH category/location/department so its dimension row is exact regardless of other seed data.

function slab_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function slab_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

/** Fresh category/location/department for isolation → returns [catId, locId, deptId, suffix]. */
function slab_dims(string $rid): array
{
    slab_pdo()->prepare("INSERT INTO ticket_categories (code, name, is_active, sort_order) VALUES (?, ?, 1, 1)")
        ->execute(["SLABC-$rid", "SLAB Cat $rid"]);
    $catId = (int) slab_pdo()->lastInsertId();
    slab_pdo()->prepare("INSERT INTO locations (code, name) VALUES (?, ?)")->execute(["SLABL-$rid", "SLAB Loc $rid"]);
    $locId = (int) slab_pdo()->lastInsertId();
    // NOTE: the test DB's departments table has a NOT NULL code column (dev DB does not) — supply it.
    slab_pdo()->prepare("INSERT INTO departments (code, name, is_active) VALUES (?, ?, 1)")->execute(["SLABD-$rid", "SLAB Dept $rid"]);
    $deptId = (int) slab_pdo()->lastInsertId();

    return [$catId, $locId, $deptId];
}

function slab_cleanup(int $ticketId, int $catId, int $locId, int $deptId): void
{
    if ($ticketId > 0) {
        slab_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // sla_tracks cascade
    }
    if ($catId > 0) {
        slab_pdo()->prepare('DELETE FROM ticket_categories WHERE id = ?')->execute([$catId]);
    }
    if ($locId > 0) {
        slab_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
    if ($deptId > 0) {
        slab_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
}

/** Find the report row whose dimension label matches, for the given dimension. */
function slab_row(string $dimension, string $label): ?array
{
    $page = slab_service()->getSlaBreachReportPage(['id' => 4, 'role' => 'admin'], ['dimension' => $dimension]);
    foreach ($page['rows'] as $row) {
        if ($row['label'] === $label) {
            return $row;
        }
    }

    return null;
}

test('sla breach: counting + response/resolution pivot per dimension value', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$catId, $locId, $deptId] = slab_dims($rid);
    $ticketId = 0;

    try {
        slab_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, ?, ?, ?, 1, 'in_progress', NOW())"
        )->execute(["SLABT-$rid", $deptId, $locId, $catId]);
        $ticketId = (int) slab_pdo()->lastInsertId();

        // response = met (on time), resolution = breached
        slab_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, achieved_at, status) VALUES (?, 'response', ?, ?, 'met')")
            ->execute([$ticketId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() - 7200)]);
        slab_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, breached_at, status) VALUES (?, 'resolution', ?, ?, 'breached')")
            ->execute([$ticketId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s')]);

        $row = slab_row('category', "SLAB Cat $rid");
        assert_true($row !== null, 'fresh category appears in breach report');
        assert_same(0, $row['response']['breached'], 'response breached = 0 (was met)');
        assert_same(1, $row['response']['met'], 'response met = 1');
        assert_same(1, $row['resolution']['breached'], 'resolution breached = 1');
        assert_same(1, $row['total_breached'], 'total breached = 1');
        assert_same(1, $row['total_met'], 'total met = 1');
        assert_same('50.0%', $row['breach_rate_label'], 'breach rate = 1/2 = 50%');
    } finally {
        slab_cleanup($ticketId, $catId, $locId, $deptId);
    }
});

test('sla breach: pending past-due counts as breach, pending not-yet-due excluded', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$catId, $locId, $deptId] = slab_dims($rid);
    $ticketId = 0;

    try {
        slab_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, ?, ?, ?, 1, 'in_progress', NOW())"
        )->execute(["SLABT-$rid", $deptId, $locId, $catId]);
        $ticketId = (int) slab_pdo()->lastInsertId();

        // response pending but NOT due yet (target future) → excluded ; resolution pending PAST due → breach
        slab_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, status) VALUES (?, 'response', ?, 'pending')")
            ->execute([$ticketId, date('Y-m-d H:i:s', time() + 7200)]);
        slab_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, status) VALUES (?, 'resolution', ?, 'pending')")
            ->execute([$ticketId, date('Y-m-d H:i:s', time() - 7200)]);

        $row = slab_row('category', "SLAB Cat $rid");
        assert_true($row !== null, 'category appears');
        assert_same(0, $row['response']['breached'], 'response pending-not-due is NOT a breach');
        assert_same(0, $row['response']['met'], 'response pending-not-due is not concluded');
        assert_same(1, $row['resolution']['breached'], 'resolution pending-past-due IS a breach');
        assert_same(1, $row['total_breached'], 'total breached = 1');
        assert_same('100.0%', $row['breach_rate_label'], 'only concluded track breached = 100%');
    } finally {
        slab_cleanup($ticketId, $catId, $locId, $deptId);
    }
});

test('sla breach: same ticket is grouped correctly under different dimensions', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$catId, $locId, $deptId] = slab_dims($rid);
    $ticketId = 0;

    try {
        slab_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, ?, ?, ?, 1, 'in_progress', NOW())"
        )->execute(["SLABT-$rid", $deptId, $locId, $catId]);
        $ticketId = (int) slab_pdo()->lastInsertId();
        slab_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, breached_at, status) VALUES (?, 'resolution', ?, ?, 'breached')")
            ->execute([$ticketId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s')]);

        $byCat = slab_row('category', "SLAB Cat $rid");
        $byDept = slab_row('department', "SLAB Dept $rid");
        $byLoc = slab_row('location', "SLAB Loc $rid");
        assert_same(1, $byCat['total_breached'] ?? -1, 'grouped by category → 1 breach');
        assert_same(1, $byDept['total_breached'] ?? -1, 'grouped by department → 1 breach');
        assert_same(1, $byLoc['total_breached'] ?? -1, 'grouped by location → 1 breach');
    } finally {
        slab_cleanup($ticketId, $catId, $locId, $deptId);
    }
});

test('sla breach: ticket with no department is bucketed as ไม่ระบุแผนก', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$catId, $locId] = slab_dims($rid);
    $ticketId = 0;

    try {
        slab_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, NULL, ?, ?, 1, 'in_progress', NOW())"
        )->execute(["SLABT-$rid", $locId, $catId]);
        $ticketId = (int) slab_pdo()->lastInsertId();
        slab_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, breached_at, status) VALUES (?, 'resolution', ?, ?, 'breached')")
            ->execute([$ticketId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s')]);

        $row = slab_row('department', 'ไม่ระบุแผนก');
        assert_true($row !== null, 'null-department tickets bucket under ไม่ระบุแผนก');
        assert_true($row['total_breached'] >= 1, 'the null-dept breach is counted there');
    } finally {
        slab_cleanup($ticketId, $catId, $locId, 0);
    }
});

test('sla breach: export xlsx (1 sheet + dimension header) / pdf %PDF- / csv header', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) slab_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'slab_') . '.xlsx';

    try {
        $export = slab_service()->exportSlaBreachExcel($admin, ['dimension' => 'priority']);
        file_put_contents($tmp, (string) $export['content']);
        $book = IOFactory::createReader('Xlsx')->load($tmp);
        assert_same(1, $book->getSheetCount(), 'single sheet');
        assert_same('SLA เกินกำหนด', $book->getSheetNames()[0], 'sheet title');
        assert_same('ระดับความสำคัญ', (string) $book->getActiveSheet()->getCell('A1')->getValue(), 'first header = dimension label (priority)');
        $book->disconnectWorksheets();

        $pdf = slab_service()->exportSlaBreachPdf($admin, ['dimension' => 'department']);
        assert_same('%PDF-', substr((string) $pdf['content'], 0, 5), 'pdf magic bytes');

        $csv = (string) slab_service()->exportSlaBreachCsv($admin, ['dimension' => 'location'])['content'];
        assert_true(str_contains($csv, 'สถานที่'), 'csv first header reflects the location dimension');
        assert_true(str_contains($csv, '%เกิน'), 'csv carries the breach-rate column');
    } finally {
        @unlink($tmp);
        slab_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});
