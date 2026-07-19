<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\TicketPrintService;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;
use DomainException;
use RuntimeException;
use Throwable;

class TicketsController
{
    use HandlesFormSubmission;

    public function __construct(
        private TicketService $tickets,
        private TicketPrintService $print,
        private TicketWorkflowService $workflow,
    ) {
    }

    public function index(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $data = $this->tickets->getTicketIndexData($viewer, request()?->query ?? []);

        Response::view('tickets/index', [
            'title' => 'รายการแจ้งซ่อม',
            'pageHeading' => 'รายการแจ้งซ่อม',
            'currentUser' => $viewer,
            'metrics' => $data['metrics'],
            'tickets' => $data['tickets'],
            'roleLabel' => $data['roleLabel'],
            'filters' => $data['filters'],
            'pagination' => $data['pagination'],
            'queueMaxId' => $data['queueMaxId'],
            'activeFilterChips' => $data['activeFilterChips'],
            'urgentAlerts' => $data['urgentAlerts'],
        ]);
    }

    public function create(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $form = $this->tickets->getCreateFormData($viewer, pull_old_input(), request()?->query ?? []);

        Response::view('tickets/create', [
            'title' => 'แจ้งปัญหาใหม่',
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
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra (โครงสร้างพื้นฐาน) → ตัวจัดการ error ส่วนกลางจะ log แล้วส่ง 500 แบบทั่วไป ไม่หลุด SQL ออกไป
        } catch (DomainException|RuntimeException $exception) {
            // DomainException คือข้อมูลนำเข้าที่คาดไว้ (CSRF/validation) — แค่ flash พอ. ส่วน RuntimeException ตรงนี้คือ
            // ความผิดพลาดระดับปฏิบัติการ (เช่น ที่เก็บไฟล์แนบเขียนลงดิสก์ไม่ได้) ซึ่งเมื่อก่อนถูก flash ไป
            // โดยไม่มีร่องรอยใน server log; ให้บันทึกไว้เพื่อให้ตรวจสอบได้.
            if ($exception instanceof RuntimeException) {
                log_caught_exception('ticket.store', $exception, ['user' => (int) ($viewer['id'] ?? 0)]);
            }
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
            'title' => 'ทำซ้ำ Ticket',
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
        if (!is_manager_or_admin($role)) {
            flash('error', 'เฉพาะผู้จัดการหรือผู้ดูแลระบบเท่านั้น');
            Response::redirect('/tickets');
        }

        try {
            csrf_validate();
            $raw = (string) ($_POST['ticket_ids'] ?? '');
            $ids = array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== '' && ctype_digit($v));
            $result = $this->workflow->bulkApproveTickets($ids, $viewer);

            $message = 'Approve สำเร็จ ' . (int) $result['approved'] . ' รายการ';
            $failedCount = count($result['failed'] ?? []);
            if ($failedCount > 0) {
                $message .= ' · ล้มเหลว ' . $failedCount . ' รายการ (สถานะอาจเปลี่ยนไปแล้ว)';
            }
            flash('success', $message);
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra (โครงสร้างพื้นฐาน) → ตัวจัดการ error ส่วนกลางจะ log แล้วส่ง 500 แบบทั่วไป ไม่หลุด SQL ออกไป
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect('/tickets?status=pending_approval');
    }

    public function approve(string $ticketId): void
    {
        $this->handleUpdate(
            fn (array $viewer) => $this->workflow->approveTicket((int) $ticketId, $viewer, $_POST),
            successMessage: 'อนุมัติรายการแจ้งซ่อมเรียบร้อยแล้ว',
            redirectTo: '/tickets/' . (int) $ticketId,
            oldInputOnError: ['note' => (string) ($_POST['note'] ?? '')],
        );
    }

    public function reject(string $ticketId): void
    {
        $this->handleUpdate(
            fn (array $viewer) => $this->workflow->rejectTicket((int) $ticketId, $viewer, $_POST),
            successMessage: 'ปฏิเสธรายการแจ้งซ่อมเรียบร้อยแล้ว',
            redirectTo: '/tickets/' . (int) $ticketId,
            oldInputOnError: ['note' => (string) ($_POST['note'] ?? '')],
        );
    }

    public function assign(string $ticketId): void
    {
        $this->handleUpdate(
            fn (array $viewer) => $this->workflow->assignTechnician((int) $ticketId, $viewer, $_POST),
            successMessage: 'มอบหมายช่างเทคนิคเรียบร้อยแล้ว',
            redirectTo: '/tickets/' . (int) $ticketId,
            oldInputOnError: [
                'technician_id' => (string) ($_POST['technician_id'] ?? ''),
                'instructions' => (string) ($_POST['instructions'] ?? ''),
            ],
        );
    }

    public function accept(string $ticketId): void
    {
        $this->handleUpdate(
            fn (array $viewer) => $this->workflow->acceptAssignedWork((int) $ticketId, $viewer, $_POST),
            successMessage: 'รับงานเรียบร้อยแล้ว',
            redirectTo: '/tickets/' . (int) $ticketId,
            oldInputOnError: ['accept_note' => (string) ($_POST['accept_note'] ?? '')],
        );
    }

    public function start(string $ticketId): void
    {
        $this->handleUpdate(
            fn (array $viewer) => $this->workflow->startAssignedWork((int) $ticketId, $viewer, $_POST),
            successMessage: 'อัปเดตสถานะเป็นกำลังดำเนินการแล้ว',
            redirectTo: '/tickets/' . (int) $ticketId,
            oldInputOnError: ['start_note' => (string) ($_POST['start_note'] ?? '')],
        );
    }

    public function resolve(string $ticketId): void
    {
        $this->handleUpdate(
            fn (array $viewer) => $this->workflow->resolveAssignedWork((int) $ticketId, $viewer, $_POST),
            successMessage: 'สรุปผลการซ่อมและอัปเดตงานเป็น Resolved เรียบร้อยแล้ว',
            redirectTo: '/tickets/' . (int) $ticketId,
            oldInputOnError: [
                'diagnosis_summary' => (string) ($_POST['diagnosis_summary'] ?? ''),
                'resolution_summary' => (string) ($_POST['resolution_summary'] ?? ''),
                'labor_minutes' => (string) ($_POST['labor_minutes'] ?? '0'),
            ],
        );
    }

    public function complete(string $ticketId): void
    {
        $this->handleUpdate(
            fn (array $viewer) => $this->workflow->completeResolvedTicket((int) $ticketId, $viewer, $_POST),
            successMessage: 'ยืนยันปิดงานและบันทึกคะแนนความพึงพอใจเรียบร้อยแล้ว',
            redirectTo: '/tickets/' . (int) $ticketId,
            oldInputOnError: [
                'closure_note' => (string) ($_POST['closure_note'] ?? ''),
                'score' => (string) ($_POST['score'] ?? ''),
                'feedback' => (string) ($_POST['feedback'] ?? ''),
            ],
        );
    }

    public function reopen(string $ticketId): void
    {
        $this->handleUpdate(
            fn (array $viewer) => $this->workflow->reopenTicket((int) $ticketId, $viewer, $_POST),
            successMessage: 'ส่งงานกลับไปดำเนินการซ้ำเรียบร้อยแล้ว',
            redirectTo: '/tickets/' . (int) $ticketId,
            oldInputOnError: ['reopen_note' => (string) ($_POST['reopen_note'] ?? '')],
        );
    }

    public function cancel(string $ticketId): void
    {
        $this->handleUpdate(
            fn (array $viewer) => $this->workflow->cancelTicket((int) $ticketId, $viewer, $_POST),
            successMessage: 'ยกเลิก Ticket เรียบร้อยแล้ว',
            redirectTo: '/tickets/' . (int) $ticketId,
            oldInputOnError: ['cancel_note' => (string) ($_POST['cancel_note'] ?? '')],
        );
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
            'title' => 'รายละเอียด Ticket',
            'pageHeading' => 'รายละเอียด Ticket',
            'currentUser' => $viewer,
            'ticket' => $detail['ticket'],
            'attachments' => $detail['attachments'],
            'comments' => $detail['comments'],
            'activityLogs' => $detail['activityLogs'],
            'workflow' => $detail['workflow'],
        ]);
    }

