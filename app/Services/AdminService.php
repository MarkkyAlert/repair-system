<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AdminRepository;
use App\Repositories\SettingsRepository;
use DomainException;

class AdminService
{
    public function __construct(
        private AdminRepository $admin,
        private SettingsRepository $settings,
    ) {
    }

    public function getAdminPageData(array $viewer): array
    {
        $this->assertAdmin($viewer);

        $departments = $this->admin->getDepartments();
        $settings = $this->settings->all();
        $categorySla = $this->extractCategorySlaMap($settings);

        return [
            'users' => $this->admin->getUsers(),
            'departments' => $departments,
            'departmentOptions' => array_map(fn (array $department): array => [
                'id' => (int) ($department['id'] ?? 0),
                'name' => (string) ($department['name'] ?? '-'),
            ], $departments),
            'roles' => ['requester', 'manager', 'technician', 'admin'],
            'categories' => $this->admin->getTicketCategories(),
            'categorySla' => $categorySla,
            'settings' => array_values(array_filter($settings, static fn (array $setting): bool => !str_starts_with((string) ($setting['setting_key'] ?? ''), 'category_sla_'))),
        ];
    }

    public function updateUser(int $userId, array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $role = trim((string) ($input['role'] ?? 'requester'));

        if ($fullName === '' || $email === '') {
            throw new DomainException('กรุณากรอกชื่อและอีเมลผู้ใช้งานให้ครบถ้วน');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('รูปแบบอีเมลผู้ใช้งานไม่ถูกต้อง');
        }

        if (!in_array($role, ['requester', 'manager', 'technician', 'admin'], true)) {
            throw new DomainException('Role ผู้ใช้งานไม่ถูกต้อง');
        }

        $this->admin->updateUser($userId, [
            'full_name' => $fullName,
            'email' => $email,
            'phone' => trim((string) ($input['phone'] ?? '')),
            'role' => $role,
            'department_id' => (int) ($input['department_id'] ?? 0) > 0 ? (int) $input['department_id'] : null,
            'is_active' => in_array((string) ($input['is_active'] ?? '0'), ['1', 'true', 'on'], true),
        ]);
    }

    public function updateDepartment(int $departmentId, array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));

        if ($code === '' || $name === '') {
            throw new DomainException('กรุณากรอกรหัสและชื่อแผนกให้ครบถ้วน');
        }

        $this->admin->updateDepartment($departmentId, [
            'code' => $code,
            'name' => $name,
            'description' => trim((string) ($input['description'] ?? '')),
            'is_active' => in_array((string) ($input['is_active'] ?? '0'), ['1', 'true', 'on'], true),
        ]);
    }

    public function updateCategory(int $categoryId, array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $responseHours = max(0, (float) ($input['response_hours'] ?? 0));
        $resolutionHours = max(0, (float) ($input['resolution_hours'] ?? 0));

        if ($code === '' || $name === '') {
            throw new DomainException('กรุณากรอกรหัสและชื่อหมวดหมู่ให้ครบถ้วน');
        }

        $this->admin->updateTicketCategory($categoryId, [
            'code' => $code,
            'name' => $name,
            'description' => trim((string) ($input['description'] ?? '')),
            'sort_order' => max(1, (int) ($input['sort_order'] ?? 1)),
            'is_active' => in_array((string) ($input['is_active'] ?? '0'), ['1', 'true', 'on'], true),
        ]);
        $this->settings->upsert('category_sla_' . $categoryId, json_encode([
            'response_minutes' => (int) round($responseHours * 60),
            'resolution_minutes' => (int) round($resolutionHours * 60),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'json', false, (int) ($viewer['id'] ?? 0));
    }

    public function updateSetting(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        $key = trim((string) ($input['setting_key'] ?? ''));
        if ($key === '') {
            throw new DomainException('กรุณาระบุ setting key');
        }

        $type = trim((string) ($input['value_type'] ?? 'string'));
        $value = trim((string) ($input['setting_value'] ?? ''));

        if (!in_array($type, ['string', 'int', 'bool', 'json'], true)) {
            throw new DomainException('ชนิดข้อมูลของ setting ไม่ถูกต้อง');
        }

        if ($type === 'json' && $value !== '') {
            json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new DomainException('ค่า setting แบบ JSON ไม่ถูกต้อง');
            }
        }

        $this->settings->upsert(
            $key,
            $value,
            $type,
            in_array((string) ($input['is_public'] ?? '0'), ['1', 'true', 'on'], true),
            (int) ($viewer['id'] ?? 0)
        );
    }

    private function extractCategorySlaMap(array $settings): array
    {
        $map = [];

        foreach ($settings as $setting) {
            $key = (string) ($setting['setting_key'] ?? '');
            if (!preg_match('/^category_sla_(\d+)$/', $key, $matches)) {
                continue;
            }

            $categoryId = (int) ($matches[1] ?? 0);
            $payload = json_decode((string) ($setting['setting_value'] ?? ''), true);
            $map[$categoryId] = [
                'response_minutes' => max(0, (int) ($payload['response_minutes'] ?? 0)),
                'resolution_minutes' => max(0, (int) ($payload['resolution_minutes'] ?? 0)),
                'response_hours' => round(max(0, (int) ($payload['response_minutes'] ?? 0)) / 60, 2),
                'resolution_hours' => round(max(0, (int) ($payload['resolution_minutes'] ?? 0)) / 60, 2),
            ];
        }

        return $map;
    }

    private function assertAdmin(array $viewer): void
    {
        if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
            throw new DomainException('เฉพาะผู้ดูแลระบบเท่านั้น');
        }
    }
}
