<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\AdminService;
use DomainException;
use RuntimeException;

class AdminController
{
    public function __construct(private AdminService $admin)
    {
    }

    public function index(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        try {
            $data = $this->admin->getAdminPageData($viewer);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/dashboard');
        }

        Response::view('admin/index', [
            'title' => 'Admin Panels',
            'pageHeading' => 'ตั้งค่าระบบ',
            'currentUser' => $viewer,
            'users' => $data['users'],
            'departments' => $data['departments'],
            'categories' => $data['categories'],
            'departmentOptions' => $data['departmentOptions'],
            'roles' => $data['roles'],
            'categorySla' => $data['categorySla'],
            'settings' => $data['settings'],
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

    public function updateDepartment(string $departmentId): void
    {
        $this->handleUpdate(function (array $viewer) use ($departmentId): void {
            $this->admin->updateDepartment((int) $departmentId, $viewer, $_POST);
        });
    }

    public function updateCategory(string $categoryId): void
    {
        $this->handleUpdate(function (array $viewer) use ($categoryId): void {
            $this->admin->updateCategory((int) $categoryId, $viewer, $_POST);
        });
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

    private function handleUpdate(callable $callback, string $successMessage = 'บันทึกข้อมูลเรียบร้อยแล้ว'): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            $callback($viewer);
            flash('success', $successMessage);
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect('/admin');
    }
}
