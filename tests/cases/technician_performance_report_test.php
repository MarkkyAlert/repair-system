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

/**
 * Seed the immutable `ticket_resolved` event the report now reads for as-reported resolved/MTTR (Phase 2).
 * $actorId = the technician who ACTUALLY resolved; $createdAt = the close time (defaults to the ticket's resolved_at).
 */
function tpr_resolve_event(int $ticketId, int $actorId, string $createdAt): void
{
    tpr_pdo()->prepare(
        "INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status, created_at)
         VALUES (?, ?, 'ticket_resolved', 'in_progress', 'resolved', ?)"
    )->execute([$ticketId, $actorId, $createdAt]);
}

/**
 * Seed the frozen per-cycle resolution SLA track the report now reads for the technician's SLA-on-time (R13):
 * on-time = the resolve-event time <= this $targetAt. cycle 1 = the first resolve.
 */
function tpr_sla_track(int $ticketId, string $targetAt, int $cycle = 1): void
{
    tpr_pdo()->prepare(
        "INSERT INTO ticket_sla_tracks (ticket_id, metric_type, cycle, target_at, status)
         VALUES (?, 'resolution', ?, ?, 'met')"
    )->execute([$ticketId, $cycle, $targetAt]);
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

test('technician performance: more than 200 resolvers are all counted, none dropped by a LIMIT (round-2 #2)', function (): void {
    // A people-evaluation report must not silently cap the roster. The resolved credit + its base now come from
    // getTechnicianResolverStats (one row per resolver, no LIMIT). Seed 201 technicians who each resolve one ticket
    // (a real ticket_resolved event) and assert all 201 appear with the team total intact.
    $rid = bin2hex(random_bytes(4));
    $repo = tvm_container()->get(\App\Repositories\ReportRepository::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    tpr_pdo()->prepare('INSERT INTO departments (code, name) VALUES (?, ?)')->execute(["TPRB-$rid", "TPRB Dept $rid"]);
    $deptId = (int) tpr_pdo()->lastInsertId();
    $count = 201;

    try {
        $userStmt = tpr_pdo()->prepare(
            "INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)"
        );
        $ticketStmt = tpr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, 1, ?, 'resolved', NOW(), NOW())"
        );
        for ($i = 0; $i < $count; $i++) {
            $userStmt->execute(["tprb_{$rid}_{$i}", "tprb_{$rid}_{$i}@example.com", "TPRB Tech $rid $i"]);
            $techId = (int) tpr_pdo()->lastInsertId();
            $ticketStmt->execute(["TPRBT-{$rid}-{$i}", $deptId, $techId]);
            tpr_resolve_event((int) tpr_pdo()->lastInsertId(), $techId, date('Y-m-d H:i:s'));
        }

        // scope to the fresh department so the resolver roster = exactly our 201 technicians (one resolve each)
        $stats = $repo->getTechnicianResolverStats($admin, ['department_id' => $deptId]);
        assert_same($count, count($stats), 'all 201 resolvers are returned (not capped at 200)');
        assert_same($count, (int) array_sum(array_column($stats, 'resolved')), 'team resolved total = 201, not silently capped at 200');
    } finally {
        tpr_pdo()->prepare('DELETE FROM tickets WHERE requester_department_id = ?')->execute([$deptId]);
        tpr_pdo()->prepare('DELETE FROM users WHERE username LIKE ?')->execute(["tprb_{$rid}_%"]);
        tpr_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

test('technician performance: per-tech SLA on-time rate (as-reported, from the frozen cycle target)', function (): void {
    // SLA-on-time is now immutable/as-reported (R13): of the tickets the technician RESOLVED, how many closed
    // within their cycle's FROZEN target_at (ticket_sla_tracks) — not the current resolution_due_at. So a later
    // reopen/reassign can't restate it. (First-response time + labor were removed — not immutable per-tech.)
    $rid = bin2hex(random_bytes(4));
    [$techId, $fullName] = tpr_tech($rid);

    try {
        $now = time();
        // A: resolved ON TIME — resolve event (now-3600) <= frozen target (now)
        tpr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'resolved', ?, ?)"
        )->execute(["TPRA-$rid", $techId, date('Y-m-d H:i:s', $now - 7200), date('Y-m-d H:i:s', $now - 3600)]);
        $a = (int) tpr_pdo()->lastInsertId();
        tpr_resolve_event($a, $techId, date('Y-m-d H:i:s', $now - 3600));
        tpr_sla_track($a, date('Y-m-d H:i:s', $now)); // target after the resolve → on time
        // B: resolved LATE — resolve event (now) > frozen target (now-3600)
        tpr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'resolved', ?, ?)"
        )->execute(["TPRB-$rid", $techId, date('Y-m-d H:i:s', $now - 10800), date('Y-m-d H:i:s', $now)]);
        $b = (int) tpr_pdo()->lastInsertId();
        tpr_resolve_event($b, $techId, date('Y-m-d H:i:s', $now));
        tpr_sla_track($b, date('Y-m-d H:i:s', $now - 3600)); // target before the resolve → late

        $row = tpr_row($fullName);
        assert_true($row !== null, 'technician appears');
        assert_false(isset($row['assigned']), 'no per-tech "รับ" column (removed R14 — date-filtered current assignee)');
        assert_same(2, $row['resolved'], 'resolved = 2 (as-reported: the technician resolved both, via the resolve events)');
        assert_same('50.0%', $row['sla_on_time_label'], 'SLA on-time = 1 of 2 = 50% (A on time, B late vs the frozen target)');
        assert_same(2, $row['sla_base'], 'sla_base = 2 (both resolves have a cycle track)');
    } finally {
        tpr_cleanup($techId);
    }
});

