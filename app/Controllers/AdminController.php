<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Repositories\UserRepository;
use App\Services\AdminService;
use App\Services\NotificationService;
use DomainException;
use RuntimeException;

class AdminController
{
    use HandlesFormSubmission;

    public function __construct(
        private AdminService $admin,
        private NotificationService $notifications,
        private UserRepository $users,
    ) {
    }

    public function index(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

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
        ]);
    }

    public function updateUser(string $userId): void
    {
        $this->handleUpdate(function (array $viewer) use ($userId): void {
            $this->admin->updateUser((int) $userId, $viewer, $_POST);
        });
    }

    public function createUser(): void
    {
        $this->handleUpdate(function (array $viewer): void {
            $this->admin->createUser($viewer, $_POST);
        }, 'สร้างบัญชีผู้ใช้งานเรียบร้อยแล้ว');
    }

    public function createDepartment(): void
    {
        $this->handleUpdate(function (array $viewer): void {
            $this->admin->createDepartment($viewer, $_POST);
        }, 'เพิ่มแผนกเรียบร้อยแล้ว');
    }

    public function updateDepartment(string $departmentId): void
    {
        $this->handleUpdate(function (array $viewer) use ($departmentId): void {
            $this->admin->updateDepartment((int) $departmentId, $viewer, $_POST);
        });
    }

    public function deleteDepartment(string $departmentId): void
    {
        $this->handleUpdate(function (array $viewer) use ($departmentId): void {
            $this->admin->deleteDepartment((int) $departmentId, $viewer);
        }, 'ลบแผนกเรียบร้อยแล้ว');
    }

    public function createCategory(): void
    {
        $this->handleUpdate(function (array $viewer): void {
            $this->admin->createCategory($viewer, $_POST);
        }, 'เพิ่มหมวดหมู่งานเรียบร้อยแล้ว');
    }

    public function updateCategory(string $categoryId): void
    {
        $this->handleUpdate(function (array $viewer) use ($categoryId): void {
            $this->admin->updateCategory((int) $categoryId, $viewer, $_POST);
        });
    }

    public function deleteCategory(string $categoryId): void
    {
        $this->handleUpdate(function (array $viewer) use ($categoryId): void {
            $this->admin->deleteCategory((int) $categoryId, $viewer);
        }, 'ลบหมวดหมู่งานเรียบร้อยแล้ว');
    }

    public function createAssetCategory(): void
    {
        $this->handleUpdate(function (array $viewer): void {
            $this->admin->createAssetCategory($viewer, $_POST);
        }, 'เพิ่มหมวดหมู่ Asset เรียบร้อยแล้ว');
    }

    public function updateAssetCategory(string $categoryId): void
    {
        $this->handleUpdate(function (array $viewer) use ($categoryId): void {
            $this->admin->updateAssetCategory((int) $categoryId, $viewer, $_POST);
        });
    }

    public function deleteAssetCategory(string $categoryId): void
    {
        $this->handleUpdate(function (array $viewer) use ($categoryId): void {
            $this->admin->deleteAssetCategory((int) $categoryId, $viewer);
        }, 'ลบหมวดหมู่ Asset เรียบร้อยแล้ว');
    }

    public function createLocation(): void
    {
        $this->handleUpdate(function (array $viewer): void {
            $this->admin->createLocation($viewer, $_POST);
        }, 'เพิ่มสถานที่เรียบร้อยแล้ว');
    }

    public function updateLocation(string $locationId): void
    {
        $this->handleUpdate(function (array $viewer) use ($locationId): void {
            $this->admin->updateLocation((int) $locationId, $viewer, $_POST);
        });
    }

    public function updatePriority(string $priorityId): void
    {
        $this->handleUpdate(function (array $viewer) use ($priorityId): void {
            $this->admin->updatePriority((int) $priorityId, $viewer, $_POST);
        });
    }

    public function createPriority(): void
    {
        $this->handleUpdate(function (array $viewer): void {
            $this->admin->createPriority($viewer, $_POST);
        }, 'เพิ่ม Priority เรียบร้อยแล้ว');
    }

    public function deletePriority(string $priorityId): void
    {
        $this->handleUpdate(function (array $viewer) use ($priorityId): void {
            $this->admin->deletePriority((int) $priorityId, $viewer);
        }, 'ลบ Priority เรียบร้อยแล้ว');
    }

    public function deleteLocation(string $locationId): void
    {
        $this->handleUpdate(function (array $viewer) use ($locationId): void {
            $this->admin->deleteLocation((int) $locationId, $viewer);
        }, 'ลบสถานที่เรียบร้อยแล้ว');
    }

    public function updateSystemSettings(): void
    {
        $this->handleUpdate(function (array $viewer): void {
            $this->admin->updateSystemSettings($viewer, $_POST);
        }, 'อัปเดตการตั้งค่าระบบเรียบร้อยแล้ว');
    }

    public function sendTestEmail(): void
    {
        $this->handleUpdate(function (array $viewer): void {
            $this->admin->sendTestEmail($viewer, $_POST);
        }, 'ส่งอีเมลทดสอบเรียบร้อยแล้ว');
    }

    public function updateSetting(): void
    {
        $this->handleUpdate(function (array $viewer): void {
            $this->admin->updateSetting($viewer, $_POST);
        });
    }

    public function updateLogo(): void
    {
        $this->handleUpdate(function (array $viewer): void {
            $this->admin->updateLogo($viewer, $_FILES, $_POST);
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
            $result = $this->admin->sendBroadcast($viewer, $_POST, $this->notifications);
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
