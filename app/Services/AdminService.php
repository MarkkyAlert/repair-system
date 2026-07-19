<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\AdminRepository;
use App\Repositories\SettingsRepository;
use DomainException;
use App\Repositories\LoginAttemptRepository;

class AdminService
{
    public function __construct(
        private AdminRepository $admin,
        private SettingsRepository $settings,
        private AuditLogRepository $auditLogs,
        private AuditLogger $audit,
        private EmailTemplateService $emailTemplates,
        private LoginAttemptRepository $loginAttempts,
    ) {
    }

    public function getAdminPageData(array $viewer, array $query = []): array
    {
        assert_admin($viewer);

        $departments = $this->admin->getDepartments();
        $categories = $this->admin->getTicketCategories();
        $assetCategories = $this->admin->getAssetCategories();
        $locations = $this->admin->getLocations();
        $priorities = $this->admin->getPriorities();
        $settings = $this->settings->all();
        $categorySla = $this->extractCategorySlaMap($settings);
        $auditFilters = $this->normalizeAuditFilters($query);
        $auditPage = max(1, (int) ($query['audit_page'] ?? 1));
        // แบ่งหน้าแท็บ users (แต่ละแถว render ฟอร์มแก้ไขเต็ม ๆ) ไม่โหลด user ทุกคนพร้อมกัน; ส่วน dropdown
        // filter ของ audit ยังต้องใช้ user ทั้งหมด เลยดึง list เบา ๆ ที่มีแค่ id+name.
        $usersPage = $this->admin->getUsersPage(max(1, (int) ($query['user_page'] ?? 1)), 25);

        return [
            'users' => $usersPage['items'],
            'usersPagination' => $usersPage,
            'userFilterOptions' => $this->admin->getUserFilterOptions(),
            'departments' => $departments,
            'departmentOptions' => array_map(fn (array $department): array => [
                'id' => (int) ($department['id'] ?? 0),
                'name' => (string) ($department['name'] ?? '-'),
            ], $departments),
            'roles' => valid_roles(),
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
            'loginAttempts' => $this->loginAttempts->getRecent(50),
            'loginAttemptStats' => [
                'recent_failures' => $this->loginAttempts->countRecentFailures(60),
            ],
        ];
    }

    public function updateUser(int $userId, array $viewer, array $input): void
    {
        assert_admin($viewer);
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $role = trim((string) ($input['role'] ?? 'requester'));

        if ($fullName === '' || $email === '') {
            throw new DomainException('กรุณากรอกชื่อและอีเมลผู้ใช้งานให้ครบถ้วน');
        }

        require_max_length($fullName, 150, 'ชื่อผู้ใช้งาน'); // users.full_name VARCHAR(150)
        $phone = trim((string) ($input['phone'] ?? ''));
        require_max_length($phone, 30, 'เบอร์โทร'); // users.phone VARCHAR(30)
        if ($phone !== '' && !valid_phone_format($phone)) {
            // ให้ตรงกับ flow ของ profile/import — admin แก้ก็ต้องไม่รับเบอร์ที่ผิดรูปเหมือนตอน user
            // แก้ profile ของตัวเองที่ก็ปฏิเสธเหมือนกัน.
            throw new DomainException('รูปแบบเบอร์โทรไม่ถูกต้อง');
        }

        if (!is_valid_email($email)) {
            throw new DomainException('รูปแบบอีเมลผู้ใช้งานไม่ถูกต้อง');
        }

        if (!in_array($role, valid_roles(), true)) {
            throw new DomainException('Role ผู้ใช้งานไม่ถูกต้อง');
        }

        // guard ตัวเดียวกับ createUser — ไม่งั้น department_id ที่ผิดจะหลุดไปชน FK แล้วเด้งเป็น
        // PDOException/500 (ตัวห่อฟอร์มดักแค่ Domain/Runtime) แทนที่จะเป็นข้อความที่เข้าใจง่าย.
        $departmentId = strict_int($input['department_id'] ?? null, 'แผนก '); // ปฏิเสธค่าอย่าง "1junk"
        if ($departmentId > 0 && !$this->admin->departmentExists($departmentId)) {
            throw new DomainException('Department ที่เลือกไม่ถูกต้อง');
        }

        $payload = [
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'role' => $role,
            'department_id' => $departmentId > 0 ? $departmentId : null,
            'is_active' => truthy_input($input['is_active'] ?? '0'),
            'original_version' => strict_int($input['original_version'] ?? null, 'เวอร์ชันข้อมูล'), // optimistic lock
        ];
        $this->admin->updateUser($userId, $payload);
        $this->recordAudit($viewer, 'user.updated', 'user', $userId, $payload);
    }

    public function createUser(array $viewer, array $input): void
    {
        assert_admin($viewer);

        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $role = trim((string) ($input['role'] ?? 'requester'));
        $password = (string) ($input['password'] ?? '');
        $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');
        $departmentId = strict_int($input['department_id'] ?? null, 'แผนก '); // ปฏิเสธค่าอย่าง "1junk"

        if ($username === '' || $fullName === '' || $email === '' || $password === '' || $passwordConfirmation === '') {
            throw new DomainException('กรุณากรอกชื่อผู้ใช้ ชื่อ อีเมล และรหัสผ่านให้ครบถ้วน');
        }

        require_max_length($fullName, 150, 'ชื่อผู้ใช้งาน'); // users.full_name VARCHAR(150)
        $phone = trim((string) ($input['phone'] ?? ''));
        require_max_length($phone, 30, 'เบอร์โทร'); // users.phone VARCHAR(30)
        if ($phone !== '' && !valid_phone_format($phone)) {
            // ให้ตรงกับ flow ของ profile/import — user ที่ admin สร้างก็ต้องไม่รับเบอร์ที่ผิดรูป.
            throw new DomainException('รูปแบบเบอร์โทรไม่ถูกต้อง');
        }

        if (!is_valid_username($username)) {
            throw new DomainException('ชื่อผู้ใช้ต้องมี 3-50 ตัวอักษร และใช้ได้เฉพาะ a-z, 0-9, จุด, ขีดกลาง และขีดล่าง');
        }

        if (!is_valid_email($email)) {
            throw new DomainException('รูปแบบอีเมลผู้ใช้งานไม่ถูกต้อง');
        }

        if (!in_array($role, valid_roles(), true)) {
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
            'phone' => $phone,
            'role' => $role,
            'department_id' => $departmentId > 0 ? $departmentId : null,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'is_active' => truthy_input($input['is_active'] ?? '0'),
        ];
        $userId = $this->admin->createUser($payload);
        unset($payload['password_hash']);
        $this->recordAudit($viewer, 'user.created', 'user', $userId, $payload);
    }

