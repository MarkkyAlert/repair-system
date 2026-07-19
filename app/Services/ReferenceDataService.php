<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AdminRepository;
use App\Repositories\SettingsRepository;
use DomainException;
use PDO;
use Throwable;

/**
 * CRUD ข้อมูลหลัก/ข้อมูลอ้างอิง (master/reference-data) สำหรับ admin: แผนก, หมวดหมู่ ticket, หมวดหมู่ asset,
 * สถานที่ และ priority. แยกออกมาจาก AdminService; ส่วนการอ่านข้อมูลของหน้า admin ยังอยู่
 * ใน AdminService. ข้อความแจ้งเตือนกรณีค่าซ้ำ (unique-violation) จัดการอยู่ภายใน AdminRepository.
 */
class ReferenceDataService
{
    public function __construct(
        private AdminRepository $admin,
        private SettingsRepository $settings,
        private AuditLogger $audit,
        private PDO $db,
    ) {
    }

    // --- แผนก (Departments) ---------------------------------------------------------

    public function createDepartment(array $viewer, array $input): void
    {
        assert_admin($viewer);
        $payload = $this->buildMasterPayload($input, 'แผนก');
        $departmentId = $this->admin->createDepartment($payload);
        $this->audit->record($viewer, 'department.created', 'department', $departmentId, $payload);
    }

    public function updateDepartment(int $departmentId, array $viewer, array $input): void
    {
        assert_admin($viewer);
        if ($departmentId <= 0) {
            throw new DomainException('ไม่พบแผนกที่ต้องการแก้ไข');
        }

        $payload = $this->buildMasterPayload($input, 'แผนก');
        $this->admin->updateDepartment($departmentId, $payload);
        $this->audit->record($viewer, 'department.updated', 'department', $departmentId, $payload);
    }

    public function deleteDepartment(int $departmentId, array $viewer): void
    {
        assert_admin($viewer);
        if ($departmentId <= 0) {
            throw new DomainException('ไม่พบแผนกที่ต้องการลบ');
        }

        $this->admin->deleteDepartment($departmentId);
        $this->audit->record($viewer, 'department.deleted', 'department', $departmentId);
    }

    // --- หมวดหมู่ ticket (Ticket categories) ---------------------------------------------------