test('technician performance: avg rating & SLA rate carry their sample size (Finding B)', function (): void {
    // A technician with a SINGLE 5-star rating must not look identical, on a people-evaluation report,
    // to one with 40 ratings averaging 5.0. The row must carry the base counts behind the averages/rates
    // (rating_count, sla_base) so the report can show how many data points each number rests on.
    $rid = bin2hex(random_bytes(4));
    [$techId, $fullName] = tpr_tech($rid);

    try {
        $now = time();
        // one resolved, on-time ticket with a single 5-star rating for this technician (all read via the resolve cycle)
        tpr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'resolved', ?, ?)"
        )->execute(["TPRR-$rid", $techId, date('Y-m-d H:i:s', $now - 3600), date('Y-m-d H:i:s', $now - 1800)]);
        $tid = (int) tpr_pdo()->lastInsertId();
        tpr_resolve_event($tid, $techId, date('Y-m-d H:i:s', $now - 1800));
        tpr_sla_track($tid, date('Y-m-d H:i:s', $now)); // resolve (now-1800) <= target (now) → on time
        tpr_pdo()->prepare(
            "INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, cycle, score, feedback, created_at, updated_at)
             VALUES (?, 1, ?, 1, 5, '', ?, ?)"
        )->execute([$tid, $techId, date('Y-m-d H:i:s', $now - 1800), date('Y-m-d H:i:s', $now - 1800)]);

        $row = tpr_row($fullName);
        assert_true($row !== null, 'technician appears');
        assert_same('5.0', $row['avg_rating_label'], 'avg rating label = 5.0 (resolver cohort, cycle 1)');
        assert_same('100.0%', $row['sla_on_time_label'], 'SLA on-time = 1 of 1 = 100%');
        // Finding B: the sample size behind each average/rate must be exposed on the row
        assert_same(1, $row['rating_count'] ?? null, 'row exposes rating_count = 1 (the single rating behind 5.0)');
        assert_same(1, $row['sla_base'] ?? null, 'row exposes sla_base = 1 (the single SLA-concluded ticket behind the rate)');
    } finally {
        tpr_pdo()->prepare('DELETE FROM ticket_ratings WHERE technician_id = ?')->execute([$techId]);
        tpr_cleanup($techId);
    }
});

