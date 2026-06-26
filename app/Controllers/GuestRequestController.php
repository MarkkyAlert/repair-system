<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Repositories\AdminRepository;
use App\Services\GuestTicketService;
use App\Services\TicketService;
use DomainException;
use RuntimeException;

class GuestRequestController
{
    public function __construct(
        private GuestTicketService $guests,
        private TicketService $tickets,
        private AdminRepository $adminRepo,
    ) {
    }

    public function index(): void
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

    public function convert(string $requestId): void
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

    public function reject(string $requestId): void
    {
        $this->handleUpdate(function (array $viewer) use ($requestId): void {
            $this->guests->rejectRequest((int) $requestId, $viewer, trim((string) ($_POST['note'] ?? '')));
        }, 'ปฏิเสธคำขอเรียบร้อยแล้ว', '/admin/guest-requests');
    }

    private function handleUpdate(callable $callback, string $successMessage, string $redirectTo): void
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
