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

    public function getByCommentId(int $commentId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ticket_attachments WHERE comment_id = :comment_id');
        $stmt->execute(['comment_id' => $commentId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
