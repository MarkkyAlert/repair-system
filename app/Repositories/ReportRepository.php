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

        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) AS total_tickets,
                COALESCE(SUM(CASE WHEN t.status IN ('resolved', 'completed') THEN 1 ELSE 0 END), 0) AS resolved_tickets,
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
                ROUND(COALESCE(AVG(CASE
                    WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.requested_at, t.resolved_at)
                    ELSE NULL
                END), 0), 1) AS avg_resolution_minutes,
                ROUND(COALESCE(AVG(tr.score), 0), 1) AS avg_rating
             FROM tickets t
             LEFT JOIN ticket_ratings tr ON tr.ticket_id = t.id
             WHERE $whereClause"
        );
        $stmt->execute($params);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_tickets' => 0,
            'resolved_tickets' => 0,
            'overdue_tickets' => 0,
            'avg_resolution_minutes' => 0,
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
                END), 0), 0) AS avg_resolution_minutes
             FROM tickets t
             INNER JOIN assets a ON a.id = t.asset_id
             INNER JOIN asset_categories ac ON ac.id = a.asset_category_id
             INNER JOIN locations l ON l.id = a.location_id
             WHERE $whereClause
             GROUP BY a.id, a.asset_code, a.name, a.status, ac.name, l.name
             ORDER BY failure_count DESC, last_failure_at DESC
             LIMIT " . $limit
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
        $role = (string) ($viewer['role'] ?? 'guest');
        $userId = (int) ($viewer['id'] ?? 0);

        if ($role === 'requester') {
            $params['requester_id'] = $userId;
            return 't.requester_id = :requester_id';
        }

        if ($role === 'technician') {
            $params['technician_id'] = $userId;
            return 't.assigned_technician_id = :technician_id';
        }

        if ($role === 'manager' || $role === 'admin') {
            return '1 = 1';
        }

        return '0 = 1';
    }
}
