<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Throwable;

class NotificationRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function createNotification(array $payload, array $recipientIds): int
    {
        $createdAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();

            $notificationStmt = $this->db->prepare(
                'INSERT INTO notifications (type, title, message, payload, related_type, related_id, created_at)
                 VALUES (:type, :title, :message, :payload, :related_type, :related_id, :created_at)'
            );
            $notificationStmt->execute([
                'type' => $payload['type'],
                'title' => $payload['title'],
                'message' => $payload['message'],
                'payload' => $payload['payload'] ?? null,
                'related_type' => $payload['related_type'] ?? null,
                'related_id' => $payload['related_id'] ?? null,
                'created_at' => $createdAt,
            ]);

            $notificationId = (int) $this->db->lastInsertId();

            $recipientStmt = $this->db->prepare(
                'INSERT INTO notification_recipients (notification_id, user_id, is_read, read_at, created_at)
                 VALUES (:notification_id, :user_id, :is_read, :read_at, :created_at)'
            );

            foreach ($recipientIds as $recipientId) {
                $recipientStmt->execute([
                    'notification_id' => $notificationId,
                    'user_id' => (int) $recipientId,
                    'is_read' => 0,
                    'read_at' => null,
                    'created_at' => $createdAt,
                ]);
            }

            $this->db->commit();

            return $notificationId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function getUserNotifications(int $userId, int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));

        $stmt = $this->db->prepare(
            "SELECT
                nr.id AS recipient_id,
                nr.is_read,
                nr.read_at,
                n.id,
                n.type,
                n.title,
                n.message,
                n.payload,
                n.related_type,
                n.related_id,
                n.created_at
             FROM notification_recipients nr
             INNER JOIN notifications n ON n.id = nr.notification_id
             WHERE nr.user_id = :user_id
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT $limit"
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countUnreadNotifications(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM notification_recipients
             WHERE user_id = :user_id AND is_read = 0'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function getTicketContextsByIds(array $ticketIds): array
    {
        $ticketIds = array_values(array_unique(array_filter(array_map('intval', $ticketIds), static fn (int $id): bool => $id > 0)));
        if ($ticketIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ticketIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT
                t.id,
                t.ticket_no,
                t.title,
                t.status,
                t.approval_status,
                t.requester_id,
                t.assigned_manager_id,
                t.assigned_technician_id,
                t.first_response_at,
                t.resolved_at,
                t.response_due_at,
                t.resolution_due_at,
                p.code AS priority_code,
                p.name AS priority_name
             FROM tickets t
             LEFT JOIN priorities p ON p.id = t.priority_id
             WHERE t.id IN ($placeholders)"
        );
        $stmt->execute($ticketIds);

        $contexts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $contexts[(int) ($row['id'] ?? 0)] = $row;
        }

        return $contexts;
    }

    public function markAsRead(int $userId, int $notificationId): void
    {
        $readAt = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE notification_recipients
             SET is_read = 1,
                 read_at = COALESCE(read_at, :read_at)
             WHERE user_id = :user_id AND notification_id = :notification_id'
        );
        $stmt->execute([
            'read_at' => $readAt,
            'user_id' => $userId,
            'notification_id' => $notificationId,
        ]);
    }

    public function markTicketNotificationsAsRead(int $userId, int $ticketId): void
    {
        $readAt = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "UPDATE notification_recipients nr
             INNER JOIN notifications n ON n.id = nr.notification_id
             SET nr.is_read = 1,
                 nr.read_at = COALESCE(nr.read_at, :read_at)
             WHERE nr.user_id = :user_id
               AND n.related_type = 'ticket'
               AND n.related_id = :ticket_id
               AND nr.is_read = 0"
        );
        $stmt->execute([
            'read_at' => $readAt,
            'user_id' => $userId,
            'ticket_id' => $ticketId,
        ]);
    }

    public function markAllAsRead(int $userId): void
    {
        $readAt = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE notification_recipients
             SET is_read = 1,
                 read_at = COALESCE(read_at, :read_at)
             WHERE user_id = :user_id AND is_read = 0'
        );
        $stmt->execute([
            'read_at' => $readAt,
            'user_id' => $userId,
        ]);
    }
}
