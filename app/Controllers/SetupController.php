<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\AdminRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Services\AdminService;
use App\Services\DemoDataService;
use DomainException;
use RuntimeException;
use Throwable;

class SetupController
{
    public function __construct(
        private SettingsRepository $settings,
        private AdminRepository $admin,
        private UserRepository $users,
        private AdminService $adminService,
        private DemoDataService $demoData,
    ) {
    }

    public function show(): void
    {
        if ($this->isSetupCompleted()) {
            Response::redirect('/login');
        }

        $hasAdmin = $this->adminExists();

        Response::view('setup/index', [
            'title' => 'ตั้งค่าระบบครั้งแรก',
            'pageHeading' => 'Setup Wizard',
            'hasAdmin' => $hasAdmin,
            'errorMessage' => flash_message('error'),
        ], 'guest');
    }

    public function execute(): void
    {
        if ($this->isSetupCompleted()) {
            Response::redirect('/login');
        }

        try {
            csrf_validate();

            $appName = trim((string) ($_POST['app_name'] ?? ''));
            if ($appName === '') {
                throw new DomainException('กรุณากรอกชื่อระบบ');
            }
            if (mb_strlen($appName) > 100) {
                throw new DomainException('ชื่อระบบยาวเกินกำหนด');
            }

            $hasAdmin = $this->adminExists();
            $adminId = 0;

            // Step 1: app name
            $this->settings->upsert('app_name', $appName, 'string', true, 0);

            // Step 2: create admin if needed
            if (!$hasAdmin) {
                $username = strtolower(trim((string) ($_POST['admin_username'] ?? '')));
                $email = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
                $fullName = trim((string) ($_POST['admin_full_name'] ?? ''));
                $password = (string) ($_POST['admin_password'] ?? '');

                if ($username === '' || $email === '' || $fullName === '' || $password === '') {
                    throw new DomainException('กรุณากรอกข้อมูลผู้ดูแลระบบให้ครบถ้วน');
                }
                if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
                    throw new DomainException('ชื่อผู้ใช้ต้องมี 3-50 ตัว (a-z, 0-9, จุด, ขีดกลาง, ขีดล่าง)');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new DomainException('รูปแบบอีเมลผู้ดูแลระบบไม่ถูกต้อง');
                }
                if (strlen($password) < 8) {
                    throw new DomainException('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
                }

                $adminId = $this->admin->createUser([
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'full_name' => $fullName,
                    'phone' => '',
                    'role' => 'admin',
                    'department_id' => null,
                    'is_active' => true,
                ]);
            } else {
                $adminUser = $this->findFirstAdmin();
                $adminId = (int) ($adminUser['id'] ?? 0);
            }

            // Step 3: load demo data (optional)
            $loadDemo = in_array((string) ($_POST['load_demo'] ?? '0'), ['1', 'true', 'on'], true);
            $demoSummary = null;
            if ($loadDemo) {
                $demoSummary = $this->demoData->load($adminId);
            }

            // Step 4: mark completed
            $this->settings->upsert('setup_completed', '1', 'bool', false, $adminId);

            $message = 'ตั้งค่าระบบเสร็จสมบูรณ์';
            if ($demoSummary !== null) {
                $message .= ' · โหลดข้อมูลตัวอย่างแล้ว (' . (int) ($demoSummary['assets'] ?? 0) . ' assets, ' . (int) ($demoSummary['tickets'] ?? 0) . ' tickets)';
            }
            flash('success', $message . ' กรุณาเข้าสู่ระบบ');
            Response::redirect('/login');
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/setup');
        } catch (Throwable $exception) {
            error_log('[setup.execute] ' . $exception->getMessage());
            flash('error', 'ไม่สามารถตั้งค่าระบบได้ กรุณาตรวจสอบ log');
            Response::redirect('/setup');
        }
    }

    public static function isSetupCompletedStatic(SettingsRepository $settings): bool
    {
        $row = $settings->getByKey('setup_completed');
        if (!is_array($row)) {
            return false;
        }
        return (string) ($row['setting_value'] ?? '0') === '1';
    }

    private function isSetupCompleted(): bool
    {
        return self::isSetupCompletedStatic($this->settings);
    }

    private function adminExists(): bool
    {
        $stmt = $this->users->findByLogin('admin');
        // Could be any admin — use a broader check via DB
        $count = (int) app(\PDO::class)->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
        return $count > 0;
    }

    private function findFirstAdmin(): ?array
    {
        $stmt = app(\PDO::class)->query("SELECT id, username, email FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
