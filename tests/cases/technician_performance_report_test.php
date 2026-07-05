<?php
declare(strict_types=1);

use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Tests for the standalone Technician Workload & Performance report (/reports/technician-performance).
// The defining behaviour: each row mixes PERIOD performance (date-filtered: first-response, SLA on-time,
// assigned/resolved) with a LIVE workload snapshot (open_now / oldest / share) that must IGNORE the date
// filter. Also proves idle technicians still appear (base = all active techs) and terminal tickets are
// excluded from the live backlog. Fresh throwaway technicians give exact per-row assertions.

function tpr_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function tpr_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

/** Insert a throwaway active technician, return [id, fullName]. */
function tpr_tech(string $rid): array
{
    $fullName = "TPR Tech $rid";
    tpr_pdo()->prepare(
        "INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)"
    )->execute(["tpr_$rid", "tpr_$rid@example.com", $fullName]);

    return [(int) tpr_pdo()->lastInsertId(), $fullName];
}

function tpr_row(string $fullName, array $filters = []): ?array
{
    $page = tpr_service()->getTechnicianPerformanceReportPage(['id' => 4, 'role' => 'admin'], $filters);
    foreach ($page['rows'] as $row) {
        if ($row['full_name'] === $fullName) {
            return $row;
        }
    }

    return null;
}

function tpr_cleanup(int $techId): void
{
    if ($techId > 0) {
        tpr_pdo()->prepare('DELETE FROM tickets WHERE assigned_technician_id = ?')->execute([$techId]); // sla_tracks cascade
        tpr_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$techId]);
    }
}

test('technician performance: period first-response avg + per-tech SLA on-time rate', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$techId, $fullName] = tpr_tech($rid);

    try {
        $now = time();
        // A: responded 30min after request, resolved ON TIME (resolved_at <= resolution_due_at)
        tpr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, first_response_at, resolved_at, resolution_due_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'resolved', ?, ?, ?, ?)"
        )->execute([
            "TPRA-$rid", $techId,
            date('Y-m-d H:i:s', $now - 7200), date('Y-m-d H:i:s', $now - 7200 + 1800),
            date('Y-m-d H:i:s', $now - 3600), date('Y-m-d H:i:s', $now),
        ]);
        // B: responded 90min after request, resolved LATE (resolved_at > resolution_due_at)
        tpr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, first_response_at, resolved_at, resolution_due_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'resolved', ?, ?, ?, ?)"
        )->execute([
            "TPRB-$rid", $techId,
            date('Y-m-d H:i:s', $now - 10800), date('Y-m-d H:i:s', $now - 10800 + 5400),
            date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now - 3600),
        ]);

        $row = tpr_row($fullName);
        assert_true($row !== null, 'technician appears');
        assert_same(2, $row['assigned'], 'assigned = 2');
        assert_same(2, $row['resolved'], 'resolved = 2');
        assert_same('50.0%', $row['sla_on_time_label'], 'SLA on-time = 1 of 2 = 50%');
        assert_same('1.0', $row['first_response_hours_label'], 'first response avg (30+90)/2 = 60min = 1.0h');
    } finally {
        tpr_cleanup($techId);
    }
});

test('technician performance: live workload is a NOW snapshot, ignores date filter + excludes terminal', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$techId, $fullName] = tpr_tech($rid);

    try {
        // Open ticket requested 10 days ago (well outside any recent/future window)
        tpr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'in_progress', ?)"
        )->execute(["TPRO-$rid", $techId, date('Y-m-d H:i:s', time() - 10 * 86400)]);
        // Terminal ticket (closed) — must NOT count toward live backlog
        tpr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'closed', NOW())"
        )->execute(["TPRC-$rid", $techId]);

        // Query with a FUTURE window → period sees nothing, but the snapshot must be unchanged
        $row = tpr_row($fullName, ['from_date' => '2030-01-01', 'to_date' => '2030-01-02']);
        assert_true($row !== null, 'technician appears even with a period that matches no tickets');
        assert_same(1, $row['open_now'], 'open_now = 1 (in_progress counted, closed excluded, ignores date filter)');
        assert_same('10 วัน', $row['oldest_open_age_label'], 'oldest open age = 10 days');
        assert_same(0, $row['assigned'], 'period assigned = 0 under the future window');
    } finally {
        tpr_cleanup($techId);
    }
});

test('technician performance: idle technician still appears (base = all active techs)', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$techId, $fullName] = tpr_tech($rid);

    try {
        $row = tpr_row($fullName);
        assert_true($row !== null, 'idle technician with zero tickets still appears');
        assert_same(0, $row['open_now'], 'idle tech open_now = 0');
        assert_same(0, $row['assigned'], 'idle tech assigned = 0');
        assert_same('-', $row['completion_label'], 'idle tech completion = -');
        assert_same('-', $row['sla_on_time_label'], 'idle tech SLA = -');
    } finally {
        tpr_cleanup($techId);
    }
});

test('technician performance: heavier current load sorts above lighter', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$hiId, $hiName] = tpr_tech("hi$rid");
    [$loId, $loName] = tpr_tech("lo$rid");

    try {
        for ($i = 0; $i < 3; $i++) {
            tpr_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at)
                 VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'in_progress', NOW())"
            )->execute(["TPRH-$rid-$i", $hiId]);
        }
        tpr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'in_progress', NOW())"
        )->execute(["TPRL-$rid", $loId]);

        $rows = tpr_service()->getTechnicianPerformanceReportPage(['id' => 4, 'role' => 'admin'], [])['rows'];
        $names = array_column($rows, 'full_name');
        $hiPos = array_search($hiName, $names, true);
        $loPos = array_search($loName, $names, true);
        assert_true($hiPos !== false && $loPos !== false, 'both technicians appear');
        assert_true($hiPos < $loPos, 'the 3-open tech sorts above the 1-open tech');
    } finally {
        tpr_cleanup($hiId);
        tpr_cleanup($loId);
    }
});

test('technician performance: export xlsx (1 sheet + header) / pdf %PDF- / csv header', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) tpr_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'tpr_') . '.xlsx';

    try {
        $export = tpr_service()->exportTechnicianPerformanceExcel($admin, []);
        file_put_contents($tmp, (string) $export['content']);
        $book = IOFactory::createReader('Xlsx')->load($tmp);
        assert_same(1, $book->getSheetCount(), 'single sheet');
        assert_same('ผลงานทีมช่าง', $book->getSheetNames()[0], 'sheet title');
        assert_same('ช่าง', (string) $book->getActiveSheet()->getCell('A1')->getValue(), 'header cell A1');
        $book->disconnectWorksheets();

        $pdf = tpr_service()->exportTechnicianPerformancePdf($admin, []);
        assert_same('%PDF-', substr((string) $pdf['content'], 0, 5), 'pdf magic bytes');

        $csv = (string) tpr_service()->exportTechnicianPerformanceCsv($admin, [])['content'];
        assert_true(str_contains($csv, 'ช่าง'), 'csv keeps header');
        assert_true(str_contains($csv, 'งานค้างปัจจุบัน'), 'csv carries the live-backlog column');
    } finally {
        @unlink($tmp);
        tpr_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});
