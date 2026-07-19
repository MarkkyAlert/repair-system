<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class NotificationPreferenceRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function isEnabled(int $userId, string $notificationType, string $channel): bool
    {
        if ($userId <= 0 || $notificationType === '' || !in_array($channel, ['email', 'in_app'], true)) {
            return true;
        }

        $stmt = $this->db->prepare(
            'SELECT is_enabled
             FROM notification_preferences
             WHERE user_id = :user_id
               AND notification_type = :notification_type
               AND channel = :channel
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'notification_type' => $notificationType,
            'channel' => $channel,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return true;
        }

        return (int) $row['is_enabled'] === 1;
    }

    /**
     * ฝาแฝดแบบ batch ของ isEnabled(): คืนเฉพาะ $userIds ที่มีแถว is_enabled=0 ตั้งไว้ชัด ๆ
     * สำหรับ type/channel นี้ ผู้ใช้ที่ไม่มีแถวถือว่ายังเปิดอยู่ (โมเดลแบบ opt-out)
     * ช่วยให้ผู้เรียกกรองผู้รับหลายคนได้ด้วย query เดียว ไม่ต้อง query ทีละคน
     */
    public function disabledUserIds(array $userIds, string $notificationType, string $channel): array
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($userIds === [] || $notificationType === '' || !in_array($channel, ['email', 'in_app'], true)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT user_id
             FROM notification_preferences
             WHERE notification_type = ?
               AND channel = ?
               AND is_enabled = 0
               AND user_id IN ($placeholders)"
        );
        $stmt->execute([$notificationType, $channel, ...$userIds]);

        return array_map(static fn (mixed $id): int => (int) $id, $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function getMatrix(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT notification_type, channel, is_enabled
             FROM notification_preferences
             WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $matrix = [];
        foreach ($rows as $row) {
            $type = (string) ($row['notification_type'] ?? '');
            $channel = (string) ($row['channel'] ?? '');
            $matrix[$type][$channel] = (int) ($row['is_enabled'] ?? 1) === 1;
        }

        return $matrix;
    }

    public function upsertMatrix(int $userId, array $matrix): void
    {
        if ($userId <= 0) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO notification_preferences (user_id, notification_type, channel, is_enabled, updated_at)
             VALUES (:user_id, :notification_type, :channel, :is_enabled, :updated_at)
             ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                updated_at = VALUES(updated_at)'
        );

        $now = date('Y-m-d H:i:s');
        // ทั้งหมดหรือไม่ทำเลย: matrix ของ preference ทั้งชุดถือเป็นการบันทึกครั้งเดียว ห่อ upsert ของแต่ละ cell ไว้ใน transaction
        // ถ้าพังกลางคันจะ rollback cell ก่อนหน้ากลับ ไม่ให้เหลือ matrix ที่เซฟไปครึ่งเดียวค้างไว้
        try {
            $this->db->beginTransaction();
            foreach ($matrix as $type => $channels) {
                foreach ($channels as $channel => $enabled) {
                    if (!in_array($channel, ['email', 'in_app'], true)) {
                        continue;
                    }
                    $stmt->execute([
                        'user_id' => $userId,
                        'notification_type' => $type,
                        'channel' => $channel,
                        'is_enabled' => $enabled ? 1 : 0,
                        'updated_at' => $now,
                    ]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }
}
