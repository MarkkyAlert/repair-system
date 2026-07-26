<?php
declare(strict_types=1);

use App\Services\ReportService;
use App\Services\TicketWorkflowService;

function aiv_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function aiv_reports(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

function aiv_workflow(): TicketWorkflowService
{
    return tvm_container()->get(TicketWorkflowService::class);
}

/** @return array<string,string> */
function aiv_executive_kpis(array $page): array
{
    $values = [];
    foreach ($page['kpis'] ?? [] as $kpi) {
        $values[(string) ($kpi['label'] ?? '')] = (string) ($kpi['value_label'] ?? '');
    }

    return $values;
}

test('analytics: a late real-flow resolution does not restate a closed reporting period', function (): void {
    $manager = ['id' => 2, 'role' => 'manager'];
    $technician = ['id' => 3, 'role' => 'technician'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $rid = strtoupper(bin2hex(random_bytes(4)));
    $ticketId = 0;
    $assetId = 0;
    $locationId = 0;
    $departmentId = 0;
    $notificationBaseline = (int) aiv_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM notifications')->fetchColumn();
    $emailBaseline = (int) aiv_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM email_queue')->fetchColumn();

    try {
        aiv_pdo()->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
            ->execute(["AIVD-$rid", "Analytics immutable $rid"]);
        $departmentId = (int) aiv_pdo()->lastInsertId();

        aiv_pdo()->prepare('INSERT INTO locations (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
            ->execute(["AIVL-$rid", "Analytics immutable location $rid"]);
        $locationId = (int) aiv_pdo()->lastInsertId();

        $assetCategoryId = (int) aiv_pdo()->query('SELECT id FROM asset_categories ORDER BY id LIMIT 1')->fetchColumn();
        $ticketCategoryId = (int) aiv_pdo()->query('SELECT id FROM ticket_categories ORDER BY id LIMIT 1')->fetchColumn();
        $priorityId = (int) aiv_pdo()->query('SELECT id FROM priorities ORDER BY id LIMIT 1')->fetchColumn();

        aiv_pdo()->prepare(
            'INSERT INTO assets (asset_code, name, asset_category_id, department_id, location_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, "active", NOW(), NOW())'
        )->execute(["AIVA-$rid", "Analytics immutable asset $rid", $assetCategoryId, $departmentId, $locationId]);
        $assetId = (int) aiv_pdo()->lastInsertId();

        aiv_pdo()->prepare(
            'INSERT INTO tickets (
                ticket_no, title, description, requester_id, requester_department_id, location_id, asset_id,
                ticket_category_id, priority_id, assigned_manager_id, assigned_technician_id,
                approval_status, status, requested_at, approved_at, assigned_at, started_at, first_response_at,
                response_due_at, resolution_due_at, created_at, updated_at
             ) VALUES (
                ?, "Analytics period immutability", "late closure probe", 1, ?, ?, ?, ?, ?, 2, 3,
                "approved", "in_progress", "2021-01-05 09:00:00", "2021-01-05 09:10:00",
                "2021-01-05 09:20:00", "2021-01-05 09:30:00", "2021-01-05 09:30:00",
                "2021-01-05 10:00:00", "2021-01-06 09:00:00", "2021-01-05 09:00:00", "2021-01-05 09:30:00"
             )'
        )->execute(["AIV-$rid", $departmentId, $locationId, $assetId, $ticketCategoryId, $priorityId]);
        $ticketId = (int) aiv_pdo()->lastInsertId();

        aiv_pdo()->prepare(
            'INSERT INTO work_orders (
                work_order_no, ticket_id, technician_id, assigned_by, status, labor_minutes,
                assigned_at, accepted_at, started_at, created_at, updated_at
             ) VALUES (?, ?, 3, 2, "in_progress", 30, "2021-01-05 09:20:00",
                "2021-01-05 09:25:00", "2021-01-05 09:30:00", "2021-01-05 09:20:00", "2021-01-05 09:30:00")'
        )->execute(["AIVW-$rid", $ticketId]);

        aiv_pdo()->prepare(
            'INSERT INTO ticket_sla_tracks (ticket_id, metric_type, cycle, target_at, achieved_at, status, created_at)
             VALUES (?, "response", 1, "2021-01-05 10:00:00", "2021-01-05 09:30:00", "met", "2021-01-05 09:00:00")'
        )->execute([$ticketId]);
        aiv_pdo()->prepare(
            'INSERT INTO ticket_sla_tracks (ticket_id, metric_type, cycle, target_at, status, created_at)
             VALUES (?, "resolution", 1, "2021-01-06 09:00:00", "pending", "2021-01-05 09:00:00")'
        )->execute([$ticketId]);

        $common = [
            'department_id' => $departmentId,
            'from_date' => '2021-01-01',
            'to_date' => '2021-01-31',
        ];
        $snapshot = static function () use ($manager, $common, $locationId): array {
            $overview = aiv_reports()->getReportPageData($manager, $common);
            $asset = aiv_reports()->getAssetReliabilityReportPage($manager, [
                'location_id' => $locationId,
                'from_date' => '2021-01-01',
                'to_date' => '2021-01-31',
            ]);
            $hotspot = aiv_reports()->getProblemHotspotReportPage($manager, $common + ['dimension' => 'department']);
            $executive = aiv_executive_kpis(aiv_reports()->getExecutiveSummaryPage($manager, $common + ['preset' => 'custom']));

            return [
                'overview_resolved' => (int) ($overview['summary']['resolved'] ?? -1),
                'overview_mttr' => (string) ($overview['summary']['avgResolutionHoursLabel'] ?? ''),
                'overview_rating' => (string) ($overview['summary']['avgRatingLabel'] ?? ''),
                'asset_mttr' => (string) ($asset['rows'][0]['avg_resolution_hours_label'] ?? ''),
                'asset_downtime' => (string) ($asset['rows'][0]['downtime_hours_label'] ?? ''),
                'hotspot_open' => (int) ($hotspot['rows'][0]['open_count'] ?? -1),
                'hotspot_overdue' => (int) ($hotspot['rows'][0]['overdue_count'] ?? -1),
                'hotspot_overdue_rate' => (string) ($hotspot['rows'][0]['overdue_rate_label'] ?? ''),
                'hotspot_mttr' => (string) ($hotspot['rows'][0]['avg_resolution_hours_label'] ?? ''),
                'executive_resolved' => (string) ($executive['ปิดงาน'] ?? ''),
                'executive_completion' => (string) ($executive['อัตราปิดงาน'] ?? ''),
                'executive_breached' => (string) ($executive['เกิน SLA'] ?? ''),
                'executive_mttr' => (string) ($executive['เวลาซ่อมเฉลี่ย (ชม.)'] ?? ''),
                'executive_rating' => (string) ($executive['คะแนนเฉลี่ย'] ?? ''),
            ];
        };

        $before = $snapshot();
        aiv_workflow()->resolveAssignedWork($ticketId, $technician, [
            'diagnosis_summary' => 'late diagnosis',
            'resolution_summary' => 'late resolution',
            'labor_minutes' => 15,
        ]);
        $afterResolution = $snapshot();

        assert_same($before, $afterResolution, 'January metrics stay byte-for-byte unchanged after a July real-flow resolution');
        assert_same(0, $afterResolution['overview_resolved'], 'the January snapshot still sees the job as open');
        assert_same('-', $afterResolution['overview_mttr'], 'January does not acquire a 48k-hour MTTR from a July closure');

        aiv_workflow()->reopenTicket($ticketId, $requester, ['reopen_note' => 'late reopen']);
        assert_same($before, $snapshot(), 'a new SLA cycle created after period-end does not restate January');

        aiv_workflow()->acceptAssignedWork($ticketId, $technician, ['accept_note' => '']);
        aiv_workflow()->startAssignedWork($ticketId, $technician, ['start_note' => '']);
        aiv_workflow()->resolveAssignedWork($ticketId, $technician, [
            'diagnosis_summary' => 'second late diagnosis',
            'resolution_summary' => 'second late resolution',
            'labor_minutes' => 10,
        ]);
        aiv_workflow()->completeResolvedTicket($ticketId, $requester, [
            'score' => 5,
            'closure_note' => '',
            'feedback' => 'late period rating',
        ]);
        assert_same($before, $snapshot(), 'a rating recorded after period-end does not restate January CSAT');

        $includingClosure = aiv_reports()->getReportPageData($manager, [
            'department_id' => $departmentId,
            'from_date' => '2021-01-01',
            'to_date' => date('Y-m-d'),
        ]);
        assert_same(1, (int) ($includingClosure['summary']['resolved'] ?? 0), 'a report whose end date includes the real closure still counts it');
        assert_true((string) ($includingClosure['summary']['avgResolutionHoursLabel'] ?? '-') !== '-', 'the inclusive report still calculates MTTR');
        assert_same('5.0', (string) ($includingClosure['summary']['avgRatingLabel'] ?? '-'), 'the inclusive report still includes the real rating');
    } finally {
        if ($ticketId > 0) {
            aiv_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
            aiv_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        aiv_pdo()->prepare('DELETE FROM email_queue WHERE id > ?')->execute([$emailBaseline]);
        aiv_pdo()->prepare('DELETE FROM notifications WHERE id > ?')->execute([$notificationBaseline]);
        if ($assetId > 0) {
            aiv_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        }
        if ($locationId > 0) {
            aiv_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$locationId]);
        }
        if ($departmentId > 0) {
            aiv_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$departmentId]);
        }
    }
});