    public function createTicketCategory(array $viewer, array $input): void
    {
        assert_admin($viewer);
        $payload = $this->buildCategoryPayload($input, 'หมวดหมู่');
        $slaPayload = $this->encodeSlaPayload($input);

        try {
            $this->db->beginTransaction();
            $categoryId = $this->admin->createTicketCategory($payload);
            $this->settings->upsert('category_sla_' . $categoryId, $slaPayload, 'json', false, (int) ($viewer['id'] ?? 0));
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $this->audit->record($viewer, 'ticket_category.created', 'ticket_category', $categoryId, $payload + ['sla' => json_decode($slaPayload, true)]);
    }

    public function updateTicketCategory(int $categoryId, array $viewer, array $input): void
    {
        assert_admin($viewer);
        if ($categoryId <= 0) {
            throw new DomainException('ไม่พบหมวดหมู่งานที่ต้องการแก้ไข');
        }

        $payload = $this->buildCategoryPayload($input, 'หมวดหมู่');
        $slaPayload = $this->encodeSlaPayload($input);

        try {
            $this->db->beginTransaction();
            $this->admin->updateTicketCategory($categoryId, $payload);
            $this->settings->upsert('category_sla_' . $categoryId, $slaPayload, 'json', false, (int) ($viewer['id'] ?? 0));
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $this->audit->record($viewer, 'ticket_category.updated', 'ticket_category', $categoryId, $payload + ['sla' => json_decode($slaPayload, true)]);
    }

    public function deleteTicketCategory(int $categoryId, array $viewer): void
    {
        assert_admin($viewer);
        if ($categoryId <= 0) {
            throw new DomainException('ไม่พบหมวดหมู่งานที่ต้องการลบ');
        }

        $this->admin->deleteTicketCategory($categoryId);
        $this->audit->record($viewer, 'ticket_category.deleted', 'ticket_category', $categoryId);
    }

    // --- หมวดหมู่ asset (Asset categories) ----------------------------------------------------

    public function createAssetCategory(array $viewer, array $input): void
    {
        assert_admin($viewer);
        $payload = $this->buildCategoryPayload($input, 'หมวดหมู่ Asset');
        $categoryId = $this->admin->createAssetCategory($payload);
        $this->audit->record($viewer, 'asset_category.created', 'asset_category', $categoryId, $payload);
    }

    public function updateAssetCategory(int $categoryId, array $viewer, array $input): void
    {
        assert_admin($viewer);
        if ($categoryId <= 0) {
            throw new DomainException('ไม่พบหมวดหมู่ Asset ที่ต้องการแก้ไข');
        }

        $payload = $this->buildCategoryPayload($input, 'หมวดหมู่ Asset');
        $this->admin->updateAssetCategory($categoryId, $payload);
        $this->audit->record($viewer, 'asset_category.updated', 'asset_category', $categoryId, $payload);
    }

    public function deleteAssetCategory(int $categoryId, array $viewer): void
    {
        assert_admin($viewer);
        if ($categoryId <= 0) {
            throw new DomainException('ไม่พบหมวดหมู่ Asset ที่ต้องการลบ');
        }

        $this->admin->deleteAssetCategory($categoryId);
        $this->audit->record($viewer, 'asset_category.deleted', 'asset_category', $categoryId);
    }

    // --- สถานที่ (Locations) -----------------------------------------------------------

    public function createLocation(array $viewer, array $input): void
    {
        assert_admin($viewer);
        $payload = $this->buildLocationPayload($input);
        $locationId = $this->admin->createLocation($payload);
        $this->audit->record($viewer, 'location.created', 'location', $locationId, $payload);
    }

    public function updateLocation(int $locationId, array $viewer, array $input): void
    {
        assert_admin($viewer);
        if ($locationId <= 0) {
            throw new DomainException('ไม่พบสถานที่ที่ต้องการแก้ไข');
        }

        $payload = $this->buildLocationPayload($input);
        $this->admin->updateLocation($locationId, $payload);
        $this->audit->record($viewer, 'location.updated', 'location', $locationId, $payload);
    }

    public function deleteLocation(int $locationId, array $viewer): void
    {
        assert_admin($viewer);
        if ($locationId <= 0) {
            throw new DomainException('ไม่พบสถานที่ที่ต้องการลบ');
        }

        $this->admin->deleteLocation($locationId);
        $this->audit->record($viewer, 'location.deleted', 'location', $locationId, []);
    }

    // --- ลำดับความสำคัญ (Priorities) ----------------------------------------------------------

    public function createPriority(array $viewer, array $input): void
    {
        assert_admin($viewer);
        $payload = $this->buildPriorityCreatePayload($input);
        $priorityId = $this->admin->createPriority($payload);
        $this->audit->record($viewer, 'priority.created', 'priority', $priorityId, $payload);
    }

    public function updatePriority(int $priorityId, array $viewer, array $input): void
    {
        assert_admin($viewer);
        if ($priorityId <= 0) {
            throw new DomainException('ไม่พบ Priority ที่ต้องการแก้ไข');
        }

        $payload = $this->buildPriorityPayload($input);
        $this->admin->updatePriority($priorityId, $payload);
        $this->audit->record($viewer, 'priority.updated', 'priority', $priorityId, $payload);
    }

    public function deletePriority(int $priorityId, array $viewer): void
    {
        assert_admin($viewer);
        if ($priorityId <= 0) {
            throw new DomainException('ไม่พบ Priority ที่ต้องการลบ');
        }

        $this->admin->deletePriority($priorityId);
        $this->audit->record($viewer, 'priority.deleted', 'priority', $priorityId, []);
    }

    // --- ตัวสร้าง payload (Payload builders) ----------------------------------------------------

    private function buildMasterPayload(array $input, string $label, int $nameMax = 150): array
    {
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));

        if ($code === '' || $name === '') {
            throw new DomainException('กรุณากรอกรหัสและชื่อ' . $label . 'ให้ครบถ้วน');
        }

        // จำกัดตามคอลัมน์ใน DB (code VARCHAR(50); name VARCHAR(150), categories VARCHAR(100)) เพื่อให้
        // ค่าที่ยาวเกินได้ข้อความแจ้งที่เข้าใจง่าย ไม่ใช่ DB error ดิบ ๆ จาก strict-mode.
        require_max_length($code, 50, 'รหัส' . $label);
        require_max_length($name, $nameMax, 'ชื่อ' . $label);

        return [
            'code' => $code,
            'name' => $name,
            'description' => trim((string) ($input['description'] ?? '')),
            'is_active' => truthy_input($input['is_active'] ?? '0'),
        ];
    }

