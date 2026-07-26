<?php

declare(strict_types=1);

use App\Services\ReportService;

// BI-review (2026-07-26, owner decision "ไม่นับทั้งคู่"): work that was never performed must not enter the SLA
// population or the asset-health score. Two shapes of "never performed" exist:
//   - cancelled — the requester withdrew it (already excluded from the two SLA queries before this round)
//   - rejected  — the manager refused it at approval; rejectTicket() only flips the status and leaves the two
//                 ticket_sla_tracks rows seeded at creation sitting at 'pending' forever. The reports' own
//                 "OR (pending AND target_at < NOW())" clause then scores every rejected ticket as 2 permanent
//                 breaches, while the SLA cron deliberately skips terminal tickets and never alerted on any of
//                 them. Report and alerting disagreed about what "breached" means.
// The same population rule applies to asset reliability: a cancelled/rejected ticket is not a failure, so it must
// not raise failure_count (which feeds the ควรเปลี่ยน capital-replacement bucket) or shorten MTBF.

function rsp_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** Fresh department + location + asset so the assertions cannot collide with other cases. */
function rsp_dims(string $sfx): array
{
    $pdo = rsp_pdo();
    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["RSPD-$sfx", "RspDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO locations (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["RSPL-$sfx", "RspLoc-$sfx"]);
    $locId = (int) $pdo->lastInsertId();
    $assetCategoryId = (int) $pdo->query('SELECT id FROM asset_categories LIMIT 1')->fetchColumn();
    $pdo->prepare('INSERT INTO assets (asset_code, name, status, asset_category_id, location_id, created_at, updated_at) VALUES (?, ?, "active", ?, ?, NOW(), NOW())')
        ->execute(["RSPA-$sfx", "RspAsset-$sfx", $assetCategoryId, $locId]);

    return [
        'dept' => $deptId,
        'loc' => $locId,
        'asset' => (int) $pdo->lastInsertId(),
        'cat' => (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn(),
        'pri' => (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn(),
    ];
}

/** A ticket in the given status whose response+resolution targets are already in the past. */
function rsp_ticket(array $d, string $sfx, string $status, int $n): int
{
    $requested = date('Y-m-d H:i:s', strtotime('-10 days'));
    $due = date('Y-m-d H:i:s', strtotime('-9 days'));
    rsp_pdo()->prepare(
        'INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id,
            asset_id, ticket_category_id, priority_id, status, approval_status, requested_at, response_due_at,
            resolution_due_at, cancelled_at, created_at, updated_at)
         VALUES (?, ?, "x", 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    )->execute([
        "RSP-$sfx-$n", "rsp $status $n", $d['dept'], $d['loc'], $d['asset'], $d['cat'], $d['pri'],
        $status, $status === 'rejected' ? 'rejected' : 'approved', $requested, $due, $due,
        $status === 'cancelled' ? $requested : null,
    ]);
    $ticketId = (int) rsp_pdo()->lastInsertId();

    // both tracks are seeded at creation and left pending — exactly what the live flow leaves behind
    foreach (['response', 'resolution'] as $metric) {
        rsp_pdo()->prepare(
            'INSERT INTO ticket_sla_tracks (ticket_id, metric_type, cycle, target_at, status, created_at)
             VALUES (?, ?, 1, ?, "pending", ?)'
        )->execute([$ticketId, $metric, $due, $requested]);
    }

    return $ticketId;
}

function rsp_cleanup(array $d, string $sfx): void
{
    rsp_pdo()->prepare('DELETE FROM tickets WHERE ticket_no LIKE ?')->execute(["RSP-$sfx-%"]);
    rsp_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$d['asset']]);
    rsp_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$d['loc']]);
    rsp_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$d['dept']]);
}

test('sla population: a REJECTED ticket never scores an SLA breach (nobody was asked to do the work)', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $sfx = bin2hex(random_bytes(4));
    $d = rsp_dims($sfx);

    try {
        rsp_ticket($d, $sfx, 'rejected', 1);

        $summary = $svc->getSlaBreachReportPage($admin, [
            'department_id' => $d['dept'],
            'dimension' => 'department',
        ])['summary'] ?? [];

        assert_same(0, (int) ($summary['total_breached'] ?? -1), 'a rejected ticket contributes no SLA breach at all');
        assert_same(0, (int) ($summary['response_breached'] ?? -1), 'its stale pending response track is not a breach');
        assert_same(0, (int) ($summary['resolution_breached'] ?? -1), 'its stale pending resolution track is not a breach');
    } finally {
        rsp_cleanup($d, $sfx);
    }
});

test('sla population: a CANCELLED ticket also stays out, but a REAL breach is still counted (not over-broad)', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $sfx = bin2hex(random_bytes(4));
    $d = rsp_dims($sfx);

    try {
        rsp_ticket($d, $sfx, 'cancelled', 1);
        rsp_ticket($d, $sfx, 'rejected', 2);

        $filters = ['department_id' => $d['dept'], 'dimension' => 'department'];
        $summary = $svc->getSlaBreachReportPage($admin, $filters)['summary'] ?? [];
        assert_same(0, (int) ($summary['total_breached'] ?? -1), 'cancelled + rejected together still score nothing');

        // positive path: a genuinely open ticket past its target MUST still be reported as breached,
        // otherwise the exclusion has silently swallowed real breaches too.
        rsp_ticket($d, $sfx, 'in_progress', 3);
        $after = $svc->getSlaBreachReportPage($admin, $filters)['summary'] ?? [];
        assert_same(2, (int) ($after['total_breached'] ?? -1), 'the live overdue ticket still breaches on both metrics');
    } finally {
        rsp_cleanup($d, $sfx);
    }
});

test('asset health: cancelled/rejected tickets are not failures (must not push an asset toward ควรเปลี่ยน)', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $sfx = bin2hex(random_bytes(4));
    $d = rsp_dims($sfx);

    $findAsset = static function (array $rows, int $assetId): ?array {
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $assetId) {
                return $row;
            }
        }

        return null;
    };

    try {
        rsp_ticket($d, $sfx, 'in_progress', 1); // one real, live failure
        $before = $findAsset($svc->getAssetReliabilityReportPage($admin, [])['rows'] ?? [], $d['asset']);
        assert_true($before !== null, 'the asset appears in the reliability report');
        assert_same(1, (int) $before['failure_count'], 'the one real failure is counted');

        // three tickets that were never worked on must not register as failures
        rsp_ticket($d, $sfx, 'cancelled', 2);
        rsp_ticket($d, $sfx, 'rejected', 3);
        rsp_ticket($d, $sfx, 'rejected', 4);

        $after = $findAsset($svc->getAssetReliabilityReportPage($admin, [])['rows'] ?? [], $d['asset']);
        assert_same(1, (int) $after['failure_count'], 'cancelled/rejected tickets did not inflate failure_count');
        assert_same(
            (string) $before['health_label'],
            (string) $after['health_label'],
            'the health verdict is unchanged by work nobody performed'
        );
    } finally {
        rsp_cleanup($d, $sfx);
    }
});
