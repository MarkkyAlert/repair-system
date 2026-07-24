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

    /**
     * หน้าตัวช่วยตั้งค่าระบบครั้งแรก (GET /setup) — public endpoint แต่ล็อกตัวเองเมื่อ setup เสร็จแล้ว หรือมี admin ที่ active อยู่แล้ว (เช็คทั้ง flag และการมี admin กันช่องบน seed-deploy) → redirect ไป /login.
     * render ฟอร์มตั้งค่า (layout guest).
     */
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

    /**
     * รันการตั้งค่าระบบครั้งแรก (POST /setup) — public endpoint แต่ทำได้ครั้งเดียว (ล็อกเมื่อ setup เสร็จ/มี admin อยู่แล้ว).
     * ผลข้างเคียง: ผ่าน runFirstRunSetup ตั้งชื่อระบบ + สร้าง admin คนแรก + (ถ้าเลือก) โหลด demo + ตั้ง flag setup_completed แบบ atomic ใน named lock กันการยิงพร้อมกันสร้าง admin ซ้ำ.
     * สำเร็จ → redirect ไป /login (flash อาจมีรหัสช่างตัวอย่างที่โชว์ครั้งเดียว); error → rollback แล้ว redirect กลับ /setup.
     */
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
            if (mb_strlen($appName) > \App\Services\SystemSettingsService::APP_NAME_MAX_LENGTH) {
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
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
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
     * เตรียมสถานะการตั้งค่าครั้งแรก (ชื่อระบบ → admin คนแรก → demo ถ้าเลือก → flag ว่าตั้งค่าเสร็จ) แบบ atomic คือทำสำเร็จหมดหรือไม่ทำเลย,
     * เรียงคิวด้วย named lock ของ DB แล้วเช็ค admin/flag ซ้ำอีกทีตอนอยู่ในล็อก กันไม่ให้ setup ที่ยิงพร้อมกันสองชุด
     * ต่างคนต่างสร้าง admin (unique key ผูกกับ username/email แต่ละตัว ไม่ใช่ "มี admin ได้คนเดียว"). ไม่มี HTTP/redirect
     * เลยเทสต์ตรง ๆ ได้. โยน DomainException ถ้าตั้งค่าเสร็จไปแล้ว (ฝ่ายที่แพ้ตอนแข่งกันยิงพร้อมกัน) หรือข้อมูล admin
     * ที่กรอกมาไม่ถูกต้อง; คืนค่าสรุป demo (หรือ null).
     *
     * @param array<string, mixed> $input ฟิลด์ admin_* / load_demo แบบดิบ
     * @return array<string, mixed>|null
     */
    public function runFirstRunSetup(string $appName, array $input): ?array
    {
        $this->acquireSetupLock();

        try {
            if ($this->isSetupCompleted() || $this->adminExists()) {
                // อีก request ที่แข่งกันทำ ตั้งค่าเสร็จไปแล้ว — อย่าสร้าง admin คนที่สอง
                throw new DomainException('ระบบถูกตั้งค่าเรียบร้อยแล้ว กรุณาเข้าสู่ระบบ');
            }

            $username = strtolower(trim((string) ($input['admin_username'] ?? '')));
            $email = strtolower(trim((string) ($input['admin_email'] ?? '')));
            $fullName = trim((string) ($input['admin_full_name'] ?? ''));
            $password = (string) ($input['admin_password'] ?? '');

            if ($username === '' || $email === '' || $fullName === '' || $password === '') {
                throw new DomainException('กรุณากรอกข้อมูลผู้ดูแลระบบให้ครบถ้วน');
            }
            require_max_length($fullName, 150, 'ชื่อ-นามสกุล'); // users.full_name VARCHAR(150) — ให้ตรงกับ flow อื่น ๆ ที่เกี่ยวกับ user
            if (!is_valid_username($username)) {
                throw new DomainException('ชื่อผู้ใช้ต้องมี 3-50 ตัว (a-z, 0-9, จุด, ขีดกลาง, ขีดล่าง)');
            }
            if (!is_valid_email($email)) {
                throw new DomainException('รูปแบบอีเมลผู้ดูแลระบบไม่ถูกต้อง');
            }
            if (!password_has_minimum_length($password)) {
                throw new DomainException('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
            }

            try {
                // เขียนข้อมูลตั้งค่าครั้งแรกแบบทำทั้งหมดหรือไม่ทำเลย (ยกเว้นให้ช่วง bootstrap ของกฎ "transaction ต้องอยู่ใน service")
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
     * คืน true ถ้ามี admin ที่ active อยู่แล้วอย่างน้อยหนึ่งคน (เคส deploy แบบ seed หรือสร้างเอง). เป็น static +
     * รับ PDO เข้ามาเป็น argument เพราะด่านกั้น setup (public/index.php) รันก่อน container จะประกอบ controller; ตัว
     * SQL อยู่ใน UserRepository เลยไม่มีข้อความ query ปนอยู่ใน controller นี้.
     */
    public static function hasActiveAdmin(PDO $db): bool
    {
        return (new UserRepository($db))->hasActiveAdmin();
    }

    /**
     * บอกว่า request ต้องถูก redirect ไป /setup ไหม. ถือว่า setup เสร็จเมื่อ flag ถูกตั้ง หรือมี
     * admin ที่ active อยู่แล้ว — กฎเดียวกับที่ show()/execute() ใช้กั้น /setup. ให้ด่านกั้น
     * (public/index.php) ใช้กฎเดียวกันจะได้ไม่วนลูป /setup ↔ /login บน deploy แบบ seed/มี admin มาให้แล้ว.
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

    /** named lock ที่ผูกกับ connection สำหรับเรียงลำดับการตั้งค่าครั้งแรกไม่ให้ทำซ้อนกัน (ปล่อยอัตโนมัติเมื่อปิด connection). */
    private function acquireSetupLock(): void
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(:name, 10)');
        $stmt->execute(['name' => 'maintenance-first-run-setup']);
        if ((int) $stmt->fetchColumn() !== 1) {
            // มี setup อีกชุดที่ยิงพร้อมกันถือ lock อยู่ — เป็นสถานการณ์ "ลองใหม่" ที่คาดไว้ ไม่ใช่ปัญหาระดับปฏิบัติการ
            // เลยจัดเป็น DomainException (แจ้งผ่าน flash แล้วให้ลองใหม่) ตามหมวดหมู่ error ที่วางไว้.
            throw new DomainException('ระบบกำลังตั้งค่าอยู่ กรุณาลองใหม่อีกครั้ง');
        }
    }

    private function releaseSetupLock(): void
    {
        try {
            $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute(['name' => 'maintenance-first-run-setup']);
        } catch (Throwable) {
            // การปล่อย lock ที่ผูกกับ connection ต้องไม่ไปบดบังผลลัพธ์ของการ setup
        }
    }
}
