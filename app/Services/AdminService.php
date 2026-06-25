<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\AdminRepository;
use App\Repositories\SettingsRepository;
use DomainException;
use PDO;
use Throwable;

class AdminService
{
    public function __construct(
        private AdminRepository $admin,
        private SettingsRepository $settings,
        private AuditLogRepository $auditLogs,
        private EmailTemplateService $emailTemplates,
        private MailerService $mailer,
        private PDO $db,
    ) {
    }

    public function getAdminPageData(array $viewer, array $query = []): array
    {
        $this->assertAdmin($viewer);

        $departments = $this->admin->getDepartments();
        $categories = $this->admin->getTicketCategories();
        $assetCategories = $this->admin->getAssetCategories();
        $locations = $this->admin->getLocations();
        $priorities = $this->admin->getPriorities();
        $settings = $this->settings->all();
        $categorySla = $this->extractCategorySlaMap($settings);
        $auditFilters = $this->normalizeAuditFilters($query);
        $auditPage = max(1, (int) ($query['audit_page'] ?? 1));

        return [
            'users' => $this->admin->getUsers(),
            'departments' => $departments,
            'departmentOptions' => array_map(fn (array $department): array => [
                'id' => (int) ($department['id'] ?? 0),
                'name' => (string) ($department['name'] ?? '-'),
            ], $departments),
            'roles' => ['requester', 'manager', 'technician', 'admin'],
            'categories' => $categories,
            'assetCategories' => $assetCategories,
            'locations' => $locations,
            'priorities' => array_map(fn (array $priority): array => array_merge($priority, [
                'response_hours' => round(max(0, (int) ($priority['response_time_minutes'] ?? 0)) / 60, 2),
                'resolution_hours' => round(max(0, (int) ($priority['resolution_time_minutes'] ?? 0)) / 60, 2),
            ]), $priorities),
            'categorySla' => $categorySla,
            'systemSettingForm' => $this->buildSystemSettingForm($settings),
            'settings' => array_values(array_filter($settings, static fn (array $setting): bool => !str_starts_with((string) ($setting['setting_key'] ?? ''), 'category_sla_'))),
            'rolePreview' => $this->buildRolePreview(),
            'auditLogs' => $this->auditLogs->paginate($auditFilters, $auditPage, 20),
            'auditFilters' => $auditFilters,
            'auditFilterOptions' => $this->auditLogs->getFilterOptions(),
            'mailDiagnostics' => $this->buildMailDiagnostics(),
            'emailPreviews' => $this->buildEmailPreviews($viewer),
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

        $payload = [
            'full_name' => $fullName,
            'email' => $email,
            'phone' => trim((string) ($input['phone'] ?? '')),
            'role' => $role,
            'department_id' => (int) ($input['department_id'] ?? 0) > 0 ? (int) $input['department_id'] : null,
            'is_active' => in_array((string) ($input['is_active'] ?? '0'), ['1', 'true', 'on'], true),
        ];
        $this->admin->updateUser($userId, $payload);
        $this->recordAudit($viewer, 'user.updated', 'user', $userId, $payload);
    }

    public function createUser(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);

        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $role = trim((string) ($input['role'] ?? 'requester'));
        $password = (string) ($input['password'] ?? '');
        $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');
        $departmentId = (int) ($input['department_id'] ?? 0);

        if ($username === '' || $fullName === '' || $email === '' || $password === '' || $passwordConfirmation === '') {
            throw new DomainException('กรุณากรอกชื่อผู้ใช้ ชื่อ อีเมล และรหัสผ่านให้ครบถ้วน');
        }

        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
            throw new DomainException('ชื่อผู้ใช้ต้องมี 3-50 ตัวอักษร และใช้ได้เฉพาะ a-z, 0-9, จุด, ขีดกลาง และขีดล่าง');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('รูปแบบอีเมลผู้ใช้งานไม่ถูกต้อง');
        }

        if (!in_array($role, ['requester', 'manager', 'technician', 'admin'], true)) {
            throw new DomainException('Role ผู้ใช้งานไม่ถูกต้อง');
        }

        if ($password !== $passwordConfirmation) {
            throw new DomainException('ยืนยันรหัสผ่านไม่ตรงกัน');
        }

