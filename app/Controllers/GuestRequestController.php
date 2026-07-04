<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Repositories\AdminRepository;
use App\Services\GuestTicketService;
use App\Services\TicketService;
use DomainException;

class GuestRequestController
{
    use HandlesFormSubmission;

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
        require_role($viewer, ['manager', 'admin'], 'หน้านี้สงวนสำหรับผู้จัดการหรือผู้ดูแลระบบเท่านั้น');

        $query = request()?->query ?? [];
        $status = (string) ($query['status'] ?? 'new');
        $page = max(1, (int) ($query['page'] ?? 1));
        $data = $this->guests->getModerationData($status, $page);

        Response::view('admin/guest-requests', [
            'title' => 'คำขอแจ้งซ่อมจาก QR',
            'pageHeading' => 'คำขอแจ้งซ่อมจาก QR',
            'currentUser' => $viewer,
            'requests' => $data['requests'],
            'totals' => $data['totals'],
            'pagination' => $data['pagination'],
            'selectedStatus' => $status,
            'priorities' => $this->adminRepo->getPriorities(),
            'categories' => $this->adminRepo->getTicketCategories(),
            'queueMaxId' => $this->guests->getQueueMaxId(),
        ]);
    }

    /**
     * Lightweight JSON state สำหรับ live poll หน้าคิว — max id ของ guest request (คำขอใหม่ = id เพิ่ม).
     */
    public function state(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['manager', 'admin'], 'หน้านี้สงวนสำหรับผู้จัดการหรือผู้ดูแลระบบเท่านั้น');

        Response::json(['max_id' => $this->guests->getQueueMaxId()]);
    }

    public function convert(string $requestId): void
    {
        $this->handleUpdate(function (array $viewer) use ($requestId): void {
            $priorityId = (int) ($_POST['priority_id'] ?? 0);
            $categoryId = (int) ($_POST['ticket_category_id'] ?? 0);
            if ($priorityId <= 0 || $categoryId <= 0) {
                throw new DomainException('กรุณาเลือกความสำคัญและหมวดหมู่');
            }
            $this->guests->convertToTicket((int) $requestId, $viewer, $priorityId, $categoryId, $this->tickets);
        }, 'แปลงเป็น Ticket เรียบร้อยแล้ว', '/admin/guest-requests', ['manager', 'admin'], 'หน้านี้สงวนสำหรับผู้จัดการหรือผู้ดูแลระบบเท่านั้น');
    }

    public function reject(string $requestId): void
    {
        $this->handleUpdate(function (array $viewer) use ($requestId): void {
            $this->guests->rejectRequest((int) $requestId, $viewer, trim((string) ($_POST['note'] ?? '')));
        }, 'ปฏิเสธคำขอเรียบร้อยแล้ว', '/admin/guest-requests', ['manager', 'admin'], 'หน้านี้สงวนสำหรับผู้จัดการหรือผู้ดูแลระบบเท่านั้น');
    }
}
