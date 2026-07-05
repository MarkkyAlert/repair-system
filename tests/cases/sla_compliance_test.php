<?php
declare(strict_types=1);

use App\Services\ReportService;

// Tests for the SLA Compliance report: the pivot/percentage/tone logic (pure) and the met/breached/
// pending counting against the DB (breached = status='breached' OR pending-past-due; pending-not-due
// is excluded from the %).

function slac_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function slac_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

function slac_insert_ticket(string $no): int
{
    slac_pdo()->exec(
        "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id)
         VALUES ('$no', 'sla compliance test', 'x', 1, 1, 1, 1)"
    );
    return (int) slac_pdo()->lastInsertId();
}

function slac_insert_sla(int $ticketId, string $metric, string $status, string $targetAt): void
{
    $achieved = $status === 'met' ? date('Y-m-d H:i:s', strtotime($targetAt) - 3600) : null;
    $breachedAt = $status === 'breached' ? date('Y-m-d H:i:s') : null;
    slac_pdo()->prepare(
        'INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, achieved_at, breached_at, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$ticketId, $metric, $targetAt, $achieved, $breachedAt, $status]);
}

test('sla compliance: pivot + pct + tone (buildSlaCompliance, reflection)', function (): void {
    $m = new ReflectionMethod(ReportService::class, 'buildSlaCompliance');
    $m->setAccessible(true);
    $out = $m->invoke(slac_service(), [
        ['priority_name' => 'High', 'priority_level' => 3, 'metric_type' => 'response', 'met' => 9, 'breached' => 1],
        ['priority_name' => 'High', 'priority_level' => 3, 'metric_type' => 'resolution', 'met' => 0, 'breached' => 0],
        ['priority_name' => 'Low', 'priority_level' => 1, 'metric_type' => 'response', 'met' => 3, 'breached' => 1],
    ]);

    // overall response = 12 met / 2 breached → 85.7% → warning
    assert_same(12, $out['overall']['response']['met']);
    assert_same(2, $out['overall']['response']['breached']);
    assert_same('warning', $out['overall']['response']['tone'], '85.7% → warning');
    // resolution 0/0 → no concluded SLA → null / '-'
    assert_same(null, $out['overall']['resolution']['pct'], 'no data → null pct');
    assert_same('-', $out['overall']['resolution']['pct_label']);
    // byPriority ordered by level desc: High(3) → Low(1)
    assert_same('High', $out['byPriority'][0]['priority_name']);
    assert_same('Low', $out['byPriority'][1]['priority_name']);
    // High response 9/10 = 90% → success
    assert_same('success', $out['byPriority'][0]['response']['tone'], '90% → success');
    assert_true($out['hasData']);
});

test('sla compliance: counting met/breached/pending via getReportPageData (delta)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $t1 = 0;
    $t2 = 0;
    try {
        $before = slac_service()->getReportPageData($admin, [])['slaCompliance']['overall'];
        $past = date('Y-m-d H:i:s', time() - 3600);
        $future = date('Y-m-d H:i:s', time() + 3600);

        $t1 = slac_insert_ticket('SLACT-' . bin2hex(random_bytes(3)));
        slac_insert_sla($t1, 'response', 'met', $past);
        slac_insert_sla($t1, 'resolution', 'breached', $past);

        $t2 = slac_insert_ticket('SLACT-' . bin2hex(random_bytes(3)));
        slac_insert_sla($t2, 'response', 'pending', $past);      // past due → counts as breached
        slac_insert_sla($t2, 'resolution', 'pending', $future);  // not due yet → excluded

        $after = slac_service()->getReportPageData($admin, [])['slaCompliance']['overall'];

        assert_same($before['response']['met'] + 1, $after['response']['met'], 'response met +1 (T1 met)');
        assert_same($before['response']['breached'] + 1, $after['response']['breached'], 'response breached +1 (T2 pending-past)');
        assert_same($before['resolution']['met'], $after['resolution']['met'], 'resolution met unchanged');
        assert_same($before['resolution']['breached'] + 1, $after['resolution']['breached'], 'resolution breached +1 (T1 only; T2 pending-future excluded)');
    } finally {
        foreach ([$t1, $t2] as $ticketId) {
            if ($ticketId > 0) {
                slac_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // sla_tracks cascade
            }
        }
    }
});
