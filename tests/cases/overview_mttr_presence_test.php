<?php
declare(strict_types=1);

use App\Services\ReportService;

// Overview report MTTR must tell "no resolved tickets" apart from "resolved in ~0 minutes":
//   - getSummary used COALESCE(AVG,0), so an empty period showed 0.0 h (looked like real fast work);
//   - mapReportRow gated on resolution_minutes > 0, so a same-minute resolve showed '-' (looked unresolved).
// Fixed with a resolution_base count (empty → '-') and a resolved-presence gate (same-minute → '0.0'),
// matching the empty≠zero + same-minute rules already used in the other report mappers. (BI-review round-2 #3.)

function omt_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function omt_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

function omt_dept(string $rid): int
{
    omt_pdo()->prepare('INSERT INTO departments (code, name) VALUES (?, ?)')->execute(["OMTD-$rid", "OMT Dept $rid"]);

    return (int) omt_pdo()->lastInsertId();
}

function omt_row(array $page, string $ticketNo): ?array
{
    foreach ($page['rows'] as $row) {
        if (($row['ticket_no'] ?? null) === $ticketNo) {
            return $row;
        }
    }

    return null;
}

test('overview MTTR: same-minute resolve shows 0.0 (row + summary), empty range shows - (round-2 #3)', function (): void {
    $rid = bin2hex(random_bytes(4));
    $deptId = omt_dept($rid);
    $admin = ['id' => 4, 'role' => 'admin'];
    $ids = [];
    $baselineJobId = (int) omt_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        // resolved in the SAME minute → resolution 0 min, but resolved_at IS set (a real, fast resolution)
        $now = date('Y-m-d H:i:s');
        omt_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, 1, 'resolved', ?, ?)"
        )->execute(["OMTT-$rid", $deptId, $now, $now]);
        $ids[] = (int) omt_pdo()->lastInsertId();

        $page = omt_service()->getReportPageData($admin, ['department_id' => $deptId]);
        assert_same('0.0', $page['summary']['avgResolutionHoursLabel'], 'summary MTTR = 0.0 (same-minute resolve, base > 0), not lost');
        $row = omt_row($page, "OMTT-$rid");
        assert_true($row !== null, 'the ticket appears in the overview rows');
        assert_same('0.0', $row['resolution_hours_label'], 'row MTTR = 0.0 (same-minute), not "-"');

        // empty (far-future) window → no resolved tickets → "-", NOT 0
        $future = date('Y-m-d', strtotime('+2 years'));
        $empty = omt_service()->getReportPageData($admin, ['department_id' => $deptId, 'from_date' => $future, 'to_date' => $future]);
        assert_same('-', $empty['summary']['avgResolutionHoursLabel'], 'empty period MTTR = "-", not 0');

        // the overview PDF (reports/pdf.php) reads the same label — render it to confirm the view uses it
        $pdf = (string) omt_service()->exportPdf($admin, ['department_id' => $deptId, 'from_date' => $future, 'to_date' => $future])['content'];
        assert_same('%PDF-', substr($pdf, 0, 5), 'overview PDF renders for an empty range via the MTTR label');
    } finally {
        omt_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        foreach ($ids as $id) {
            omt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        omt_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

test('overview MTTR: an unresolved ticket row shows - and no resolved tickets → summary - (round-2 #3)', function (): void {
    $rid = bin2hex(random_bytes(4));
    $deptId = omt_dept($rid);
    $admin = ['id' => 4, 'role' => 'admin'];
    $ids = [];

    try {
        omt_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, 1, 'in_progress', NOW())"
        )->execute(["OMTU-$rid", $deptId]);
        $ids[] = (int) omt_pdo()->lastInsertId();

        $page = omt_service()->getReportPageData($admin, ['department_id' => $deptId]);
        $row = omt_row($page, "OMTU-$rid");
        assert_true($row !== null, 'the unresolved ticket appears');
        assert_same('-', $row['resolution_hours_label'], 'unresolved row MTTR = "-" (never resolved)');
        assert_same('-', $page['summary']['avgResolutionHoursLabel'], 'no resolved tickets → summary MTTR "-"');
    } finally {
        foreach ($ids as $id) {
            omt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        omt_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});
