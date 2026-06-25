<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Repositories\AdminRepository;
use App\Repositories\EmailTemplateRepository;
use App\Services\AdminService;
use App\Services\EmailQueueService;
use App\Services\EmailTemplateService;
use App\Services\GuestTicketService;
use App\Services\TicketService;
use App\Services\UserImportService;
use DomainException;
use RuntimeException;

class AdminController
{
    public function __construct(
        private AdminService $admin,
        private EmailQueueService $emailQueue,
        private UserImportService $userImporter,
        private EmailTemplateRepository $templates,
        private GuestTicketService $guests,
        private TicketService $tickets,
        private AdminRepository $adminRepo,
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
            'title' => 'Admin Panels',
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

    public function emailQueue(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
            flash('error', 'เฉพาะผู้ดูแลระบบเท่านั้น');
            Response::redirect('/dashboard');
        }

        $query = request()?->query ?? [];
        $status = (string) ($query['status'] ?? '');
        $page = max(1, (int) ($query['page'] ?? 1));
        $data = $this->emailQueue->listJobsPaginated($status, $page);

        Response::view('admin/email-queue', [
            'title' => 'Email Queue',
            'pageHeading' => 'คิวอีเมล',
            'currentUser' => $viewer,
            'jobs' => $data['jobs'],
            'totals' => $data['totals'],
            'pagination' => $data['pagination'],
            'selectedStatus' => $status,
        ]);
    }

    public function retryEmailJob(string $emailId): void
    {
        $this->handleUpdate(function () use ($emailId): void {
            $this->emailQueue->retryJob((int) $emailId);
        }, 'ส่งคำสั่งให้ลองอีเมลใหม่เรียบร้อยแล้ว', '/admin/email-queue');
    }

    public function importUsersForm(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
            flash('error', 'เฉพาะผู้ดูแลระบบเท่านั้น');
            Response::redirect('/dashboard');
        }

