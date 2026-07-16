<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Read-side ticket queries split out of TicketRepository (read-only — no mutations/locks/transactions):
 * dashboard aggregates, list/queue pagination, ticket detail, comments/activity, reference data, and
 * asset/notification-context reads. Grouped by the section comments below. The mutation side lives in
 * TicketRepository; the visibility + filter query-builders live here as private helpers.
 */
class TicketReadRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * As-reported (F1 Phase 2): ticket_sla_tracks / ticket_ratings now hold one row per lifecycle CYCLE
     * (reopen / re-rate append instead of overwrite). Every CURRENT-STATE surface here (dashboard overdue,
     * ticket detail SLA + rating, "breached" list filter) must read only the ticket's LATEST cycle, or an old
     * cycle's verdict/rating would leak into the current view. These pin an aliased reference to that cycle.
     */
    private function latestSlaCycleClause(string $a): string
    {
        return "$a.cycle = (SELECT MAX(slc.cycle) FROM ticket_sla_tracks slc WHERE slc.ticket_id = $a.ticket_id AND slc.metric_type = $a.metric_type)";
    }

    private function latestRatingCycleClause(string $a): string
    {
        return "$a.cycle = (SELECT MAX(rtc.cycle) FROM ticket_ratings rtc WHERE rtc.ticket_id = $a.ticket_id)";
    }

    // ── Dashboard reads — metrics, recent, trends, breakdowns, CSAT ──
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
                              AND {$this->latestSlaCycleClause('ts')}
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
                              AND {$this->latestSlaCycleClause('ts')}
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
             LEFT JOIN ticket_ratings tr ON tr.ticket_id = t.id AND {$this->latestRatingCycleClause('tr')}
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
                              AND {$this->latestSlaCycleClause('ts')}
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
             WHERE $whereClause AND {$this->latestRatingCycleClause('tr')}"
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

    // ── Private query helpers — dashboard filters + row visibility ──
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
            $conditions[] = "EXISTS (SELECT 1 FROM ticket_sla_tracks preset_ts WHERE preset_ts.ticket_id = t.id AND {$this->latestSlaCycleClause('preset_ts')} AND (preset_ts.status = 'breached' OR (preset_ts.status = 'pending' AND preset_ts.target_at < NOW())))";
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
        $role = (string) ($viewer['role'] ?? \App\Support\Role::GUEST);
        $userId = (int) ($viewer['id'] ?? 0);

        if ($role === \App\Support\Role::REQUESTER) {
            $params['requester_id'] = $userId;
            return 't.requester_id = :requester_id';
        }

        if ($role === \App\Support\Role::TECHNICIAN) {
            $params['technician_id'] = $userId;
            $params['technician_requester_id'] = $userId;
            return '(t.assigned_technician_id = :technician_id OR t.requester_id = :technician_requester_id)';
        }

        if ($role === \App\Support\Role::MANAGER || $role === \App\Support\Role::ADMIN) {
            return '1 = 1';
        }

        return '0 = 1';
    }

    // ── Ticket list / queue — pagination + filters ──
    public function getVisibleTicketsPage(array $viewer, array $filters, int $page, int $perPage): array
    {
        $params = [];
        $conditions = [$this->visibilityClause($viewer, $params)];
        $this->applyTicketIndexFilters($conditions, $filters, $params);
        $whereClause = implode(' AND ', $conditions);
        $perPage = max(1, min($perPage, 100));

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM tickets t
             INNER JOIN priorities p ON p.id = t.priority_id
             WHERE $whereClause"
        );
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetchColumn() ?: 0);
        ['page' => $page, 'offset' => $offset, 'totalPages' => $totalPages] = paginate($page, $perPage, $total);
        $closed = ticket_terminal_statuses_sql();

        $stmt = $this->db->prepare(
            "SELECT
                t.id, t.ticket_no, t.title, t.status, t.approval_status, t.channel,
                t.requested_at, t.updated_at, t.first_response_at, t.response_due_at,
                t.resolved_at, t.resolution_due_at,
                p.code AS priority_code, p.name AS priority_name,
                c.name AS category_name, l.name AS location_name,
                requester.full_name AS requester_name, technician.full_name AS technician_name
             FROM tickets t
             INNER JOIN priorities p ON p.id = t.priority_id
             INNER JOIN ticket_categories c ON c.id = t.ticket_category_id
             INNER JOIN locations l ON l.id = t.location_id
             INNER JOIN users requester ON requester.id = t.requester_id
             LEFT JOIN users technician ON technician.id = t.assigned_technician_id
             WHERE $whereClause
             ORDER BY
                CASE WHEN t.status IN ($closed) THEN 1 ELSE 0 END,
                CASE WHEN t.status NOT IN ($closed)
                          AND t.resolution_due_at IS NOT NULL AND t.resolution_due_at < NOW() THEN 0 ELSE 1 END,
                p.level DESC,
                t.requested_at DESC, t.id DESC
             LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    // ── SLA breach reads ──
    public function getPendingOverdueSlaBreaches(): array
    {
        $closed = ticket_terminal_statuses_sql();
        $stmt = $this->db->prepare(
            "SELECT
                ts.id,
                ts.ticket_id,
                ts.metric_type,
                ts.target_at,
                t.ticket_no,
                t.title,
                t.status,
                t.approval_status,
                t.requester_id,
                t.assigned_manager_id,
                t.assigned_technician_id
             FROM ticket_sla_tracks ts
             INNER JOIN tickets t ON t.id = ts.ticket_id
             WHERE ts.status = 'pending'
               AND {$this->latestSlaCycleClause('ts')}
               AND ts.target_at < NOW()
               AND t.status NOT IN ($closed)
             ORDER BY ts.target_at ASC, ts.id ASC
             LIMIT 500"
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── Reference data — create form ──
    public function getCreateFormReferenceData(): array
    {
        $priorities = $this->db->query(
            'SELECT id, code, name, level, response_time_minutes, resolution_time_minutes
             FROM priorities
             WHERE is_active = 1
             ORDER BY sort_order ASC, level ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $categories = $this->db->query(
            'SELECT id, code, name
             FROM ticket_categories
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $locations = $this->db->query(
            'SELECT id, code, name, building, floor, room
             FROM locations
             WHERE is_active = 1
             ORDER BY name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $assets = $this->db->query(
            "SELECT id, asset_code, name, location_id, status
             FROM assets
             WHERE status IN ('active', 'maintenance')
             ORDER BY asset_code ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'priorities' => $priorities,
            'categories' => $categories,
            'locations' => $locations,
            'assets' => $assets,
        ];
    }

    public function getActiveTechnicians(): array
    {
        $stmt = $this->db->query(
            "SELECT id, full_name
             FROM users
             WHERE role = 'technician' AND is_active = 1
             ORDER BY full_name ASC, id ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── Ticket detail — single ticket, comments, activity ──
    /**
     * Batch visibility-scoped fetch of the workflow-policy columns for a set of tickets — for bulk actions
     * (e.g. bulk approve) that would otherwise call findVisibleTicketById once per id. Returns only the
     * columns the policy + transition need, not the full detail row. (perf-review F2)
     *
     * @param int[] $ticketIds
     * @return array<int, array<string, mixed>>
     */
    public function findVisibleTicketsByIds(array $ticketIds, array $viewer): array
    {
        $ticketIds = array_values(array_unique(array_filter(array_map('intval', $ticketIds), static fn (int $id): bool => $id > 0)));
        if ($ticketIds === []) {
            return [];
        }

        $params = [];
        $visibility = $this->visibilityClause($viewer, $params);

        $placeholders = [];
        foreach ($ticketIds as $i => $id) {
            $key = 'bulk_id_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $inList = implode(', ', $placeholders);

        $stmt = $this->db->prepare(
            "SELECT t.id, t.ticket_no, t.status, t.approval_status, t.requester_id, t.assigned_manager_id, t.assigned_technician_id
             FROM tickets t
             WHERE t.id IN ($inList) AND $visibility"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findVisibleTicketById(int $ticketId, array $viewer): ?array
    {
        $params = ['ticket_id' => $ticketId];
        $visibility = $this->visibilityClause($viewer, $params);

        $stmt = $this->db->prepare(
            "SELECT
                t.id,
                t.ticket_no,
                t.title,
                t.description,
                t.requester_id,
                t.location_id,
                t.asset_id,
                t.ticket_category_id,
                t.priority_id,
                t.status,
                t.approval_status,
                t.assigned_manager_id,
                t.assigned_technician_id,
                t.channel,
                t.impact_level,
                t.urgency_level,
                t.requested_at,
                t.approved_at,
                t.assigned_at,
                t.started_at,
                t.first_response_at,
                t.resolved_at,
                t.completed_at,
                t.cancelled_at,
                t.closed_at,
                t.response_due_at,
                t.resolution_due_at,
                t.resolution_summary,
                t.closure_note,
                tr.score AS rating_score,
                tr.feedback AS rating_feedback,
                tr.created_at AS rating_created_at,
                p.code AS priority_code,
                p.name AS priority_name,
                c.name AS category_name,
                l.name AS location_name,
                l.building,
                l.floor,
                l.room,
                requester.full_name AS requester_name,
                requester.email AS requester_email,
                requester.phone AS requester_phone,
                manager.full_name AS manager_name,
                technician.full_name AS technician_name,
                wo.work_order_no,
                wo.status AS work_order_status,
                wo.instructions AS work_order_instructions,
                wo.diagnosis_summary AS work_order_diagnosis_summary,
                wo.resolution_summary AS work_order_resolution_summary,
                wo.labor_minutes AS work_order_labor_minutes,
                wo.assigned_at AS work_order_assigned_at,
                wo.accepted_at AS work_order_accepted_at,
                wo.started_at AS work_order_started_at,
                wo.completed_at AS work_order_completed_at,
                a.asset_code,
                a.name AS asset_name
             FROM tickets t
             INNER JOIN priorities p ON p.id = t.priority_id
             INNER JOIN ticket_categories c ON c.id = t.ticket_category_id
             INNER JOIN locations l ON l.id = t.location_id
             INNER JOIN users requester ON requester.id = t.requester_id
             LEFT JOIN users manager ON manager.id = t.assigned_manager_id
             LEFT JOIN users technician ON technician.id = t.assigned_technician_id
             LEFT JOIN work_orders wo ON wo.ticket_id = t.id
             LEFT JOIN ticket_ratings tr ON tr.ticket_id = t.id AND {$this->latestRatingCycleClause('tr')}
             LEFT JOIN assets a ON a.id = t.asset_id
             WHERE t.id = :ticket_id AND $visibility
             LIMIT 1"
        );
        $stmt->execute($params);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getCommentsByTicketId(int $ticketId, bool $includeInternal): array
    {
        $sql =
            'SELECT c.id, c.user_id, c.body, c.is_internal, c.created_at, c.updated_at, u.full_name AS author_name, u.role AS author_role
             FROM ticket_comments c
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.ticket_id = :ticket_id';

        if (!$includeInternal) {
            $sql .= ' AND c.is_internal = 0';
        }

        $sql .= ' ORDER BY c.created_at ASC, c.id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['ticket_id' => $ticketId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getActivityLogsByTicketId(int $ticketId): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.id, l.action, l.from_status, l.to_status, l.details, l.created_at, u.full_name AS actor_name, u.role AS actor_role
             FROM ticket_activity_logs l
             LEFT JOIN users u ON u.id = l.actor_id
             WHERE l.ticket_id = :ticket_id
             ORDER BY l.created_at DESC, l.id DESC'
        );
        $stmt->execute(['ticket_id' => $ticketId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── Counters & bounds ──
    public function countAllTickets(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
    }

    /** จำนวนช่างเทคนิคที่ใช้งานอยู่ — ใช้เช็ค checklist "เพิ่มผู้ใช้/ช่าง". */
    public function countTechnicians(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'technician' AND is_active = 1")->fetchColumn();
    }

    public function getMaxVisibleTicketId(array $viewer): int
    {
        $params = [];
        $visibility = $this->visibilityClause($viewer, $params);
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(t.id), 0) FROM tickets t WHERE $visibility");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    // ── Asset-related ticket reads ──
    public function findRecentTicketsByAssetId(int $assetId, int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));

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
             WHERE t.asset_id = :asset_id
             ORDER BY t.requested_at DESC, t.id DESC
             LIMIT $limit"
        );
        $stmt->execute(['asset_id' => $assetId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countTicketsByAssetId(int $assetId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM tickets WHERE asset_id = :asset_id');
        $stmt->execute(['asset_id' => $assetId]);

        return (int) $stmt->fetchColumn();
    }

    // ── Notification context reads ──
    public function findTicketNotificationContextById(int $ticketId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, ticket_no, title, requester_id, assigned_manager_id, assigned_technician_id, status, approval_status
             FROM tickets
             WHERE id = :ticket_id
             LIMIT 1'
        );
        $stmt->execute(['ticket_id' => $ticketId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findActiveApproverIds(): array
    {
        $stmt = $this->db->query(
            "SELECT id
             FROM users
             WHERE is_active = 1 AND role IN ('manager', 'admin')
             ORDER BY CASE role WHEN 'manager' THEN 1 ELSE 2 END, id ASC"
        );

        return array_map(static fn (mixed $id): int => (int) $id, $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    // ── Private query helper — ticket list filters ──
    private function applyTicketIndexFilters(array &$conditions, array $filters, array &$params): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $priority = trim((string) ($filters['priority'] ?? ''));
        $technicianId = (int) ($filters['technician_id'] ?? 0);
        $sla = trim((string) ($filters['sla'] ?? ''));

        if ($search !== '') {
            $conditions[] = '(t.ticket_no LIKE :ticket_no_search OR t.title LIKE :ticket_title_search)';
            $params['ticket_no_search'] = '%' . $search . '%';
            $params['ticket_title_search'] = '%' . $search . '%';
        }
        if ($status !== '') {
            $conditions[] = 't.status = :ticket_status';
            $params['ticket_status'] = $status;
        }
        if ($priority !== '') {
            $conditions[] = 'p.code = :ticket_priority';
            $params['ticket_priority'] = $priority;
        }
        if ($technicianId > 0) {
            $conditions[] = 't.assigned_technician_id = :ticket_technician_id';
            $params['ticket_technician_id'] = $technicianId;
        }
        if ($sla === 'overdue') {
            $conditions[] = 't.status NOT IN (' . ticket_terminal_statuses_sql() . ')';
            $conditions[] = "EXISTS (SELECT 1 FROM ticket_sla_tracks ticket_sla_filter WHERE ticket_sla_filter.ticket_id = t.id AND {$this->latestSlaCycleClause('ticket_sla_filter')} AND (ticket_sla_filter.status = 'breached' OR (ticket_sla_filter.status = 'pending' AND ticket_sla_filter.target_at < NOW())))";
        }
    }
}
