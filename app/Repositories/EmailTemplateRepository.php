<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class EmailTemplateRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function getAllOverrides(): array
    {
        $stmt = $this->db->query(
            'SELECT template_key, field_key, field_value
             FROM email_template_overrides'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $template = (string) ($row['template_key'] ?? '');
            $field = (string) ($row['field_key'] ?? '');
            if ($template !== '' && $field !== '') {
                $map[$template][$field] = (string) ($row['field_value'] ?? '');
            }
        }

        return $map;
    }

    public function getByKey(string $templateKey): array
    {
        $stmt = $this->db->prepare(
            'SELECT field_key, field_value
             FROM email_template_overrides
             WHERE template_key = :template_key'
        );
        $stmt->execute(['template_key' => $templateKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $fields = [];
        foreach ($rows as $row) {
            $fields[(string) ($row['field_key'] ?? '')] = (string) ($row['field_value'] ?? '');
        }

        return $fields;
    }

    /**
     * บันทึกหลาย field ของ template หนึ่งอันแบบทั้งหมดหรือไม่ทำเลย: upsert แต่ละ field ทำใน
     * transaction เดียว ถ้าพังกลางคันจะ rollback field ก่อนหน้ากลับ (ไม่มีทางได้ template ที่เซฟครึ่งเดียว เช่น
     * subject ใหม่กับ body เก่า) $fieldValues คือ fieldKey => value
     *
     * @param array<string, string> $fieldValues
     */
    public function upsertFields(string $templateKey, array $fieldValues, int $userId): void
    {
        try {
            $this->db->beginTransaction();
            foreach ($fieldValues as $fieldKey => $value) {
                $this->upsertField($templateKey, (string) $fieldKey, (string) $value, $userId);
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function upsertField(string $templateKey, string $fieldKey, string $value, int $userId): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO email_template_overrides (template_key, field_key, field_value, updated_by, updated_at)
             VALUES (:template_key, :field_key, :field_value, :updated_by, :updated_at)
             ON DUPLICATE KEY UPDATE
                field_value = VALUES(field_value),
                updated_by = VALUES(updated_by),
                updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            'template_key' => $templateKey,
            'field_key' => $fieldKey,
            'field_value' => $value,
            'updated_by' => $userId > 0 ? $userId : null,
            'updated_at' => $now,
        ]);
    }

    public function resetTemplate(string $templateKey): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM email_template_overrides WHERE template_key = :template_key'
        );
        $stmt->execute(['template_key' => $templateKey]);
    }
}
