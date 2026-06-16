<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use RuntimeException;
use Throwable;

class TicketRepository
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
        $closedStatuses = "'resolved','completed','rejected','cancelled','closed'";

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
        $params = [
            'report_year' => $year,
        ];
        $conditions = [$this->visibilityClause($viewer, $params), 'YEAR(t.requested_at) = :report_year'];
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
        $params = [
            'report_year' => $year,
        ];
        $conditions = [
            $this->visibilityClause($viewer, $params),
            't.resolved_at IS NOT NULL',
            'YEAR(t.resolved_at) = :report_year',
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
        $closedStatuses = "'resolved','completed','rejected','cancelled','closed'";
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
        $closedStatuses = "'resolved','completed','rejected','cancelled','closed'";
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

    public function getVisibleTickets(array $viewer): array
    {
        $params = [];
        $visibility = $this->visibilityClause($viewer, $params);

        $stmt = $this->db->prepare(
            "SELECT
                t.id,
                t.ticket_no,
                t.title,
                t.status,
                t.approval_status,
                t.channel,
                t.requested_at,
                t.updated_at,
                t.first_response_at,
                t.response_due_at,
                t.resolved_at,
                t.resolution_due_at,
                p.code AS priority_code,
                p.name AS priority_name,
                c.name AS category_name,
                l.name AS location_name,
                requester.full_name AS requester_name,
                technician.full_name AS technician_name
             FROM tickets t
             INNER JOIN priorities p ON p.id = t.priority_id
             INNER JOIN ticket_categories c ON c.id = t.ticket_category_id
             INNER JOIN locations l ON l.id = t.location_id
             INNER JOIN users requester ON requester.id = t.requester_id
             LEFT JOIN users technician ON technician.id = t.assigned_technician_id
             WHERE $visibility
             ORDER BY t.requested_at DESC, t.id DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

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
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

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
             ORDER BY t.requested_at DESC, t.id DESC
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

    public function getPendingOverdueSlaBreaches(): array
    {
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
               AND ts.target_at < NOW()
               AND t.status NOT IN ('resolved', 'completed', 'rejected', 'cancelled', 'closed')
             ORDER BY ts.target_at ASC, ts.id ASC"
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markSlaBreachedById(int $slaTrackId, string $breachedAt): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE ticket_sla_tracks
             SET breached_at = COALESCE(breached_at, :breached_at),
                 status = :status
             WHERE id = :sla_track_id
               AND status = :pending_status
               AND target_at < :breached_at'
        );
        $stmt->execute([
            'breached_at' => $breachedAt,
            'status' => 'breached',
            'sla_track_id' => $slaTrackId,
            'pending_status' => 'pending',
        ]);

        return $stmt->rowCount() > 0;
    }

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

    public function createTicket(array $payload): array
    {
        $requestedAt = (string) ($payload['requested_at'] ?? date('Y-m-d H:i:s'));
        $responseDueAt = (string) ($payload['response_due_at'] ?? '');
        $resolutionDueAt = (string) ($payload['resolution_due_at'] ?? '');
        $submissionToken = (string) ($payload['submission_token'] ?? '');
        $existingTicketId = $this->findTicketIdBySubmissionToken($submissionToken);
        if ($existingTicketId !== null) {
            return ['id' => $existingTicketId, 'created' => false];
        }

        $approverId = $this->findDefaultApproverId();
        $numberLock = 'ticket-number-' . date('Ymd', strtotime($requestedAt) ?: time());
        $startedTransaction = !$this->db->inTransaction();

        if ($approverId === null) {
            throw new RuntimeException('ไม่พบผู้อนุมัติเริ่มต้นในระบบ กรุณาตรวจสอบข้อมูลผู้ใช้งาน role manager หรือ admin');
        }

        try {
            $this->acquireNamedLock($numberLock);
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }
            $ticketNo = $this->generateNextTicketNumber($requestedAt);

            $ticketStmt = $this->db->prepare(
                'INSERT INTO tickets (
                    ticket_no,
                    submission_token,
                    title,
                    description,
                    requester_id,
                    requester_department_id,
                    location_id,
                    asset_id,
                    ticket_category_id,
                    priority_id,
                    assigned_manager_id,
                    assigned_technician_id,
                    approval_status,
                    status,
                    channel,
                    impact_level,
                    urgency_level,
                    requested_at,
                    response_due_at,
                    resolution_due_at,
                    created_at,
                    updated_at
                 ) VALUES (
                    :ticket_no,
                    :submission_token,
                    :title,
                    :description,
                    :requester_id,
                    :requester_department_id,
                    :location_id,
                    :asset_id,
                    :ticket_category_id,
                    :priority_id,
                    :assigned_manager_id,
                    :assigned_technician_id,
                    :approval_status,
                    :status,
                    :channel,
                    :impact_level,
                    :urgency_level,
                    :requested_at,
                    :response_due_at,
                    :resolution_due_at,
                    :created_at,
                    :updated_at
                 )'
            );

            try {
                $ticketStmt->execute([
                    'ticket_no' => $ticketNo,
                    'submission_token' => $submissionToken,
                    'title' => $payload['title'],
                    'description' => $payload['description'],
                    'requester_id' => $payload['requester_id'],
                    'requester_department_id' => $payload['requester_department_id'],
                    'location_id' => $payload['location_id'],
                    'asset_id' => $payload['asset_id'],
                    'ticket_category_id' => $payload['ticket_category_id'],
                    'priority_id' => $payload['priority_id'],
                    'assigned_manager_id' => $approverId,
                    'assigned_technician_id' => null,
                    'approval_status' => 'pending',
                    'status' => 'pending_approval',
                    'channel' => 'web',
                    'impact_level' => $payload['impact_level'],
                    'urgency_level' => $payload['urgency_level'],
                    'requested_at' => $requestedAt,
                    'response_due_at' => $responseDueAt,
                    'resolution_due_at' => $resolutionDueAt,
                    'created_at' => $requestedAt,
                    'updated_at' => $requestedAt,
                ]);
            } catch (Throwable $exception) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                if ($this->isSubmissionTokenConflict($exception, $submissionToken)) {
                    $existingTicketId = $this->findTicketIdBySubmissionToken($submissionToken);
                    if ($existingTicketId !== null) {
                        return ['id' => $existingTicketId, 'created' => false];
                    }
                }

                throw $exception;
            }

            $ticketId = (int) $this->db->lastInsertId();

            $approvalStmt = $this->db->prepare(
                'INSERT INTO ticket_approvals (ticket_id, approver_id, action, note, acted_at, created_at)
                 VALUES (:ticket_id, :approver_id, :action, :note, :acted_at, :created_at)'
            );
            $approvalStmt->execute([
                'ticket_id' => $ticketId,
                'approver_id' => $approverId,
                'action' => 'pending',
                'note' => 'สร้างคำขออนุมัติอัตโนมัติจากหน้าแจ้งซ่อมใหม่',
                'acted_at' => null,
                'created_at' => $requestedAt,
            ]);

            $slaStmt = $this->db->prepare(
                'INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, achieved_at, breached_at, status, created_at)
                 VALUES (:ticket_id, :metric_type, :target_at, :achieved_at, :breached_at, :status, :created_at)'
            );
            $slaStmt->execute([
                'ticket_id' => $ticketId,
                'metric_type' => 'response',
                'target_at' => $responseDueAt,
                'achieved_at' => null,
                'breached_at' => null,
                'status' => 'pending',
                'created_at' => $requestedAt,
            ]);
            $slaStmt->execute([
                'ticket_id' => $ticketId,
                'metric_type' => 'resolution',
                'target_at' => $resolutionDueAt,
                'achieved_at' => null,
                'breached_at' => null,
                'status' => 'pending',
                'created_at' => $requestedAt,
            ]);

            $activityStmt = $this->db->prepare(
                'INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status, details, created_at)
                 VALUES (:ticket_id, :actor_id, :action, :from_status, :to_status, :details, :created_at)'
            );
            $activityStmt->execute([
                'ticket_id' => $ticketId,
                'actor_id' => $payload['requester_id'],
                'action' => 'ticket_submitted',
                'from_status' => null,
                'to_status' => 'pending_approval',
                'details' => 'ผู้ใช้งานสร้างรายการแจ้งซ่อมผ่านหน้าเว็บ',
                'created_at' => $requestedAt,
            ]);

            if ($startedTransaction) {
                $this->db->commit();
            }

            return ['id' => $ticketId, 'created' => true];
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        } finally {
            $this->releaseNamedLock($numberLock);
        }
    }

    public function approveTicket(int $ticketId, int $actorId, string $note, string $currentStatus): void
    {
        $actedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $this->lockTicketForTransition($ticketId, ['pending_approval'], 'pending');

            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET approval_status = :approval_status,
                     status = :status,
                     approved_at = :approved_at,
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'approval_status' => 'approved',
                'status' => 'approved',
                'approved_at' => $actedAt,
                'updated_at' => $actedAt,
                'ticket_id' => $ticketId,
            ]);

            $this->upsertApprovalDecision($ticketId, $actorId, 'approved', $note, $actedAt);
            $this->insertActivityLog($ticketId, $actorId, 'ticket_approved', $currentStatus, 'approved', $note !== '' ? $note : 'หัวหน้างานอนุมัติรายการแจ้งซ่อม');

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function rejectTicket(int $ticketId, int $actorId, string $note, string $currentStatus): void
    {
        $actedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $this->lockTicketForTransition($ticketId, ['pending_approval'], 'pending');

            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET approval_status = :approval_status,
                     status = :status,
                     approved_at = NULL,
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'approval_status' => 'rejected',
                'status' => 'rejected',
                'updated_at' => $actedAt,
                'ticket_id' => $ticketId,
            ]);

            $this->upsertApprovalDecision($ticketId, $actorId, 'rejected', $note, $actedAt);
            $this->insertActivityLog($ticketId, $actorId, 'ticket_rejected', $currentStatus, 'rejected', $note);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function assignTechnician(int $ticketId, int $actorId, int $technicianId, string $technicianName, string $instructions, string $currentStatus): void
    {
        $assignedAt = date('Y-m-d H:i:s');
        $workOrderNumberLock = null;

        try {
            $this->db->beginTransaction();
            $this->lockTicketForTransition($ticketId, ['approved', 'assigned'], 'approved');

            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET assigned_technician_id = :technician_id,
                     status = :status,
                     assigned_at = :assigned_at,
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'technician_id' => $technicianId,
                'status' => 'assigned',
                'assigned_at' => $assignedAt,
                'updated_at' => $assignedAt,
                'ticket_id' => $ticketId,
            ]);

            $existingWorkOrderStmt = $this->db->prepare(
                'SELECT id, work_order_no
                 FROM work_orders
                 WHERE ticket_id = :ticket_id
                 LIMIT 1'
            );
            $existingWorkOrderStmt->execute(['ticket_id' => $ticketId]);
            $existingWorkOrder = $existingWorkOrderStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($existingWorkOrder !== null) {
                $workOrderStmt = $this->db->prepare(
                    'UPDATE work_orders
                     SET technician_id = :technician_id,
                         assigned_by = :assigned_by,
                         status = :status,
                         instructions = :instructions,
                         assigned_at = :assigned_at,
                         accepted_at = NULL,
                         started_at = NULL,
                         completed_at = NULL,
                         updated_at = :updated_at
                     WHERE ticket_id = :ticket_id'
                );
                $workOrderStmt->execute([
                    'technician_id' => $technicianId,
                    'assigned_by' => $actorId,
                    'status' => 'assigned',
                    'instructions' => $instructions !== '' ? $instructions : null,
                    'assigned_at' => $assignedAt,
                    'updated_at' => $assignedAt,
                    'ticket_id' => $ticketId,
                ]);
            } else {
                $workOrderNumberLock = 'work-order-number-' . date('Ymd', strtotime($assignedAt) ?: time());
                $this->acquireNamedLock($workOrderNumberLock);
                $workOrderStmt = $this->db->prepare(
                    'INSERT INTO work_orders (
                        work_order_no,
                        ticket_id,
                        technician_id,
                        assigned_by,
                        status,
                        instructions,
                        diagnosis_summary,
                        resolution_summary,
                        labor_minutes,
                        assigned_at,
                        accepted_at,
                        started_at,
                        completed_at,
                        created_at,
                        updated_at
                     ) VALUES (
                        :work_order_no,
                        :ticket_id,
                        :technician_id,
                        :assigned_by,
                        :status,
                        :instructions,
                        :diagnosis_summary,
                        :resolution_summary,
                        :labor_minutes,
                        :assigned_at,
                        :accepted_at,
                        :started_at,
                        :completed_at,
                        :created_at,
                        :updated_at
                     )'
                );
                $workOrderStmt->execute([
                    'work_order_no' => $this->generateNextWorkOrderNumber($assignedAt),
                    'ticket_id' => $ticketId,
                    'technician_id' => $technicianId,
                    'assigned_by' => $actorId,
                    'status' => 'assigned',
                    'instructions' => $instructions !== '' ? $instructions : null,
                    'diagnosis_summary' => null,
                    'resolution_summary' => null,
                    'labor_minutes' => 0,
                    'assigned_at' => $assignedAt,
                    'accepted_at' => null,
                    'started_at' => null,
                    'completed_at' => null,
                    'created_at' => $assignedAt,
                    'updated_at' => $assignedAt,
                ]);
            }

            $details = 'มอบหมายงานให้ช่างเทคนิค ' . $technicianName;
            if ($instructions !== '') {
                $details .= ' พร้อมคำสั่งงาน: ' . $instructions;
            }

            $this->insertActivityLog($ticketId, $actorId, 'technician_assigned', $currentStatus, 'assigned', $details);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        } finally {
            if ($workOrderNumberLock !== null) {
                $this->releaseNamedLock($workOrderNumberLock);
            }
        }
    }

    public function acceptAssignedWork(int $ticketId, int $actorId, string $note, string $currentStatus): void
    {
        $acceptedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $this->lockTicketForTransition($ticketId, ['assigned'], 'approved', 'assigned_technician_id', $actorId);

            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET status = :status,
                     first_response_at = COALESCE(first_response_at, :first_response_at),
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'status' => 'accepted',
                'first_response_at' => $acceptedAt,
                'updated_at' => $acceptedAt,
                'ticket_id' => $ticketId,
            ]);

            $workOrderStmt = $this->db->prepare(
                'UPDATE work_orders
                 SET status = :status,
                     accepted_at = COALESCE(accepted_at, :accepted_at),
                     updated_at = :updated_at
                 WHERE ticket_id = :ticket_id'
            );
            $workOrderStmt->execute([
                'status' => 'accepted',
                'accepted_at' => $acceptedAt,
                'updated_at' => $acceptedAt,
                'ticket_id' => $ticketId,
            ]);

            if ($workOrderStmt->rowCount() === 0) {
                throw new RuntimeException('ไม่พบ work order สำหรับ ticket นี้');
            }

            $this->markSlaAchieved($ticketId, 'response', $acceptedAt);
            $this->insertActivityLog($ticketId, $actorId, 'work_accepted', $currentStatus, 'accepted', $note !== '' ? $note : 'ช่างเทคนิครับงานและยืนยันเริ่มดำเนินการ');

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function startAssignedWork(int $ticketId, int $actorId, string $note, string $currentStatus): void
    {
        $startedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $this->lockTicketForTransition($ticketId, ['assigned', 'accepted'], 'approved', 'assigned_technician_id', $actorId);

            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET status = :status,
                     started_at = COALESCE(started_at, :started_at),
                     first_response_at = COALESCE(first_response_at, :first_response_at),
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'status' => 'in_progress',
                'started_at' => $startedAt,
                'first_response_at' => $startedAt,
                'updated_at' => $startedAt,
                'ticket_id' => $ticketId,
            ]);

            $workOrderStmt = $this->db->prepare(
                'UPDATE work_orders
                 SET status = :status,
                     accepted_at = COALESCE(accepted_at, :accepted_at),
                     started_at = COALESCE(started_at, :started_at),
                     updated_at = :updated_at
                 WHERE ticket_id = :ticket_id'
            );
            $workOrderStmt->execute([
                'status' => 'in_progress',
                'accepted_at' => $startedAt,
                'started_at' => $startedAt,
                'updated_at' => $startedAt,
                'ticket_id' => $ticketId,
            ]);

            if ($workOrderStmt->rowCount() === 0) {
                throw new RuntimeException('ไม่พบ work order สำหรับ ticket นี้');
            }

            $this->markSlaAchieved($ticketId, 'response', $startedAt);
            $this->insertActivityLog($ticketId, $actorId, 'work_started', $currentStatus, 'in_progress', $note !== '' ? $note : 'ช่างเทคนิคเริ่มดำเนินงานตามที่ได้รับมอบหมาย');

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function resolveAssignedWork(int $ticketId, int $actorId, string $diagnosisSummary, string $resolutionSummary, int $laborMinutes, string $currentStatus): void
    {
        $resolvedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $this->lockTicketForTransition($ticketId, ['accepted', 'in_progress'], 'approved', 'assigned_technician_id', $actorId);

            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET status = :status,
                     started_at = COALESCE(started_at, :started_at),
                     first_response_at = COALESCE(first_response_at, :first_response_at),
                     resolved_at = :resolved_at,
                     resolution_summary = :resolution_summary,
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'status' => 'resolved',
                'started_at' => $resolvedAt,
                'first_response_at' => $resolvedAt,
                'resolved_at' => $resolvedAt,
                'resolution_summary' => $resolutionSummary,
                'updated_at' => $resolvedAt,
                'ticket_id' => $ticketId,
            ]);

            $workOrderStmt = $this->db->prepare(
                'UPDATE work_orders
                 SET status = :status,
                     accepted_at = COALESCE(accepted_at, :accepted_at),
                     started_at = COALESCE(started_at, :started_at),
                     completed_at = :completed_at,
                     diagnosis_summary = :diagnosis_summary,
                     resolution_summary = :resolution_summary,
                     labor_minutes = :labor_minutes,
                     updated_at = :updated_at
                 WHERE ticket_id = :ticket_id'
            );
            $workOrderStmt->execute([
                'status' => 'completed',
                'accepted_at' => $resolvedAt,
                'started_at' => $resolvedAt,
                'completed_at' => $resolvedAt,
                'diagnosis_summary' => $diagnosisSummary,
                'resolution_summary' => $resolutionSummary,
                'labor_minutes' => $laborMinutes,
                'updated_at' => $resolvedAt,
                'ticket_id' => $ticketId,
            ]);

            if ($workOrderStmt->rowCount() === 0) {
                throw new RuntimeException('ไม่พบ work order สำหรับ ticket นี้');
            }

            $this->markSlaAchieved($ticketId, 'response', $resolvedAt);
            $this->markSlaAchieved($ticketId, 'resolution', $resolvedAt);

            $details = 'ช่างเทคนิคสรุปการวิเคราะห์: ' . $diagnosisSummary . ' | วิธีแก้ไข: ' . $resolutionSummary;
            if ($laborMinutes > 0) {
                $details .= ' | ใช้เวลา ' . $laborMinutes . ' นาที';
            }

            $this->insertActivityLog($ticketId, $actorId, 'ticket_resolved', $currentStatus, 'resolved', $details);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function completeResolvedTicket(int $ticketId, int $actorId, ?int $technicianId, string $closureNote, int $score, string $feedback, string $currentStatus): void
    {
        $completedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $this->lockTicketForTransition($ticketId, ['resolved'], 'approved', 'requester_id', $actorId);

            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET status = :status,
                     completed_at = :completed_at,
                     closure_note = :closure_note,
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'status' => 'completed',
                'completed_at' => $completedAt,
                'closure_note' => $closureNote !== '' ? $closureNote : null,
                'updated_at' => $completedAt,
                'ticket_id' => $ticketId,
            ]);

            $this->upsertTicketRating($ticketId, $actorId, $technicianId, $score, $feedback, $completedAt);

            $details = 'ผู้แจ้งยืนยันผลการดำเนินงาน';
            if ($closureNote !== '') {
                $details .= ' | หมายเหตุปิดงาน: ' . $closureNote;
            }
            $details .= ' | คะแนนความพึงพอใจ: ' . $score . '/5';
            if ($feedback !== '') {
                $details .= ' | ความเห็น: ' . $feedback;
            }

            $this->insertActivityLog($ticketId, $actorId, 'ticket_completed', $currentStatus, 'completed', $details);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function reopenTicket(int $ticketId, int $actorId, string $note, string $currentStatus, string $responseDueAt, string $resolutionDueAt): void
    {
        $reopenedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $this->lockTicketForTransition($ticketId, ['resolved', 'completed'], 'approved', 'requester_id', $actorId);

            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET status = :status,
                     assigned_at = :assigned_at,
                     first_response_at = NULL,
                     started_at = NULL,
                     resolved_at = NULL,
                     completed_at = NULL,
                     resolution_summary = NULL,
                     closure_note = NULL,
                     response_due_at = :response_due_at,
                     resolution_due_at = :resolution_due_at,
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'status' => 'assigned',
                'assigned_at' => $reopenedAt,
                'response_due_at' => $responseDueAt,
                'resolution_due_at' => $resolutionDueAt,
                'updated_at' => $reopenedAt,
                'ticket_id' => $ticketId,
            ]);

            $workOrderStmt = $this->db->prepare(
                'UPDATE work_orders
                 SET status = :status,
                     assigned_at = :assigned_at,
                     accepted_at = NULL,
                     started_at = NULL,
                     completed_at = NULL,
                     diagnosis_summary = NULL,
                     resolution_summary = NULL,
                     labor_minutes = 0,
                     updated_at = :updated_at
                 WHERE ticket_id = :ticket_id'
            );
            $workOrderStmt->execute([
                'status' => 'assigned',
                'assigned_at' => $reopenedAt,
                'updated_at' => $reopenedAt,
                'ticket_id' => $ticketId,
            ]);

            if ($workOrderStmt->rowCount() === 0) {
                throw new RuntimeException('ไม่พบ work order สำหรับ ticket นี้');
            }

            $ratingStmt = $this->db->prepare('DELETE FROM ticket_ratings WHERE ticket_id = :ticket_id');
            $ratingStmt->execute(['ticket_id' => $ticketId]);

            $this->resetSlaTrack($ticketId, 'response', $responseDueAt);
            $this->resetSlaTrack($ticketId, 'resolution', $resolutionDueAt);
            $this->insertActivityLog($ticketId, $actorId, 'ticket_reopened', $currentStatus, 'assigned', $note);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function cancelTicket(int $ticketId, int $actorId, string $note, string $currentStatus): void
    {
        $cancelledAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $expectedApprovalStatus = $currentStatus === 'pending_approval' ? 'pending' : 'approved';
            $this->lockTicketForTransition($ticketId, [$currentStatus], $expectedApprovalStatus, 'requester_id', $actorId);

            $approvalStatus = $currentStatus === 'pending_approval' ? 'not_required' : 'approved';
            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET status = :status,
                     approval_status = :approval_status,
                     cancelled_at = :cancelled_at,
                     closure_note = :closure_note,
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'status' => 'cancelled',
                'approval_status' => $approvalStatus,
                'cancelled_at' => $cancelledAt,
                'closure_note' => $note,
                'updated_at' => $cancelledAt,
                'ticket_id' => $ticketId,
            ]);

            if ($currentStatus === 'pending_approval') {
                $approvalStmt = $this->db->prepare(
                    "DELETE FROM ticket_approvals
                     WHERE ticket_id = :ticket_id AND action = 'pending'"
                );
                $approvalStmt->execute(['ticket_id' => $ticketId]);
            }

            $this->insertActivityLog($ticketId, $actorId, 'ticket_cancelled', $currentStatus, 'cancelled', $note);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
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
             LEFT JOIN ticket_ratings tr ON tr.ticket_id = t.id
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

    private function generateNextTicketNumber(string $requestedAt): string
    {
        $datePart = date('Ymd', strtotime($requestedAt) ?: time());
        $prefix = 'MT-' . $datePart . '-';

        $stmt = $this->db->prepare(
            'SELECT ticket_no
             FROM tickets
             WHERE ticket_no LIKE :ticket_prefix
             ORDER BY ticket_no DESC
             LIMIT 1'
        );
        $stmt->execute(['ticket_prefix' => $prefix . '%']);

        $latestTicketNo = (string) ($stmt->fetchColumn() ?: '');
        $nextSequence = 1;

        if ($latestTicketNo !== '') {
            $suffix = substr($latestTicketNo, -4);
            if (ctype_digit($suffix)) {
                $nextSequence = ((int) $suffix) + 1;
            }
        }

        return $prefix . str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);
    }

    private function generateNextWorkOrderNumber(string $assignedAt): string
    {
        $datePart = date('Ymd', strtotime($assignedAt) ?: time());
        $prefix = 'WO-' . $datePart . '-';

        $stmt = $this->db->prepare(
            'SELECT work_order_no
             FROM work_orders
             WHERE work_order_no LIKE :work_order_prefix
             ORDER BY work_order_no DESC
             LIMIT 1'
        );
        $stmt->execute(['work_order_prefix' => $prefix . '%']);

        $latestWorkOrderNo = (string) ($stmt->fetchColumn() ?: '');
        $nextSequence = 1;

        if ($latestWorkOrderNo !== '') {
            $suffix = substr($latestWorkOrderNo, -4);
            if (ctype_digit($suffix)) {
                $nextSequence = ((int) $suffix) + 1;
            }
        }

        return $prefix . str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);
    }

    private function findDefaultApproverId(): ?int
    {
        $stmt = $this->db->query(
            "SELECT id
             FROM users
             WHERE is_active = 1 AND role IN ('manager', 'admin')
             ORDER BY CASE role WHEN 'manager' THEN 1 ELSE 2 END, id ASC
             LIMIT 1"
        );

        $approverId = $stmt->fetchColumn();

        return $approverId !== false ? (int) $approverId : null;
    }

    private function upsertApprovalDecision(int $ticketId, int $approverId, string $action, string $note, string $actedAt): void
    {
        $lookupStmt = $this->db->prepare(
            'SELECT id
             FROM ticket_approvals
             WHERE ticket_id = :ticket_id
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $lookupStmt->execute(['ticket_id' => $ticketId]);

        $approvalId = $lookupStmt->fetchColumn();

        if ($approvalId !== false) {
            $updateStmt = $this->db->prepare(
                'UPDATE ticket_approvals
                 SET approver_id = :approver_id,
                     action = :action,
                     note = :note,
                     acted_at = :acted_at
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'action' => $action,
                'approver_id' => $approverId,
                'note' => $note !== '' ? $note : null,
                'acted_at' => $actedAt,
                'id' => (int) $approvalId,
            ]);

            return;
        }

        $insertStmt = $this->db->prepare(
            'INSERT INTO ticket_approvals (ticket_id, approver_id, action, note, acted_at, created_at)
             VALUES (:ticket_id, :approver_id, :action, :note, :acted_at, :created_at)'
        );
        $insertStmt->execute([
            'ticket_id' => $ticketId,
            'approver_id' => $approverId,
            'action' => $action,
            'note' => $note !== '' ? $note : null,
            'acted_at' => $actedAt,
            'created_at' => $actedAt,
        ]);
    }

    private function findTicketIdBySubmissionToken(string $submissionToken): ?int
    {
        if ($submissionToken === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id FROM tickets WHERE submission_token = :submission_token LIMIT 1'
        );
        $stmt->execute(['submission_token' => $submissionToken]);
        $ticketId = $stmt->fetchColumn();

        return $ticketId !== false ? (int) $ticketId : null;
    }

    private function isSubmissionTokenConflict(Throwable $exception, string $submissionToken): bool
    {
        if ($submissionToken === '' || !$exception instanceof \PDOException) {
            return false;
        }

        if ((string) $exception->getCode() !== '23000') {
            return false;
        }

        return $this->findTicketIdBySubmissionToken($submissionToken) !== null;
    }

    private function acquireNamedLock(string $name): void
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(:name, 5)');
        $stmt->execute(['name' => $name]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('ระบบกำลังสร้างเลข Ticket กรุณาลองอีกครั้ง');
        }
    }

    private function releaseNamedLock(string $name): void
    {
        try {
            $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute(['name' => $name]);
        } catch (Throwable) {
            // Releasing a connection-scoped lock must not hide the original operation result.
        }
    }

    private function lockTicketForTransition(
        int $ticketId,
        array $allowedStatuses,
        ?string $expectedApprovalStatus = null,
        ?string $ownerColumn = null,
        ?int $ownerId = null
    ): void {
        $allowedOwnerColumns = ['assigned_technician_id', 'requester_id'];
        if ($ownerColumn !== null && !in_array($ownerColumn, $allowedOwnerColumns, true)) {
            throw new RuntimeException('ไม่สามารถตรวจสอบผู้ดำเนินการของ Ticket ได้');
        }

        $columns = 'status, approval_status';
        if ($ownerColumn !== null) {
            $columns .= ', ' . $ownerColumn;
        }

        $stmt = $this->db->prepare(
            "SELECT $columns
             FROM tickets
             WHERE id = :ticket_id
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute(['ticket_id' => $ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $valid = $ticket !== null
            && in_array((string) ($ticket['status'] ?? ''), $allowedStatuses, true)
            && ($expectedApprovalStatus === null || (string) ($ticket['approval_status'] ?? '') === $expectedApprovalStatus)
            && ($ownerColumn === null || (int) ($ticket[$ownerColumn] ?? 0) === (int) $ownerId);

        if (!$valid) {
            throw new RuntimeException('สถานะ Ticket ถูกเปลี่ยนแล้ว กรุณารีเฟรชหน้าแล้วลองอีกครั้ง');
        }
    }

    private function insertActivityLog(int $ticketId, int $actorId, string $action, ?string $fromStatus, ?string $toStatus, string $details): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status, details, created_at)
             VALUES (:ticket_id, :actor_id, :action, :from_status, :to_status, :details, :created_at)'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'actor_id' => $actorId,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'details' => $details !== '' ? $details : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function upsertTicketRating(int $ticketId, int $requesterId, ?int $technicianId, int $score, string $feedback, string $createdAt): void
    {
        $lookupStmt = $this->db->prepare(
            'SELECT id
             FROM ticket_ratings
             WHERE ticket_id = :ticket_id
             LIMIT 1'
        );
        $lookupStmt->execute(['ticket_id' => $ticketId]);

        $ratingId = $lookupStmt->fetchColumn();

        if ($ratingId !== false) {
            $updateStmt = $this->db->prepare(
                'UPDATE ticket_ratings
                 SET requester_id = :requester_id,
                     technician_id = :technician_id,
                     score = :score,
                     feedback = :feedback,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'requester_id' => $requesterId,
                'technician_id' => $technicianId,
                'score' => $score,
                'feedback' => $feedback !== '' ? $feedback : null,
                'updated_at' => $createdAt,
                'id' => (int) $ratingId,
            ]);

            return;
        }

        $insertStmt = $this->db->prepare(
            'INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, score, feedback, created_at, updated_at)
             VALUES (:ticket_id, :requester_id, :technician_id, :score, :feedback, :created_at, :updated_at)'
        );
        $insertStmt->execute([
            'ticket_id' => $ticketId,
            'requester_id' => $requesterId,
            'technician_id' => $technicianId,
            'score' => $score,
            'feedback' => $feedback !== '' ? $feedback : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function markSlaAchieved(int $ticketId, string $metricType, string $achievedAt): void
    {
        // RISK MAP: SLA status must be updated idempotently from the effective achieved timestamp.
        $stmt = $this->db->prepare(
            'UPDATE ticket_sla_tracks
             SET achieved_at = COALESCE(achieved_at, :achieved_at_value),
                 breached_at = CASE
                     WHEN COALESCE(achieved_at, :achieved_at_compare) > target_at
                         THEN COALESCE(breached_at, COALESCE(achieved_at, :breached_at_value))
                     ELSE breached_at
                 END,
                 status = CASE
                     WHEN COALESCE(achieved_at, :status_achieved_at_compare) > target_at THEN :breached_status
                     ELSE :met_status
                 END
             WHERE ticket_id = :ticket_id AND metric_type = :metric_type'
        );
        $stmt->execute([
            'achieved_at_value' => $achievedAt,
            'achieved_at_compare' => $achievedAt,
            'breached_at_value' => $achievedAt,
            'status_achieved_at_compare' => $achievedAt,
            'breached_status' => 'breached',
            'met_status' => 'met',
            'ticket_id' => $ticketId,
            'metric_type' => $metricType,
        ]);
    }

    private function resetSlaTrack(int $ticketId, string $metricType, string $targetAt): void
    {
        // RISK MAP: Reopen depends on existing response/resolution SLA rows; schema should enforce one row per metric.
        $stmt = $this->db->prepare(
            'UPDATE ticket_sla_tracks
             SET target_at = :target_at,
                 achieved_at = NULL,
                 breached_at = NULL,
                 status = :status
             WHERE ticket_id = :ticket_id AND metric_type = :metric_type'
        );
        $stmt->execute([
            'target_at' => $targetAt,
            'status' => 'pending',
            'ticket_id' => $ticketId,
            'metric_type' => $metricType,
        ]);
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
            $conditions[] = "t.status NOT IN ('resolved','completed','rejected','cancelled','closed')";
            $conditions[] = "EXISTS (SELECT 1 FROM ticket_sla_tracks preset_ts WHERE preset_ts.ticket_id = t.id AND (preset_ts.status = 'breached' OR (preset_ts.status = 'pending' AND preset_ts.target_at < NOW())))";
        } elseif ($preset === 'pending_approval') {
            $conditions[] = "t.status = 'pending_approval' AND t.approval_status = 'pending'";
        } elseif ($preset === 'today') {
            $conditions[] = 't.requested_at >= :preset_today_start AND t.requested_at <= :preset_today_end';
            $params['preset_today_start'] = date('Y-m-d 00:00:00');
            $params['preset_today_end'] = date('Y-m-d 23:59:59');
        }
    }

    private function applyTicketIndexFilters(array &$conditions, array $filters, array &$params): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $priority = trim((string) ($filters['priority'] ?? ''));
        $technicianId = (int) ($filters['technician_id'] ?? 0);

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
