<?php
declare(strict_types=1);

use App\Services\ReportService;

// F1 (round-6): a backwards timestamp — resolved_at < requested_at, or first_response_at < requested_at — is
// bad seed/import data the schema allows (no chronological constraint). Every duration metric must EXCLUDE it
// (show '-'), never a negative value, across Executive / Technician (MTTR + first response) / Trend / Hotspot
// / Asset. Completion still counts the status-resolved ticket; a valid same-minute resolution still reads 0.0.

function dbt_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function dbt_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

test('reports: a backwards resolved_at/first_response_at → all duration metrics show "-", never negative (round-6 F1)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    dbt_pdo()->prepare('INSERT INTO departments (code, name) VALUES (?, ?)')->execute(["DBTD-$rid", "DBT Dept $rid"]);
    $deptId = (int) dbt_pdo()->lastInsertId();
    dbt_pdo()->prepare('INSERT INTO locations (code, name) VALUES (?, ?)')->execute(["DBTL-$rid", "DBT Loc $rid"]);
    $locId = (int) dbt_pdo()->lastInsertId();
    dbt_pdo()->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)")
        ->execute(["dbt_$rid", "dbt_$rid@example.com", "DBT Tech $rid"]);
    $techId = (int) dbt_pdo()->lastInsertId();
    dbt_pdo()->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id) VALUES (?, ?, 1, ?)")
        ->execute(["DBTA-$rid", "DBT Asset $rid", $locId]);
    $assetId = (int) dbt_pdo()->lastInsertId();

    try {
        // requested 10:00 · resolved 09:00 (1h backwards) · first response 08:00 (2h backwards) — all impossible
        dbt_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, asset_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, resolved_at, first_response_at)
             VALUES (?, 'x', 'x', 1, ?, ?, ?, 1, 1, ?, 'resolved', '2020-07-15 10:00:00', '2020-07-15 09:00:00', '2020-07-15 08:00:00')"
        )->execute(["DBT-$rid", $deptId, $locId, $assetId, $techId]);
        $dbtTicketId = (int) dbt_pdo()->lastInsertId();
        // as-reported trend buckets on the resolve event; log the (backwards) closure so the trend's
        // `re.resolved_at >= t.requested_at` guard is genuinely exercised, not sidestepped by an absent event.
        dbt_pdo()->prepare(
            "INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status, created_at)
             VALUES (?, ?, 'ticket_resolved', 'in_progress', 'resolved', '2020-07-15 09:00:00')"
        )->execute([$dbtTicketId, $techId]);

        // Executive: MTTR "-" but completion still counts the status-resolved ticket
        $exKpis = [];
        foreach (dbt_service()->getExecutiveSummaryPage($admin, ['preset' => 'custom', 'from_date' => '2020-07-01', 'to_date' => '2020-07-31'])['kpis'] as $k) {
            $exKpis[$k['label']] = $k;
        }
        assert_same('-', $exKpis['เวลาซ่อมเฉลี่ย (ชม.)']['value_label'], 'Executive MTTR = "-" (backwards resolved_at excluded, not -1.0)');
        assert_true((int) $exKpis['ปิดงาน']['value_label'] >= 1, 'completion still counts the status-resolved ticket');

        // Technician: MTTR + first response "-"
        $tech = null;
        foreach (dbt_service()->getTechnicianPerformanceReportPage($admin, ['department_id' => $deptId])['rows'] as $r) {
            if ($r['full_name'] === "DBT Tech $rid") {
                $tech = $r;
                break;
            }
        }
        assert_true($tech !== null, 'technician appears');
        assert_same('-', $tech['mttr_hours_label'], 'technician MTTR = "-", not -1.0');
        assert_same('-', $tech['first_response_hours_label'], 'technician first-response = "-", not -2.0');

        // Trend: the backwards ticket is excluded from the resolved bucket → MTTR "-"
        $jul = ttr_period(dbt_service()->getTicketTrendReportPage($admin, ['granularity' => 'month', 'from_date' => '2020-07-01', 'to_date' => '2020-07-31']), '2020-07');
        assert_true($jul !== null, 'the July trend bucket exists');
        assert_same('-', $jul['mttr_hours_label'], 'trend MTTR = "-", not -1.0');

        // Hotspot: avg resolution "-"
        $hot = null;
        foreach (dbt_service()->getProblemHotspotReportPage($admin, ['dimension' => 'location'])['rows'] as $r) {
            if ($r['label'] === "DBT Loc $rid") {
                $hot = $r;
                break;
            }
        }
        assert_true($hot !== null, 'hotspot location appears');
        assert_same('-', $hot['avg_resolution_hours_label'], 'hotspot avg resolution = "-", not -1.0');

        // Asset: avg resolution "-"
        $asset = null;
        foreach (dbt_service()->getAssetReliabilityReportPage($admin, [])['rows'] as $r) {
            if (($r['asset_code'] ?? null) === "DBTA-$rid") {
                $asset = $r;
                break;
            }
        }
        assert_true($asset !== null, 'asset appears');
        assert_same('-', $asset['avg_resolution_hours_label'], 'asset avg resolution = "-", not -1.0');
    } finally {
        dbt_pdo()->prepare('DELETE FROM tickets WHERE requester_department_id = ?')->execute([$deptId]);
        dbt_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        dbt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$techId]);
        dbt_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
        dbt_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

