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
    }
}