test('technician performance: CSV export cells reconcile with the on-screen row (screen↔export parity)', function (): void {
    // People-evaluation report: the % / คะแนน / sample-size a manager reads on screen must be byte-identical
    // in the CSV they hand to HR. Screen (mapTechnicianPerformanceRow) and export (technicianPerformanceExportRow)
    // format separately — pin them together. (BI-review #4: screen↔export reconciliation.)
    $rid = bin2hex(random_bytes(4));
    [$techId, $fullName] = tpr_tech($rid);
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) tpr_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        $now = time();
        tpr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'resolved', ?, ?)"
        )->execute(["TPRP-$rid", $techId, date('Y-m-d H:i:s', $now - 3600), date('Y-m-d H:i:s', $now - 1800)]);
        $tid = (int) tpr_pdo()->lastInsertId();
        tpr_resolve_event($tid, $techId, date('Y-m-d H:i:s', $now - 1800)); // as-reported resolved/MTTR/SLA/rating read this
        tpr_sla_track($tid, date('Y-m-d H:i:s', $now)); // resolve (now-1800) <= target (now) → on time → 100%
        tpr_pdo()->prepare(
            "INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, cycle, score, feedback, created_at, updated_at)
             VALUES (?, 1, ?, 1, 5, '', ?, ?)"
        )->execute([$tid, $techId, date('Y-m-d H:i:s', $now - 1800), date('Y-m-d H:i:s', $now - 1800)]);

        $screen = tpr_row($fullName);
        assert_true($screen !== null, 'technician appears on screen');

        $csv = (string) tpr_service()->exportTechnicianPerformanceCsv($admin, [])['content'];
        $exportRow = null;
        foreach (explode("\n", trim(substr($csv, 3))) as $line) { // substr(3) strips the BOM
            $cells = str_getcsv($line);
            if (($cells[0] ?? null) === $fullName) {
                $exportRow = $cells;
                break;
            }
        }
        assert_true($exportRow !== null, 'the same technician appears as a CSV row');

        // cell-by-cell vs the export header order (…, ปิดงาน, SLA ตรงเวลา, งาน SLA, …, คะแนน, จำนวนรีวิว, …)
        // NOTE: no "อัตราปิดงาน" column — removed as a non-immutable people-eval metric (R12)
        assert_same((string) $screen['resolved'], $exportRow[4], 'CSV ปิดงาน = screen resolved');
        assert_same($screen['sla_on_time_label'], $exportRow[5], 'CSV SLA ตรงเวลา = screen sla_on_time_label (100.0%)');
        assert_same((string) $screen['sla_base'], $exportRow[6], 'CSV งาน SLA = screen sla_base (sample behind the rate)');
        assert_same($screen['avg_rating_label'], $exportRow[8], 'CSV คะแนน = screen avg_rating_label (5.0)');
        assert_same((string) $screen['rating_count'], $exportRow[9], 'CSV จำนวนรีวิว = screen rating_count');

        // XLSX parity — % columns as real numbers (screen_pct/100), rating/counts numeric
        $xlsxTmp = tempnam(sys_get_temp_dir(), 'tprx_') . '.xlsx';
        file_put_contents($xlsxTmp, (string) tpr_service()->exportTechnicianPerformanceExcel($admin, [])['content']);
        $sheet = IOFactory::createReader('Xlsx')->load($xlsxTmp)->getActiveSheet();
        @unlink($xlsxTmp);
        $xlsxRow = null;
        foreach ($sheet->toArray(null, true, false) as $r) { // formatData=false → raw values (1.0, not "100.0%")
            if (($r[0] ?? null) === $fullName) {
                $xlsxRow = $r;
                break;
            }
        }
        assert_true($xlsxRow !== null, 'the same technician appears as an XLSX row');
        assert_same((int) $screen['resolved'], (int) $xlsxRow[4], 'XLSX ปิดงาน numeric = screen');
        assert_same((float) rtrim($screen['sla_on_time_label'], '%') / 100, (float) $xlsxRow[5], 'XLSX SLA ตรงเวลา = screen rate as a real number');
        assert_same((float) $screen['avg_rating_label'], (float) $xlsxRow[8], 'XLSX คะแนน numeric = screen avg_rating');
        assert_same((int) $screen['rating_count'], (int) $xlsxRow[9], 'XLSX จำนวนรีวิว numeric = screen');
    } finally {
        tpr_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
        tpr_pdo()->prepare('DELETE FROM ticket_ratings WHERE technician_id = ?')->execute([$techId]);
        tpr_cleanup($techId);
    }
});

