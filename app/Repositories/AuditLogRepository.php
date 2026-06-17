<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class AuditLogRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function record(array $payload): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, context, created_at)
             VALUES (:user_id, :action, :entity_type, :entity_id, :ip_address, :user_agent, :context, :created_at)'
        );
        $stmt->execute([
            'user_id' => (int) ($payload['user_id'] ?? 0) > 0 ? (int) $payload['user_id'] : null,
            'action' => (string) ($payload['action'] ?? ''),
            'entity_type' => (string) ($payload['entity_type'] ?? ''),
            'entity_id' => (int) ($payload['entity_id'] ?? 0) > 0 ? (int) $payload['entity_id'] : null,
            'ip_address' => $payload['ip_address'] ?? null,
            'user_agent' => $payload['user_agent'] ?? null,
            'context' => $payload['context'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function paginate(array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        [$where, $params] = $this->buildFilterSql($filters);

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM audit_logs a' . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            'SELECT a.id, a.user_id, u.full_name AS user_name, u.username, a.action, a.entity_type, a.entity_id,
                    a.ip_address, a.user_agent, a.context, a.created_at
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id'
            . $where .
            ' ORDER BY a.created_at DESC, a.id DESC
              LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . ltrim((string) $key, ':'), $value);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    public function getFilterOptions(): array
    {
        return [
            'actions' => $this->distinctValues('action'),
            'entityTypes' => $this->distinctValues('entity_type'),
        ];
    }

    private function buildFilterSql(array $filters): array
    {
        $clauses = [];
        $params = [];

        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '') {
            $clauses[] = 'a.action = :action';
            $params['action'] = $action;
        }

        $entityType = trim((string) ($filters['entity_type'] ?? ''));
        if ($entityType !== '') {
            $clauses[] = 'a.entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }

        $userId = (int) ($filters['user_id'] ?? 0);
        if ($userId > 0) {
            $clauses[] = 'a.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $clauses[] = 'a.created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $clauses[] = 'a.created_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    private function distinctValues(string $column): array
    {
        $stmt = $this->db->query(
            "SELECT DISTINCT {$column} AS value
             FROM audit_logs
             WHERE {$column} <> ''
             ORDER BY {$column} ASC"
        );

        return array_map(static fn (array $row): string => (string) ($row['value'] ?? ''), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
}
