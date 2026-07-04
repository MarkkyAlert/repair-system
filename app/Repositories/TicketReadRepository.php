<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Read-side ticket queries split out of TicketRepository — dashboard aggregates + CSAT summary
 * (read-only, no mutations/locks). First slice of the read/write repository split.
 * visibilityClause is intentionally kept in both repos so each stays self-contained.
 */
class TicketReadRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function getDashboardMetrics(array $viewer, array $filters = []): array
    {
        $params = [];
        $visibility = $this->visibilityClause($viewer, $params);
        $conditions = [$visibility];
        $this->applyDashboardFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);
        $closedStatuses = ticket_terminal_statuses_sql();

        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) AS total_tickets,
                COALESCE(SUM(CASE WHEN t.approval_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_approval_tickets,
                COALESCE(SUM(CASE WHEN t.status IN ('assigned', 'accepted', 'in_progress', 'on_hold') THEN 1 ELSE 0 END), 0) AS active_work_tickets,
                COALESCE(SUM(CASE
                    WHEN t.completed_at IS NOT NULL
                        AND YEAR(t.completed_at) = YEAR(CURDATE())
                        AND MONTH(t.completed_at) = MONTH(CURDATE())
                    THEN 1
                    ELSE 0
                END), 0) AS completed_this_month_tickets,
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
                END), 0) AS overdue_tickets
             FROM tickets t
             WHERE $whereClause"
        );
        $stmt->execute($params);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_tickets' => 0,
            'pending_approval_tickets' => 0,
            'active_work_tickets' => 0,
            'completed_this_month_tickets' => 0,
            'overdue_tickets' => 0,
        ];
    }

    public function getRecentTickets(array $viewer, array $filters = [], int $limit = 5): array
    {
        $params = [];
        $visibility = $this->visibilityClause($viewer, $params);
        $conditions = [$visibility];
        $this->applyDashboardFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);
        $limit = max(1, min($limit, 20));

        $stmt = $this->db->prepare(
            "SELECT
                t.id,
                t.ticket_no,
                t.title,
                t.status,
                t.approval_status,
                t.requested_at,
                t.first_response_at,
                t.response_due_at,
                t.resolved_at,
                t.resolution_due_at,
                p.code AS priority_code,
                p.name AS priority_name,
                l.name AS location_name,
                requester.full_name AS requester_name
             FROM tickets t
             INNER JOIN priorities p ON p.id = t.priority_id
             INNER JOIN locations l ON l.id = t.location_id
             INNER JOIN users requester ON requester.id = t.requester_id
             WHERE $whereClause
             ORDER BY t.requested_at DESC, t.id DESC
             LIMIT $limit"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getDashboardFilterReferenceData(): array
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

        $years = $this->db->query(
            'SELECT DISTINCT YEAR(requested_at) AS report_year
             FROM tickets
             WHERE requested_at IS NOT NULL
             ORDER BY report_year DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'departments' => $departments,
            'categories' => $categories,
            'years' => $years,
        ];
    }

    public function getDashboardMonthlyTicketCounts(array $viewer, array $filters, int $year): array
    {
        // ใช้ช่วงวันที่ (sargable) แทน YEAR(column) เพื่อให้ใช้ index idx_tickets_requested_at ได้
        $params = [
            'year_start' => sprintf('%04d-01-01 00:00:00', $year),
            'year_end' => sprintf('%04d-01-01 00:00:00', $year + 1),
        ];
        $conditions = [$this->visibilityClause($viewer, $params), 't.requested_at >= :year_start AND t.requested_at < :year_end'];
        $this->applyDashboardFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);

        $stmt = $this->db->prepare(
            "SELECT
                MONTH(t.requested_at) AS month_no,
                COUNT(*) AS total_tickets
             FROM tickets t
             WHERE $whereClause
             GROUP BY MONTH(t.requested_at)
             ORDER BY month_no ASC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getDashboardCategoryBreakdown(array $viewer, array $filters, int $limit = 6): array
    {
        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyDashboardFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);
        $limit = max(1, min($limit, 10));

        $stmt = $this->db->prepare(
            "SELECT
                c.id,
                c.name AS category_name,
                COUNT(*) AS total_tickets
             FROM tickets t
             INNER JOIN ticket_categories c ON c.id = t.ticket_category_id
             WHERE $whereClause
             GROUP BY c.id, c.name
             ORDER BY total_tickets DESC, c.name ASC
             LIMIT $limit"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getDashboardDepartmentBreakdown(array $viewer, array $filters, int $limit = 6): array
    {
        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyDashboardFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);
        $limit = max(1, min($limit, 10));

        $stmt = $this->db->prepare(
            "SELECT
                t.requester_department_id,
                COALESCE(d.name, 'Unassigned') AS department_name,
                COUNT(*) AS total_tickets
             FROM tickets t
             LEFT JOIN departments d ON d.id = t.requester_department_id
             WHERE $whereClause
             GROUP BY t.requester_department_id, d.name
             ORDER BY total_tickets DESC, department_name ASC
             LIMIT $limit"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getDashboardMonthlyResolutionAverages(array $viewer, array $filters, int $year): array
    {
        // ใช้ช่วงวันที่ (sargable) แทน YEAR(column) — resolved_at ยังไม่มี index แต่เลี่ยง function overhead
        $params = [
            'year_start' => sprintf('%04d-01-01 00:00:00', $year),
            'year_end' => sprintf('%04d-01-01 00:00:00', $year + 1),
        ];
        $conditions = [
            $this->visibilityClause($viewer, $params),
            't.resolved_at IS NOT NULL',
            't.resolved_at >= :year_start AND t.resolved_at < :year_end',
        ];
        $this->applyDashboardFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);

        $stmt = $this->db->prepare(
            "SELECT
                MONTH(t.resolved_at) AS month_no,
                AVG(TIMESTAMPDIFF(MINUTE, t.requested_at, t.resolved_at)) AS avg_minutes
             FROM tickets t
             WHERE $whereClause
             GROUP BY MONTH(t.resolved_at)
             ORDER BY month_no ASC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getDashboardTopTechnicians(array $viewer, array $filters, int $limit = 5): array
    {
        $params = [];
        $conditions = [
            $this->visibilityClause($viewer, $params),
            't.assigned_technician_id IS NOT NULL',
        ];
        $this->applyDashboardFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);
        $closedStatuses = ticket_terminal_statuses_sql();
        $limit = max(1, min($limit, 10));

        $stmt = $this->db->prepare(
            "SELECT
                u.id,
                u.full_name,
                COUNT(DISTINCT t.id) AS ticket_count,
                ROUND(COALESCE(AVG(tr.score), 0), 1) AS avg_rating,
                COUNT(DISTINCT CASE
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
                END) AS overdue_count
             FROM tickets t
             INNER JOIN users u ON u.id = t.assigned_technician_id
             LEFT JOIN ticket_ratings tr ON tr.ticket_id = t.id
             WHERE $whereClause
             GROUP BY u.id, u.full_name
             ORDER BY ticket_count DESC, avg_rating DESC, u.full_name ASC
             LIMIT $limit"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getDashboardTopCategories(array $viewer, array $filters, int $limit = 5): array
    {
        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyDashboardFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);
        $closedStatuses = ticket_terminal_statuses_sql();
        $limit = max(1, min($limit, 10));

        $stmt = $this->db->prepare(
            "SELECT
                c.id,
                c.name AS category_name,
                COUNT(*) AS total_tickets,
                COUNT(DISTINCT CASE
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
                END) AS overdue_count
             FROM tickets t
             INNER JOIN ticket_categories c ON c.id = t.ticket_category_id
             WHERE $whereClause
             GROUP BY c.id, c.name
             ORDER BY total_tickets DESC, c.name ASC
             LIMIT $limit"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getCsatSummary(array $viewer, array $filters = []): array
    {
        $params = [];
        $visibility = $this->visibilityClause($viewer, $params);
        $conditions = [$visibility];
        $this->applyDashboardFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);

        $stmt = $this->db->prepare(
            "SELECT
                COUNT(tr.id) AS total_ratings,
                COALESCE(ROUND(AVG(tr.score), 2), 0) AS average_score,
                SUM(CASE WHEN tr.score = 5 THEN 1 ELSE 0 END) AS s5,
                SUM(CASE WHEN tr.score = 4 THEN 1 ELSE 0 END) AS s4,
                SUM(CASE WHEN tr.score = 3 THEN 1 ELSE 0 END) AS s3,
                SUM(CASE WHEN tr.score = 2 THEN 1 ELSE 0 END) AS s2,
                SUM(CASE WHEN tr.score = 1 THEN 1 ELSE 0 END) AS s1,
                SUM(CASE WHEN tr.score >= 4 THEN 1 ELSE 0 END) AS positive_ratings
             FROM ticket_ratings tr
             INNER JOIN tickets t ON t.id = tr.ticket_id
             WHERE $whereClause"
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $total = (int) ($row['total_ratings'] ?? 0);
        $positive = (int) ($row['positive_ratings'] ?? 0);

        return [
            'total_ratings' => $total,
            'average_score' => (float) ($row['average_score'] ?? 0),
            'positive_percent' => $total > 0 ? (int) round($positive * 100 / $total) : 0,
            'distribution' => [
                5 => (int) ($row['s5'] ?? 0),
                4 => (int) ($row['s4'] ?? 0),
                3 => (int) ($row['s3'] ?? 0),
                2 => (int) ($row['s2'] ?? 0),
                1 => (int) ($row['s1'] ?? 0),
            ],
        ];
    }

    private function applyDashboardFilters(array &$conditions, array $filters, array &$params): void
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

        $preset = (string) ($filters['preset'] ?? '');
        $presetUserId = (int) ($filters['preset_user_id'] ?? 0);
        $presetRole = (string) ($filters['preset_role'] ?? '');
        if ($preset === 'mine' && $presetUserId > 0) {
            if ($presetRole === 'technician') {
                $conditions[] = 't.assigned_technician_id = :preset_user_id';
            } elseif ($presetRole === 'manager') {
                $conditions[] = 't.assigned_manager_id = :preset_user_id';
            } else {
                $conditions[] = 't.requester_id = :preset_user_id';
            }
            $params['preset_user_id'] = $presetUserId;
        } elseif ($preset === 'overdue') {
            $conditions[] = 't.status NOT IN (' . ticket_terminal_statuses_sql() . ')';
            $conditions[] = "EXISTS (SELECT 1 FROM ticket_sla_tracks preset_ts WHERE preset_ts.ticket_id = t.id AND (preset_ts.status = 'breached' OR (preset_ts.status = 'pending' AND preset_ts.target_at < NOW())))";
        } elseif ($preset === 'pending_approval') {
            $conditions[] = "t.status = 'pending_approval' AND t.approval_status = 'pending'";
        } elseif ($preset === 'today') {
            $conditions[] = 't.requested_at >= :preset_today_start AND t.requested_at <= :preset_today_end';
            $params['preset_today_start'] = date('Y-m-d 00:00:00');
            $params['preset_today_end'] = date('Y-m-d 23:59:59');
        }
    }

    private function visibilityClause(array $viewer, array &$params): string
    {
        $role = (string) ($viewer['role'] ?? 'guest');
        $userId = (int) ($viewer['id'] ?? 0);

        if ($role === 'requester') {
            $params['requester_id'] = $userId;
            return 't.requester_id = :requester_id';
        }

        if ($role === 'technician') {
            $params['technician_id'] = $userId;
            $params['technician_requester_id'] = $userId;
            return '(t.assigned_technician_id = :technician_id OR t.requester_id = :technician_requester_id)';
        }

        if ($role === 'manager' || $role === 'admin') {
            return '1 = 1';
        }

        return '0 = 1';
    }
}