test('technician performance: a status-resolved ticket with NO resolve event is not counted; same-minute event stays 0.0 (Phase 2, was round-4 F1)', function (): void {
    // INTENT REWRITTEN (Phase 2, owner-settled): the dedicated report's resolved + MTTR are now event-sourced —
    // attributed to the actor of the `ticket_resolved` event (getTechnicianResolverStats), not derived from
    // t.status / t.resolved_at. So a ticket that is status='resolved' but carries NO resolve event (bad seed /
    // import — production ALWAYS writes the event) is simply NOT counted: resolved=0, MTTR '-'. Previously this
    // encoded "status='resolved' + NULL resolved_at → resolved=1, MTTR '-'"; under event-sourcing there is no
    // close event to count, so the count drops rather than the MTTR alone. A genuine same-minute closure — a
    // resolve event whose created_at equals requested_at — still reads 0.0 (0 minutes, boundary included).
    // The /reports OVERVIEW MINI now reads the SAME immutable resolver rows as the full page (R13), so it too
    // counts only real resolve events — asserted equal below.
    $rid = bin2hex(random_bytes(4));
    [$nullTech, $nullName] = tpr_tech($rid);
    [$fastTech, $fastName] = tpr_tech($rid . 'F');
    $admin = ['id' => 4, 'role' => 'admin'];
    tpr_pdo()->prepare('INSERT INTO departments (code, name) VALUES (?, ?)')->execute(["TPRFD-$rid", "TPRF Dept $rid"]);
    $deptId = (int) tpr_pdo()->lastInsertId();

    try {
        // status='resolved' but NO resolved_at and NO resolve event → not a counted closure under event-sourcing
        tpr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, 1, ?, 'resolved', NOW())"
        )->execute(["TPRF-$rid", $deptId, $nullTech]);
        // genuine same-minute resolution: resolved_at = requested_at, WITH a matching resolve event by fastTech
        $now = date('Y-m-d H:i:s');
        tpr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, 1, ?, 'resolved', ?, ?)"
        )->execute(["TPRG-$rid", $deptId, $fastTech, $now, $now]);
        tpr_resolve_event((int) tpr_pdo()->lastInsertId(), $fastTech, $now);

        // dedicated technician report (mapTechnicianPerformanceRow) — as-reported/event-sourced
        $nullRow = tpr_row($nullName);
        assert_true($nullRow !== null, 'the technician still appears (base = all active techs)');
        assert_same(0, $nullRow['resolved'], 'a status-resolved ticket with NO resolve event is not counted (event-sourced)');
        assert_same('-', $nullRow['mttr_hours_label'], 'no resolve event → MTTR "-"');
        assert_same('0.0', tpr_row($fastName)['mttr_hours_label'], 'a real same-minute resolve event still shows 0.0');

        // overview mini-table — now the SAME immutable resolver rows as the full page (R13): event-sourced, agrees
        $mini = [];
        foreach (tpr_service()->getReportPageData($admin, ['department_id' => $deptId])['technicianPerformance'] as $t) {
            $mini[$t['full_name']] = $t;
        }
        assert_same('-', $mini[$nullName]['mttr_hours_label'] ?? null, 'overview mini: NULL resolved_at → "-"');
        assert_same('0.0', $mini[$fastName]['mttr_hours_label'] ?? null, 'overview mini: same-minute → 0.0');
    } finally {
        tpr_cleanup($nullTech);
        tpr_cleanup($fastTech);
        tpr_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

test('technician performance: a same-minute resolution shows MTTR 0.0, not "-" (Finding F5-rem)', function (): void {
    // MTTR presence must come from the resolved COUNT, not the average value: a ticket resolved within the same
    // clock-minute as the request has a real 0.0h, not "no data".
    $rid = bin2hex(random_bytes(4));
    [$techId, $fullName] = tpr_tech($rid);

    try {
        tpr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'resolved', '2020-05-10 10:00:00', '2020-05-10 10:00:45')"
        )->execute(["TPRZ-$rid", $techId]);
        // as-reported resolved/MTTR read the resolve event (Phase 2) — same clock-minute as the request → 0 minutes
        tpr_resolve_event((int) tpr_pdo()->lastInsertId(), $techId, '2020-05-10 10:00:45');

        $row = tpr_row($fullName, ['from_date' => '2020-05-01', 'to_date' => '2020-05-31']);
        assert_true($row !== null, 'technician appears');
        assert_same(1, $row['resolved'], 'resolved = 1');
        assert_same('0.0', $row['mttr_hours_label'], 'MTTR 0.0h (resolved in the same minute), not "-"');
    } finally {
        tpr_cleanup($techId);
    }
});

