<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class CommentRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function getCommentsByTicketId(int $ticketId, bool $includeInternal): array
    {
        $sql =
            'SELECT c.id, c.ticket_id, c.user_id, c.parent_id, c.body, c.is_internal, c.created_at, c.updated_at, u.full_name AS author_name, u.role AS author_role
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

    public function findCommentById(int $commentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id, c.ticket_id, c.user_id, c.parent_id, c.body, c.is_internal, c.created_at, c.updated_at, u.full_name AS author_name, u.role AS author_role
             FROM ticket_comments c
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.id = :comment_id
             LIMIT 1'
        );
        $stmt->execute(['comment_id' => $commentId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createComment(int $ticketId, int $userId, string $body, bool $isInternal, ?int $parentId = null): int
    {
        $createdAt = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO ticket_comments (ticket_id, user_id, parent_id, body, is_internal, created_at, updated_at)
             VALUES (:ticket_id, :user_id, :parent_id, :body, :is_internal, :created_at, :updated_at)'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'parent_id' => $parentId,
            'body' => $body,
            'is_internal' => $isInternal ? 1 : 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateComment(int $commentId, string $body, bool $isInternal): void
    {
        $stmt = $this->db->prepare(
            'UPDATE ticket_comments
             SET body = :body,
                 is_internal = :is_internal,
                 updated_at = :updated_at
             WHERE id = :comment_id'
        );
        $stmt->execute([
            'body' => $body,
            'is_internal' => $isInternal ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
            'comment_id' => $commentId,
        ]);
    }

    public function deleteComment(int $commentId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM ticket_comments
             WHERE id = :comment_id'
        );
        $stmt->execute(['comment_id' => $commentId]);
    }
}
