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
use PDO;
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
        private PDO $db,
    ) {
    }

    public function show(): void
    {
        // ล็อก setup เมื่อ setup เสร็จแล้ว หรือมี admin อยู่แล้ว — /setup เป็น guest endpoint,
        // ถ้าเช็คแค่ flag จะเปิดช่องบน seed-deploy (admin จาก seed แต่ flag ยังไม่ถูกตั้ง)
        $hasAdmin = $this->adminExists();
        if ($this->isSetupCompleted() || $hasAdmin) {
            Response::redirect('/login');
        }

        Response::view('setup/index', [
            'title' => 'ตั้งค่าระบบครั้งแรก',
            'pageHeading' => 'ตัวช่วยตั้งค่าระบบ',
            'hasAdmin' => $hasAdmin,
            'errorMessage' => flash_message('error'),
        ], 'guest');
    }

    public function execute(): void
    {
        // ล็อก setup เมื่อ setup เสร็จแล้ว หรือมี admin อยู่แล้ว (กัน guest POST /setup บน seed-deploy
        // → โหลด demo/แก้ settings/ได้บัญชี technician จาก demo) — คู่กับ guard ใน show()
        if ($this->isSetupCompleted() || $this->adminExists()) {
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

            // ห่อ write ทั้งชุด (app_name → admin → demo → setup_completed) ใน transaction เดียว
            // → first-run all-or-nothing; ถ้าพังกลางทาง rollback หมด retry ได้จาก state สะอาด.
            // (createUser/upsert plain INSERT, demoData->load เป็น transaction-aware → participate)
            $this->db->beginTransaction();

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
                if (!is_valid_email($email)) {
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
            $loadDemo = truthy_input($_POST['load_demo'] ?? '0');
            $demoSummary = null;
            if ($loadDemo) {
                $demoSummary = $this->demoData->load($adminId);
            }

            // Step 4: mark completed
            $this->settings->upsert('setup_completed', '1', 'bool', false, $adminId);

            $this->db->commit();

            $message = 'ตั้งค่าระบบเสร็จสมบูรณ์';
            if ($demoSummary !== null) {
                $message .= ' · โหลดข้อมูลตัวอย่างแล้ว (' . (int) ($demoSummary['assets'] ?? 0) . ' assets, ' . (int) ($demoSummary['tickets'] ?? 0) . ' tickets)';
                if (!empty($demoSummary['demo_technician'])) {
                    $cred = $demoSummary['demo_technician'];
                    $message .= ' · บัญชีช่างตัวอย่าง: ' . (string) $cred['username']
                        . ' / ' . (string) $cred['password'] . ' (บันทึกไว้ — รหัสนี้จะไม่แสดงอีก)';
                }
            }
            flash('success', $message . ' กรุณาเข้าสู่ระบบ');
            Response::redirect('/login');
        } catch (DomainException|RuntimeException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            flash('error', $exception->getMessage());
            Response::redirect('/setup');
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
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

    /** True if at least one active admin already exists (a seed/manual deploy already has one). */
    public static function hasActiveAdmin(PDO $db): bool
    {
        return (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn() > 0;
    }

    /**
     * Whether a request must be redirected to /setup. Setup counts as done once the flag is set OR an
     * active admin already exists — the SAME rule show()/execute() use to guard /setup. Keeping the gate
     * (public/index.php) in sync prevents the /setup ↔ /login loop on seed/admin-provisioned deploys.
     */
    public static function requiresSetupRedirect(SettingsRepository $settings, PDO $db): bool
    {
        return !self::isSetupCompletedStatic($settings) && !self::hasActiveAdmin($db);
    }

    private function isSetupCompleted(): bool
    {
        return self::isSetupCompletedStatic($this->settings);
    }

    private function adminExists(): bool
    {
        return self::hasActiveAdmin($this->db);
    }

    private function findFirstAdmin(): ?array
    {
        $stmt = app(\PDO::class)->query("SELECT id, username, email FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
