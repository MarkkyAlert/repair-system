<?php
declare(strict_types=1);

namespace App\Repositories;

use DomainException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class AssetRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function getAssetList(): array
    {
        $stmt = $this->db->query(
            "SELECT
                a.id,
                a.asset_code,
                a.name,
                a.serial_number,
                a.brand,
                a.model,
                a.status,
                a.warranty_expires_at,
                ac.name AS category_name,
                l.name AS location_name,
                l.building,
                l.room,
                d.name AS department_name,
                custodian.full_name AS custodian_name,
                qr.token AS qr_token,
                qr.last_scanned_at
             FROM assets a
             INNER JOIN asset_categories ac ON ac.id = a.asset_category_id
             INNER JOIN locations l ON l.id = a.location_id
             LEFT JOIN departments d ON d.id = a.department_id
             LEFT JOIN users custodian ON custodian.id = a.custodian_user_id
             LEFT JOIN asset_qr_tokens qr ON qr.id = (
                SELECT q.id
                FROM asset_qr_tokens q
                WHERE q.asset_id = a.id AND q.is_active = 1
                ORDER BY q.id DESC
                LIMIT 1
             )
             ORDER BY a.asset_code ASC, a.id ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getAssetListPage(int $page, int $perPage, array $filters = []): array
    {
        $perPage = max(1, min($perPage, 100));
        [$whereSql, $params] = $this->buildAssetListWhere($filters);

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM assets a
             INNER JOIN asset_categories ac ON ac.id = a.asset_category_id
             INNER JOIN locations l ON l.id = a.location_id
             LEFT JOIN departments d ON d.id = a.department_id
             LEFT JOIN users custodian ON custodian.id = a.custodian_user_id
             $whereSql"
        );
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $countStmt->execute();

        $total = (int) ($countStmt->fetchColumn() ?: 0);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(
            "SELECT
                a.id, a.asset_code, a.name, a.serial_number, a.brand, a.model, a.status,
                a.warranty_expires_at, ac.name AS category_name, l.name AS location_name,
                l.building, l.room, d.name AS department_name, custodian.full_name AS custodian_name,
                qr.token AS qr_token, qr.last_scanned_at
             FROM assets a
             INNER JOIN asset_categories ac ON ac.id = a.asset_category_id
             INNER JOIN locations l ON l.id = a.location_id
             LEFT JOIN departments d ON d.id = a.department_id
             LEFT JOIN users custodian ON custodian.id = a.custodian_user_id
             LEFT JOIN asset_qr_tokens qr ON qr.id = (
                SELECT q.id FROM asset_qr_tokens q
                WHERE q.asset_id = a.id AND q.is_active = 1
                ORDER BY q.id DESC LIMIT 1
             )
             $whereSql
             ORDER BY a.asset_code ASC, a.id ASC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    private function buildAssetListWhere(array $filters): array
    {
        $where = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(a.asset_code LIKE :q_asset_code OR a.name LIKE :q_name OR a.serial_number LIKE :q_serial OR a.brand LIKE :q_brand OR a.model LIKE :q_model)';
            $like = '%' . $q . '%';
            $params['q_asset_code'] = $like;
            $params['q_name'] = $like;
            $params['q_serial'] = $like;
            $params['q_brand'] = $like;
            $params['q_model'] = $like;
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $where[] = 'a.asset_category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        $locationId = (int) ($filters['location_id'] ?? 0);
        if ($locationId > 0) {
            $where[] = 'a.location_id = :location_id';
            $params['location_id'] = $locationId;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }

    public function getAssetFormReferenceData(): array
    {
        $categories = $this->db->query(
            'SELECT id, code, name
             FROM asset_categories
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $departments = $this->db->query(
            'SELECT id, code, name
             FROM departments
             WHERE is_active = 1
             ORDER BY name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $locations = $this->db->query(
            'SELECT id, code, name, building, floor, room
             FROM locations
             WHERE is_active = 1
             ORDER BY name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $custodians = $this->db->query(
            "SELECT id, full_name, role
             FROM users
             WHERE is_active = 1
             ORDER BY full_name ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'categories' => $categories,
            'departments' => $departments,
            'locations' => $locations,
            'custodians' => $custodians,
        ];
    }

    private function translateAssetUniqueViolation(Throwable $exception): void
    {
        if (!$exception instanceof PDOException || (string) $exception->getCode() !== '23000') {
            return;
        }
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'asset_code')) {
            throw new DomainException('รหัส Asset นี้มีอยู่ในระบบแล้ว');
        }
        if (str_contains($message, 'serial_number')) {
            throw new DomainException('Serial number นี้มีอยู่ในระบบแล้ว');
        }
    }

    public function createAsset(array $payload): int
    {
        $createdAt = date('Y-m-d H:i:s');
        $token = $this->generateUniqueQrToken();

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                'INSERT INTO assets (
                    asset_code,
                    name,
                    serial_number,
                    asset_category_id,
                    department_id,
                    location_id,
                    custodian_user_id,
                    brand,
                    model,
                    vendor,
                    purchase_date,
                    warranty_expires_at,
                    status,
                    notes,
                    created_at,
                    updated_at
                 ) VALUES (
                    :asset_code,
                    :name,
                    :serial_number,
                    :asset_category_id,
                    :department_id,
                    :location_id,
                    :custodian_user_id,
                    :brand,
                    :model,
                    :vendor,
                    :purchase_date,
                    :warranty_expires_at,
                    :status,
                    :notes,
                    :created_at,
                    :updated_at
                 )'
            );
            $stmt->execute([
                'asset_code' => $payload['asset_code'],
                'name' => $payload['name'],
                'serial_number' => $payload['serial_number'] ?: null,
                'asset_category_id' => $payload['asset_category_id'],
                'department_id' => $payload['department_id'],
                'location_id' => $payload['location_id'],
                'custodian_user_id' => $payload['custodian_user_id'],
                'brand' => $payload['brand'] ?: null,
                'model' => $payload['model'] ?: null,
                'vendor' => $payload['vendor'] ?: null,
                'purchase_date' => $payload['purchase_date'] ?: null,
                'warranty_expires_at' => $payload['warranty_expires_at'] ?: null,
                'status' => $payload['status'],
                'notes' => $payload['notes'] ?: null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $assetId = (int) $this->db->lastInsertId();
            $this->insertQrToken($assetId, $token, $payload['generated_by'] ?? null, $createdAt);

            $this->db->commit();

            return $assetId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->translateAssetUniqueViolation($exception);

            throw $exception;
        }
    }

    public function updateAsset(int $assetId, array $payload): void
    {
        // Optimistic lock: prevent one admin's stale edit form from overwriting another admin's newer asset update.
        $stmt = $this->db->prepare(
            'UPDATE assets
             SET asset_code = :asset_code,
                 name = :name,
                 serial_number = :serial_number,
                 asset_category_id = :asset_category_id,
                 department_id = :department_id,
                 location_id = :location_id,
                 custodian_user_id = :custodian_user_id,
                 brand = :brand,
                 model = :model,
                 vendor = :vendor,
                 purchase_date = :purchase_date,
                 warranty_expires_at = :warranty_expires_at,
                 status = :status,
                 notes = :notes,
                 updated_at = :updated_at
             WHERE id = :asset_id
               AND updated_at = :original_updated_at'
        );
        try {
            $stmt->execute([
                'asset_code' => $payload['asset_code'],
                'name' => $payload['name'],
                'serial_number' => $payload['serial_number'] ?: null,
                'asset_category_id' => $payload['asset_category_id'],
                'department_id' => $payload['department_id'],
                'location_id' => $payload['location_id'],
                'custodian_user_id' => $payload['custodian_user_id'],
                'brand' => $payload['brand'] ?: null,
                'model' => $payload['model'] ?: null,
                'vendor' => $payload['vendor'] ?: null,
                'purchase_date' => $payload['purchase_date'] ?: null,
                'warranty_expires_at' => $payload['warranty_expires_at'] ?: null,
                'status' => $payload['status'],
                'notes' => $payload['notes'] ?: null,
                'updated_at' => date('Y-m-d H:i:s'),
                'asset_id' => $assetId,
                'original_updated_at' => (string) ($payload['original_updated_at'] ?? ''),
            ]);
        } catch (PDOException $exception) {
            $this->translateAssetUniqueViolation($exception);
            throw $exception;
        }

        if ($stmt->rowCount() > 0) {
            return;
        }

        $currentStmt = $this->db->prepare('SELECT updated_at FROM assets WHERE id = :asset_id LIMIT 1');
        $currentStmt->execute(['asset_id' => $assetId]);
        $currentUpdatedAt = $currentStmt->fetchColumn();
        if ($currentUpdatedAt === false || (string) $currentUpdatedAt !== (string) ($payload['original_updated_at'] ?? '')) {
            throw new DomainException('ข้อมูล Asset ถูกแก้ไขโดยผู้ใช้อื่นแล้ว กรุณารีเฟรชหน้าแล้วลองอีกครั้ง');
        }
    }

    public function regenerateQrToken(int $assetId, ?int $generatedBy = null): string
    {
        // RISK MAP: Keep deactivate+insert in one transaction; consider an active-token constraint for stronger safety.
        $createdAt = date('Y-m-d H:i:s');
        $token = $this->generateUniqueQrToken();

        try {
            $this->db->beginTransaction();

            $deactivateStmt = $this->db->prepare(
                'UPDATE asset_qr_tokens
                 SET is_active = 0,
                     updated_at = :updated_at
                 WHERE asset_id = :asset_id AND is_active = 1'
            );
            $deactivateStmt->execute([
                'updated_at' => $createdAt,
                'asset_id' => $assetId,
            ]);

            $this->insertQrToken($assetId, $token, $generatedBy, $createdAt);

            $this->db->commit();

            return $token;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function findAssetById(int $assetId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT
                a.id,
                a.asset_code,
                a.name,
                a.serial_number,
                a.asset_category_id,
                a.department_id,
                a.location_id,
                a.custodian_user_id,
                a.brand,
                a.model,
                a.vendor,
                a.purchase_date,
                a.warranty_expires_at,
                a.status,
                a.notes,
                a.created_at,
                a.updated_at,
                ac.code AS category_code,
                ac.name AS category_name,
                d.name AS department_name,
                l.code AS location_code,
                l.name AS location_name,
                l.building,
                l.floor,
                l.room,
                custodian.full_name AS custodian_name,
                qr.token AS qr_token,
                qr.last_scanned_at,
                qr.created_at AS qr_created_at
             FROM assets a
             INNER JOIN asset_categories ac ON ac.id = a.asset_category_id
             INNER JOIN locations l ON l.id = a.location_id
             LEFT JOIN departments d ON d.id = a.department_id
             LEFT JOIN users custodian ON custodian.id = a.custodian_user_id
             LEFT JOIN asset_qr_tokens qr ON qr.id = (
                SELECT q.id
                FROM asset_qr_tokens q
                WHERE q.asset_id = a.id AND q.is_active = 1
                ORDER BY q.id DESC
                LIMIT 1
             )
             WHERE a.id = :asset_id
             LIMIT 1"
        );
        $stmt->execute(['asset_id' => $assetId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findActiveAssetByToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT
                a.id,
                a.asset_code,
                a.name,
                a.serial_number,
                a.location_id,
                a.status,
                a.notes,
                ac.name AS category_name,
                l.name AS location_name,
                l.building,
                l.floor,
                l.room,
                qr.token,
                qr.last_scanned_at
             FROM asset_qr_tokens qr
             INNER JOIN assets a ON a.id = qr.asset_id
             INNER JOIN asset_categories ac ON ac.id = a.asset_category_id
             INNER JOIN locations l ON l.id = a.location_id
             WHERE qr.token = :token AND qr.is_active = 1
             LIMIT 1"
        );
        $stmt->execute(['token' => $token]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function touchScanToken(string $token): void
    {
        $stmt = $this->db->prepare(
            'UPDATE asset_qr_tokens
             SET last_scanned_at = :last_scanned_at,
                 updated_at = :updated_at
             WHERE token = :token AND is_active = 1'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'last_scanned_at' => $now,
            'updated_at' => $now,
            'token' => $token,
        ]);
    }

    public function getPrintableAssets(): array
    {
        $stmt = $this->db->query(
            "SELECT
                a.id,
                a.asset_code,
                a.name,
                l.name AS location_name,
                qr.token
             FROM assets a
             INNER JOIN locations l ON l.id = a.location_id
             INNER JOIN asset_qr_tokens qr ON qr.id = (
                SELECT q.id
                FROM asset_qr_tokens q
                WHERE q.asset_id = a.id AND q.is_active = 1
                ORDER BY q.id DESC
                LIMIT 1
             )
             ORDER BY a.asset_code ASC, a.id ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function insertQrToken(int $assetId, string $token, ?int $generatedBy, string $createdAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO asset_qr_tokens (asset_id, token, generated_by, is_active, last_scanned_at, created_at, updated_at)
             VALUES (:asset_id, :token, :generated_by, :is_active, :last_scanned_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            'asset_id' => $assetId,
            'token' => $token,
            'generated_by' => $generatedBy,
            'is_active' => 1,
            'last_scanned_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function generateUniqueQrToken(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = bin2hex(random_bytes(16));
            $stmt = $this->db->prepare(
                'SELECT COUNT(*)
                 FROM asset_qr_tokens
                 WHERE token = :token'
            );
            $stmt->execute(['token' => $token]);

            if ((int) $stmt->fetchColumn() === 0) {
                return $token;
            }
        }

        throw new RuntimeException('ไม่สามารถสร้าง QR token ที่ไม่ซ้ำกันได้ กรุณาลองใหม่อีกครั้ง');
    }
}