    private function buildRolePreview(): array
    {
        return [
            'roles' => [
                'requester' => role_label_th('requester'),
                'manager' => role_label_th('manager'),
                'technician' => role_label_th('technician'),
                'admin' => role_label_th('admin'),
            ],
            'capabilities' => [
                ['label' => 'เข้าสู่ Dashboard และดูภาพรวมของตนเอง', 'roles' => ['requester', 'manager', 'technician', 'admin']],
                ['label' => 'สร้าง Ticket ใหม่และติดตามงานที่ตนแจ้ง', 'roles' => ['requester', 'manager', 'technician', 'admin']],
                ['label' => 'ยกเลิก Ticket ของตนเองก่อนเริ่มงาน', 'roles' => ['requester', 'manager', 'technician', 'admin']],
                ['label' => 'อนุมัติ/ปฏิเสธ Ticket ที่รออนุมัติ', 'roles' => ['manager', 'admin']],
                ['label' => 'มอบหมายช่างและจัดคิวงานซ่อม', 'roles' => ['manager', 'admin']],
                // งานลงมือของช่าง (รับงาน/เริ่มงาน/ปิดงาน) เป็นสิทธิ์ของช่างเท่านั้น — TicketPolicy::canTechnicianWork
                // ต้องเป็นช่างที่ถูกมอบหมาย; admin เป็นผู้จัดการ/มอบหมาย แต่ไม่ได้ลงมือซ่อมเอง.
                ['label' => 'รับงาน เริ่มงาน และปิดงานซ่อม', 'roles' => ['technician']],
                ['label' => 'เพิ่ม/แก้ไขความคิดเห็นและโน้ตภายในตามสิทธิ์ Ticket', 'roles' => ['requester', 'manager', 'technician', 'admin']],
                ['label' => 'จัดการทรัพย์สินและแผ่น QR', 'roles' => ['manager', 'admin']],
                ['label' => 'ดูรายงานและส่งออก Excel/PDF/CSV', 'roles' => ['manager', 'admin']],
                ['label' => 'ตั้งค่าระบบ ผู้ใช้ ข้อมูลหลัก โลโก้ และทดสอบอีเมล', 'roles' => ['admin']],
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
            'from_name' => MailerService::resolveFromName(
                (string) config('mail.from_name', ''),
                (string) setting('app_name', config('app.name', 'Repair System'))
            ),
            'reply_to_address' => (string) config('mail.reply_to_address', ''),
            'log_path' => (string) config('mail.log_path', storage_path('mail-logs')),
            'is_log_driver' => $driver === 'log',
        ];
    }

    private function buildEmailPreviews(array $viewer): array
    {
        $passwordReset = $this->emailTemplates->buildSamplePasswordReset($viewer);
        $notification = $this->emailTemplates->buildSampleTicketEvent($viewer);

        return [
            'password_reset' => [
                'label' => 'รีเซ็ตรหัสผ่าน',
                'subject' => (string) ($passwordReset['subject'] ?? ''),
                'body_html' => (string) ($passwordReset['body_html'] ?? ''),
                'body_text' => (string) ($passwordReset['body_text'] ?? ''),
            ],
            'notification' => [
                'label' => 'การแจ้งเตือน / เหตุการณ์ Ticket',
                'subject' => (string) ($notification['subject'] ?? ''),
                'body_html' => (string) ($notification['body_html'] ?? ''),
                'body_text' => (string) ($notification['body_text'] ?? ''),
            ],
        ];
    }

    private function recordAudit(array $viewer, string $action, string $entityType, ?int $entityId = null, array $context = []): void
    {
        $this->audit->record($viewer, $action, $entityType, $entityId, $this->redactAuditPii($context));
    }

    /**
     * กันข้อมูลติดต่อดิบ (PII อย่าง email/phone) ออกจาก audit record ที่เก็บถาวรบน production — เพราะ entry นั้น
     * ระบุเป้าหมายด้วย entity id + full_name อยู่แล้ว ไม่ต้องเก็บที่อยู่/เบอร์จริงไว้ตรงนั้นซ้ำ.
     * Dev/local เก็บค่าเต็มไว้ debug ให้ตรงกับนโยบาย PII ของ mail-log.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function redactAuditPii(array $context): array
    {
        if ((string) config('app.env', 'production') !== 'production') {
            return $context;
        }
        if (array_key_exists('email', $context)) {
            $context['email'] = MailerService::maskEmail((string) $context['email']);
        }
        if (array_key_exists('phone', $context)) {
            $context['phone'] = MailerService::maskPhone((string) $context['phone']);
        }

        return $context;
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
            'app_tagline' => $values['app_tagline'] ?? 'Maintenance Operations',
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
}
