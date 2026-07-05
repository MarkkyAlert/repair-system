<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Repositories\TicketReadRepository;
use App\Repositories\UserRepository;
use App\Services\AdminService;
use App\Services\BroadcastService;
use App\Services\DemoDataService;
use App\Services\ReferenceDataService;
use App\Services\SystemSettingsService;
use DomainException;
use RuntimeException;

class AdminController
{
    use HandlesFormSubmission;

    public function __construct(
        private AdminService $admin,
        private BroadcastService $broadcast,
        private SystemSettingsService $systemSettings,
        private ReferenceDataService $reference,
        private DemoDataService $demoData,
        private TicketReadRepository $reads,
        private UserRepository $users,
    ) {
    }

    /**
     * Admin-only mutation wrapper — บังคับ role gate ('admin') ที่ controller ก่อน csrf/mutation
     * (require_role → 403 abort). service assert_admin คงไว้เป็น defense-in-depth. ทุก admin action
     * ใช้ตัวนี้แทน handleUpdate เพื่อกันการลืมส่ง role (ต้นตอ BAC แบบ EmailQueue::retry).
     */
    private function adminUpdate(
        callable $callback,
        string $successMessage = 'บันทึกข้อมูลเรียบร้อยแล้ว',
        string $redirectTo = '/admin',
        ?array $oldInputOnError = null
    ): void {
        $this->handleUpdate($callback, $successMessage, $redirectTo, ['admin'], 'หน้านี้สงวนสำหรับผู้ดูแลระบบเท่านั้น', $oldInputOnError);
    }

    public function index(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['admin'], 'หน้านี้สงวนสำหรับผู้ดูแลระบบเท่านั้น');

        try {
            $data = $this->admin->getAdminPageData($viewer, request()?->query ?? []);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/dashboard');
        }

