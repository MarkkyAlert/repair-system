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

    /** คืน true ถ้า broadcast ที่มี idempotency token นี้ถูกส่งไปแล้ว (กัน retry / เปิดแท็บซ้ำ). */
    public function broadcastTokenExists(string $submissionToken): bool
    {
        if ($submissionToken === '') {
            return false;
        }
        $stmt = $this->db->prepare('SELECT EXISTS(SELECT 1 FROM notifications WHERE submission_token = :token)');
        $stmt->execute(['token' => $submissionToken]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * สร้าง notification หนึ่งรายการแล้วแจกให้ผู้รับหลายคน.
     * ผลข้างเคียง: ทำใน transaction เดียว — INSERT notifications + INSERT notification_recipients (multi-row แบ่ง chunk).
     * ไม่ dedupe ในตัว: broadcast ที่มี submission_token ควรเช็ค broadcastTokenExists ก่อนเรียก; UNIQUE(submission_token)
     * เป็นด่านสุดท้าย (ถ้าชนจะ throw). ผู้รับที่ id ซ้ำ/<=0 ถูกกรองทิ้ง ถ้าไม่เหลือผู้รับก็ยังสร้างตัว notification
     * @param array<string, mixed> $payload ต้องมี 'type','title','message'; ไม่บังคับ 'payload','related_type','related_id','submission_token'
     * @param int[] $recipientIds user id ผู้รับ
     * @return int id ของ notification ที่สร้าง
     * @throws Throwable เมื่อ write ใด ๆ ล้มเหลว (rollback tx ก่อน rethrow)
     */
    public function createNotification(array $payload, array $recipientIds): int
    {
        $createdAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();

            $notificationStmt = $this->db->prepare(
                'INSERT INTO notifications (type, title, message, payload, related_type, related_id, submission_token, created_at)
                 VALUES (:type, :title, :message, :payload, :related_type, :related_id, :submission_token, :created_at)'
            );
            $notificationStmt->execute([
                'type' => $payload['type'],
                'title' => $payload['title'],
                'message' => $payload['message'],
                'payload' => $payload['payload'] ?? null,
                'related_type' => $payload['related_type'] ?? null,
                'related_id' => $payload['related_id'] ?? null,
                // idempotency token (เฉพาะ broadcast); กรณีอื่นเป็น NULL — UNIQUE ที่ nullable ยอมให้มี NULL ได้หลายค่า
                'submission_token' => ($payload['submission_token'] ?? '') !== '' ? $payload['submission_token'] : null,
                'created_at' => $createdAt,
            ]);

            $notificationId = (int) $this->db->lastInsertId();

            $this->insertRecipients($notificationId, $recipientIds, $createdAt);

            $this->db->commit();

            return $notificationId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * insert แถวผู้รับของ notification ด้วย multi-row INSERT ที่จำกัดขนาด (หนึ่ง statement ต่อ chunk) แทนที่จะ
     * execute() ทีละผู้รับ การ broadcast ไปทั้งองค์กรเดิมใช้ round-trip แบบ O(recipients); วิธีนี้ทำให้เหลือ
     * O(recipients / CHUNK) แบ่งเป็น chunk เพื่อคุมจำนวน placeholder ให้ต่ำกว่าขีดจำกัดของ driver/packet มาก ๆ
     *
     * @param int[] $recipientIds
     */
    private function insertRecipients(int $notificationId, array $recipientIds, string $createdAt): void
    {
        $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds), static fn (int $id): bool => $id > 0)));
        if ($recipientIds === []) {
            return;
        }

        foreach (array_chunk($recipientIds, 200) as $chunk) {
            $rows = implode(', ', array_fill(0, count($chunk), '(?, ?, 0, NULL, ?)'));
            $params = [];
            foreach ($chunk as $recipientId) {
                $params[] = $notificationId;
                $params[] = $recipientId;
                $params[] = $createdAt;
            }
            $this->db
                ->prepare("INSERT INTO notification_recipients (notification_id, user_id, is_read, read_at, created_at) VALUES $rows")
                ->execute($params);
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

    public function getUserNotificationsPage(int $userId, int $page, int $perPage): array
    {
        $perPage = max(1, min($perPage, 50));
        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM notification_recipients WHERE user_id = :user_id');
        $countStmt->execute(['user_id' => $userId]);
        $total = (int) ($countStmt->fetchColumn() ?: 0);
        ['page' => $page, 'offset' => $offset, 'totalPages' => $totalPages] = paginate($page, $perPage, $total);

        $stmt = $this->db->prepare(
            "SELECT nr.id AS recipient_id, nr.is_read, nr.read_at,
                n.id, n.type, n.title, n.message, n.payload, n.related_type, n.related_id, n.created_at
             FROM notification_recipients nr
             INNER JOIN notifications n ON n.id = nr.notification_id
             WHERE nr.user_id = :user_id
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute(['user_id' => $userId]);

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
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

    /**
     * ทำเครื่องหมายว่าผู้ใช้อ่าน notification นี้แล้ว.
     * ผลข้างเคียง: UPDATE notification_recipients (is_read=1, read_at ครั้งแรกด้วย COALESCE) เฉพาะแถวของ (user, notification) นี้; idempotent
     */
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

    /**
     * ทำเครื่องหมายว่าอ่านแล้วทุก notification ของผู้ใช้ที่ผูกกับ ticket นี้ (ใช้ตอนเปิดหน้ารายละเอียด ticket).
     * ผลข้างเคียง: UPDATE notification_recipients (join notifications) ทุกแถวที่ยัง unread ของ (user, related ticket) นี้; idempotent
     */
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

    /**
     * ทำเครื่องหมายว่าอ่านแล้วทุก notification ที่ยัง unread ของผู้ใช้.
     * ผลข้างเคียง: UPDATE notification_recipients ทุกแถวที่ is_read=0 ของ user นี้ (set is_read=1, read_at ครั้งแรกด้วย COALESCE)
     */
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