        if (strlen($password) < 8) {
            throw new DomainException('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
        }

        if ($departmentId > 0 && !$this->admin->departmentExists($departmentId)) {
            throw new DomainException('Department ที่เลือกไม่ถูกต้อง');
        }

        $payload = [
            'username' => $username,
            'full_name' => $fullName,
            'email' => $email,
            'phone' => trim((string) ($input['phone'] ?? '')),
            'role' => $role,
            'department_id' => $departmentId > 0 ? $departmentId : null,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'is_active' => in_array((string) ($input['is_active'] ?? '0'), ['1', 'true', 'on'], true),
        ];
        $userId = $this->admin->createUser($payload);
        unset($payload['password_hash']);
        $this->recordAudit($viewer, 'user.created', 'user', $userId, $payload);
    }

    public function createDepartment(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        $payload = $this->buildMasterPayload($input, 'แผนก');
        $departmentId = $this->admin->createDepartment($payload);
        $this->recordAudit($viewer, 'department.created', 'department', $departmentId, $payload);
    }

    public function updateDepartment(int $departmentId, array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        if ($departmentId <= 0) {
            throw new DomainException('ไม่พบแผนกที่ต้องการแก้ไข');
        }

        $payload = $this->buildMasterPayload($input, 'แผนก');
        $this->admin->updateDepartment($departmentId, $payload);
        $this->recordAudit($viewer, 'department.updated', 'department', $departmentId, $payload);
    }

    public function deleteDepartment(int $departmentId, array $viewer): void
    {
        $this->assertAdmin($viewer);
        if ($departmentId <= 0) {
            throw new DomainException('ไม่พบแผนกที่ต้องการลบ');
        }

        $this->admin->deleteDepartment($departmentId);
        $this->recordAudit($viewer, 'department.deleted', 'department', $departmentId);
    }

    public function createCategory(array $viewer, array $input): void
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