        Response::view('admin/index', [
            'title' => 'ตั้งค่าระบบ',
            'pageHeading' => 'ตั้งค่าระบบ',
            'currentUser' => $viewer,
            'users' => $data['users'],
            'departments' => $data['departments'],
            'categories' => $data['categories'],
            'assetCategories' => $data['assetCategories'],
            'locations' => $data['locations'],
            'priorities' => $data['priorities'],
            'departmentOptions' => $data['departmentOptions'],
            'roles' => $data['roles'],
            'categorySla' => $data['categorySla'],
            'systemSettingForm' => $data['systemSettingForm'],
            'settings' => $data['settings'],
            'rolePreview' => $data['rolePreview'],
            'auditLogs' => $data['auditLogs'],
            'auditFilters' => $data['auditFilters'],
            'auditFilterOptions' => $data['auditFilterOptions'],
            'mailDiagnostics' => $data['mailDiagnostics'],
            'emailPreviews' => $data['emailPreviews'],
            'loginAttempts' => $data['loginAttempts'],
            'loginAttemptStats' => $data['loginAttemptStats'],
            'canLoadDemo' => $this->reads->countAllTickets() === 0,
        ]);
    }

    public function loadDemoData(): void
    {
        // เขียนเองแทน handleUpdate เพราะต้อง surface รหัสช่างตัวอย่าง (สุ่มต่อครั้ง) ลง flash
        // ก่อน redirect — handleUpdate ใช้ success message คงที่แล้ว redirect ทันที
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['admin'], 'เฉพาะผู้ดูแลระบบเท่านั้น');

        try {
            csrf_validate();
            $result = $this->demoData->load((int) ($viewer['id'] ?? 0));
            $message = 'โหลดข้อมูลตัวอย่างเรียบร้อยแล้ว';
            if (!empty($result['demo_technician'])) {
                $cred = $result['demo_technician'];
                $message .= ' · บัญชีช่างตัวอย่าง: ' . (string) $cred['username']
                    . ' / ' . (string) $cred['password'] . ' (บันทึกไว้ — รหัสนี้จะไม่แสดงอีก)';
            }
            flash('success', $message);
        } catch (DomainException | RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect('/admin');
    }

    public function updateUser(string $userId): void
    {
        $this->adminUpdate(function (array $viewer) use ($userId): void {
            $this->admin->updateUser((int) $userId, $viewer, $_POST);
        });
    }

    public function createUser(): void
    {
        $this->adminUpdate(function (array $viewer): void {
            $this->admin->createUser($viewer, $_POST);
        }, 'สร้างบัญชีผู้ใช้งานเรียบร้อยแล้ว');
    }

    public function createDepartment(): void
    {
        $this->adminUpdate(function (array $viewer): void {
            $this->reference->createDepartment($viewer, $_POST);
        }, 'เพิ่มแผนกเรียบร้อยแล้ว');
    }

    public function updateDepartment(string $departmentId): void
    {
        $this->adminUpdate(function (array $viewer) use ($departmentId): void {
            $this->reference->updateDepartment((int) $departmentId, $viewer, $_POST);
        });
    }

    public function deleteDepartment(string $departmentId): void
    {
        $this->adminUpdate(function (array $viewer) use ($departmentId): void {
            $this->reference->deleteDepartment((int) $departmentId, $viewer);
        }, 'ลบแผนกเรียบร้อยแล้ว');
    }

    public function createTicketCategory(): void
    {
        $this->adminUpdate(function (array $viewer): void {
            $this->reference->createTicketCategory($viewer, $_POST);
        }, 'เพิ่มหมวดหมู่งานเรียบร้อยแล้ว');
    }

    public function updateTicketCategory(string $categoryId): void
    {
        $this->adminUpdate(function (array $viewer) use ($categoryId): void {
            $this->reference->updateTicketCategory((int) $categoryId, $viewer, $_POST);
        });
    }

    public function deleteTicketCategory(string $categoryId): void
    {
        $this->adminUpdate(function (array $viewer) use ($categoryId): void {
            $this->reference->deleteTicketCategory((int) $categoryId, $viewer);
        }, 'ลบหมวดหมู่งานเรียบร้อยแล้ว');
    }

    public function createAssetCategory(): void
    {
        $this->adminUpdate(function (array $viewer): void {
            $this->reference->createAssetCategory($viewer, $_POST);
        }, 'เพิ่มหมวดหมู่ Asset เรียบร้อยแล้ว');
    }

    public function updateAssetCategory(string $categoryId): void
    {
        $this->adminUpdate(function (array $viewer) use ($categoryId): void {
            $this->reference->updateAssetCategory((int) $categoryId, $viewer, $_POST);
        });
    }

    public function deleteAssetCategory(string $categoryId): void
    {
        $this->adminUpdate(function (array $viewer) use ($categoryId): void {
            $this->reference->deleteAssetCategory((int) $categoryId, $viewer);
        }, 'ลบหมวดหมู่ Asset เรียบร้อยแล้ว');
    }

    public function createLocation(): void
    {
        $this->adminUpdate(function (array $viewer): void {
            $this->reference->createLocation($viewer, $_POST);
        }, 'เพิ่มสถานที่เรียบร้อยแล้ว');
    }

    public function updateLocation(string $locationId): void
    {
        $this->adminUpdate(function (array $viewer) use ($locationId): void {
            $this->reference->updateLocation((int) $locationId, $viewer, $_POST);
        });
    }

    public function deleteLocation(string $locationId): void
    {
        $this->adminUpdate(function (array $viewer) use ($locationId): void {
            $this->reference->deleteLocation((int) $locationId, $viewer);
        }, 'ลบสถานที่เรียบร้อยแล้ว');
    }

    public function createPriority(): void
    {
        $this->adminUpdate(function (array $viewer): void {
            $this->reference->createPriority($viewer, $_POST);
        }, 'เพิ่ม Priority เรียบร้อยแล้ว');
    }

    public function updatePriority(string $priorityId): void
    {
        $this->adminUpdate(function (array $viewer) use ($priorityId): void {
            $this->reference->updatePriority((int) $priorityId, $viewer, $_POST);
        });
    }

    public function deletePriority(string $priorityId): void
    {
        $this->adminUpdate(function (array $viewer) use ($priorityId): void {
            $this->reference->deletePriority((int) $priorityId, $viewer);
        }, 'ลบ Priority เรียบร้อยแล้ว');
    }

    public function updateSystemSettings(): void
    {
        $this->adminUpdate(function (array $viewer): void {
            $this->systemSettings->updateSystemSettings($viewer, $_POST);
        }, 'อัปเดตการตั้งค่าระบบเรียบร้อยแล้ว');
    }

    public function sendTestEmail(): void
    {
        $this->adminUpdate(function (array $viewer): void {
            $this->broadcast->sendTestEmail($viewer, $_POST);
        }, 'ส่งอีเมลทดสอบเรียบร้อยแล้ว');
    }

    public function updateSetting(): void
    {
        $this->adminUpdate(function (array $viewer): void {
            $this->systemSettings->updateSetting($viewer, $_POST);
        });
    }

    public function updateLogo(): void
    {
        $this->adminUpdate(function (array $viewer): void {
            $this->systemSettings->updateLogo($viewer, $_FILES, $_POST);
        }, 'อัปเดตโลโก้องค์กรเรียบร้อยแล้ว');
    }

    public function broadcastForm(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['admin'], 'หน้านี้สงวนสำหรับผู้ดูแลระบบเท่านั้น');

        $actorId = (int) ($viewer['id'] ?? 0);
        $recipientCounts = [
            '' => max(0, count($this->users->findActiveUserIds(null)) - 1),
        ];
        foreach (valid_roles() as $role) {
            $ids = $this->users->findActiveUserIds($role);
            $recipientCounts[$role] = count(array_filter($ids, static fn (int $id): bool => $id !== $actorId));
        }

        Response::view('admin/broadcast', [
            'title' => 'ส่งประกาศถึงผู้ใช้',
            'pageHeading' => 'ประกาศจากผู้ดูแลระบบ',
            'currentUser' => $viewer,
            'errorMessage' => flash_message('error'),
            'successMessage' => flash_message('success'),
            'oldInput' => pull_old_input(),
            'recipientCounts' => $recipientCounts,
        ]);
    }

    public function sendBroadcast(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            $result = $this->broadcast->sendBroadcast($viewer, $_POST);
            flash('success', sprintf(
                'ส่งประกาศแล้ว — in-app: %d คน · email: %d คน',
                (int) ($result['in_app_count'] ?? 0),
                (int) ($result['email_count'] ?? 0)
            ));
            Response::redirect('/admin/broadcast');
        } catch (DomainException | RuntimeException $exception) {
            with_old_input([
                'title' => (string) ($_POST['title'] ?? ''),
                'message' => (string) ($_POST['message'] ?? ''),
                'role_filter' => (string) ($_POST['role_filter'] ?? ''),
            ]);
            flash('error', $exception->getMessage());
            Response::redirect('/admin/broadcast');
        }
    }

}
