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

            $demoSummary = $this->runFirstRunSetup($appName, $_POST);

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
            log_caught_exception('setup.execute', $exception);
            flash('error', 'ไม่สามารถตั้งค่าระบบได้ กรุณาตรวจสอบ log');
            Response::redirect('/setup');
        }
    }

    /**
     * Provision the first-run state (app name → first admin → optional demo → completed flag) atomically,
     * serialised by a DB named lock with an admin/flag RE-CHECK INSIDE the lock so two concurrent setups can
     * never each create an admin (unique keys are per username/email, not "only one admin"). No HTTP/redirect,
     * so it is directly testable. Throws DomainException if setup is already done (the race loser) or the admin
     * input is invalid; returns the demo summary (or null). (logic-review R6-F1)
     *
     * @param array<string, mixed> $input raw admin_* / load_demo fields
     * @return array<string, mixed>|null
     */
    public function runFirstRunSetup(string $appName, array $input): ?array
    {
        $this->acquireSetupLock();

        try {
            if ($this->isSetupCompleted() || $this->adminExists()) {
                // the other request in the race already finished setup — do not create a second admin
                throw new DomainException('ระบบถูกตั้งค่าเรียบร้อยแล้ว กรุณาเข้าสู่ระบบ');
            }

            $username = strtolower(trim((string) ($input['admin_username'] ?? '')));
            $email = strtolower(trim((string) ($input['admin_email'] ?? '')));
            $fullName = trim((string) ($input['admin_full_name'] ?? ''));
            $password = (string) ($input['admin_password'] ?? '');

            if ($username === '' || $email === '' || $fullName === '' || $password === '') {
                throw new DomainException('กรุณากรอกข้อมูลผู้ดูแลระบบให้ครบถ้วน');
            }
            if (!is_valid_username($username)) {
                throw new DomainException('ชื่อผู้ใช้ต้องมี 3-50 ตัว (a-z, 0-9, จุด, ขีดกลาง, ขีดล่าง)');
            }
            if (!is_valid_email($email)) {
                throw new DomainException('รูปแบบอีเมลผู้ดูแลระบบไม่ถูกต้อง');
            }
            if (strlen($password) < 8) {
                throw new DomainException('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
            }

            try {
                // all-or-nothing first-run write (bootstrap exception to the "transactions live in services" rule)
                $this->db->beginTransaction();
                $this->settings->upsert('app_name', $appName, 'string', true, 0);

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

                $demoSummary = null;
                if (truthy_input($input['load_demo'] ?? '0') && config('app.allow_demo_data', false)) {
                    $demoSummary = $this->demoData->load($adminId);
                }

                $this->settings->upsert('setup_completed', '1', 'bool', false, $adminId);
                $this->db->commit();

                return $demoSummary;
            } catch (Throwable $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $exception;
            }
        } finally {
            $this->releaseSetupLock();
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

    /**
     * True if at least one active admin already exists (a seed/manual deploy already has one). Static +
     * PDO-arg because the setup gate (public/index.php) runs before the container wires controllers; the
     * SQL itself lives in UserRepository so no query text sits in this controller.
     */
    public static function hasActiveAdmin(PDO $db): bool
    {
        return (new UserRepository($db))->hasActiveAdmin();
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

    /** Connection-scoped named lock serialising first-run setup (auto-released on connection close). */
    private function acquireSetupLock(): void
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(:name, 10)');
        $stmt->execute(['name' => 'maintenance-first-run-setup']);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('ระบบกำลังตั้งค่าอยู่ กรุณาลองใหม่อีกครั้ง');
        }
    }

    private function releaseSetupLock(): void
    {
        try {
            $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute(['name' => 'maintenance-first-run-setup']);
        } catch (Throwable) {
            // releasing a connection-scoped lock must not mask the setup result
        }
    }
}
