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