test('reports overview: same-minute resolution shows 0.0 in the technician + asset mini-tables (F5-rem overview)', function (): void {
    // The /reports overview mini-tables must use the same base-count presence as the dedicated reports.
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    [$techId, $fullName] = tpr_tech($rid);
    $locId = 0;
    $assetId = 0;
    $ticketId = 0;
    $base = date('Y-m-d H:i'); // current-minute anchor → in-window + TIMESTAMPDIFF(MINUTE)=0

    try {
        tpr_pdo()->prepare("INSERT INTO locations (code, name) VALUES (?, ?)")->execute(["OVL-$rid", "OV Loc $rid"]);
        $locId = (int) tpr_pdo()->lastInsertId();
        tpr_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id, status) VALUES (?, 'OV Asset', 1, ?, 'active')")->execute(["OVA-$rid", $locId]);
        $assetId = (int) tpr_pdo()->lastInsertId();
        tpr_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, asset_id, status, requested_at, resolved_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, ?, ?, 'resolved', ?, ?)"
        )->execute(["OVT-$rid", $locId, $techId, $assetId, "$base:00", "$base:30"]);
        $ticketId = (int) tpr_pdo()->lastInsertId();
        tpr_resolve_event($ticketId, $techId, "$base:30"); // overview mini is now event-sourced too (R13)

        $page = tpr_service()->getReportPageData($admin, []);

        $tech = null;
        foreach ($page['technicianPerformance'] as $t) {
            if ($t['full_name'] === $fullName) {
                $tech = $t;
            }
        }
        assert_true($tech !== null, 'fresh technician appears in the overview mini');
        assert_same('0.0', $tech['mttr_hours_label'], 'technician mini MTTR 0.0 (same-minute), not "-"');

        $asset = null;
        foreach ($page['assetReliability'] as $a) {
            if ($a['asset_code'] === "OVA-$rid") {
                $asset = $a;
            }
        }
        assert_true($asset !== null, 'fresh asset appears in the overview mini');
        assert_same('0.0', $asset['avg_resolution_hours_label'], 'asset mini avg 0.0 (same-minute), not "-"');
    } finally {
        if ($ticketId > 0) {
            tpr_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        if ($assetId > 0) {
            tpr_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        }
        if ($locId > 0) {
            tpr_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
        }
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
        assert_false(isset($row['assigned']), 'no per-tech "รับ" column (removed R14 — it was a date-filtered current-assignee hybrid)');
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
        assert_false(isset($row['assigned']), 'no per-tech "รับ" column (removed R14)');
        assert_false(isset($row['completion_label']), 'no per-tech completion % (removed R12)');
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

// bug-hunt B6 (2nd pass): the technician live-workload query applied NO filters, while the resolved/SLA/CSAT
// columns honor department_id/category_id. So on a category- or dept-filtered report, "งานค้างตอนนี้" showed the
// technician's whole-org open load while the other half of the row was filtered — two different populations in one
// row. getTechnicianLiveWorkload now applies the same dept/category filter (in the JOIN ON, so a technician with
// no matching open work still appears with open_now = 0).
test('technician performance B6: current-workload (open_now) honors the category filter, matching the resolved columns', function (): void {
    $rid = bin2hex(random_bytes(4));
    [$techId, $fullName] = tpr_tech($rid);
    try {
        // two non-terminal tickets assigned to this tech, in two different categories
        $ins = tpr_pdo()->prepare("INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at) VALUES (?, 'x','x',1,1,?,1,?, 'in_progress', NOW())");
        $ins->execute(["B6A-$rid", 1, $techId]);
        $ins->execute(["B6B-$rid", 2, $techId]);

        $unfiltered = tpr_row($fullName, []);
        assert_true($unfiltered !== null, 'the technician appears in the unfiltered report');
        assert_same(2, (int) ($unfiltered['open_now'] ?? -1), 'unfiltered: both open tickets count');

        $filtered = tpr_row($fullName, ['category_id' => 1]);
        assert_true($filtered !== null, 'the technician still appears when filtered (open work in the category)');
        assert_same(1, (int) ($filtered['open_now'] ?? -1), 'category-filtered: only the category-1 open ticket counts, not the whole-org load');
    } finally {
        tpr_cleanup($techId);
    }
});