test('reports: a backwards resolved_at is not a false SLA pass — technician + generic label (round-7 F1-residual)', function (): void {
    // A backwards resolved_at (09:00, before the 10:00 request) is <= the 11:00 due, so it would falsely read
    // as SLA on-time. It must NOT count: technician SLA base excludes it (label '-'), and the generic per-ticket
    // sla_label must not say "อยู่ใน SLA" for an achievement that predates the request.
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    dbt_pdo()->prepare('INSERT INTO departments (code, name) VALUES (?, ?)')->execute(["DBTS-$rid", "DBTS Dept $rid"]);
    $deptId = (int) dbt_pdo()->lastInsertId();
    dbt_pdo()->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)")
        ->execute(["dbts_$rid", "dbts_$rid@example.com", "DBTS Tech $rid"]);
    $techId = (int) dbt_pdo()->lastInsertId();

    try {
        // requested 10:00 · resolved 09:00 (before request) · first response 08:00 · both due times AFTER request
        dbt_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, resolved_at, resolution_due_at, first_response_at, response_due_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, 1, ?, 'resolved', '2020-07-15 10:00:00', '2020-07-15 09:00:00', '2020-07-15 11:00:00', '2020-07-15 08:00:00', '2020-07-15 10:30:00')"
        )->execute(["DBTS-$rid", $deptId, $techId]);

        // technician SLA: the impossible resolve must not count as a concluded, on-time SLA
        $tech = null;
        foreach (dbt_service()->getTechnicianPerformanceReportPage($admin, ['department_id' => $deptId])['rows'] as $r) {
            if ($r['full_name'] === "DBTS Tech $rid") {
                $tech = $r;
                break;
            }
        }
        assert_true($tech !== null, 'technician appears');
        assert_same(0, $tech['sla_base'], 'sla_base excludes the backwards resolve (not a valid SLA conclusion)');
        assert_same('-', $tech['sla_on_time_label'], 'technician SLA = "-", not a false 100%');

        // generic overview per-ticket SLA label must not read a false "อยู่ใน SLA"
        $row = null;
        foreach (dbt_service()->getReportPageData($admin, ['department_id' => $deptId])['rows'] as $r) {
            if (($r['ticket_no'] ?? null) === "DBTS-$rid") {
                $row = $r;
                break;
            }
        }
        assert_true($row !== null, 'the ticket appears in the overview rows');
        assert_true(($row['sla_label'] ?? '') !== 'อยู่ใน SLA', 'a pre-request achievement is not a false "within SLA"');
    } finally {
        dbt_pdo()->prepare('DELETE FROM tickets WHERE requester_department_id = ?')->execute([$deptId]);
        dbt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$techId]);
        dbt_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

