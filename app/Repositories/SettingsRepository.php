<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class SettingsRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        $stmt = $this->db->query(
            'SELECT id, setting_key, setting_value, value_type, is_public, updated_by, created_at, updated_at
             FROM system_settings
             ORDER BY setting_key ASC, id ASC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function upsert(string $key, ?string $value, string $type, bool $isPublic, int $updatedBy): void
    {
        // RISK MAP: การ check-then-insert (เช็คก่อนแล้วค่อย insert) อาศัย uq_system_settings_key; ควร catch duplicate-key หากมีการเขียนพร้อมกัน (concurrent writes) มากขึ้น
        $existing = $this->getByKey($key);
        $now = date('Y-m-d H:i:s');

        if ($existing !== null) {
            $stmt = $this->db->prepare(
                'UPDATE system_settings
                 SET setting_value = :setting_value,
                     value_type = :value_type,
                     is_public = :is_public,
                     updated_by = :updated_by,
                     updated_at = :updated_at
                 WHERE setting_key = :setting_key'
            );
            $stmt->execute([
                'setting_value' => $value,
                'value_type' => $type,
                'is_public' => $isPublic ? 1 : 0,
                'updated_by' => $updatedBy > 0 ? $updatedBy : null,
                'updated_at' => $now,
                'setting_key' => $key,
            ]);
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO system_settings (setting_key, setting_value, value_type, is_public, updated_by, created_at, updated_at)
             VALUES (:setting_key, :setting_value, :value_type, :is_public, :updated_by, :created_at, :updated_at)'
        );
        $stmt->execute([
            'setting_key' => $key,
            'setting_value' => $value,
            'value_type' => $type,
            'is_public' => $isPublic ? 1 : 0,
            'updated_by' => $updatedBy > 0 ? $updatedBy : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function getByKey(string $key): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, setting_key, setting_value, value_type, is_public, updated_by, created_at, updated_at
             FROM system_settings
             WHERE setting_key = :setting_key
             LIMIT 1'
        );
        $stmt->execute(['setting_key' => $key]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