        $this->recordAudit($viewer, 'ticket_category.created', 'ticket_category', $categoryId, $payload + ['sla' => json_decode($slaPayload, true)]);
    }

    public function updateCategory(int $categoryId, array $viewer, array $input): void
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

        $this->recordAudit($viewer, 'ticket_category.updated', 'ticket_category', $categoryId, $payload + ['sla' => json_decode($slaPayload, true)]);
    }

    public function deleteCategory(int $categoryId, array $viewer): void
    {
        $this->assertAdmin($viewer);
        if ($categoryId <= 0) {
            throw new DomainException('ไม่พบหมวดหมู่งานที่ต้องการลบ');
        }

        $this->admin->deleteTicketCategory($categoryId);
        $this->recordAudit($viewer, 'ticket_category.deleted', 'ticket_category', $categoryId);
    }

    public function createAssetCategory(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        $payload = $this->buildCategoryPayload($input, 'หมวดหมู่ Asset');
        $categoryId = $this->admin->createAssetCategory($payload);
        $this->recordAudit($viewer, 'asset_category.created', 'asset_category', $categoryId, $payload);
    }

    public function updateAssetCategory(int $categoryId, array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        if ($categoryId <= 0) {
            throw new DomainException('ไม่พบหมวดหมู่ Asset ที่ต้องการแก้ไข');
        }

        $payload = $this->buildCategoryPayload($input, 'หมวดหมู่ Asset');
        $this->admin->updateAssetCategory($categoryId, $payload);
        $this->recordAudit($viewer, 'asset_category.updated', 'asset_category', $categoryId, $payload);
    }

    public function deleteAssetCategory(int $categoryId, array $viewer): void
    {
        $this->assertAdmin($viewer);
        if ($categoryId <= 0) {
            throw new DomainException('ไม่พบหมวดหมู่ Asset ที่ต้องการลบ');
        }

        $this->admin->deleteAssetCategory($categoryId);
        $this->recordAudit($viewer, 'asset_category.deleted', 'asset_category', $categoryId);
    }

    public function createLocation(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        $payload = $this->buildLocationPayload($input);
        $locationId = $this->admin->createLocation($payload);
        $this->recordAudit($viewer, 'location.created', 'location', $locationId, $payload);
    }

    public function updateLocation(int $locationId, array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        if ($locationId <= 0) {
            throw new DomainException('ไม่พบสถานที่ที่ต้องการแก้ไข');
        }

        $payload = $this->buildLocationPayload($input);
        $this->admin->updateLocation($locationId, $payload);
        $this->recordAudit($viewer, 'location.updated', 'location', $locationId, $payload);
    }

    public function updatePriority(int $priorityId, array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        if ($priorityId <= 0) {
            throw new DomainException('ไม่พบ Priority ที่ต้องการแก้ไข');
        }

        $payload = $this->buildPriorityPayload($input);
        $this->admin->updatePriority($priorityId, $payload);
        $this->recordAudit($viewer, 'priority.updated', 'priority', $priorityId, $payload);
    }

    public function createPriority(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        $payload = $this->buildPriorityCreatePayload($input);
        $priorityId = $this->admin->createPriority($payload);
        $this->recordAudit($viewer, 'priority.created', 'priority', $priorityId, $payload);
    }

    public function deletePriority(int $priorityId, array $viewer): void
    {
        $this->assertAdmin($viewer);
        if ($priorityId <= 0) {
            throw new DomainException('ไม่พบ Priority ที่ต้องการลบ');
        }

        $this->admin->deletePriority($priorityId);
        $this->recordAudit($viewer, 'priority.deleted', 'priority', $priorityId, []);
    }

    public function deleteLocation(int $locationId, array $viewer): void
    {
        $this->assertAdmin($viewer);
        if ($locationId <= 0) {
            throw new DomainException('ไม่พบสถานที่ที่ต้องการลบ');
        }

        $this->admin->deleteLocation($locationId);
        $this->recordAudit($viewer, 'location.deleted', 'location', $locationId, []);
    }

    public function updateLogo(array $viewer, array $files, array $input): void
    {
        $this->assertAdmin($viewer);

        $remove = in_array((string) ($input['remove_logo'] ?? '0'), ['1', 'true', 'on'], true);
        if ($remove) {
            $currentLogoPath = $this->currentLogoFilePath();
            $this->settings->upsert('app_logo_path', '', 'string', true, (int) ($viewer['id'] ?? 0));
            $this->deleteLogoFile($currentLogoPath);
            $this->recordAudit($viewer, 'logo.removed', 'system_setting', null, ['setting_key' => 'app_logo_path']);
            return;
        }

        $file = $files['logo'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new DomainException('กรุณาเลือกไฟล์โลโก้ที่จะอัปโหลด');
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new DomainException('ไม่สามารถอ่านไฟล์โลโก้ได้ กรุณาลองใหม่');
        }

        if ((int) ($file['size'] ?? 0) > 1048576) {
            throw new DomainException('ไฟล์โลโก้ต้องมีขนาดไม่เกิน 1MB');
        }

        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file((string) $file['tmp_name']);
        if (!isset($allowed[$mime])) {
            throw new DomainException('รองรับเฉพาะไฟล์ PNG, JPEG หรือ WebP');
        }

        $extension = $allowed[$mime];
        $relativeDirectory = 'storage/uploads/branding';
        $absoluteDirectory = BASE_PATH . '/' . $relativeDirectory;
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
            throw new \RuntimeException('ไม่สามารถสร้างโฟลเดอร์เก็บโลโก้ได้');
        }

        $storedName = 'logo-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $absolutePath = $absoluteDirectory . '/' . $storedName;
        if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
            throw new \RuntimeException('ไม่สามารถบันทึกไฟล์โลโก้ได้');
        }

        $currentLogoPath = $this->currentLogoFilePath();
        $relativeStoredPath = $relativeDirectory . '/' . $storedName;
        try {
            $this->settings->upsert('app_logo_path', $relativeStoredPath, 'string', true, (int) ($viewer['id'] ?? 0));
        } catch (Throwable $exception) {
            $this->deleteLogoFile($absolutePath);
            throw $exception;
        }

        $this->deleteLogoFile($currentLogoPath);
        $this->recordAudit($viewer, 'logo.updated', 'system_setting', null, [
            'setting_key' => 'app_logo_path',
            'stored_path' => $relativeStoredPath,
            'mime' => $mime,
        ]);
    }

    private function deleteCurrentLogo(): void
    {
        $this->deleteLogoFile($this->currentLogoFilePath());
    }

    private function currentLogoFilePath(): ?string
    {
        $existing = $this->settings->getByKey('app_logo_path');
        $existingPath = trim((string) ($existing['setting_value'] ?? ''));
        if ($existingPath === '') {
            return null;
        }

        $relativePath = ltrim($existingPath, '/');
        $storageRoot = realpath(BASE_PATH . '/storage/uploads/branding');
        $publicRoot = realpath(BASE_PATH . '/public/uploads/branding');
        $absoluteCandidates = [
            BASE_PATH . '/' . $relativePath,
            BASE_PATH . '/public/' . $relativePath,
        ];

        foreach ($absoluteCandidates as $absoluteCandidate) {
            $absoluteReal = realpath($absoluteCandidate);
            if ($absoluteReal === false || !is_file($absoluteReal)) {
                continue;
            }

            if (($storageRoot !== false && str_starts_with($absoluteReal, $storageRoot))
                || ($publicRoot !== false && str_starts_with($absoluteReal, $publicRoot))) {
                return $absoluteReal;
            }
        }

        return null;
    }

    private function deleteLogoFile(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Setting keys ที่ระบบจัดการผ่าน endpoint เฉพาะ ห้ามแก้ผ่าน /admin/settings (freeform)
     * เพื่อกัน admin เขียนทับโดยไม่ผ่าน validation ของ form หลัก
     */
    private const PROTECTED_SETTING_KEYS = [
        'app_logo_path',     // /admin/settings/logo
        'app_name',          // /admin/system-settings
        'default_timezone',  // /admin/system-settings
        'ticket_prefix',     // /admin/system-settings
        'business_hours',    // /admin/system-settings
    ];

    /**
     * Setting key prefixes ที่ระบบจัดการผ่าน endpoint เฉพาะ
     */
    private const PROTECTED_SETTING_PREFIXES = [
        'category_sla_',  // /admin/categories/*
    ];

    public function updateSetting(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);
        $key = trim((string) ($input['setting_key'] ?? ''));
        if ($key === '') {
            throw new DomainException('กรุณาระบุ setting key');
        }

        if (in_array($key, self::PROTECTED_SETTING_KEYS, true)) {
            throw new DomainException('Setting key "' . $key . '" ถูกควบคุมโดยระบบ กรุณาแก้ผ่านฟอร์มเฉพาะ');
        }

        foreach (self::PROTECTED_SETTING_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                throw new DomainException('Setting key prefix "' . $prefix . '" ถูกควบคุมโดยระบบ');
            }
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
        $this->recordAudit($viewer, 'setting.updated', 'system_setting', null, [
            'setting_key' => $key,
            'value_type' => $type,
            'is_public' => in_array((string) ($input['is_public'] ?? '0'), ['1', 'true', 'on'], true),
        ]);
    }

    public function updateSystemSettings(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);

        $appName = trim((string) ($input['app_name'] ?? ''));
        $timezone = trim((string) ($input['default_timezone'] ?? ''));
        $ticketPrefix = strtoupper(trim((string) ($input['ticket_prefix'] ?? '')));
        $businessStart = trim((string) ($input['business_start'] ?? ''));
        $businessEnd = trim((string) ($input['business_end'] ?? ''));
        $updatedBy = (int) ($viewer['id'] ?? 0);

        if ($appName === '') {
            throw new DomainException('กรุณากรอกชื่อระบบ');
        }

        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            throw new DomainException('Timezone ไม่ถูกต้อง');
        }

        if (!preg_match('/^[A-Z0-9_-]{2,12}$/', $ticketPrefix)) {
            throw new DomainException('Ticket prefix ต้องมี 2-12 ตัวอักษร และใช้ได้เฉพาะ A-Z, 0-9, ขีดกลาง หรือขีดล่าง');
        }

        if (!$this->isValidTime($businessStart) || !$this->isValidTime($businessEnd)) {
            throw new DomainException('เวลาเริ่มและเวลาสิ้นสุดต้องอยู่ในรูปแบบ HH:MM');
        }

        if ($businessStart >= $businessEnd) {
            throw new DomainException('เวลาเริ่มทำการต้องน้อยกว่าเวลาสิ้นสุด');
        }

        try {
            $this->db->beginTransaction();
            $this->settings->upsert('app_name', $appName, 'string', true, $updatedBy);
            $this->settings->upsert('default_timezone', $timezone, 'string', false, $updatedBy);
            $this->settings->upsert('ticket_prefix', $ticketPrefix, 'string', false, $updatedBy);
            $this->settings->upsert('business_hours', json_encode([
                'start' => $businessStart,
                'end' => $businessEnd,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'json', false, $updatedBy);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $this->recordAudit($viewer, 'system_settings.updated', 'system_setting', null, [
            'app_name' => $appName,
            'default_timezone' => $timezone,
            'ticket_prefix' => $ticketPrefix,
            'business_hours' => [
                'start' => $businessStart,
                'end' => $businessEnd,
            ],
        ]);
    }

    public function sendTestEmail(array $viewer, array $input): void
    {
        $this->assertAdmin($viewer);

        $email = strtolower(trim((string) ($input['to_email'] ?? '')));
        $template = trim((string) ($input['template'] ?? 'password_reset'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('กรุณาระบุอีเมลปลายทางให้ถูกต้อง');
        }

        if (!in_array($template, ['password_reset', 'notification'], true)) {
            throw new DomainException('Template อีเมลไม่ถูกต้อง');
        }

        $message = $template === 'password_reset'
            ? $this->buildSamplePasswordResetEmail($viewer)
            : $this->buildSampleNotificationEmail($viewer);
        $message['to_email'] = $email;
        $message['to_name'] = (string) ($viewer['full_name'] ?? 'Admin');

        try {
            $this->mailer->send($message);
        } catch (Throwable $exception) {
            throw new DomainException('ส่งอีเมลทดสอบไม่สำเร็จ: กรุณาตรวจสอบค่า SMTP/MAIL_DRIVER และลองใหม่');
        }

        $this->recordAudit($viewer, 'email_test.sent', 'email', null, [
            'to_email' => $email,
            'template' => $template,
            'driver' => (string) config('mail.driver', 'log'),
        ]);
    }

    private function buildRolePreview(): array
    {
        return [
            'roles' => [
                'requester' => 'ผู้แจ้ง',
                'manager' => 'ผู้จัดการ',
                'technician' => 'ช่างเทคนิค',
                'admin' => 'ผู้ดูแลระบบ',
            ],
            'capabilities' => [
                ['label' => 'เข้าสู่ Dashboard และดูภาพรวมของตนเอง', 'roles' => ['requester', 'manager', 'technician', 'admin']],
                ['label' => 'สร้าง Ticket ใหม่และติดตามงานที่ตนแจ้ง', 'roles' => ['requester', 'admin']],
                ['label' => 'ยกเลิก Ticket ของตนเองก่อนเริ่มงาน', 'roles' => ['requester', 'admin']],
                ['label' => 'อนุมัติ/ปฏิเสธ Ticket ที่รออนุมัติ', 'roles' => ['manager', 'admin']],
                ['label' => 'มอบหมายช่างและจัดคิวงานซ่อม', 'roles' => ['manager', 'admin']],
                ['label' => 'รับงาน เริ่มงาน และปิดงานซ่อม', 'roles' => ['technician', 'admin']],
                ['label' => 'เพิ่ม/แก้ไข comment และ internal note ตามสิทธิ์ Ticket', 'roles' => ['requester', 'manager', 'technician', 'admin']],
                ['label' => 'จัดการ Asset และ QR sheet', 'roles' => ['manager', 'admin']],
                ['label' => 'ดู Reports และ Export Excel/PDF/CSV', 'roles' => ['manager', 'admin']],
                ['label' => 'ตั้งค่าระบบ ผู้ใช้ master data โลโก้ และ Email test', 'roles' => ['admin']],
            ],
        ];
    }

    private function normalizeAuditFilters(array $query): array
    {
        return [
            'action' => trim((string) ($query['action'] ?? '')),
            'entity_type' => trim((string) ($query['entity_type'] ?? '')),
            'user_id' => max(0, (int) ($query['user_id'] ?? 0)),
            'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($query['date_from'] ?? '')) ? (string) $query['date_from'] : '',
            'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($query['date_to'] ?? '')) ? (string) $query['date_to'] : '',
        ];
    }

    private function buildMailDiagnostics(): array
    {
        $driver = strtolower((string) config('mail.driver', 'log'));

        return [
            'driver' => $driver,
            'host' => (string) config('mail.host', '127.0.0.1'),
            'port' => (string) config('mail.port', 1025),
            'encryption' => (string) config('mail.encryption', ''),
            'from_address' => (string) config('mail.from_address', 'noreply@example.com'),
            'from_name' => (string) config('mail.from_name', setting('app_name', config('app.name', 'Repair System'))),
            'reply_to_address' => (string) config('mail.reply_to_address', ''),
            'log_path' => (string) config('mail.log_path', storage_path('mail-logs')),
            'is_log_driver' => $driver === 'log',
        ];
    }

    private function buildEmailPreviews(array $viewer): array
    {
        $passwordReset = $this->buildSamplePasswordResetEmail($viewer);
        $notification = $this->buildSampleNotificationEmail($viewer);

        return [
            'password_reset' => [
                'label' => 'Password Reset',
                'subject' => (string) ($passwordReset['subject'] ?? ''),
                'body_html' => (string) ($passwordReset['body_html'] ?? ''),
                'body_text' => (string) ($passwordReset['body_text'] ?? ''),
            ],
            'notification' => [
                'label' => 'Notification / Ticket Event',
                'subject' => (string) ($notification['subject'] ?? ''),
                'body_html' => (string) ($notification['body_html'] ?? ''),
                'body_text' => (string) ($notification['body_text'] ?? ''),
            ],
        ];
    }

    private function buildSamplePasswordResetEmail(array $viewer): array
    {
        return $this->emailTemplates->buildPasswordReset(
            [
                'id' => (int) ($viewer['id'] ?? 0),
                'full_name' => (string) ($viewer['full_name'] ?? 'ผู้ดูแลระบบ'),
                'email' => (string) ($viewer['email'] ?? 'admin@example.com'),
            ],
            url('/reset-password?email=admin%40example.com&token=preview-token'),
            date('Y-m-d H:i:s', time() + 3600)
        );
    }

    private function buildSampleNotificationEmail(array $viewer): array
    {
        return $this->emailTemplates->buildTicketEvent(
            [
                'id' => 1,
                'ticket_no' => (string) setting('ticket_prefix', 'MT') . '-PREVIEW-0001',
                'title' => 'ตัวอย่างงานซ่อมจากระบบ',
                'status' => 'approved',
            ],
            [
                'id' => (int) ($viewer['id'] ?? 0),
                'full_name' => (string) ($viewer['full_name'] ?? 'ผู้ดูแลระบบ'),
                'email' => (string) ($viewer['email'] ?? 'admin@example.com'),
            ],
            'ticket.approved',
            'มี Ticket ที่อนุมัติแล้ว',
            'Ticket ตัวอย่างถูกอนุมัติและพร้อมมอบหมายช่าง'
        );
    }

    private function recordAudit(array $viewer, string $action, string $entityType, ?int $entityId = null, array $context = []): void
    {
        $request = request();
        $server = $request?->server ?? $_SERVER;
        $userAgent = substr((string) ($server['HTTP_USER_AGENT'] ?? ''), 0, 255);

        $this->auditLogs->record([
            'user_id' => (int) ($viewer['id'] ?? 0),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => substr((string) ($server['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            'user_agent' => $userAgent !== '' ? $userAgent : null,
            'context' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
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

    private function buildSystemSettingForm(array $settings): array
    {
        $values = [];
        foreach ($settings as $setting) {
            $values[(string) ($setting['setting_key'] ?? '')] = (string) ($setting['setting_value'] ?? '');
        }

        $businessHours = json_decode($values['business_hours'] ?? '', true);
        if (!is_array($businessHours)) {
            $businessHours = [];
        }

        return [
            'app_name' => $values['app_name'] ?? (string) config('app.name', 'Repair System'),
            'default_timezone' => $values['default_timezone'] ?? (string) config('app.timezone', 'Asia/Bangkok'),
            'ticket_prefix' => $values['ticket_prefix'] ?? 'MT',
            'business_start' => (string) ($businessHours['start'] ?? '08:30'),
            'business_end' => (string) ($businessHours['end'] ?? '17:30'),
            'timezoneOptions' => $this->timezoneOptions($values['default_timezone'] ?? (string) config('app.timezone', 'Asia/Bangkok')),
        ];
    }

    private function timezoneOptions(string $selectedTimezone): array
    {
        $preferred = ['Asia/Bangkok', 'UTC', 'Asia/Singapore', 'Asia/Tokyo', 'Europe/London', 'America/New_York'];
        $timezones = array_values(array_unique(array_merge($preferred, [$selectedTimezone], timezone_identifiers_list())));

        return array_map(static fn (string $timezone): array => [
            'value' => $timezone,
            'label' => $timezone,
        ], array_values(array_filter($timezones, static fn (string $timezone): bool => in_array($timezone, timezone_identifiers_list(), true))));
    }

    private function isValidTime(string $value): bool
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
            return false;
        }

        return true;
    }

    private function assertAdmin(array $viewer): void
    {
        if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
            throw new DomainException('เฉพาะผู้ดูแลระบบเท่านั้น');
        }
    }
}
