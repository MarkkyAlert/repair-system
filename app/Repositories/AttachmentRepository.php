<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class AttachmentRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(array $payload): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ticket_attachments
                (ticket_id, comment_id, uploaded_by, original_name, stored_name, disk_path, mime_type, file_size, created_at)
             VALUES
                (:ticket_id, :comment_id, :uploaded_by, :original_name, :stored_name, :disk_path, :mime_type, :file_size, :created_at)'
        );
        $stmt->execute($payload + ['created_at' => date('Y-m-d H:i:s')]);

        return (int) $this->db->lastInsertId();
    }

    public function findById(int $attachmentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT a.*, c.is_internal
             FROM ticket_attachments a
             LEFT JOIN ticket_comments c ON c.id = a.comment_id
             WHERE a.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $attachmentId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getByTicketId(int $ticketId, bool $includeInternal): array
    {
        $sql = 'SELECT a.*, c.is_internal
                FROM ticket_attachments a
                LEFT JOIN ticket_comments c ON c.id = a.comment_id
                WHERE a.ticket_id = :ticket_id';
        if (!$includeInternal) {
            $sql .= ' AND (a.comment_id IS NULL OR c.is_internal = 0)';
        }
        $sql .= ' ORDER BY a.created_at ASC, a.id ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['ticket_id' => $ticketId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Attachments belonging to a specific set of comment ids — for the live-poll feed which already knows
     * which (new) comments it is rendering, so it needn't scan every attachment on the ticket. (perf-review F4)
     *
     * @param int[] $commentIds
     * @return array<int, array<string, mixed>>
     */
    public function getByCommentIds(array $commentIds, bool $includeInternal): array
    {
        $commentIds = array_values(array_unique(array_filter(array_map('intval', $commentIds), static fn (int $id): bool => $id > 0)));
        if ($commentIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($commentIds), '?'));
        $sql = "SELECT a.*, c.is_internal
                FROM ticket_attachments a
                INNER JOIN ticket_comments c ON c.id = a.comment_id
                WHERE a.comment_id IN ($placeholders)";
        if (!$includeInternal) {
            $sql .= ' AND c.is_internal = 0';
        }
        $sql .= ' ORDER BY a.created_at ASC, a.id ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($commentIds);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getByCommentId(int $commentId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ticket_attachments WHERE comment_id = :comment_id');
        $stmt->execute(['comment_id' => $commentId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * คืน disk_path ทุกแถวใน DB (สำหรับ orphan cleanup worker)
     * คืนเป็น associative array [path => true] เพื่อ lookup เร็ว
     */
    public function getAllStoredPathsLookup(): array
    {
        $stmt = $this->db->query('SELECT disk_path FROM ticket_attachments');
        $lookup = [];
        foreach ($stmt as $row) {
            $path = trim((string) ($row['disk_path'] ?? ''));
            if ($path !== '') {
                $lookup[$path] = true;
            }
        }

        return $lookup;
    }
}