    private function buildCategoryPayload(array $input, string $label): array
    {
        // ticket_categories.name / asset_categories.name เป็น VARCHAR(150) — ค่าเริ่มต้นของ buildMasterPayload.
        // (รอบก่อนเคยจำกัดผิดไว้ที่ 100 ทำให้ชื่อยาว 101–150 ที่ถูกต้องถูกปฏิเสธ.) sort_order เป็น
        // SMALLINT UNSIGNED (≤65535).
        $sortOrder = max(1, strict_int($input['sort_order'] ?? null, 'ลำดับการแสดง', 1));
        if ($sortOrder > 65535) {
            throw new DomainException('ลำดับการแสดงต้องไม่เกิน 65535');
        }

        return array_merge($this->buildMasterPayload($input, $label), [
            'sort_order' => $sortOrder,
        ]);
    }

    private function encodeSlaPayload(array $input): string
    {
        // ใช้ strict_float เพื่อให้ค่าที่ไม่ใช่ตัวเลขอย่าง "abc" ถูกปฏิเสธ ไม่ใช่แอบแปลงเป็น SLA 0 นาทีเงียบ ๆ.
        // เดิมค่าติดลบจะถูกบีบเป็น 0 เงียบ ๆ — ตอนนี้ถูกปฏิเสธ. is_numeric() ยังรับ "1e999"
        // (→ INF → (int) 0 นาที) และค่าจำกัดที่ล้น INT UNSIGNED ด้วย ทั้งคู่ถูกดักไว้ข้างล่าง.
        $responseHours = strict_float($input['response_hours'] ?? null, 'เวลาตอบรับ (SLA) ');
        $resolutionHours = strict_float($input['resolution_hours'] ?? null, 'เวลาแก้ไข (SLA) ');
        if ($responseHours < 0 || $resolutionHours < 0) {
            throw new DomainException('เวลา SLA ต้องไม่ติดลบ');
        }

        return json_encode([
            'response_minutes' => $this->slaMinutes($responseHours, 'เวลาตอบรับ (SLA) '),
            'resolution_minutes' => $this->slaMinutes($resolutionHours, 'เวลาแก้ไข (SLA) '),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * แปลงค่า SLA หน่วยชั่วโมงที่ตรวจแล้วเป็นจำนวนนาทีเต็ม โดยปฏิเสธค่าที่ไม่ finite (is_numeric() ปล่อย
     * "1e999" ผ่านเป็น INF ซึ่ง cast เป็น 0 นาที) และค่าใด ๆ ที่จะล้นคอลัมน์
     * response_time_minutes / resolution_time_minutes ชนิด INT UNSIGNED (สูงสุด 4294967295).
     */
    private function slaMinutes(float $hours, string $label): int
    {
        if (!is_finite($hours)) {
            throw new DomainException($label . 'ต้องเป็นตัวเลขที่ถูกต้อง');
        }
        if ($hours * 60 > 4294967295) {
            throw new DomainException($label . 'มากเกินช่วงที่ระบบรองรับ');
        }

        return (int) round($hours * 60);
    }

    private function buildLocationPayload(array $input): array
    {
        $payload = $this->buildMasterPayload($input, 'สถานที่');
        $building = trim((string) ($input['building'] ?? ''));
        $floor = trim((string) ($input['floor'] ?? ''));
        $room = trim((string) ($input['room'] ?? ''));
        // locations.building เป็น VARCHAR(150), floor/room เป็น VARCHAR(50)
        require_max_length($building, 150, 'อาคาร');
        require_max_length($floor, 50, 'ชั้น');
        require_max_length($room, 50, 'ห้อง');

        return array_merge($payload, [
            'building' => $building,
            'floor' => $floor,
            'room' => $room,
        ]);
    }

    private function buildPriorityCreatePayload(array $input): array
    {
        $base = $this->buildPriorityPayload($input);
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $level = strict_int($input['level'] ?? null, 'ระดับความสำคัญ'); // ปฏิเสธค่าอย่าง "50junk"

        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,50}$/', $code)) {
            throw new DomainException('รหัส Priority ต้องเป็น A-Z, 0-9, ขีดกลาง หรือขีดล่าง ความยาว 2-50 ตัวอักษร');
        }
        if ($level < 1 || $level > 99) {
            throw new DomainException('ระดับ Priority ต้องอยู่ระหว่าง 1-99');
        }

        return $base + ['code' => $code, 'level' => $level];
    }

    private function buildPriorityPayload(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $color = strtolower(trim((string) ($input['color'] ?? '')));
        $responseHoursRaw = trim((string) ($input['response_hours'] ?? '0'));
        $resolutionHoursRaw = trim((string) ($input['resolution_hours'] ?? '0'));

        if ($name === '') {
            throw new DomainException('กรุณากรอกชื่อ Priority');
        }

        // จำกัดตามคอลัมน์ของ priorities (name VARCHAR(100), color VARCHAR(30), sort_order TINYINT ≤255) เพื่อให้
        // ค่าที่จงใจทำให้ยาวเกินได้ข้อความแจ้งที่เข้าใจง่าย ไม่ใช่ DB error ดิบ ๆ จาก strict-mode.
        require_max_length($name, 100, 'ชื่อ Priority');
        require_max_length($color, 30, 'สี');

        if (!is_numeric($responseHoursRaw) || !is_numeric($resolutionHoursRaw)) {
            throw new DomainException('SLA ต้องเป็นตัวเลขชั่วโมง');
        }

        $responseHours = (float) $responseHoursRaw;
        $resolutionHours = (float) $resolutionHoursRaw;
        if ($responseHours < 0 || $resolutionHours < 0) {
            throw new DomainException('SLA ต้องไม่ติดลบ');
        }
        // is_numeric() รับ "1e999" (→ INF → 0 นาที) และค่าจำกัดที่ล้นคอลัมน์นาทีชนิด INT UNSIGNED —
        // slaMinutes ปฏิเสธทั้งคู่ก่อนถึงขั้น cast/DB.
        $responseMinutes = $this->slaMinutes($responseHours, 'เวลาตอบรับ (SLA) ');
        $resolutionMinutes = $this->slaMinutes($resolutionHours, 'เวลาแก้ไข (SLA) ');

        $sortOrder = max(1, strict_int($input['sort_order'] ?? null, 'ลำดับการแสดง', 1));
        if ($sortOrder > 255) {
            throw new DomainException('ลำดับการแสดงต้องไม่เกิน 255');
        }

        return [
            'name' => $name,
            'color' => $color,
            'response_time_minutes' => $responseMinutes,
            'resolution_time_minutes' => $resolutionMinutes,
            'sort_order' => $sortOrder,
            'is_active' => truthy_input($input['is_active'] ?? '0'),
        ];
    }
}