test('reports: a valid on-time resolution still counts as SLA 100% / "อยู่ใน SLA" (round-7 guard not over-broad)', function (): void {
    // requested 09:00 · resolved 10:00 (after request, before the 12:00 due) · response on-time → still a pass.
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    dbt_pdo()->prepare('INSERT INTO departments (code, name) VALUES (?, ?)')->execute(["DBTW-$rid", "DBTW Dept $rid"]);
    $deptId = (int) dbt_pdo()->lastInsertId();
    dbt_pdo()->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)")
        ->execute(["dbtw_$rid", "dbtw_$rid@example.com", "DBTW Tech $rid"]);
    $techId = (int) dbt_pdo()->lastInsertId();

    try {
        dbt_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, resolved_at, resolution_due_at, first_response_at, response_due_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, 1, ?, 'resolved', '2020-07-15 09:00:00', '2020-07-15 10:00:00', '2020-07-15 12:00:00', '2020-07-15 09:15:00', '2020-07-15 09:30:00')"
        )->execute(["DBTW-$rid", $deptId, $techId]);

        $tech = null;
        foreach (dbt_service()->getTechnicianPerformanceReportPage($admin, ['department_id' => $deptId])['rows'] as $r) {
            if ($r['full_name'] === "DBTW Tech $rid") {
                $tech = $r;
                break;
            }
        }
        assert_true($tech !== null, 'technician appears');
        assert_same(1, $tech['sla_base'], 'valid resolve counts in the SLA base');
        assert_same('100.0%', $tech['sla_on_time_label'], 'valid on-time resolve → 100%');

        $row = null;
        foreach (dbt_service()->getReportPageData($admin, ['department_id' => $deptId])['rows'] as $r) {
            if (($r['ticket_no'] ?? null) === "DBTW-$rid") {
                $row = $r;
                break;
            }
        }
        assert_same('อยู่ใน SLA', $row['sla_label'] ?? '', 'a genuinely on-time ticket still reads "within SLA"');
    } finally {
        dbt_pdo()->prepare('DELETE FROM tickets WHERE requester_department_id = ?')->execute([$deptId]);
        dbt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$techId]);
        dbt_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

test('reports: a valid same-minute resolution still reads 0.0, not "-" (round-6 F1 guard is not over-broad)', function (): void {
    // the >= requested_at guard must keep valid rows: resolved_at == requested_at (0 minutes) is still a real,
    // in-window resolution → 0.0, not excluded. Technician MTTR is now event-sourced (Phase 2), so seed the
    // matching same-minute `ticket_resolved` event (actor = the technician) — the guard is exercised on the
    // event's created_at, not sidestepped by an absent event.
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    dbt_pdo()->prepare('INSERT INTO departments (code, name) VALUES (?, ?)')->execute(["DBTV-$rid", "DBTV Dept $rid"]);
    $deptId = (int) dbt_pdo()->lastInsertId();
    dbt_pdo()->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)")
        ->execute(["dbtv_$rid", "dbtv_$rid@example.com", "DBTV Tech $rid"]);
    $techId = (int) dbt_pdo()->lastInsertId();

    try {
        $now = date('Y-m-d H:i:s');
        dbt_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, resolved_at, first_response_at)
             VALUES (?, 'x', 'x', 1, ?, 1, 1, 1, ?, 'resolved', ?, ?, ?)"
        )->execute(["DBTV-$rid", $deptId, $techId, $now, $now, $now]);
        $tid = (int) dbt_pdo()->lastInsertId();
        dbt_pdo()->prepare(
            "INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status, created_at)
             VALUES (?, ?, 'ticket_resolved', 'in_progress', 'resolved', ?)"
        )->execute([$tid, $techId, $now]);

        $tech = null;
        foreach (dbt_service()->getTechnicianPerformanceReportPage($admin, ['department_id' => $deptId])['rows'] as $r) {
            if ($r['full_name'] === "DBTV Tech $rid") {
                $tech = $r;
                break;
            }
        }
        assert_true($tech !== null, 'technician appears');
        assert_same('0.0', $tech['mttr_hours_label'], 'same-minute resolve → MTTR 0.0 (valid, boundary included)');
        assert_same('0.0', $tech['first_response_hours_label'], 'same-minute first response → 0.0');
    } finally {
        dbt_pdo()->prepare('DELETE FROM tickets WHERE requester_department_id = ?')->execute([$deptId]);
        dbt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$techId]);
        dbt_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});
