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
        ['page' => $page, 'offset' => $offset, 'totalPages' => $totalPages] = paginate($page, $perPage, $total);
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

    /**
     * แถวสำหรับ export — คอลัมน์ตรงกับ AssetImportService::CSV_COLUMNS (codes/username) เพื่อ round-trip
     * กับ import ได้ ใช้ filter WHERE ชุดเดียวกับ list (buildAssetListWhere) + LIMIT bound กัน OOM.
     */
    public function getAssetsForExport(array $filters, int $limit = 10000): array
    {
        $limit = max(1, $limit);
        [$whereSql, $params] = $this->buildAssetListWhere($filters);
        $stmt = $this->db->prepare(
            "SELECT
                a.asset_code,
                a.name,
                a.serial_number,
                ac.code AS category_code,
                l.code AS location_code,
                d.code AS department_code,
                custodian.username AS custodian_username,
                a.brand,
                a.model,
                a.vendor,
                a.purchase_date,
                a.warranty_expires_at,
                a.status,
                a.notes
             FROM assets a
             INNER JOIN asset_categories ac ON ac.id = a.asset_category_id
             INNER JOIN locations l ON l.id = a.location_id
             LEFT JOIN departments d ON d.id = a.department_id
             LEFT JOIN users custodian ON custodian.id = a.custodian_user_id
             $whereSql
             ORDER BY a.asset_code ASC, a.id ASC
             LIMIT :limit"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function buildAssetListWhere(array $filters): array
    {
        $where = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(a.asset_code LIKE :q_asset_code OR a.name LIKE :q_name OR a.serial_number LIKE :q_serial OR a.brand LIKE :q_brand OR a.model LIKE :q_model)';
            // escape wildcard ของ LIKE (%, _) ในคำค้น — รหัสทรัพย์สิน/serial มี _ บ่อย ถ้าไม่ escape จะจับเกินไปผิดแถว
            $like = '%' . like_escape($q) . '%';
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

    /**
     * ชุดข้อมูลอ้างอิงแบบเบาสำหรับ filter ของหน้า list asset (แค่ category + location) — แถบ filter
     * ของ list ไม่มีตัวเลือก department/custodian ถ้าโหลดมาด้วย (แบบที่ getAssetFormReferenceData ทำให้ฟอร์ม
     * create/edit) ก็เท่ากับเสีย query ฟรีสองครั้งต่อการดู list หนึ่งครั้ง
     */
    public function getAssetIndexReferenceData(): array
    {
        $categories = $this->db->query(
            'SELECT id, code, name
             FROM asset_categories
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $locations = $this->db->query(
            'SELECT id, code, name, building, floor, room
             FROM locations
             WHERE is_active = 1
             ORDER BY name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'categories' => $categories,
            'locations' => $locations,
        ];
    }

    public function getAssetFormReferenceData(?array $asset = null): array
    {
        $categories = $this->db->query(
            'SELECT id, code, name, is_active
             FROM asset_categories
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $departments = $this->db->query(
            'SELECT id, code, name, is_active
             FROM departments
             WHERE is_active = 1
             ORDER BY name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $locations = $this->db->query(
            'SELECT id, code, name, building, floor, room, is_active
             FROM locations
             WHERE is_active = 1
             ORDER BY name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $custodians = $this->db->query(
            "SELECT id, full_name, role, is_active
             FROM users
             WHERE is_active = 1
             ORDER BY full_name ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // แก้ฟอร์มของ asset ที่หมวด/สถานที่/แผนก/ผู้ครอบครอง "ปัจจุบัน" ถูก archive (is_active=0) ไปแล้ว: ต่อค่าปัจจุบัน
        // ที่ปิดใช้งานเข้ามาใน list ด้วย เพื่อ 1) dropdown แสดงค่าเดิม (ติดป้าย inactive) ไม่ใช่ช่องว่าง 2) เซฟแล้วค่าไม่หาย
        // เงียบ ๆ (validation ยอมรับค่าปัจจุบัน). ฟอร์มสร้างใหม่ (asset = null) ยังเห็นเฉพาะตัว active.
        if ($asset !== null) {
            $this->appendCurrentInactiveRef($categories, 'asset_categories', 'id, code, name, is_active', (int) ($asset['asset_category_id'] ?? 0));
            $this->appendCurrentInactiveRef($departments, 'departments', 'id, code, name, is_active', (int) ($asset['department_id'] ?? 0));
            $this->appendCurrentInactiveRef($locations, 'locations', 'id, code, name, building, floor, room, is_active', (int) ($asset['location_id'] ?? 0));
            $this->appendCurrentInactiveRef($custodians, 'users', 'id, full_name, role, is_active', (int) ($asset['custodian_user_id'] ?? 0));
        }

        return [
            'categories' => $categories,
            'departments' => $departments,
            'locations' => $locations,
            'custodians' => $custodians,
        ];
    }

    /**
     * ถ้า id ปัจจุบันของ asset ไม่อยู่ใน list (เพราะถูก archive) ให้ดึงแถวนั้นมาต่อท้าย (ไม่สนใจ is_active) เพื่อคงค่าเดิม
     * ในฟอร์มแก้ไข — reference ที่ผูกอยู่แล้วต้องไม่หาย. แถวที่ต่อเข้ามาพก is_active=0 ให้ view ติดป้าย "(ปิดใช้งาน)".
     * $table/$columns เป็น literal ที่ควบคุมเองในคลาสนี้ ไม่ใช่ input ผู้ใช้.
     *
     * @param array<int, array<mixed>> $list
     */
    private function appendCurrentInactiveRef(array &$list, string $table, string $columns, int $currentId): void
    {
        if ($currentId <= 0) {
            return;
        }
        foreach ($list as $row) {
            if ((int) ($row['id'] ?? 0) === $currentId) {
                return; // ยัง active — มีใน list อยู่แล้ว
            }
        }
        $stmt = $this->db->prepare("SELECT $columns FROM $table WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $currentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $list[] = $row;
        }
    }

    /**
     * code→row ของ category/location/department "ทั้งหมด" (รวมที่ archive แล้ว) — สำหรับ import ที่ต้องผูกรหัสของไฟล์
     * ที่ export ออกไปกลับให้ครบ. export ดึงทุกแถวไม่กรอง is_active ดังนั้นการ re-import ต้อง resolve รหัสของ master
     * ที่ถูก archive ได้ ไม่ปฏิเสธแถว (custodian ยังคง active-only ที่ตัว import จงใจ กันมอบทรัพย์ให้บัญชีที่ปิดไปแล้ว).
     *
     * @return array{categories: list<array<string, mixed>>, locations: list<array<string, mixed>>, departments: list<array<string, mixed>>}
     */
    public function getImportCodeReference(): array
    {
        return [
            'categories' => $this->db->query('SELECT id, code FROM asset_categories')->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'locations' => $this->db->query('SELECT id, code FROM locations')->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'departments' => $this->db->query('SELECT id, code FROM departments')->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    /**
     * asset_code / serial_number ที่ "มีอยู่ในระบบแล้ว" จากชุดที่ไฟล์ import ส่งมา — คิวรีเดียวทั้งไฟล์
     * (แบบเดียวกับ UserRepository::existingLoginsAndEmails) เพื่อให้หน้า preview บอกได้ตั้งแต่ก่อนเขียนจริงว่า
     * แถวไหนจะชนของเดิม แทนที่จะปล่อยไปตายตอน INSERT แล้วรายงานรวม ๆ ว่า "ข้าม n รายการ"
     *
     * @param list<string> $assetCodes
     * @param list<string> $serials
     * @return array{codes: array<string, true>, serials: array<string, true>}
     */
    public function existingCodesAndSerials(array $assetCodes, array $serials): array
    {
        $normalize = static fn (array $values, callable $case): array => array_values(array_unique(array_filter(
            array_map(static fn ($v): string => $case(trim((string) $v)), $values),
            static fn (string $v): bool => $v !== ''
        )));
        $assetCodes = $normalize($assetCodes, static fn (string $v): string => strtoupper($v));
        $serials = $normalize($serials, static fn (string $v): string => $v);

        $found = ['codes' => [], 'serials' => []];
        if ($assetCodes === [] && $serials === []) {
            return $found;
        }

        // collation utf8mb4_unicode_ci เทียบแบบไม่สนตัวพิมพ์อยู่แล้ว จึงไม่ห่อ LOWER()/UPPER() ในเงื่อนไข
        // (index uq_assets_asset_code / uq_assets_serial_number จะได้ยังถูกใช้)
        $conditions = [];
        $params = [];
        if ($assetCodes !== []) {
            $conditions[] = 'asset_code IN (' . implode(',', array_fill(0, count($assetCodes), '?')) . ')';
            $params = array_merge($params, $assetCodes);
        }
        if ($serials !== []) {
            $conditions[] = 'serial_number IN (' . implode(',', array_fill(0, count($serials), '?')) . ')';
            $params = array_merge($params, $serials);
        }

        $stmt = $this->db->prepare('SELECT asset_code, serial_number FROM assets WHERE ' . implode(' OR ', $conditions));
        $stmt->execute($params);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $code = strtoupper(trim((string) ($row['asset_code'] ?? '')));
            $serial = trim((string) ($row['serial_number'] ?? ''));
            if ($code !== '') {
                $found['codes'][$code] = true;
            }
            if ($serial !== '') {
                $found['serials'][$serial] = true;
            }
        }

        return $found;
    }

    private function translateAssetUniqueViolation(Throwable $exception): void
    {
        if (!is_duplicate_key_error($exception)) {
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

        $startedTransaction = !$this->db->inTransaction();

        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }

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
            $this->insertUniqueQrToken($assetId, $payload['generated_by'] ?? null, $createdAt);

            if ($startedTransaction) {
                $this->db->commit();
            }

            return $assetId;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->translateAssetUniqueViolation($exception);

            throw $exception;
        }
    }

    public function updateAsset(int $assetId, array $payload): void
    {
        // ⚠️ guard สำคัญ (ห้ามเอา WHERE version ออก): optimistic lock กัน lost update — ถอดออก = การแก้พร้อมกันเขียนทับกันเงียบ ๆ
        // optimistic lock ใช้ version ที่เป็น integer ไม่ใช่ updated_at: updated_at เป็น DATETIME ละเอียดแค่วินาที การแก้
        // สองครั้งในวินาทีเดียวกันจึงอาจ match ทั้งคู่ แล้วครั้งหลังเขียนทับครั้งแรกแบบเงียบ ๆ version จะเพิ่มขึ้น
        // ทุกครั้งที่เขียน ฟอร์มแก้ไขที่ถือ version เก่าไว้จึงไม่มีวัน match
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
                 version = version + 1,
                 updated_at = :updated_at
             WHERE id = :asset_id
               AND version = :original_version'
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
                'original_version' => (int) ($payload['original_version'] ?? 0),
            ]);
        } catch (PDOException $exception) {
            $this->translateAssetUniqueViolation($exception);
            throw $exception;
        }

        if ($stmt->rowCount() > 0) {
            return;
        }

        $currentStmt = $this->db->prepare('SELECT version FROM assets WHERE id = :asset_id LIMIT 1');
        $currentStmt->execute(['asset_id' => $assetId]);
        $currentVersion = $currentStmt->fetchColumn();
        if ($currentVersion === false || (int) $currentVersion !== (int) ($payload['original_version'] ?? 0)) {
            throw new DomainException('ข้อมูล Asset ถูกแก้ไขโดยผู้ใช้อื่นแล้ว กรุณารีเฟรชหน้าแล้วลองอีกครั้ง');
        }
    }

    public function regenerateQrToken(int $assetId, ?int $generatedBy = null): string
    {
        // เก็บ deactivate+insert ไว้ใน transaction เดียว แล้ว lock แถว asset ด้วย FOR UPDATE ก่อน เพื่อให้
        // การ regenerate ของ asset เดียวกันที่ยิงมาพร้อมกันทำงานเรียงทีละอัน — ไม่งั้นทั้งคู่อาจ deactivate
        // token เก่า แล้วต่างคน insert token active อันใหม่ กลายเป็นมี token active หลายอันต่อ asset
        $createdAt = date('Y-m-d H:i:s');
        $token = $this->generateUniqueQrToken();

        try {
            $this->db->beginTransaction();

            // จุด serialization: การ regenerate ตัวที่สองที่ทำพร้อมกันจะถูก block ตรงนี้จนกว่าตัวแรกจะ commit
            // แล้วขั้น deactivate ของมันจึงจะเห็น (และเคลียร์) token ที่ตัวแรกเพิ่ง insert ไป
            $lockStmt = $this->db->prepare('SELECT id FROM assets WHERE id = :asset_id FOR UPDATE');
            $lockStmt->execute(['asset_id' => $assetId]);

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
                a.version,
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

    /**
     * @param bool $reportableOnly true = เฉพาะทรัพย์สินที่ยังรับแจ้งซ่อมได้ (active/maintenance) — ใช้ตอน guest
     *                             ส่งคำขอจริง. false = เจอทุกสถานะ ใช้ตอน "แสดงผลหน้าสแกน" เพราะทรัพย์สินที่
     *                             ปลดระวางแล้วก็ยังต้องสแกนเพื่อตรวจนับได้ ต้องขึ้นว่าปลดระวางแล้ว ไม่ใช่ 404
     *                             ที่ทำให้คนสแกนนึกว่าสติกเกอร์เสียหรือระบบล่ม
     */
    public function findActiveAssetByToken(string $token, bool $reportableOnly = true): ?array
    {
        $statusCondition = $reportableOnly ? " AND a.status IN ('active', 'maintenance')" : '';
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
             WHERE qr.token = :token AND qr.is_active = 1" . $statusCondition . '
             LIMIT 1'
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

    public function getPrintableAssets(int $limit = 500, array $filters = []): array
    {
        // จำกัดจำนวนต่อหน้าพิมพ์ — กัน browser ช้าจาก QR image จำนวนมากเมื่อ asset เยอะ
        $limit = max(1, $limit);
        // ใช้ filter WHERE ชุดเดียวกับ list (buildAssetListWhere) เพื่อให้พิมพ์ตามที่กรองไว้ตรงกัน
        [$whereSql, $params] = $this->buildAssetListWhere($filters);
        $stmt = $this->db->prepare(
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
             $whereSql
             ORDER BY a.asset_code ASC, a.id ASC
             LIMIT :limit"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * insert QR token สุ่มอันใหม่ให้ asset ที่เพิ่งสร้าง โดยพึ่ง constraint UNIQUE(token) เป็นตัวกันชนกันเอง
     * แทนที่จะ SELECT ตรวจว่ามีอยู่แล้วทีละ token (generateUniqueQrToken) — เขียนทีเดียว
     * แทน check + write ช่วยลดต้นทุน token ลงครึ่งหนึ่งตอน bulk import แถว asset ถูก insert ไปแล้ว
     * duplicate-key error ตรงนี้จึงมาจาก token แน่ ๆ เมื่อเจอ (ซึ่งหายากมาก) ก็ regenerate
     * แล้วลองใหม่
     */
    private function insertUniqueQrToken(int $assetId, ?int $generatedBy, string $createdAt): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $this->insertQrToken($assetId, bin2hex(random_bytes(16)), $generatedBy, $createdAt);

                return;
            } catch (PDOException $exception) {
                if (is_duplicate_key_error($exception) && $attempt < 4) {
                    continue;
                }

                throw $exception;
            }
        }
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

        throw new DomainException('ไม่สามารถสร้าง QR token ที่ไม่ซ้ำกันได้ กรุณาลองใหม่อีกครั้ง');
    }
}
