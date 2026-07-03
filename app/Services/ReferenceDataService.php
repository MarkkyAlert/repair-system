<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AdminRepository;
use App\Repositories\SettingsRepository;
use DomainException;
use PDO;
use Throwable;

/**
 * Admin master/reference-data CRUD: departments, ticket categories, asset categories,
 * locations and priorities. Extracted from AdminService; reads for the admin page stay
 * in AdminService. Unique-violation friendly messages are handled inside AdminRepository.
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

    // --- Departments ---------------------------------------------------------

    public function createDepartment(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        $payload = $this->buildMasterPayload($input, 'แผนก');
        $departmentId = $this->admin->createDepartment($payload);
        $this->audit->record($viewer, 'department.created', 'department', $departmentId, $payload);
    }

    public function updateDepartment(int $departmentId, array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        if ($departmentId <= 0) {
            throw new DomainException('ไม่พบแผนกที่ต้องการแก้ไข');
        }

        $payload = $this->buildMasterPayload($input, 'แผนก');
        $this->admin->updateDepartment($departmentId, $payload);
        $this->audit->record($viewer, 'department.updated', 'department', $departmentId, $payload);
    }

    public function deleteDepartment(int $departmentId, array $viewer): void
    {
        $this->assertAdmin($viewer);
        if ($departmentId <= 0) {
            throw new DomainException('ไม่พบแผนกที่ต้องการลบ');
        }

        $this->admin->deleteDepartment($departmentId);
        $this->audit->record($viewer, 'department.deleted', 'department', $departmentId);
    }

    // --- Ticket categories ---------------------------------------------------

    public function createTicketCategory(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
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
        $this->assertAdmin($viewer);
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
        $this->assertAdmin($viewer);
        if ($categoryId <= 0) {
            throw new DomainException('ไม่พบหมวดหมู่งานที่ต้องการลบ');
        }

        $this->admin->deleteTicketCategory($categoryId);
        $this->audit->record($viewer, 'ticket_category.deleted', 'ticket_category', $categoryId);
    }

    // --- Asset categories ----------------------------------------------------

    public function createAssetCategory(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        $payload = $this->buildCategoryPayload($input, 'หมวดหมู่ Asset');
        $categoryId = $this->admin->createAssetCategory($payload);
        $this->audit->record($viewer, 'asset_category.created', 'asset_category', $categoryId, $payload);
    }

    public function updateAssetCategory(int $categoryId, array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        if ($categoryId <= 0) {
            throw new DomainException('ไม่พบหมวดหมู่ Asset ที่ต้องการแก้ไข');
        }

        $payload = $this->buildCategoryPayload($input, 'หมวดหมู่ Asset');
        $this->admin->updateAssetCategory($categoryId, $payload);
        $this->audit->record($viewer, 'asset_category.updated', 'asset_category', $categoryId, $payload);
    }

    public function deleteAssetCategory(int $categoryId, array $viewer): void
    {
        $this->assertAdmin($viewer);
        if ($categoryId <= 0) {
            throw new DomainException('ไม่พบหมวดหมู่ Asset ที่ต้องการลบ');
        }

        $this->admin->deleteAssetCategory($categoryId);
        $this->audit->record($viewer, 'asset_category.deleted', 'asset_category', $categoryId);
    }

    // --- Locations -----------------------------------------------------------

    public function createLocation(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        $payload = $this->buildLocationPayload($input);
        $locationId = $this->admin->createLocation($payload);
        $this->audit->record($viewer, 'location.created', 'location', $locationId, $payload);
    }

    public function updateLocation(int $locationId, array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        if ($locationId <= 0) {
            throw new DomainException('ไม่พบสถานที่ที่ต้องการแก้ไข');
        }

        $payload = $this->buildLocationPayload($input);
        $this->admin->updateLocation($locationId, $payload);
        $this->audit->record($viewer, 'location.updated', 'location', $locationId, $payload);
    }

    public function deleteLocation(int $locationId, array $viewer): void
    {
        $this->assertAdmin($viewer);
        if ($locationId <= 0) {
            throw new DomainException('ไม่พบสถานที่ที่ต้องการลบ');
        }

        $this->admin->deleteLocation($locationId);
        $this->audit->record($viewer, 'location.deleted', 'location', $locationId, []);
    }

    // --- Priorities ----------------------------------------------------------

    public function createPriority(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        $payload = $this->buildPriorityCreatePayload($input);
        $priorityId = $this->admin->createPriority($payload);
        $this->audit->record($viewer, 'priority.created', 'priority', $priorityId, $payload);
    }

    public function updatePriority(int $priorityId, array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        if ($priorityId <= 0) {
            throw new DomainException('ไม่พบ Priority ที่ต้องการแก้ไข');
        }

        $payload = $this->buildPriorityPayload($input);
        $this->admin->updatePriority($priorityId, $payload);
        $this->audit->record($viewer, 'priority.updated', 'priority', $priorityId, $payload);
    }

    public function deletePriority(int $priorityId, array $viewer): void
    {
        $this->assertAdmin($viewer);
        if ($priorityId <= 0) {
            throw new DomainException('ไม่พบ Priority ที่ต้องการลบ');
        }

        $this->admin->deletePriority($priorityId);
        $this->audit->record($viewer, 'priority.deleted', 'priority', $priorityId, []);
    }

    // --- Payload builders ----------------------------------------------------

    private function buildMasterPayload(array $input, string $label): array
    {
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));

        if ($code === '' || $name === '') {
            throw new DomainException('กรุณากรอกรหัสและชื่อ' . $label . 'ให้ครบถ้วน');
        }

        return [
            'code' => $code,
            'name' => $name,
            'description' => trim((string) ($input['description'] ?? '')),
            'is_active' => in_array((string) ($input['is_active'] ?? '0'), ['1', 'true', 'on'], true),
        ];
    }

    private function buildCategoryPayload(array $input, string $label): array
    {
        return array_merge($this->buildMasterPayload($input, $label), [
            'sort_order' => max(1, (int) ($input['sort_order'] ?? 1)),
        ]);
    }

    private function encodeSlaPayload(array $input): string
    {
        $responseHours = max(0, (float) ($input['response_hours'] ?? 0));
        $resolutionHours = max(0, (float) ($input['resolution_hours'] ?? 0));

        return json_encode([
            'response_minutes' => (int) round($responseHours * 60),
            'resolution_minutes' => (int) round($resolutionHours * 60),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildLocationPayload(array $input): array
    {
        $payload = $this->buildMasterPayload($input, 'สถานที่');

        return array_merge($payload, [
            'building' => trim((string) ($input['building'] ?? '')),
            'floor' => trim((string) ($input['floor'] ?? '')),
            'room' => trim((string) ($input['room'] ?? '')),
        ]);
    }

    private function buildPriorityCreatePayload(array $input): array
    {
        $base = $this->buildPriorityPayload($input);
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $level = (int) ($input['level'] ?? 0);

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

        if (!is_numeric($responseHoursRaw) || !is_numeric($resolutionHoursRaw)) {
            throw new DomainException('SLA ต้องเป็นตัวเลขชั่วโมง');
        }

        $responseHours = (float) $responseHoursRaw;
        $resolutionHours = (float) $resolutionHoursRaw;
        if ($responseHours < 0 || $resolutionHours < 0) {
            throw new DomainException('SLA ต้องไม่ติดลบ');
        }

        return [
            'name' => $name,
            'color' => $color,
            'response_time_minutes' => (int) round($responseHours * 60),
            'resolution_time_minutes' => (int) round($resolutionHours * 60),
            'sort_order' => max(1, (int) ($input['sort_order'] ?? 1)),
            'is_active' => in_array((string) ($input['is_active'] ?? '0'), ['1', 'true', 'on'], true),
        ];
    }

    private function assertAdmin(array $viewer): void
    {
        if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
            throw new DomainException('เฉพาะผู้ดูแลระบบเท่านั้น');
        }
    }
}
