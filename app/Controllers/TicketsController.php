<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\TicketService;
use DomainException;
use RuntimeException;
use Throwable;

class TicketsController
{
    public function __construct(private TicketService $tickets)
    {
    }

    public function index(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $data = $this->tickets->getTicketIndexData($viewer, request()?->query ?? []);

        Response::view('tickets/index', [
            'title' => 'Tickets',
            'pageHeading' => 'รายการแจ้งซ่อม',
            'currentUser' => $viewer,
            'metrics' => $data['metrics'],
            'tickets' => $data['tickets'],
            'roleLabel' => $data['roleLabel'],
            'filters' => $data['filters'],
            'pagination' => $data['pagination'],
        ]);
    }

    public function create(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $form = $this->tickets->getCreateFormData($viewer, pull_old_input(), request()?->query ?? []);

        Response::view('tickets/create', [
            'title' => 'Create Ticket',
            'pageHeading' => 'แจ้งปัญหาใหม่',
            'currentUser' => $viewer,
            'form' => $form,
            'errorMessage' => flash_message('error'),
        ]);
    }

    public function store(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            clear_old_input();

            $ticketId = $this->tickets->createTicket($viewer, $_POST, $_FILES['attachments'] ?? []);
            flash('success', 'สร้างรายการแจ้งซ่อมเรียบร้อยแล้ว');
            Response::redirect('/tickets/' . $ticketId);
        } catch (DomainException|RuntimeException $exception) {
            with_old_input([
                'title' => (string) ($_POST['title'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'priority_id' => (string) ($_POST['priority_id'] ?? ''),
                'ticket_category_id' => (string) ($_POST['ticket_category_id'] ?? ''),
                'location_id' => (string) ($_POST['location_id'] ?? ''),
                'asset_id' => (string) ($_POST['asset_id'] ?? ''),
                'impact_level' => (string) ($_POST['impact_level'] ?? 'medium'),
                'urgency_level' => (string) ($_POST['urgency_level'] ?? 'medium'),
                'submission_token' => (string) ($_POST['submission_token'] ?? ''),
            ]);
            flash('error', $exception->getMessage());
            Response::redirect('/tickets/create');
        }
    }

    public function duplicate(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        try {
            $form = $this->tickets->getDuplicateFormData((int) $ticketId, $viewer);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/tickets/' . (int) $ticketId);
        }

        Response::view('tickets/create', [
            'title' => 'Duplicate Ticket',
            'pageHeading' => 'เปิด Ticket ใหม่จากรายการเดิม',
            'currentUser' => $viewer,
            'form' => $form,
            'errorMessage' => flash_message('error'),
        ]);
    }

    public function bulkApprove(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $role = (string) ($viewer['role'] ?? 'guest');
        if (!in_array($role, ['manager', 'admin'], true)) {
            flash('error', 'เฉพาะผู้จัดการหรือผู้ดูแลระบบเท่านั้น');
            Response::redirect('/tickets');
        }

        try {
            csrf_validate();
            $raw = (string) ($_POST['ticket_ids'] ?? '');
            $ids = array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== '' && ctype_digit($v));
            $result = $this->tickets->bulkApproveTickets($ids, $viewer);

            $message = 'Approve สำเร็จ ' . (int) $result['approved'] . ' รายการ';
            $failedCount = count($result['failed'] ?? []);
            if ($failedCount > 0) {
                $message .= ' · ล้มเหลว ' . $failedCount . ' รายการ (สถานะอาจเปลี่ยนไปแล้ว)';
            }
            flash('success', $message);
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect('/tickets?status=pending_approval');
    }

    public function approve(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            clear_old_input();

            $this->tickets->approveTicket((int) $ticketId, $viewer, $_POST);
            flash('success', 'อนุมัติรายการแจ้งซ่อมเรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            with_old_input([
                'note' => (string) ($_POST['note'] ?? ''),
            ]);
            flash('error', $exception->getMessage());
        }