    /**
     * Lightweight JSON state สำหรับ live poll ในหน้า ticket detail (status + comment_count).
     * 404 ถ้าไม่มีสิทธิ์เห็น ticket (visibility เดียวกับ show).
     */
    public function queueState(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        Response::json(['max_id' => $this->tickets->getQueueMaxVisibleId($viewer)]);
    }

    public function state(string $ticketId): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        $state = $this->tickets->getTicketLiveState((int) $ticketId, $viewer);
        if ($state === null) {
            Response::json(['error' => 'not_found'], 404);
        }

        Response::json($state);
    }

    /**
     * comment ใหม่ (id > after) render เป็น HTML (partial ร่วมกับ show.php) สำหรับ live-append.
     * 404 ถ้าไม่มีสิทธิ์เห็น ticket.
     */
    public function commentsFeed(string $ticketId): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        $after = max(0, (int) (request()?->query['after'] ?? 0));

        $comments = $this->tickets->getNewComments((int) $ticketId, $viewer, $after);
        if ($comments === null) {
            Response::json(['error' => 'not_found'], 404);
        }

        $canInternal = (string) ($viewer['role'] ?? 'guest') !== 'requester';
        $html = '';
        $latestId = $after;
        foreach ($comments as $comment) {
            $html .= render_partial('partials/tickets/comment-item', [
                'comment' => $comment,
                'ticketId' => (int) $ticketId,
                'isEditing' => false,
                'canUseInternalComment' => $canInternal,
            ]);
            $latestId = max($latestId, (int) ($comment['id'] ?? 0));
        }

        Response::json([
            'html' => $html,
            'latest_id' => $latestId,
            'count' => count($comments),
        ]);
    }

    public function print(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $paper = (string) (request()?->query['paper'] ?? 'a4');
        $print = $this->print->getPrintableTicketData((int) $ticketId, $viewer, $paper);

        if ($print === null) {
            Response::abort(404, 'ไม่พบรายการแจ้งซ่อมที่ต้องการพิมพ์');
        }

        Response::view('tickets/print', [
            'title' => 'พิมพ์ใบสั่งงาน',
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
            $export = $this->print->generatePrintableTicketPdf((int) $ticketId, $viewer, $paper);
            Response::download(
                (string) ($export['content'] ?? ''),
                (string) ($export['file_name'] ?? 'job-order.pdf'),
                (string) ($export['content_type'] ?? 'application/pdf')
            );
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra (โครงสร้างพื้นฐาน) → ตัวจัดการ error ส่วนกลางจะ log แล้วส่ง 500 แบบทั่วไป ไม่หลุด SQL ออกไป
        } catch (DomainException $exception) {
            Response::abort(404, $exception->getMessage()); // ไม่พบ / ไม่มีสิทธิ์เข้าถึง — เป็น 404 ที่คาดไว้ ไม่ต้องเขียน server log
        } catch (Throwable $exception) {
            // ความผิดพลาดระดับปฏิบัติการ (RuntimeException จากการ render PDF หรืออย่างอื่น) ไม่ใช่ 404 — เมื่อก่อน
            // ถูกรายงานผิดว่า "ไม่พบ" โดยไม่มี log. ให้ log ไว้แล้วส่ง 500.
            log_caught_exception('ticket.jobpdf', $exception, ['ticket' => (int) $ticketId]);
            Response::abort(500, 'ไม่สามารถสร้างไฟล์ Job Order PDF ได้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    public function printQr(string $ticketId): never
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $png = $this->print->generatePrintQrPng((int) $ticketId, $viewer);
            Response::download($png, 'ticket-qr-' . (int) $ticketId . '.png', 'image/png', 'inline');
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra (โครงสร้างพื้นฐาน) → ตัวจัดการ error ส่วนกลางจะ log แล้วส่ง 500 แบบทั่วไป ไม่หลุด SQL ออกไป
        } catch (DomainException $exception) {
            Response::abort(404, $exception->getMessage()); // ไม่พบ / ไม่มีสิทธิ์เข้าถึง — เป็น 404 ที่คาดไว้ ไม่ต้องเขียน server log
        } catch (Throwable $exception) {
            // ความผิดพลาดระดับปฏิบัติการตอน render (RuntimeException, GD/imagick ฯลฯ) — เมื่อก่อนถูกปิดบังเป็น 404 โดย
            // ไม่มี log; ให้แสดงเป็น 500 ที่ log ไว้ เหมือน printPdf.
            log_caught_exception('ticket.qrpng', $exception, ['ticket' => (int) $ticketId]);
            Response::abort(500, 'ไม่สามารถสร้าง QR ของ Ticket ได้ กรุณาลองใหม่อีกครั้ง');
        }
    }
}
