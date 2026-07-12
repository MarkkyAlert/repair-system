<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use RuntimeException;
use Throwable;

class ReportRepository
{
    // Absolute runaway-query ceiling. ต้อง >= ReportService::EXPORT_MAX_ROWS_* ที่ใหญ่สุด (CSV 50k)
    // เสมอ — ไม่งั้น overflow probe ของ export (getRows(maxRows+1)) จะถูกตัดเงียบ ๆ แล้ว export ได้ข้อมูล
    // ไม่ครบโดยไม่มี warning. limit จริง (screen 250 / export 10k-3k-50k) + overflow warning เป็นของ service.
    private const MAX_ROWS = 100000;

    public function __construct(private PDO $db)
    {
    }

    public function getFilterReferenceData(): array
    {
        $departments = $this->db->query(
            'SELECT id, name
             FROM departments
             WHERE is_active = 1
             ORDER BY name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $categories = $this->db->query(
            'SELECT id, name
             FROM ticket_categories
             WHERE is_active = 1
             ORDER BY sort_order ASC, name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'departments' => $departments,
            'categories' => $categories,
        ];
    }

    public function getSummary(array $viewer, array $filters): array
    {
        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyReportFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);
        $closedStatuses = ticket_terminal_statuses_sql();
        $resolvedStatuses = ticket_resolved_statuses_sql();

        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) AS total_tickets,
                COALESCE(SUM(CASE WHEN t.status IN ($resolvedStatuses) THEN 1 ELSE 0 END), 0) AS resolved_tickets,
                COALESCE(COUNT(DISTINCT CASE
                    WHEN t.status NOT IN ($closedStatuses)
                        AND EXISTS (
                            SELECT 1
                            FROM ticket_sla_tracks ts
                            WHERE ts.ticket_id = t.id
                              AND (
                                  ts.status = 'breached'
                                  OR (ts.status = 'pending' AND ts.target_at < NOW())
                              )
                        )
                    THEN t.id
                    ELSE NULL
                END), 0) AS overdue_tickets,
                COALESCE(SUM(CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM ticket_sla_tracks ts
                        WHERE ts.ticket_id = t.id
                          AND (
                              ts.status = 'breached'
                              OR (ts.status = 'pending' AND ts.target_at < NOW())
                          )
                    )
                    THEN 1
                    ELSE 0
                END), 0) AS breached_tickets,
                ROUND(COALESCE(AVG(CASE
                    WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.requested_at, t.resolved_at)
                    ELSE NULL
                END), 0), 1) AS avg_resolution_minutes,
                -- base for the MTTR average: how many tickets actually have a resolved_at. 0 → no data ('-');
                -- >0 with a 0-minute average → a real same-minute resolution ('0.0'), not 'no data'.
                SUM(CASE WHEN t.resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolution_base,
                ROUND(COALESCE(AVG(tr.score), 0), 1) AS avg_rating,
                COUNT(tr.score) AS rating_count
             FROM tickets t
             LEFT JOIN ticket_ratings tr ON tr.ticket_id = t.id
             WHERE $whereClause"
        );
        $stmt->execute($params);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_tickets' => 0,
            'resolved_tickets' => 0,
            'overdue_tickets' => 0,
            'breached_tickets' => 0,
            'avg_resolution_minutes' => 0,
            'resolution_base' => 0,
            'avg_rating' => 0,
        ];
    }

    public function getRows(array $viewer, array $filters, ?int $limit = 250): array
    {
        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyReportFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);
        $closedStatuses = ticket_terminal_statuses_sql();
        $limitClause = $limit !== null ? 'LIMIT ' . max(1, min($limit, self::MAX_ROWS)) : '';

        $stmt = $this->db->prepare(
            "SELECT
                t.id,
                t.ticket_no,
                t.title,
                t.status,
                t.approval_status,
                t.channel,
                t.requested_at,
                t.first_response_at,
                t.response_due_at,
                t.resolved_at,
                t.resolution_due_at,
                t.completed_at,
                p.code AS priority_code,
                p.name AS priority_name,
                c.name AS category_name,
                l.name AS location_name,
                COALESCE(d.name, 'Unassigned') AS department_name,
                requester.full_name AS requester_name,
                technician.full_name AS technician_name,
                tr.score AS rating_score,
                CASE
                    WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.requested_at, t.resolved_at)
                    ELSE NULL
                END AS resolution_minutes,
                CASE
                    WHEN t.status NOT IN ($closedStatuses)
                        AND EXISTS (
                            SELECT 1
                            FROM ticket_sla_tracks ts
                            WHERE ts.ticket_id = t.id
                              AND (
                                  ts.status = 'breached'
                                  OR (ts.status = 'pending' AND ts.target_at < NOW())
                              )
                        )
                    THEN 1
                    ELSE 0
                END AS is_overdue
             FROM tickets t
             INNER JOIN priorities p ON p.id = t.priority_id
             INNER JOIN ticket_categories c ON c.id = t.ticket_category_id
             INNER JOIN locations l ON l.id = t.location_id
             INNER JOIN users requester ON requester.id = t.requester_id
             LEFT JOIN departments d ON d.id = t.requester_department_id
             LEFT JOIN users technician ON technician.id = t.assigned_technician_id
             LEFT JOIN ticket_ratings tr ON tr.ticket_id = t.id
             WHERE $whereClause
             ORDER BY t.requested_at DESC, t.id DESC
             $limitClause"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * ทรัพย์สินที่แจ้งซ่อมบ่อย — group ticket ตาม asset (เฉพาะ ticket ที่ผูก asset) โดยใช้
     * filter + visibility ชุดเดียวกับ report เพื่อให้ทั้งหน้าสะท้อนตัวกรองเดียวกัน.
     */
    public function getAssetReliabilityRows(array $viewer, array $filters, int $limit = 20): array
    {
        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyReportFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);
        $limit = max(1, min($limit, 200));

        $stmt = $this->db->prepare(
            "SELECT
                a.id,
                a.asset_code,
                a.name,
                a.status,
                ac.name AS category_name,
                l.name AS location_name,
                COUNT(*) AS failure_count,
                MAX(t.requested_at) AS last_failure_at,
                ROUND(COALESCE(AVG(CASE
                    WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.requested_at, t.resolved_at)
                    ELSE NULL
                END), 0), 1) AS avg_resolution_minutes,
                SUM(CASE WHEN t.resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved_count,
                COALESCE(SUM(wo.labor_minutes), 0) AS labor_minutes
             FROM tickets t
             INNER JOIN assets a ON a.id = t.asset_id
             INNER JOIN asset_categories ac ON ac.id = a.asset_category_id
             INNER JOIN locations l ON l.id = a.location_id
             LEFT JOIN work_orders wo ON wo.ticket_id = t.id
             WHERE $whereClause
             GROUP BY a.id, a.asset_code, a.name, a.status, ac.name, l.name
             ORDER BY failure_count DESC, last_failure_at DESC
             LIMIT " . $limit
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Asset Reliability Report แบบเต็ม — เหมือน getAssetReliabilityRows แต่ (a) ใช้ asset-centric filter
     * (หมวดหมู่ asset / สถานะ asset / สถานที่ + ช่วงวันที่บน t.requested_at) และ (b) ดึงเมตริกเชิงลึกเพิ่ม
     * (purchase_date, warranty_expires_at, first_failure_at สำหรับ MTBF, downtime สะสม). work_orders 1:1
     * กับ ticket → LEFT JOIN แล้ว COUNT(*)/SUM(labor)/SUM(downtime) ไม่ fan-out.
     */
    public function getAssetReliabilityReport(array $viewer, array $filters, int $limit = 500): array
    {
        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyAssetReportFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);
        $limit = max(1, min($limit, self::MAX_ROWS));

        $stmt = $this->db->prepare(
            "SELECT
                a.id,
                a.asset_code,
                a.name,
                a.status,
                a.purchase_date,
                a.warranty_expires_at,
                ac.name AS category_name,
                l.name AS location_name,
                COUNT(*) AS failure_count,
                MAX(t.requested_at) AS last_failure_at,
                MIN(t.requested_at) AS first_failure_at,
                ROUND(COALESCE(AVG(CASE
                    WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.requested_at, t.resolved_at)
                    ELSE NULL
                END), 0), 1) AS avg_resolution_minutes,
                SUM(CASE WHEN t.resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved_count,
                COALESCE(SUM(CASE
                    WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.requested_at, t.resolved_at)
                    ELSE 0
                END), 0) AS downtime_minutes,
                COALESCE(SUM(wo.labor_minutes), 0) AS labor_minutes
             FROM tickets t
             INNER JOIN assets a ON a.id = t.asset_id
             INNER JOIN asset_categories ac ON ac.id = a.asset_category_id
             INNER JOIN locations l ON l.id = a.location_id
             LEFT JOIN work_orders wo ON wo.ticket_id = t.id
             WHERE $whereClause
             GROUP BY a.id, a.asset_code, a.name, a.status, a.purchase_date, a.warranty_expires_at, ac.name, l.name
             ORDER BY failure_count DESC, last_failure_at DESC
             LIMIT " . $limit
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** หมวดหมู่ asset + สถานที่ สำหรับ dropdown filter ของ Asset Reliability Report. */
    public function getAssetReportReferenceData(): array
    {
        $categories = $this->db->query(
            'SELECT id, name
             FROM asset_categories
             WHERE is_active = 1
             ORDER BY sort_order ASC, name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $locations = $this->db->query(
            'SELECT id, name
             FROM locations
             ORDER BY name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'categories' => $categories,
            'locations' => $locations,
        ];
    }

    /**
     * Asset-centric filter — กรองบนคุณสมบัติ asset (หมวดหมู่/สถานะ/สถานที่) + ช่วงวันที่แจ้งซ่อมบน
     * t.requested_at (มิเรอร์ date-clause ของ applyReportFilters). ใช้ prefix param แยกกัน asetreport_
     * เพื่อไม่ชนกับ visibilityClause.
     */
    private function applyAssetReportFilters(array &$conditions, array $filters, array &$params): void
    {
        $fromDate = is_string($filters['from_datetime'] ?? null) ? trim((string) $filters['from_datetime']) : '';
        $toDate = is_string($filters['to_datetime'] ?? null) ? trim((string) $filters['to_datetime']) : '';
        $categoryId = (int) ($filters['asset_category_id'] ?? 0);
        $locationId = (int) ($filters['location_id'] ?? 0);
        $status = is_string($filters['asset_status'] ?? null) ? trim((string) $filters['asset_status']) : '';

        if ($fromDate !== '') {
            $conditions[] = 't.requested_at >= :assetreport_from_date';
            $params['assetreport_from_date'] = $fromDate;
        }

        if ($toDate !== '') {
            $conditions[] = 't.requested_at <= :assetreport_to_date';
            $params['assetreport_to_date'] = $toDate;
        }

        if ($categoryId > 0) {
            $conditions[] = 'a.asset_category_id = :assetreport_category_id';
            $params['assetreport_category_id'] = $categoryId;
        }

        if ($locationId > 0) {
            $conditions[] = 'a.location_id = :assetreport_location_id';
            $params['assetreport_location_id'] = $locationId;
        }

        if ($status !== '') {
            $conditions[] = 'a.status = :assetreport_status';
            $params['assetreport_status'] = $status;
        }
    }

    /**
     * SLA compliance — นับ met/breached ต่อ priority × metric_type (response/resolution) ใช้ filter +
     * visibility ชุดเดียวกับ report. breached = status='breached' หรือ pending ที่เลยกำหนดแล้ว
     * (ตรงกับนิยาม overdue เดิมใน buildSlaMetricState). pending ที่ยังไม่ถึงกำหนด = ผลยังไม่ออก → ไม่นับ.
     */
    public function getSlaComplianceByPriority(array $viewer, array $filters): array
    {
        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyReportFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);

        $stmt = $this->db->prepare(
            "SELECT
                p.name AS priority_name,
                p.level AS priority_level,
                ts.metric_type,
                SUM(CASE WHEN ts.status = 'met' THEN 1 ELSE 0 END) AS met,
                SUM(CASE WHEN ts.status = 'breached' OR (ts.status = 'pending' AND ts.target_at < NOW()) THEN 1 ELSE 0 END) AS breached
             FROM ticket_sla_tracks ts
             INNER JOIN tickets t ON t.id = ts.ticket_id
             INNER JOIN priorities p ON p.id = t.priority_id
             WHERE $whereClause
             GROUP BY p.id, p.name, p.level, ts.metric_type
             ORDER BY p.level DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // มิติที่ยอมให้ group SLA breach ได้ (whitelist กัน SQL-injection: dimension มาจาก user input)
    private const SLA_BREACH_DIMENSIONS = [
        'priority' => ['join' => 'INNER JOIN priorities dim ON dim.id = t.priority_id', 'label' => 'dim.name', 'group' => 'dim.id', 'order' => 'dim.level DESC'],
        'category' => ['join' => 'INNER JOIN ticket_categories dim ON dim.id = t.ticket_category_id', 'label' => 'dim.name', 'group' => 'dim.id', 'order' => 'dim.name ASC'],
        'department' => ['join' => 'LEFT JOIN departments dim ON dim.id = t.requester_department_id', 'label' => "COALESCE(dim.name, 'ไม่ระบุแผนก')", 'group' => 'dim.id', 'order' => 'dim.name ASC'],
        'location' => ['join' => 'INNER JOIN locations dim ON dim.id = t.location_id', 'label' => 'dim.name', 'group' => 'dim.id', 'order' => 'dim.name ASC'],
    ];

    /**
     * SLA breach แยกตามมิติที่เลือก (priority/category/department/location) × metric_type — group
     * ticket_sla_tracks → tickets → dimension แล้วนับ met/breached (นิยาม breach เดียวกับ
     * getSlaComplianceByPriority). ts 2:1 กับ ticket แต่ join t→dimension เป็น 1:1 → group by (มิติ ×
     * metric_type) ไม่ fan-out. dimension มาจาก whitelist เท่านั้น (interpolate ปลอดภัย).
     */
    public function getSlaBreachByDimension(array $viewer, array $filters, string $dimension): array
    {
        $map = self::SLA_BREACH_DIMENSIONS[$dimension] ?? self::SLA_BREACH_DIMENSIONS['priority'];

        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applySlaBreachFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);

        $stmt = $this->db->prepare(
            "SELECT
                dim.id AS dimension_id,
                {$map['label']} AS dimension_label,
                ts.metric_type,
                SUM(CASE WHEN ts.status = 'met' THEN 1 ELSE 0 END) AS met,
                SUM(CASE WHEN ts.status = 'breached' OR (ts.status = 'pending' AND ts.target_at < NOW()) THEN 1 ELSE 0 END) AS breached
             FROM ticket_sla_tracks ts
             INNER JOIN tickets t ON t.id = ts.ticket_id
             {$map['join']}
             WHERE $whereClause
             GROUP BY {$map['group']}, ts.metric_type
             ORDER BY {$map['order']}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** dropdown filter ของ SLA Breach Report — แผนก + หมวดหมู่ + priority + สถานที่. */
    public function getSlaBreachReferenceData(): array
    {
        $departments = $this->db->query(
            'SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $categories = $this->db->query(
            'SELECT id, name FROM ticket_categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $priorities = $this->db->query(
            'SELECT id, name FROM priorities ORDER BY level DESC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $locations = $this->db->query(
            'SELECT id, name FROM locations ORDER BY name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'departments' => $departments,
            'categories' => $categories,
            'priorities' => $priorities,
            'locations' => $locations,
        ];
    }

    /**
     * Filter สำหรับ SLA Breach Report — narrow ตามช่วงวันที่แจ้ง + แผนก + หมวดหมู่ + priority + สถานที่
     * (บนตาราง t). prefix param slabreach_ กันชนกับ visibilityClause. breakdown dimension แยกต่างหาก.
     */
    private function applySlaBreachFilters(array &$conditions, array $filters, array &$params): void
    {
        $fromDate = is_string($filters['from_datetime'] ?? null) ? trim((string) $filters['from_datetime']) : '';
        $toDate = is_string($filters['to_datetime'] ?? null) ? trim((string) $filters['to_datetime']) : '';
        $departmentId = (int) ($filters['department_id'] ?? 0);
        $categoryId = (int) ($filters['category_id'] ?? 0);
        $priorityId = (int) ($filters['priority_id'] ?? 0);
        $locationId = (int) ($filters['location_id'] ?? 0);

        if ($fromDate !== '') {
            $conditions[] = 't.requested_at >= :slabreach_from_date';
            $params['slabreach_from_date'] = $fromDate;
        }
        if ($toDate !== '') {
            $conditions[] = 't.requested_at <= :slabreach_to_date';
            $params['slabreach_to_date'] = $toDate;
        }
        if ($departmentId > 0) {
            $conditions[] = 't.requester_department_id = :slabreach_department_id';
            $params['slabreach_department_id'] = $departmentId;
        }
        if ($categoryId > 0) {
            $conditions[] = 't.ticket_category_id = :slabreach_category_id';
            $params['slabreach_category_id'] = $categoryId;
        }
        if ($priorityId > 0) {
            $conditions[] = 't.priority_id = :slabreach_priority_id';
            $params['slabreach_priority_id'] = $priorityId;
        }
        if ($locationId > 0) {
            $conditions[] = 't.location_id = :slabreach_location_id';
            $params['slabreach_location_id'] = $locationId;
        }
    }

    /**
     * ผลงานช่างเทคนิคต่อคน — query เดียว fan-out-free (work_orders/ticket_ratings เป็น UNIQUE(ticket_id)
     * 1:1 → AVG(rating)/SUM(labor) ถูกต้อง). SLA ไม่รวมที่นี่ (sla_tracks 2:1 = fan-out; ดู SLA panel).
     */
    public function getTechnicianPerformance(array $viewer, array $filters, int $limit = 50): array
    {
        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyReportFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);
        $limit = max(1, min($limit, 200));
        $resolvedStatuses = ticket_resolved_statuses_sql();

        $stmt = $this->db->prepare(
            "SELECT
                u.id,
                u.full_name,
                COUNT(t.id) AS assigned,
                SUM(CASE WHEN t.status IN ($resolvedStatuses) THEN 1 ELSE 0 END) AS resolved,
                SUM(CASE WHEN t.status IN ('assigned', 'accepted', 'in_progress', 'on_hold') THEN 1 ELSE 0 END) AS open_count,
                ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.requested_at, t.resolved_at) ELSE NULL END), 1) AS mttr_minutes,
                SUM(CASE WHEN t.resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolution_base, -- base for MTTR (has a real close time); status='resolved' with NULL resolved_at must not read as 0.0
                ROUND(COALESCE(AVG(tr.score), 0), 2) AS avg_rating,
                COUNT(tr.score) AS rating_count,
                COALESCE(SUM(wo.labor_minutes), 0) AS labor_minutes
             FROM users u
             INNER JOIN tickets t ON t.assigned_technician_id = u.id
             LEFT JOIN ticket_ratings tr ON tr.ticket_id = t.id
             LEFT JOIN work_orders wo ON wo.ticket_id = t.id
             WHERE u.role = 'technician' AND $whereClause
             GROUP BY u.id, u.full_name
             ORDER BY resolved DESC, assigned DESC
             LIMIT " . $limit
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * ผลงานช่างในช่วงที่กรอง (Technician Performance Report) — เหมือน getTechnicianPerformance แต่เพิ่ม
     * เวลาตอบรับเฉลี่ย + SLA ตรงเวลาต่อคน (คิด ticket-level จาก resolution_due_at → ไม่ fan-out กับ
     * ratings/work_orders 1:1). date-filtered ตาม applyReportFilters. คง getTechnicianPerformance เดิม
     * (panel /reports ใช้อยู่) ไม่แตะ.
     */
    public function getTechnicianPeriodStats(array $viewer, array $filters): array
    {
        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyReportFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);
        $resolvedStatuses = ticket_resolved_statuses_sql();

        $stmt = $this->db->prepare(
            "SELECT
                u.id,
                u.full_name,
                COUNT(t.id) AS assigned,
                SUM(CASE WHEN t.status IN ($resolvedStatuses) THEN 1 ELSE 0 END) AS resolved,
                ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.requested_at, t.resolved_at) ELSE NULL END), 1) AS mttr_minutes,
                SUM(CASE WHEN t.resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolution_base, -- base for MTTR (has a real close time); status='resolved' with NULL resolved_at must not read as 0.0
                ROUND(AVG(CASE WHEN t.first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.requested_at, t.first_response_at) ELSE NULL END), 1) AS first_response_minutes,
                SUM(CASE WHEN t.first_response_at IS NOT NULL THEN 1 ELSE 0 END) AS first_response_count,
                SUM(CASE WHEN t.resolved_at IS NOT NULL AND t.resolution_due_at IS NOT NULL THEN 1 ELSE 0 END) AS sla_base,
                SUM(CASE WHEN t.resolved_at IS NOT NULL AND t.resolution_due_at IS NOT NULL AND t.resolved_at <= t.resolution_due_at THEN 1 ELSE 0 END) AS sla_on_time,
                ROUND(COALESCE(AVG(tr.score), 0), 2) AS avg_rating,
                COUNT(tr.score) AS rating_count,
                COALESCE(SUM(wo.labor_minutes), 0) AS labor_minutes
             FROM users u
             INNER JOIN tickets t ON t.assigned_technician_id = u.id
             LEFT JOIN ticket_ratings tr ON tr.ticket_id = t.id
             LEFT JOIN work_orders wo ON wo.ticket_id = t.id
             WHERE u.role = 'technician' AND $whereClause
             GROUP BY u.id, u.full_name"
            // no LIMIT: GROUP BY u.id yields exactly one row per technician (bounded by the technician count),
            // matching the unbounded getTechnicianLiveWorkload base. A LIMIT here silently dropped technicians
            // on a people-evaluation report and undercounted team totals when there were >200 of them.
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * งานค้างของช่าง ณ ปัจจุบัน (live snapshot) — นับ ticket ที่ยังไม่ terminal ต่อช่าง โดย **ไม่ขึ้นกับ
     * date filter** (= backlog จริงตอนนี้). base = ช่าง active ทุกคน (LEFT JOIN → idle ก็ได้แถว open_now=0)
     * เพื่อให้เห็นทั้งทีมสำหรับเกลี่ยงาน. terminal ไม่นับ (ticket_terminal_statuses_sql).
     */
    public function getTechnicianLiveWorkload(array $viewer): array
    {
        $terminal = ticket_terminal_statuses_sql();

        $stmt = $this->db->query(
            "SELECT
                u.id,
                u.full_name,
                SUM(CASE WHEN t.id IS NOT NULL THEN 1 ELSE 0 END) AS open_now,
                MIN(t.requested_at) AS oldest_open_at
             FROM users u
             LEFT JOIN tickets t
                ON t.assigned_technician_id = u.id
               AND t.status NOT IN ($terminal)
             WHERE u.role = 'technician' AND u.is_active = 1
             GROUP BY u.id, u.full_name
             ORDER BY u.full_name ASC, u.id ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // มิติที่ยอมให้ group Problem Hotspot ได้ (whitelist กัน SQL-injection)
    private const HOTSPOT_DIMENSIONS = [
        'department' => ['join' => 'LEFT JOIN departments dim ON dim.id = t.requester_department_id', 'label' => "COALESCE(dim.name, 'ไม่ระบุแผนก')", 'group' => 'dim.id'],
        'location' => ['join' => 'INNER JOIN locations dim ON dim.id = t.location_id', 'label' => 'dim.name', 'group' => 'dim.id'],
    ];

    /**
     * Problem Hotspot — สรุป ticket ต่อพื้นที่ (แผนก/สถานที่): ปริมาณ + งานค้าง + เกิน SLA + เวลาซ่อม + แรงงาน.
     * "เกิน SLA" = ticket-level นิยามเดียวกับ getSummary.overdue_tickets (status ยังไม่ terminal + EXISTS track
     * breached/pending-overdue) → reconcile กับ dashboard ได้ และไม่ fan-out (subquery scalar ต่อ ticket).
     * work_orders 1:1 → SUM(labor)/COUNT(*) ไม่ inflate. dimension มาจาก whitelist เท่านั้น.
     */
    public function getProblemHotspotByDimension(array $viewer, array $filters, string $dimension): array
    {
        $map = self::HOTSPOT_DIMENSIONS[$dimension] ?? self::HOTSPOT_DIMENSIONS['department'];
        $terminal = ticket_terminal_statuses_sql();

        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyReportFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);

        $stmt = $this->db->prepare(
            "SELECT
                {$map['label']} AS dimension_label,
                COUNT(*) AS ticket_count,
                SUM(CASE WHEN t.status NOT IN ($terminal) THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE
                    WHEN t.status NOT IN ($terminal)
                        AND EXISTS (
                            SELECT 1 FROM ticket_sla_tracks ts
                            WHERE ts.ticket_id = t.id
                              AND (ts.status = 'breached' OR (ts.status = 'pending' AND ts.target_at < NOW()))
                        )
                    THEN 1 ELSE 0
                END) AS overdue_count,
                SUM(CASE WHEN t.resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved_count,
                ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.requested_at, t.resolved_at) ELSE NULL END), 1) AS avg_resolution_minutes,
                COALESCE(SUM(wo.labor_minutes), 0) AS labor_minutes
             FROM tickets t
             LEFT JOIN work_orders wo ON wo.ticket_id = t.id
             {$map['join']}
             WHERE $whereClause
             GROUP BY {$map['group']}
             ORDER BY ticket_count DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // granularity → SQL DATE_FORMAT (whitelist กัน injection: key sortable ตรงกับ PHP ที่ gen bucket ฝั่ง service)
    private const TREND_GRANULARITY = [
        'day' => '%Y-%m-%d',
        'week' => '%x-%v',
        'month' => '%Y-%m',
    ];

    /** dept/category/status เท่านั้น (ตัดวันที่ออก) — trend ใช้ date filter คนละคอลัมน์ต่อ query. */
    private function applyTrendDimensionFilters(array &$conditions, array $filters, array &$params): void
    {
        $dateless = $filters;
        $dateless['from_datetime'] = '';
        $dateless['to_datetime'] = '';
        $this->applyReportFilters($conditions, $dateless, $params);
    }

    /** ปริมาณ ticket ที่ "แจ้ง" ต่องวด — group by DATE_FORMAT(requested_at). date window บน requested_at. */
    public function getTicketTrendCreated(array $viewer, array $filters, string $granularity): array
    {
        $fmt = self::TREND_GRANULARITY[$granularity] ?? self::TREND_GRANULARITY['month'];
        $from = is_string($filters['from_datetime'] ?? null) ? trim((string) $filters['from_datetime']) : '';
        $to = is_string($filters['to_datetime'] ?? null) ? trim((string) $filters['to_datetime']) : '';

        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyTrendDimensionFilters($conditions, $filters, $params);
        if ($from !== '') {
            $conditions[] = 't.requested_at >= :trend_from';
            $params['trend_from'] = $from;
        }
        if ($to !== '') {
            $conditions[] = 't.requested_at <= :trend_to';
            $params['trend_to'] = $to;
        }
        $whereClause = implode(' AND ', $conditions);

        $stmt = $this->db->prepare(
            "SELECT DATE_FORMAT(t.requested_at, '$fmt') AS bucket, COUNT(*) AS created
             FROM tickets t
             WHERE $whereClause
             GROUP BY bucket
             ORDER BY bucket"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * งานที่ "ปิด" ต่องวด + MTTR + SLA ตรงเวลา + CSAT — group by DATE_FORMAT(resolved_at). date window บน
     * resolved_at. ticket-level ไม่ fan-out (ticket_ratings 1:1). SLA base = ที่มี resolution_due_at.
     */
    public function getTicketTrendResolved(array $viewer, array $filters, string $granularity): array
    {
        $fmt = self::TREND_GRANULARITY[$granularity] ?? self::TREND_GRANULARITY['month'];
        $from = is_string($filters['from_datetime'] ?? null) ? trim((string) $filters['from_datetime']) : '';
        $to = is_string($filters['to_datetime'] ?? null) ? trim((string) $filters['to_datetime']) : '';

        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params), 't.resolved_at IS NOT NULL'];
        $this->applyTrendDimensionFilters($conditions, $filters, $params);
        if ($from !== '') {
            $conditions[] = 't.resolved_at >= :trend_from';
            $params['trend_from'] = $from;
        }
        if ($to !== '') {
            $conditions[] = 't.resolved_at <= :trend_to';
            $params['trend_to'] = $to;
        }
        $whereClause = implode(' AND ', $conditions);

        $stmt = $this->db->prepare(
            "SELECT
                DATE_FORMAT(t.resolved_at, '$fmt') AS bucket,
                COUNT(*) AS resolved,
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.requested_at, t.resolved_at)), 1) AS mttr_minutes,
                SUM(CASE WHEN t.resolution_due_at IS NOT NULL THEN 1 ELSE 0 END) AS sla_base,
                SUM(CASE WHEN t.resolution_due_at IS NOT NULL AND t.resolved_at <= t.resolution_due_at THEN 1 ELSE 0 END) AS sla_on_time,
                COALESCE(SUM(tr.score), 0) AS rating_sum,
                COUNT(tr.score) AS rating_count
             FROM tickets t
             LEFT JOIN ticket_ratings tr ON tr.ticket_id = t.id
             WHERE $whereClause
             GROUP BY bucket
             ORDER BY bucket"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // มิติที่ยอมให้ group Backlog Aging ได้ (whitelist กัน SQL-injection). status → label ดิบ (service map ไทย).
    private const BACKLOG_DIMENSIONS = [
        'priority' => ['join' => 'INNER JOIN priorities dim ON dim.id = t.priority_id', 'label' => 'dim.name', 'group' => 'dim.id'],
        'status' => ['join' => '', 'label' => 't.status', 'group' => 't.status'],
        'technician' => ['join' => 'LEFT JOIN users dim ON dim.id = t.assigned_technician_id', 'label' => "COALESCE(dim.full_name, 'ยังไม่มอบหมาย')", 'group' => 'dim.id'],
        'department' => ['join' => 'LEFT JOIN departments dim ON dim.id = t.requester_department_id', 'label' => "COALESCE(dim.name, 'ไม่ระบุแผนก')", 'group' => 'dim.id'],
        'location' => ['join' => 'INNER JOIN locations dim ON dim.id = t.location_id', 'label' => 'dim.name', 'group' => 'dim.id'],
    ];

    /**
     * Backlog Aging — pivot ticket ที่ยัง "ไม่ปิด" (status NOT IN terminal) ตามมิติ × ช่วงอายุ
     * (0-3/3-7/7-30/>30 วัน). อายุ = DATEDIFF(NOW(), requested_at). Snapshot ปัจจุบัน ไม่มี date filter.
     * ไม่ fan-out (tickets ล้วน + join 1:1/nullable). dimension มาจาก whitelist เท่านั้น.
     */
    public function getBacklogAgingByDimension(array $viewer, array $filters, string $dimension): array
    {
        $map = self::BACKLOG_DIMENSIONS[$dimension] ?? self::BACKLOG_DIMENSIONS['priority'];
        $terminal = ticket_terminal_statuses_sql();
        // clamp to 0 so a future requested_at (clock skew / bad import) never yields a negative age/oldest
        $age = 'GREATEST(DATEDIFF(NOW(), t.requested_at), 0)';

        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params), "t.status NOT IN ($terminal)"];
        $this->applyTrendDimensionFilters($conditions, $filters, $params); // dept/category (ไม่มี date/status)
        $whereClause = implode(' AND ', $conditions);

        $stmt = $this->db->prepare(
            "SELECT
                {$map['label']} AS dimension_label,
                SUM(CASE WHEN $age < 3 THEN 1 ELSE 0 END) AS bucket_0_3,
                SUM(CASE WHEN $age BETWEEN 3 AND 6 THEN 1 ELSE 0 END) AS bucket_3_7,
                SUM(CASE WHEN $age BETWEEN 7 AND 29 THEN 1 ELSE 0 END) AS bucket_7_30,
                SUM(CASE WHEN $age >= 30 THEN 1 ELSE 0 END) AS bucket_30_plus,
                COUNT(*) AS total,
                MAX($age) AS oldest_days
             FROM tickets t
             {$map['join']}
             WHERE $whereClause
             GROUP BY {$map['group']}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // มิติที่ยอมให้ group Reopen/FTF ได้ (whitelist กัน SQL-injection)
    private const REOPEN_DIMENSIONS = [
        'technician' => ['join' => 'LEFT JOIN users dim ON dim.id = t.assigned_technician_id', 'label' => "COALESCE(dim.full_name, 'ยังไม่มอบหมาย')", 'group' => 'dim.id'],
        'category' => ['join' => 'INNER JOIN ticket_categories dim ON dim.id = t.ticket_category_id', 'label' => 'dim.name', 'group' => 'dim.id'],
        'priority' => ['join' => 'INNER JOIN priorities dim ON dim.id = t.priority_id', 'label' => 'dim.name', 'group' => 'dim.id'],
        'department' => ['join' => 'LEFT JOIN departments dim ON dim.id = t.requester_department_id', 'label' => "COALESCE(dim.name, 'ไม่ระบุแผนก')", 'group' => 'dim.id'],
        'location' => ['join' => 'INNER JOIN locations dim ON dim.id = t.location_id', 'label' => 'dim.name', 'group' => 'dim.id'],
    ];

    /**
     * Reopen / First-Time-Fix — cohort ticket ที่ "ปิด" ในช่วง (activity `ticket_resolved`) แล้วนับว่าถูก
     * "เปิดซ้ำ" (มี activity `ticket_reopened`) กี่ตัว ต่อมิติ. reopened ⊆ resolved cohort → rate ≤ 100%.
     * ไม่ fan-out: COUNT(DISTINCT ticket_id) คุม many-events-per-ticket ; join t→dimension 1:1.
     * date window บน r.created_at (ตอนปิดงาน) ; dimension มาจาก whitelist เท่านั้น.
     */
    public function getReopenByDimension(array $viewer, array $filters, string $dimension): array
    {
        $map = self::REOPEN_DIMENSIONS[$dimension] ?? self::REOPEN_DIMENSIONS['technician'];
        $from = is_string($filters['from_datetime'] ?? null) ? trim((string) $filters['from_datetime']) : '';
        $to = is_string($filters['to_datetime'] ?? null) ? trim((string) $filters['to_datetime']) : '';

        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params), "r.action = 'ticket_resolved'"];
        $this->applyTrendDimensionFilters($conditions, $filters, $params); // dept/category (บน t) ไม่มี date/status
        if ($from !== '') {
            $conditions[] = 'r.created_at >= :reopen_from';
            $params['reopen_from'] = $from;
        }
        if ($to !== '') {
            $conditions[] = 'r.created_at <= :reopen_to';
            $params['reopen_to'] = $to;
        }
        $whereClause = implode(' AND ', $conditions);

        // As-reported (business decision): count ONLY reopens that happened WITHIN the window, so a past
        // period is immutable — a ticket closed in-window and reopened in a later period does not retroactively
        // drop this window's First-Time-Fix. Separate placeholders (:reopen_ro_*) — reusing :reopen_from/_to
        // would throw HY093 under EMULATE_PREPARES=false.
        $reopenBound = '';
        if ($from !== '') {
            $reopenBound .= ' AND ro.created_at >= :reopen_ro_from';
            $params['reopen_ro_from'] = $from;
        }
        if ($to !== '') {
            $reopenBound .= ' AND ro.created_at <= :reopen_ro_to';
            $params['reopen_ro_to'] = $to;
        }

        $stmt = $this->db->prepare(
            "SELECT
                {$map['label']} AS dimension_label,
                COUNT(DISTINCT r.ticket_id) AS resolved,
                COUNT(DISTINCT ro.ticket_id) AS reopened
             FROM ticket_activity_logs r
             INNER JOIN tickets t ON t.id = r.ticket_id
             {$map['join']}
             LEFT JOIN ticket_activity_logs ro ON ro.ticket_id = r.ticket_id AND ro.action = 'ticket_reopened'{$reopenBound}
             WHERE $whereClause
             GROUP BY {$map['group']}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // มิติที่ยอมให้ group CSAT ได้ (whitelist กัน SQL-injection). technician = ช่างที่ "ถูกให้คะแนน" จริง (tr.technician_id).
    private const CSAT_DIMENSIONS = [
        'technician' => ['join' => 'LEFT JOIN users dim ON dim.id = tr.technician_id', 'label' => "COALESCE(dim.full_name, 'ไม่ระบุช่าง')", 'group' => 'dim.id'],
        'category' => ['join' => 'INNER JOIN ticket_categories dim ON dim.id = t.ticket_category_id', 'label' => 'dim.name', 'group' => 'dim.id'],
        'priority' => ['join' => 'INNER JOIN priorities dim ON dim.id = t.priority_id', 'label' => 'dim.name', 'group' => 'dim.id'],
        'department' => ['join' => 'LEFT JOIN departments dim ON dim.id = t.requester_department_id', 'label' => "COALESCE(dim.name, 'ไม่ระบุแผนก')", 'group' => 'dim.id'],
        'location' => ['join' => 'INNER JOIN locations dim ON dim.id = t.location_id', 'label' => 'dim.name', 'group' => 'dim.id'],
    ];

    /** WHERE ร่วมของทุก query CSAT: visibility + dept/category (บน t) + date window บน tr.created_at (ตอนให้คะแนน). */
    private function ratingConditions(array $viewer, array $filters): array
    {
        $from = is_string($filters['from_datetime'] ?? null) ? trim((string) $filters['from_datetime']) : '';
        $to = is_string($filters['to_datetime'] ?? null) ? trim((string) $filters['to_datetime']) : '';

        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyTrendDimensionFilters($conditions, $filters, $params); // dept/category (บน t) ไม่มี date/status
        if ($from !== '') {
            $conditions[] = 'tr.created_at >= :csat_from';
            $params['csat_from'] = $from;
        }
        if ($to !== '') {
            $conditions[] = 'tr.created_at <= :csat_to';
            $params['csat_to'] = $to;
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * CSAT แยกตามมิติ — ค่าเฉลี่ยคะแนน/จำนวนรีวิว/พอใจ(≥4)/ไม่พอใจ(≤2) ต่อ ช่าง/หมวด/แผนก/สถานที่/priority.
     * base = ticket_ratings (1:1 กับ ticket, UNIQUE(ticket_id)) → ไม่ fan-out ; dimension มาจาก whitelist เท่านั้น.
     */
    public function getRatingByDimension(array $viewer, array $filters, string $dimension): array
    {
        $map = self::CSAT_DIMENSIONS[$dimension] ?? self::CSAT_DIMENSIONS['technician'];
        [$whereClause, $params] = $this->ratingConditions($viewer, $filters);

        $stmt = $this->db->prepare(
            "SELECT
                {$map['label']} AS dimension_label,
                COUNT(*) AS rating_count,
                SUM(tr.score) AS score_sum,
                SUM(tr.score >= 4) AS satisfied,
                SUM(tr.score <= 2) AS dissatisfied
             FROM ticket_ratings tr
             INNER JOIN tickets t ON t.id = tr.ticket_id
             {$map['join']}
             WHERE $whereClause
             GROUP BY {$map['group']}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** การกระจายคะแนน 1–5 (นับต่อ score) ในเงื่อนไขเดียวกับ report — service เติม bucket ที่หายให้เป็น 0. */
    public function getRatingDistribution(array $viewer, array $filters): array
    {
        [$whereClause, $params] = $this->ratingConditions($viewer, $filters);

        $stmt = $this->db->prepare(
            "SELECT tr.score AS score, COUNT(*) AS rating_count
             FROM ticket_ratings tr
             INNER JOIN tickets t ON t.id = tr.ticket_id
             WHERE $whereClause
             GROUP BY tr.score"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** feedback ดิบที่ผู้แจ้งเขียน (เฉพาะที่ไม่ว่าง) เรียงคะแนนแย่ก่อน — สำหรับส่วน "อ่าน feedback". */
    public function getRatingFeedback(array $viewer, array $filters, int $limit = 100): array
    {
        [$whereClause, $params] = $this->ratingConditions($viewer, $filters);
        $limit = max(1, min($limit, 500));

        $stmt = $this->db->prepare(
            "SELECT
                tr.score AS score,
                tr.feedback AS feedback,
                tr.created_at AS created_at,
                t.id AS ticket_id,
                t.ticket_no AS ticket_no,
                u.full_name AS technician_name,
                c.name AS category_name
             FROM ticket_ratings tr
             INNER JOIN tickets t ON t.id = tr.ticket_id
             LEFT JOIN users u ON u.id = tr.technician_id
             LEFT JOIN ticket_categories c ON c.id = t.ticket_category_id
             WHERE $whereClause AND tr.feedback IS NOT NULL AND TRIM(tr.feedback) <> ''
             ORDER BY tr.score ASC, tr.created_at DESC
             LIMIT $limit"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * ชั่วโมงแรงงานแยกตามหมวดงาน (จาก work_orders.labor_minutes) — ใช้ filter+visibility ชุดเดียวกับ report.
     * fan-out-free (work_orders UNIQUE(ticket_id) 1:1). HAVING labor_minutes>0 = โชว์เฉพาะหมวดที่มีแรงงานจริง.
     */
    public function getLaborByCategory(array $viewer, array $filters): array
    {
        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyReportFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);

        $stmt = $this->db->prepare(
            "SELECT
                c.name AS category_name,
                COUNT(t.id) AS tickets,
                SUM(CASE WHEN wo.labor_minutes > 0 THEN 1 ELSE 0 END) AS labored_tickets,
                COALESCE(SUM(wo.labor_minutes), 0) AS labor_minutes
             FROM tickets t
             INNER JOIN ticket_categories c ON c.id = t.ticket_category_id
             LEFT JOIN work_orders wo ON wo.ticket_id = t.id
             WHERE $whereClause
             GROUP BY c.id, c.name
             HAVING labor_minutes > 0
             ORDER BY labor_minutes DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createExportJob(int $requestedBy, string $type, string $format, array $filters): int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO export_jobs (type, format, filters, status, requested_by, created_at, updated_at)
                 VALUES (:type, :format, :filters, :status, :requested_by, :created_at, :updated_at)'
            );
            $now = date('Y-m-d H:i:s');
            $stmt->execute([
                'type' => $type,
                'format' => $format,
                'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'processing',
                'requested_by' => $requestedBy,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (Throwable $exception) {
            throw new RuntimeException('ไม่สามารถบันทึกงาน export ได้', 0, $exception);
        }
    }

    public function markExportJobCompleted(int $jobId, string $fileName, ?string $filePath = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE export_jobs
             SET status = :status,
                 file_name = :file_name,
                 file_path = :file_path,
                 completed_at = :completed_at,
                 updated_at = :updated_at,
                 error_message = NULL
             WHERE id = :id'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'status' => 'completed',
            'file_name' => $fileName,
            'file_path' => $filePath,
            'completed_at' => $now,
            'updated_at' => $now,
            'id' => $jobId,
        ]);
    }

    public function markExportJobFailed(int $jobId, string $errorMessage): void
    {
        $stmt = $this->db->prepare(
            'UPDATE export_jobs
             SET status = :status,
                 error_message = :error_message,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'updated_at' => $now,
            'id' => $jobId,
        ]);
    }

    private function applyReportFilters(array &$conditions, array $filters, array &$params): void
    {
        $fromDate = is_string($filters['from_datetime'] ?? null) ? trim((string) $filters['from_datetime']) : '';
        $toDate = is_string($filters['to_datetime'] ?? null) ? trim((string) $filters['to_datetime']) : '';
        $departmentId = (int) ($filters['department_id'] ?? 0);
        $categoryId = (int) ($filters['category_id'] ?? 0);
        $status = is_string($filters['status'] ?? null) ? trim((string) $filters['status']) : '';

        if ($fromDate !== '') {
            $conditions[] = 't.requested_at >= :filter_from_date';
            $params['filter_from_date'] = $fromDate;
        }

        if ($toDate !== '') {
            $conditions[] = 't.requested_at <= :filter_to_date';
            $params['filter_to_date'] = $toDate;
        }

        if ($departmentId > 0) {
            $conditions[] = 't.requester_department_id = :filter_department_id';
            $params['filter_department_id'] = $departmentId;
        }

        if ($categoryId > 0) {
            $conditions[] = 't.ticket_category_id = :filter_category_id';
            $params['filter_category_id'] = $categoryId;
        }

        if ($status !== '') {
            $conditions[] = 't.status = :filter_status';
            $params['filter_status'] = $status;
        }
    }

    private function visibilityClause(array $viewer, array &$params): string
    {
        $role = (string) ($viewer['role'] ?? \App\Support\Role::GUEST);
        $userId = (int) ($viewer['id'] ?? 0);

        if ($role === \App\Support\Role::REQUESTER) {
            $params['requester_id'] = $userId;
            return 't.requester_id = :requester_id';
        }

        if ($role === \App\Support\Role::TECHNICIAN) {
            $params['technician_id'] = $userId;
            return 't.assigned_technician_id = :technician_id';
        }

        if ($role === \App\Support\Role::MANAGER || $role === \App\Support\Role::ADMIN) {
            return '1 = 1';
        }

        return '0 = 1';
    }
}