        Response::redirect('/tickets/' . (int) $ticketId);
    }

    public function reject(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            clear_old_input();

            $this->tickets->rejectTicket((int) $ticketId, $viewer, $_POST);
            flash('success', 'ปฏิเสธรายการแจ้งซ่อมเรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            with_old_input([
                'note' => (string) ($_POST['note'] ?? ''),
            ]);
            flash('error', $exception->getMessage());
        }

        Response::redirect('/tickets/' . (int) $ticketId);
    }

    public function assign(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            clear_old_input();

            $this->tickets->assignTechnician((int) $ticketId, $viewer, $_POST);
            flash('success', 'มอบหมายช่างเทคนิคเรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            with_old_input([
                'technician_id' => (string) ($_POST['technician_id'] ?? ''),
                'instructions' => (string) ($_POST['instructions'] ?? ''),
            ]);
            flash('error', $exception->getMessage());
        }

        Response::redirect('/tickets/' . (int) $ticketId);
    }

    public function accept(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            clear_old_input();

            $this->tickets->acceptAssignedWork((int) $ticketId, $viewer, $_POST);
            flash('success', 'รับงานเรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            with_old_input([
                'accept_note' => (string) ($_POST['accept_note'] ?? ''),
            ]);
            flash('error', $exception->getMessage());
        }

        Response::redirect('/tickets/' . (int) $ticketId);
    }

    public function start(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            clear_old_input();

            $this->tickets->startAssignedWork((int) $ticketId, $viewer, $_POST);
            flash('success', 'อัปเดตสถานะเป็นกำลังดำเนินการแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            with_old_input([
                'start_note' => (string) ($_POST['start_note'] ?? ''),
            ]);
            flash('error', $exception->getMessage());
        }

        Response::redirect('/tickets/' . (int) $ticketId);
    }

    public function resolve(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            clear_old_input();

            $this->tickets->resolveAssignedWork((int) $ticketId, $viewer, $_POST);
            flash('success', 'สรุปผลการซ่อมและอัปเดตงานเป็น Resolved เรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            with_old_input([
                'diagnosis_summary' => (string) ($_POST['diagnosis_summary'] ?? ''),
                'resolution_summary' => (string) ($_POST['resolution_summary'] ?? ''),
                'labor_minutes' => (string) ($_POST['labor_minutes'] ?? '0'),
            ]);
            flash('error', $exception->getMessage());
        }

        Response::redirect('/tickets/' . (int) $ticketId);
    }

    public function complete(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            clear_old_input();

            $this->tickets->completeResolvedTicket((int) $ticketId, $viewer, $_POST);
            flash('success', 'ยืนยันปิดงานและบันทึกคะแนนความพึงพอใจเรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            with_old_input([
                'closure_note' => (string) ($_POST['closure_note'] ?? ''),
                'score' => (string) ($_POST['score'] ?? ''),
                'feedback' => (string) ($_POST['feedback'] ?? ''),
            ]);
            flash('error', $exception->getMessage());
        }

        Response::redirect('/tickets/' . (int) $ticketId);
    }

    public function reopen(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            clear_old_input();

            $this->tickets->reopenTicket((int) $ticketId, $viewer, $_POST);
            flash('success', 'ส่งงานกลับไปดำเนินการซ้ำเรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            with_old_input([
                'reopen_note' => (string) ($_POST['reopen_note'] ?? ''),
            ]);
            flash('error', $exception->getMessage());
        }

        Response::redirect('/tickets/' . (int) $ticketId);
    }

    public function cancel(string $ticketId): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            clear_old_input();
            $this->tickets->cancelTicket((int) $ticketId, $viewer, $_POST);
            flash('success', 'ยกเลิก Ticket เรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            with_old_input([
                'cancel_note' => (string) ($_POST['cancel_note'] ?? ''),
            ]);
            flash('error', $exception->getMessage());
        }

        Response::redirect('/tickets/' . (int) $ticketId);
    }

    public function show(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $oldInput = pull_old_input();
        if (!array_key_exists('editing_comment_id', $oldInput) && request() !== null) {
            $editCommentId = (int) (request()?->query['edit_comment'] ?? 0);
            if ($editCommentId > 0) {
                $oldInput['editing_comment_id'] = (string) $editCommentId;
            }
        }

        $detail = $this->tickets->getTicketDetailData((int) $ticketId, $viewer, $oldInput);

        if ($detail === null) {
            Response::abort(404, 'ไม่พบรายการแจ้งซ่อมที่คุณต้องการเปิดดู');
        }

        Response::view('tickets/show', [
            'title' => 'Ticket Detail',
            'pageHeading' => 'รายละเอียด Ticket',
            'currentUser' => $viewer,
            'ticket' => $detail['ticket'],
            'attachments' => $detail['attachments'],
            'comments' => $detail['comments'],
            'activityLogs' => $detail['activityLogs'],
            'workflow' => $detail['workflow'],
        ]);
    }

    public function print(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $paper = (string) (request()?->query['paper'] ?? 'a4');
        $print = $this->tickets->getPrintableTicketData((int) $ticketId, $viewer, $paper);

        if ($print === null) {
            Response::abort(404, 'ไม่พบรายการแจ้งซ่อมที่ต้องการพิมพ์');
        }

        Response::view('tickets/print', [
            'title' => 'Job Order Print',
            'pageHeading' => 'พิมพ์ใบสั่งงาน',
            'currentUser' => $viewer,
            'ticket' => $print['ticket'],
            'paper' => $print['paper'],
            'paperLabel' => $print['paper_label'],
            'printedAt' => $print['printed_at'],
        ], 'print');
    }

    public function printPdf(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $paper = (string) (request()?->query['paper'] ?? 'a4');

        try {
            $export = $this->tickets->generatePrintableTicketPdf((int) $ticketId, $viewer, $paper);
            Response::download(
                (string) ($export['content'] ?? ''),
                (string) ($export['file_name'] ?? 'job-order.pdf'),
                (string) ($export['content_type'] ?? 'application/pdf')
            );
        } catch (DomainException|RuntimeException $exception) {
            Response::abort(404, $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Job Order PDF generation failed: ' . $exception::class . ': ' . $exception->getMessage());
            Response::abort(500, 'ไม่สามารถสร้างไฟล์ Job Order PDF ได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    public function printQr(string $ticketId): never
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $png = $this->tickets->generatePrintQrPng((int) $ticketId, $viewer);
            http_response_code(200);
            header('Content-Type: image/png');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo $png;
            exit;
        } catch (DomainException|RuntimeException $exception) {
            Response::abort(404, $exception->getMessage());
        }
    }
}