        Response::view('admin/import-users', [
            'title' => 'Import Users (CSV)',
            'pageHeading' => 'นำเข้าผู้ใช้จาก CSV',
            'currentUser' => $viewer,
            'preview' => null,
            'errorMessage' => flash_message('error'),
        ]);
    }

    public function importUsersPreview(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
                throw new DomainException('เฉพาะผู้ดูแลระบบเท่านั้น');
            }

            $rows = $this->userImporter->parseUploadedFile($_FILES['csv'] ?? []);
            $preview = $this->userImporter->validateRows($rows);

            Session::put('user_import_valid_rows', $preview['valid']);

            Response::view('admin/import-users', [
                'title' => 'Preview Import Users',
                'pageHeading' => 'ตรวจสอบก่อนนำเข้า',
                'currentUser' => $viewer,
                'preview' => $preview,
                'errorMessage' => null,
            ]);
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/admin/users/import');
        }
    }

    public function importUsersExecute(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
                throw new DomainException('เฉพาะผู้ดูแลระบบเท่านั้น');
            }

            $validRows = Session::get('user_import_valid_rows', []);
            if (!is_array($validRows) || $validRows === []) {
                throw new DomainException('ไม่พบข้อมูลที่ผ่านการตรวจสอบ กรุณาเริ่มกระบวนการนำเข้าใหม่');
            }

            $result = $this->userImporter->executeImport($validRows);
            Session::forget('user_import_valid_rows');

            $skipped = count($result['skipped'] ?? []);
            $summary = 'นำเข้า ' . (int) $result['imported'] . ' ผู้ใช้';
            if ((int) $result['reset_emails_queued'] > 0) {
                $summary .= ' · ส่งอีเมลตั้งรหัสผ่าน ' . (int) $result['reset_emails_queued'] . ' ฉบับ';
            }
            if ($skipped > 0) {
                $summary .= ' · ข้าม ' . $skipped . ' รายการ';
            }
            flash('success', $summary);
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/admin/users/import');
        }

        Response::redirect('/admin');
    }

    public function importUsersTemplate(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
            Response::abort(403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
        }

        $columns = UserImportService::CSV_COLUMNS;
        $sample = ['somchai', 'somchai@example.com', 'สมชาย ใจดี', 'requester', 'IT', '0812345678', ''];

        $csv = implode(',', $columns) . "\r\n" . implode(',', array_map(static fn ($v): string => '"' . str_replace('"', '""', (string) $v) . '"', $sample)) . "\r\n";

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="user-import-template.csv"');
        echo "\xEF\xBB\xBF" . $csv;
        exit;
    }

    public function emailTemplates(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
            flash('error', 'เฉพาะผู้ดูแลระบบเท่านั้น');
            Response::redirect('/dashboard');
        }

        $overrides = $this->templates->getAllOverrides();
        $registry = [];
        foreach (EmailTemplateService::TEMPLATE_REGISTRY as $key => $meta) {
            $registry[$key] = $meta + ['is_customized' => isset($overrides[$key]) && $overrides[$key] !== []];
        }

        Response::view('admin/email-templates', [
            'title' => 'Email Templates',
            'pageHeading' => 'ตั้งค่าข้อความอีเมล',
            'currentUser' => $viewer,
            'registry' => $registry,
        ]);
    }

    public function emailTemplateEdit(string $templateKey): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
            flash('error', 'เฉพาะผู้ดูแลระบบเท่านั้น');
            Response::redirect('/dashboard');
        }

        $meta = EmailTemplateService::TEMPLATE_REGISTRY[$templateKey] ?? null;
        if ($meta === null) {
            Response::abort(404, 'ไม่พบ template ที่ต้องการแก้ไข');
        }

        $values = $this->templates->getByKey($templateKey);
        $defaults = [
            'heading' => '— ใช้ heading ที่ระบบสร้างให้ตาม event —',
            'intro' => $this->defaultIntroFor($templateKey),
            'footer_note' => 'อีเมลฉบับนี้ถูกสร้างอัตโนมัติจากระบบแจ้งซ่อม',
        ];

        Response::view('admin/email-templates-edit', [
            'title' => 'Edit Email Template',
            'pageHeading' => 'แก้ไข template: ' . (string) $meta['label'],
            'currentUser' => $viewer,
            'templateKey' => $templateKey,
            'meta' => $meta,
            'values' => $values,
            'defaults' => $defaults,
            'errorMessage' => flash_message('error'),
            'successMessage' => flash_message('success'),
        ]);
    }

    public function emailTemplateUpdate(string $templateKey): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        $userId = (int) ($viewer['id'] ?? 0);

        try {
            csrf_validate();
            if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
                throw new DomainException('เฉพาะผู้ดูแลระบบเท่านั้น');
            }

            $meta = EmailTemplateService::TEMPLATE_REGISTRY[$templateKey] ?? null;
            if ($meta === null) {
                throw new DomainException('ไม่พบ template ที่ต้องการบันทึก');
            }

            foreach ($meta['fields'] as $fieldKey) {
                $value = trim((string) ($_POST[$fieldKey] ?? ''));
                $this->templates->upsertField($templateKey, $fieldKey, $value, $userId);
            }
            flash('success', 'บันทึกการตั้งค่า template เรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect('/admin/email-templates/' . rawurlencode($templateKey));
    }

    public function emailTemplateReset(string $templateKey): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
                throw new DomainException('เฉพาะผู้ดูแลระบบเท่านั้น');
            }
            if (!isset(EmailTemplateService::TEMPLATE_REGISTRY[$templateKey])) {
                throw new DomainException('ไม่พบ template ที่ต้องการรีเซ็ต');
            }
            $this->templates->resetTemplate($templateKey);
            flash('success', 'คืนค่า template เป็นค่าเริ่มต้นเรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect('/admin/email-templates/' . rawurlencode($templateKey));
    }

    public function guestRequests(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        if (!in_array((string) ($viewer['role'] ?? 'guest'), ['manager', 'admin'], true)) {
            flash('error', 'เฉพาะผู้จัดการหรือผู้ดูแลระบบเท่านั้น');
            Response::redirect('/dashboard');
        }

        $query = request()?->query ?? [];
        $status = (string) ($query['status'] ?? 'new');
        $page = max(1, (int) ($query['page'] ?? 1));
        $data = $this->guests->getModerationData($status, $page);

        Response::view('admin/guest-requests', [
            'title' => 'Guest Ticket Requests',
            'pageHeading' => 'คำขอแจ้งซ่อมจาก QR',
            'currentUser' => $viewer,
            'requests' => $data['requests'],
            'totals' => $data['totals'],
            'pagination' => $data['pagination'],
            'selectedStatus' => $status,
            'priorities' => $this->adminRepo->getPriorities(),
            'categories' => $this->adminRepo->getTicketCategories(),
        ]);
    }

    public function convertGuestRequest(string $requestId): void
    {
        $this->handleUpdate(function (array $viewer) use ($requestId): void {
            $priorityId = (int) ($_POST['priority_id'] ?? 0);
            $categoryId = (int) ($_POST['ticket_category_id'] ?? 0);
            if ($priorityId <= 0 || $categoryId <= 0) {
                throw new DomainException('กรุณาเลือก Priority และ Category');
            }
            $this->guests->convertToTicket((int) $requestId, $viewer, $priorityId, $categoryId, $this->tickets);
        }, 'แปลงเป็น ticket เรียบร้อยแล้ว', '/admin/guest-requests');
    }

    public function rejectGuestRequest(string $requestId): void
    {
        $this->handleUpdate(function (array $viewer) use ($requestId): void {
            $this->guests->rejectRequest((int) $requestId, $viewer, trim((string) ($_POST['note'] ?? '')));
        }, 'ปฏิเสธคำขอเรียบร้อยแล้ว', '/admin/guest-requests');
    }

    private function defaultIntroFor(string $templateKey): string
    {
        return match ($templateKey) {
            'ticket_created' => 'มีการอัปเดตสถานะ ticket ที่เกี่ยวข้องกับคุณ',
            'ticket_approved' => 'มีการอัปเดตสถานะ ticket ที่เกี่ยวข้องกับคุณ',
            'ticket_rejected' => 'มีการอัปเดตสถานะ ticket ที่เกี่ยวข้องกับคุณ',
            'ticket_assigned' => 'มีการอัปเดตสถานะ ticket ที่เกี่ยวข้องกับคุณ',
            'ticket_status_changed' => 'มีการอัปเดตสถานะ ticket ที่เกี่ยวข้องกับคุณ',
            'comment_event' => 'มีความเคลื่อนไหวใหม่ใน comment ของ ticket',
            'sla_breached' => 'ระบบตรวจพบ ticket ที่เกินกำหนด SLA',
            default => 'มีการอัปเดตสถานะ ticket ที่เกี่ยวข้องกับคุณ',
        };
    }

    private function handleUpdate(callable $callback, string $successMessage = 'บันทึกข้อมูลเรียบร้อยแล้ว', string $redirectTo = '/admin'): void
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

        Response::redirect($redirectTo);
    }
}
