<?php
declare(strict_types=1);

use App\Services\ReportService;

// Tests for the Labor/Effort report + the labor column added to Asset Reliability. Crucially checks
// that the LEFT JOIN work_orders added to getAssetReliabilityRows does NOT inflate failure_count
// (work_orders is 1:1 with ticket), and that labor sums correctly per asset / per category.

function le_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function le_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

// F1 (logic review): reopen used to ZERO work_orders.labor_minutes and resolve REPLACED it, so the first
// repair's labor vanished from every labor metric (main-report ชั่วโมงแรงงาน, asset ชม.แรงงาน, hotspot) the
// moment a ticket was reopened. Labor already spent is real paid effort — as-reported, same principle as the
// frozen SLA/rating cycles: reopen must keep it and the next resolve must ADD its minutes on top.
// Driven through the REAL services (create → approve → assign → accept → start → resolve → reopen → resolve),
// never a raw UPDATE, so the guarantee holds for the actual flow.
test('labor effort: labor survives a reopen — resolve 30 then resolve 20 accumulate to 50 (F1)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $tech = ['id' => 3, 'role' => 'technician'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $tickets = tvm_container()->get(App\Services\TicketService::class);
    $wf = tvm_container()->get(App\Services\TicketWorkflowService::class);
    $ref = tvm_container()->get(App\Repositories\TicketReadRepository::class)->getCreateFormReferenceData();

    $ticketId = $tickets->createTicket($requester, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => 'LE reopen labor ' . bin2hex(random_bytes(3)),
        'description' => 'labor-across-reopen probe',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);

    try {
        $wf->approveTicket($ticketId, $admin, ['note' => '']);
        $wf->assignTechnician($ticketId, $admin, ['technician_id' => 3, 'instructions' => '']);
        $wf->acceptAssignedWork($ticketId, $tech, ['accept_note' => '']);
        $wf->startAssignedWork($ticketId, $tech, ['start_note' => '']);
        $wf->resolveAssignedWork($ticketId, $tech, ['diagnosis_summary' => 'd1', 'resolution_summary' => 'r1', 'labor_minutes' => '30']);
        assert_same(30, (int) le_pdo()->query("SELECT labor_minutes FROM work_orders WHERE ticket_id = $ticketId")->fetchColumn(), 'first resolve records 30 minutes');

        $wf->reopenTicket($ticketId, $requester, ['reopen_note' => 'ยังไม่หาย ขอให้แก้อีกรอบ']);
        assert_same(30, (int) le_pdo()->query("SELECT labor_minutes FROM work_orders WHERE ticket_id = $ticketId")->fetchColumn(), 'reopen must NOT erase the labor already spent');

        $wf->acceptAssignedWork($ticketId, $tech, ['accept_note' => '']);
        $wf->startAssignedWork($ticketId, $tech, ['start_note' => '']);
        $wf->resolveAssignedWork($ticketId, $tech, ['diagnosis_summary' => 'd2', 'resolution_summary' => 'r2', 'labor_minutes' => '20']);
        assert_same(50, (int) le_pdo()->query("SELECT labor_minutes FROM work_orders WHERE ticket_id = $ticketId")->fetchColumn(), 'second resolve ADDS its 20 minutes (30 + 20 = 50) — total real labor across cycles');
    } finally {
        le_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
        le_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades work_orders / sla_tracks / logs
    }
});

test('labor effort: asset reliability labor column + no fan-out on failure_count + by-category', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $assetId = 0;
    $ticketId = 0;

    try {
        // fresh asset (isolated → exact assertions on its reliability row)
        le_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id) VALUES (?, 'LE Test Asset', 1, 1)")
            ->execute(["LE-$rid"]);
        $assetId = (int) le_pdo()->lastInsertId();

        // one resolved ticket for the asset, with BOTH a work_order (labor 90) AND a rating
        le_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at, resolved_at)
             VALUES (?, 'le', 'x', 1, 1, 1, 1, ?, 'resolved', ?, ?)"
        )->execute(["LET-$rid", $assetId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s')]);
        $ticketId = (int) le_pdo()->lastInsertId();
        le_pdo()->prepare(
            "INSERT INTO work_orders (work_order_no, ticket_id, technician_id, assigned_by, status, labor_minutes) VALUES (?, ?, 3, 4, 'completed', 90)"
        )->execute(["WOL-$rid", $ticketId]);
        le_pdo()->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, score) VALUES (?, 1, 5)')->execute([$ticketId]);

        $page = le_service()->getReportPageData($admin, []);

        // --- Asset Reliability: my asset row ---
        $myAsset = null;
        foreach ($page['assetReliability'] as $a) {
            if ($a['asset_code'] === "LE-$rid") {
                $myAsset = $a;
                break;
            }
        }
        assert_true($myAsset !== null, 'asset appears in reliability');
        assert_same(1, $myAsset['failure_count'], 'failure_count = 1 (work_orders LEFT JOIN must NOT inflate the count)');
        assert_same('1.5', $myAsset['labor_hours_label'], 'asset labor 90min = 1.5h');

        // --- Labor by category: category of the ticket must show labor ---
        $catName = (string) le_pdo()->query('SELECT name FROM ticket_categories WHERE id = 1')->fetchColumn();
        $catRow = null;
        foreach ($page['laborEffort']['byCategory'] as $c) {
            if ($c['category_name'] === $catName) {
                $catRow = $c;
                break;
            }
        }
        assert_true($catRow !== null, 'category with labor appears in the labor table');
        assert_true($page['laborEffort']['hasData'], 'laborEffort hasData');
        assert_true((int) $catRow['labored_tickets'] >= 1, 'category has at least the 1 labored ticket');
    } finally {
        if ($ticketId > 0) {
            le_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // work_orders + ratings cascade
        }
        if ($assetId > 0) {
            le_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        }
    }
});

test('asset reliability: avg-resolution rounds to hours the same way as summary/MTTR (no double-rounding)', function (): void {
    // System-wide consistency guard: two resolved tickets of 17min + 120min average to 68.5min. Like the
    // summary card and technician MTTR, the asset avg-resolution must round to 1.1h — the old ROUND(AVG,0)
    // gave 69min → 1.15 → 1.2h, disagreeing with the rest of the report for identical underlying work.
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $assetId = 0;
    $t1 = 0;
    $t2 = 0;

    try {
        le_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id) VALUES (?, 'LE Round Asset', 1, 1)")
            ->execute(["LER-$rid"]);
        $assetId = (int) le_pdo()->lastInsertId();

        foreach ([17, 120] as $i => $minutes) {
            le_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, asset_id, status, requested_at, resolved_at)
                 VALUES (?, 'le', 'x', 1, 1, 1, 1, ?, 'resolved', ?, ?)"
            )->execute(["LERT-$rid-$i", $assetId, date('Y-m-d H:i:s', time() - $minutes * 60), date('Y-m-d H:i:s')]);
            ${'t' . ($i + 1)} = (int) le_pdo()->lastInsertId();
        }

        $myAsset = null;
        foreach (le_service()->getReportPageData($admin, [])['assetReliability'] as $a) {
            if ($a['asset_code'] === "LER-$rid") {
                $myAsset = $a;
                break;
            }
        }
        assert_true($myAsset !== null, 'asset appears in reliability');
        assert_same('1.1', $myAsset['avg_resolution_hours_label'], 'avg 68.5min rounds to 1.1h (was 1.2h under double-rounding)');
    } finally {
        foreach ([$t1, $t2] as $ticketId) {
            if ($ticketId > 0) {
                le_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
            }
        }
        if ($assetId > 0) {
            le_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        }
    }
});
