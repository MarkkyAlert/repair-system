<?php
declare(strict_types=1);

namespace App\Repositories;

use DomainException;
use PDO;
use Throwable;

class CommentRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * นับ comment ของ ticket ตาม visibility ของผู้ดู (requester ไม่เห็น internal) —
     * ใช้เป็น signal สำหรับ live poll ตรวจว่ามี comment ใหม่.
     */
    public function countForTicket(int $ticketId, bool $includeInternal): int
    {
        $sql = 'SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = :ticket_id';
        if (!$includeInternal) {
            $sql .= ' AND is_internal = 0';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['ticket_id' => $ticketId]);

        return (int) $stmt->fetchColumn();
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

    public function createComment(int $ticketId, int $userId, string $body, bool $isInternal, string $submissionToken, ?int $parentId = null): array
    {
        $createdAt = date('Y-m-d H:i:s');
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ticket_comments (ticket_id, user_id, parent_id, submission_token, body, is_internal, created_at, updated_at)
                 VALUES (:ticket_id, :user_id, :parent_id, :submission_token, :body, :is_internal, :created_at, :updated_at)'
            );
            $stmt->execute([
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'parent_id' => $parentId,
                'submission_token' => $submissionToken,
                'body' => $body,
                'is_internal' => $isInternal ? 1 : 0,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            return ['id' => (int) $this->db->lastInsertId(), 'created' => true];
        } catch (Throwable $exception) {
            $stmt = $this->db->prepare(
                'SELECT id FROM ticket_comments WHERE submission_token = :submission_token LIMIT 1'
            );
            $stmt->execute(['submission_token' => $submissionToken]);
            $commentId = $stmt->fetchColumn();
            if ($commentId !== false) {
                return ['id' => (int) $commentId, 'created' => false];
            }

            throw $exception;
        }
    }

    public function updateComment(int $commentId, string $body, bool $isInternal, string $originalUpdatedAt): void
    {
        $stmt = $this->db->prepare(
            'UPDATE ticket_comments
             SET body = :body,
                 is_internal = :is_internal,
                 updated_at = :updated_at
             WHERE id = :comment_id
               AND updated_at = :original_updated_at'
        );
        $stmt->execute([
            'body' => $body,
            'is_internal' => $isInternal ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
            'comment_id' => $commentId,
            'original_updated_at' => $originalUpdatedAt,
        ]);

        if ($stmt->rowCount() > 0) {
            return;
        }

        $existsStmt = $this->db->prepare('SELECT updated_at FROM ticket_comments WHERE id = :comment_id LIMIT 1');
        $existsStmt->execute(['comment_id' => $commentId]);
        $currentUpdatedAt = $existsStmt->fetchColumn();

        if ($currentUpdatedAt === false) {
            throw new DomainException('ไม่พบ comment ที่ต้องการแก้ไข');
        }

        if ((string) $currentUpdatedAt !== $originalUpdatedAt) {
            throw new DomainException('Comment ถูกแก้ไขโดยผู้ใช้อื่นแล้ว กรุณารีเฟรชหน้าแล้วลองอีกครั้ง');
        }
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
