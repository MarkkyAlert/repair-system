<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\TicketReadRepository;
use App\Repositories\TicketRepository;
use DomainException;

/**
 * การเปลี่ยนสถานะตลอดวงจรชีวิตของ ticket ที่แยกออกมาจาก TicketService: approve/reject/assign (manager),
 * accept/start/resolve (technician), complete/reopen/cancel (requester), + bulk approve (อนุมัติทีละหลายรายการ).
 * แต่ละตัวมีด่านตรวจสิทธิ์ผ่าน TicketPolicy (ใช้ร่วมกับ flow หน้าแสดงรายละเอียด), แก้ข้อมูลผ่าน
 * TicketRepository และส่ง notification. มีเทสต์ครอบคลุมใน tests/cases/workflow_test.php.
 */
class TicketWorkflowService
{
    public function __construct(
        private TicketRepository $tickets,
        private TicketReadRepository $reads,
        private NotificationService $notifications,
        private TicketPolicy $policy,
    ) {
    }

    /**
     * manager/admin อนุมัติคำขอที่รออนุมัติ (pending_approval → approved).
     * แยกหน้าที่ (separation of duties): manager อนุมัติคำขอที่ตัวเองแจ้งไม่ได้ (admin ยกเว้น).
     * ผลข้างเคียง: เปลี่ยนสถานะ + เขียน approval/activity log ใน transaction แล้วยิง in-app notification 'ticket.approved'.
     * @param array<string, mixed> $viewer ผู้อนุมัติ (role manager/admin — บังคับผ่าน TicketPolicy)
     * @param array<string, mixed> $input รับคีย์ 'note' (หมายเหตุอนุมัติ, ไม่บังคับ)
     * @param array<string, mixed>|null $ticket แถว ticket ที่โหลด visibility มาแล้ว (bulkApproveTickets ส่งมา); null = ให้เมธอดโหลด + เช็คสิทธิ์เอง
     * @throws DomainException เมื่อไม่มีสิทธิ์ / ไม่อยู่สถานะ pending_approval / อนุมัติคำขอที่ตัวเองแจ้ง
     */
    public function approveTicket(int $ticketId, array $viewer, array $input, ?array $ticket = null): void
    {
        // $ticket อาจถูก bulkApproveTickets โหลดมาล่วงหน้า (ดึงสิทธิ์การมองเห็นทีเดียวเป็น batch ไม่ต้องอ่าน
        // ทีละ ticket); ถ้าโหลดมาแล้วก็ยังต้องบังคับ policy manage-workflow ตัวเดียวกับที่ requireManageableTicket ทำ.
        // ส่วนการตรวจสถานะที่ถือเป็นตัวจริงเกิดใต้ row lock ใน TicketRepository::approveTicket.
        if ($ticket === null) {
            $ticket = $this->requireManageableTicket($ticketId, $viewer);
        } elseif (!$this->policy->canManageWorkflow($ticket, $viewer)) {
            throw new DomainException('คุณไม่มีสิทธิ์จัดการ workflow ของรายการนี้');
        }

        if (!$this->policy->canReviewTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ไม่อยู่ในสถานะที่อนุมัติได้');
        }

        // แยกหน้าที่กันตรวจสอบ (separation of duties): manager ห้ามอนุมัติคำขอที่ตัวเองเป็นผู้แจ้ง ต้องให้ผู้อนุมัติคนอื่น.
        // admin ยกเว้นให้ (เป็น fallback ผู้อนุมัติ กัน deadlock ในองค์กรที่มี manager คนเดียว).
        if ((string) ($viewer['role'] ?? '') === 'manager'
            && (int) ($ticket['requester_id'] ?? 0) === (int) ($viewer['id'] ?? 0)) {
            throw new DomainException('ไม่สามารถอนุมัติคำขอที่คุณเป็นผู้แจ้งเองได้ กรุณาให้ผู้จัดการหรือผู้ดูแลระบบท่านอื่นอนุมัติ');
        }

        $note = trim((string) ($input['note'] ?? ''));
        $this->tickets->approveTicket($ticketId, (int) ($viewer['id'] ?? 0), $note, (string) ($ticket['status'] ?? 'pending_approval'));
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.approved', (int) ($viewer['id'] ?? 0));
    }

    /**
     * อนุมัติหลายรายการในคำขอเดียว (สูงสุด 50) — รายตัวที่ล้มไม่ทำให้ตัวอื่นล้ม เก็บเหตุผลไว้รายงานผล.
     * ผลข้างเคียง: อนุมัติแต่ละใบผ่าน approveTicket (เขียน DB + ยิง notification 'ticket.approved' ต่อใบที่สำเร็จ).
     * @param array<int, int|string> $ticketIds id ที่จะอนุมัติ (เมธอดคัดเฉพาะ >0 + ตัดซ้ำให้เอง)
     * @param array<string, mixed>   $viewer ผู้อนุมัติ (role manager/admin)
     * @param string                 $note หมายเหตุอนุมัติ ใช้กับทุกใบ
     * @return array{approved: int, failed: list<array{ticket_id: int, reason: string}>} จำนวนที่สำเร็จ + รายการที่ล้มพร้อมเหตุผล
     * @throws DomainException เมื่อไม่ได้เลือกรายการเลย หรือเลือกเกิน 50 รายการ
     */
    public function bulkApproveTickets(array $ticketIds, array $viewer, string $note = ''): array
    {
        $ticketIds = array_values(array_unique(array_filter(array_map('intval', $ticketIds), static fn (int $id): bool => $id > 0)));
        if ($ticketIds === []) {
            throw new DomainException('กรุณาเลือกอย่างน้อย 1 รายการ');
        }
        // เพดานต่อครั้ง: แต่ละรายการเปิด transaction + row lock + ส่งแจ้งเตือนของตัวเอง — จำกัดไว้ไม่ให้
        // คำขอเดียวถือ lock ยาว/ยิงแจ้งเตือนไม่อั้นจนกระทบผู้ใช้คนอื่น
        if (count($ticketIds) > 50) {
            throw new DomainException('Approve ได้สูงสุด 50 รายการต่อครั้ง');
        }

        // ดึงข้อมูลทีเดียวเป็น batch ตามสิทธิ์การมองเห็นของทั้งชุดที่เลือก ไม่ต้องอ่านทีละ ticket ใน
        // approveTicket แต่ละครั้ง. การ approve แต่ละครั้งยังคง lock + ตรวจสถานะซ้ำอย่างเป็นตัวจริงอยู่.
        $byId = [];
        foreach ($this->reads->findVisibleTicketsByIds($ticketIds, $viewer) as $row) {
            $byId[(int) ($row['id'] ?? 0)] = $row;
        }

        $approved = 0;
        $failed = [];

        // อนุมัติแบบรายตัว: รายการที่ติดปัญหา (สถานะถูกเปลี่ยนไปแล้ว/หมดสิทธิ์) ถูกเก็บลง failed ไปรายงานผล
        // โดยไม่ทำให้รายการอื่นในชุดล้มไปด้วย
        foreach ($ticketIds as $ticketId) {
            try {
                $ticket = $byId[$ticketId] ?? null;
                if ($ticket === null) {
                    throw new DomainException('ไม่พบรายการแจ้งซ่อมที่ต้องการดำเนินการ');
                }
                $this->approveTicket($ticketId, $viewer, ['note' => $note], $ticket);
                $approved++;
            } catch (DomainException $exception) {
                $failed[] = ['ticket_id' => $ticketId, 'reason' => $exception->getMessage()];
            }
        }

        return [
            'approved' => $approved,
            'failed' => $failed,
        ];
    }

    /**
     * manager/admin ปฏิเสธ ticket ที่รออนุมัติ (pending_approval → rejected).
     * ผลข้างเคียง: เปลี่ยนสถานะ + เขียน approval/activity log ใน transaction แล้วยิง notification 'ticket.rejected'.
     * @param array<string, mixed> $input ต้องมี 'note' (เหตุผลการปฏิเสธ, ห้ามว่าง)
     * @throws DomainException เมื่อไม่มีสิทธิ์ / ไม่อยู่สถานะ pending_approval / ไม่ได้ระบุเหตุผล
     */
    public function rejectTicket(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireManageableTicket($ticketId, $viewer);

        if (!$this->policy->canReviewTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ไม่อยู่ในสถานะที่ปฏิเสธได้');
        }

        $note = trim((string) ($input['note'] ?? ''));
        if ($note === '') {
            throw new DomainException('กรุณาระบุเหตุผลในการปฏิเสธรายการนี้');
        }

        $this->tickets->rejectTicket($ticketId, (int) ($viewer['id'] ?? 0), $note, (string) ($ticket['status'] ?? 'pending_approval'));
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.rejected', (int) ($viewer['id'] ?? 0));
    }

    /**
     * manager/admin มอบหมายช่างที่ยัง active ให้ ticket ที่อนุมัติแล้ว (approved/assigned/accepted/in_progress → assigned).
     * มอบหมายใหม่กลางงาน (reassign ตอนช่าง accepted/in_progress) ได้ แต่ต้องระบุเหตุผล.
     * ผลข้างเคียง: อัปเดตช่าง + สร้าง/อัปเดต work order + reset response-SLA ถ้าเป็น reassign ใน transaction แล้วยิง notification 'ticket.assigned'.
     * @param array<string, mixed> $input ต้องมี 'technician_id' (>0); 'instructions' บังคับเมื่อ reassign งานที่ช่างรับ/เริ่มไปแล้ว
     * @throws DomainException เมื่อไม่มีสิทธิ์ / ยังไม่พร้อมมอบหมาย / ไม่เลือกช่าง / ช่างไม่ active / reassign โดยไม่ระบุเหตุผล
     */
    public function assignTechnician(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireManageableTicket($ticketId, $viewer);

        if (!$this->policy->canAssignTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการมอบหมายช่าง');
        }

        $technicianId = strict_int($input['technician_id'] ?? null, 'ช่างเทคนิค'); // ปฏิเสธค่าอย่าง "3junk"
        if ($technicianId <= 0) {
            throw new DomainException('กรุณาเลือกช่างเทคนิคที่ต้องการมอบหมาย');
        }

        $technician = $this->findReferenceById($this->reads->getActiveTechnicians(), $technicianId);
        if ($technician === null) {
            throw new DomainException('ไม่พบช่างเทคนิคที่เลือก หรือช่างไม่พร้อมใช้งาน');
        }

        $instructions = trim((string) ($input['instructions'] ?? ''));

        // การย้ายงานกลางคัน (ช่างรับหรือเริ่มงานไปแล้ว) เป็นเรื่องผิดปกติ ต้องระบุเหตุผล
        // activity log จะได้บันทึกว่าทำไมงานถึงถูกย้าย.
        if (in_array((string) ($ticket['status'] ?? ''), ['accepted', 'in_progress'], true) && $instructions === '') {
            throw new DomainException('กรุณาระบุเหตุผลในการย้ายงานที่ช่างรับไปแล้ว');
        }

        $this->tickets->assignTechnician(
            $ticketId,
            (int) ($viewer['id'] ?? 0),
            $technicianId,
            (string) ($technician['full_name'] ?? 'Technician'),
            $instructions,
            (string) ($ticket['status'] ?? 'approved')
        );
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.assigned', (int) ($viewer['id'] ?? 0));
    }

    /**
     * ช่างที่ถูกมอบหมายกดรับ ticket ของตัวเอง (assigned → accepted).
     * ผลข้างเคียง: เปลี่ยนสถานะ + บันทึก first_response_at/ปิด SLA ตอบสนอง + อัปเดต work order ใน transaction แล้วยิง notification 'ticket.accepted'.
     * @param array<string, mixed> $input รับ 'accept_note' (ไม่บังคับ)
     * @throws DomainException เมื่อไม่ใช่ช่างเจ้าของงาน / ไม่อยู่สถานะ assigned
     */
    public function acceptAssignedWork(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireTechnicianTicket($ticketId, $viewer);

        if (!$this->policy->canAcceptTechnicianWork($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการรับงาน');
        }

        $note = trim((string) ($input['accept_note'] ?? ''));
        $this->tickets->acceptAssignedWork($ticketId, (int) ($viewer['id'] ?? 0), $note, (string) ($ticket['status'] ?? 'assigned'));
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.accepted', (int) ($viewer['id'] ?? 0));
    }

    /**
     * ช่างที่ถูกมอบหมายเริ่มงาน (assigned/accepted → in_progress — เริ่มได้เลยแม้ยังไม่กดรับ).
     * ผลข้างเคียง: เปลี่ยนสถานะ + backfill first_response_at/started_at + อัปเดต work order ใน transaction แล้วยิง notification 'ticket.started'.
     * @param array<string, mixed> $input รับ 'start_note' (ไม่บังคับ)
     * @throws DomainException เมื่อไม่ใช่ช่างเจ้าของงาน / ไม่อยู่สถานะ assigned|accepted
     */
    public function startAssignedWork(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireTechnicianTicket($ticketId, $viewer);

        if (!$this->policy->canStartTechnicianWork($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการเริ่มงาน');
        }

        $note = trim((string) ($input['start_note'] ?? ''));
        $this->tickets->startAssignedWork($ticketId, (int) ($viewer['id'] ?? 0), $note, (string) ($ticket['status'] ?? 'assigned'));
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.started', (int) ($viewer['id'] ?? 0));
    }

    /**
     * ช่างที่ถูกมอบหมายส่งผลวิเคราะห์ (diagnosis) + วิธีแก้ไข (resolution) (accepted/in_progress → resolved).
     * ผลข้างเคียง: เปลี่ยนสถานะ + เขียน resolution + สะสม labor_minutes ทับเข้า work order + ปิด SLA resolution ใน transaction แล้วยิง notification 'ticket.resolved'.
     * @param array<string, mixed> $input ต้องมี 'diagnosis_summary' + 'resolution_summary' (ห้ามว่างทั้งคู่); 'labor_minutes' ต้อง >= 0
     * @throws DomainException เมื่อไม่ใช่ช่างเจ้าของงาน / ไม่อยู่สถานะ accepted|in_progress / สรุปไม่ครบ / labor ติดลบ
     */
    public function resolveAssignedWork(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireTechnicianTicket($ticketId, $viewer);

        if (!$this->policy->canResolveTechnicianWork($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการสรุปผลการซ่อม');
        }

        $diagnosisSummary = trim((string) ($input['diagnosis_summary'] ?? ''));
        $resolutionSummary = trim((string) ($input['resolution_summary'] ?? ''));
        $laborMinutes = strict_int($input['labor_minutes'] ?? null, 'จำนวนเวลาที่ใช้'); // ปฏิเสธค่าอย่าง "12junk"

        if ($diagnosisSummary === '' || $resolutionSummary === '') {
            throw new DomainException('กรุณากรอกผลการวิเคราะห์และวิธีแก้ไขให้ครบถ้วน');
        }

        if ($laborMinutes < 0) {
            throw new DomainException('จำนวนเวลาที่ใช้ต้องเป็นตัวเลขศูนย์หรือมากกว่า');
        }

        $this->tickets->resolveAssignedWork(
            $ticketId,
            (int) ($viewer['id'] ?? 0),
            $diagnosisSummary,
            $resolutionSummary,
            $laborMinutes,
            (string) ($ticket['status'] ?? 'in_progress')
        );
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.resolved', (int) ($viewer['id'] ?? 0));
    }

    /**
     * ผู้แจ้งยืนยันปิดงานที่ซ่อมเสร็จ + ให้คะแนนความพึงพอใจ 1–5 (resolved → completed).
     * ผลข้างเคียง: เปลี่ยนสถานะ + บันทึกคะแนน (append rating ของรอบปัจจุบัน) ใน transaction แล้วยิง notification 'ticket.completed'.
     * @param array<string, mixed> $input ต้องมี 'score' (1–5); 'closure_note'/'feedback' ไม่บังคับ
     * @throws DomainException เมื่อไม่ใช่ผู้แจ้ง / ไม่อยู่สถานะ resolved / คะแนนไม่อยู่ช่วง 1–5
     */
    public function completeResolvedTicket(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireRequesterTicket($ticketId, $viewer);

        if (!$this->policy->canRequesterCompleteTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการยืนยันปิดงาน');
        }

        $closureNote = trim((string) ($input['closure_note'] ?? ''));
        $score = strict_int($input['score'] ?? null, 'คะแนนความพึงพอใจ'); // ปฏิเสธค่าอย่าง "5junk"
        $feedback = trim((string) ($input['feedback'] ?? ''));

        if ($score < 1 || $score > 5) {
            throw new DomainException('กรุณาให้คะแนนความพึงพอใจตั้งแต่ 1 ถึง 5');
        }

        $this->tickets->completeResolvedTicket(
            $ticketId,
            (int) ($viewer['id'] ?? 0),
            isset($ticket['assigned_technician_id']) ? (int) $ticket['assigned_technician_id'] : null,
            $closureNote,
            $score,
            $feedback,
            (string) ($ticket['status'] ?? 'resolved')
        );
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.completed', (int) ($viewer['id'] ?? 0));
    }

    /**
     * ผู้แจ้งส่ง ticket ที่ซ่อมเสร็จกลับไปแก้ใหม่ (resolved → assigned) + คำนวณกำหนด SLA รอบใหม่. ต้องระบุเหตุผล.
     * ผลข้างเคียง: เปลี่ยนสถานะ + เคลียร์ resolved/first_response + append SLA cycle ใหม่ (คง labor/rating รอบเก่าไว้) ใน transaction แล้วยิง notification 'ticket.reopened'.
     * @param array<string, mixed> $input ต้องมี 'reopen_note' (เหตุผล, ห้ามว่าง)
     * @throws DomainException เมื่อไม่ใช่ผู้แจ้ง / ไม่อยู่สถานะ resolved / ไม่ได้ระบุเหตุผล
     */
    public function reopenTicket(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireRequesterTicket($ticketId, $viewer);
        if (!$this->policy->canRequesterReopenTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการส่งกลับไปแก้งานซ้ำ');
        }

        $note = trim((string) ($input['reopen_note'] ?? ''));
        if ($note === '') {
            throw new DomainException('กรุณาระบุเหตุผลที่ต้องการให้ดำเนินการซ้ำ');
        }

        $reopenedAt = date('Y-m-d H:i:s');
        $responseDueAt = $this->calculateReopenDueAt($ticket, 'response_due_at', $reopenedAt);
        $resolutionDueAt = $this->calculateReopenDueAt($ticket, 'resolution_due_at', $reopenedAt);

        $this->tickets->reopenTicket(
            $ticketId,
            (int) ($viewer['id'] ?? 0),
            $note,
            (string) ($ticket['status'] ?? ''),
            $responseDueAt,
            $resolutionDueAt
        );

        $this->notifications->notifyTicketEvent($ticketId, 'ticket.reopened', (int) ($viewer['id'] ?? 0));
    }

    /**
     * ผู้แจ้งยกเลิก ticket ของตัวเอง (pending_approval|approved → cancelled). ต้องระบุเหตุผล.
     * ผลข้างเคียง: เปลี่ยนสถานะ + ลบคำขออนุมัติที่ค้าง (ถ้ายกเลิกตอนรออนุมัติ) ใน transaction แล้วยิง notification 'ticket.cancelled'.
     * @param array<string, mixed> $input ต้องมี 'cancel_note' (เหตุผล, ห้ามว่าง)
     * @throws DomainException เมื่อไม่ใช่ผู้แจ้ง / ไม่อยู่สถานะที่ยกเลิกได้ / ไม่ได้ระบุเหตุผล
     */
    public function cancelTicket(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireRequesterTicket($ticketId, $viewer);
        if (!$this->policy->canRequesterCancelTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ไม่อยู่ในสถานะที่ยกเลิกได้');
        }

        $note = trim((string) ($input['cancel_note'] ?? ''));
        if ($note === '') {
            throw new DomainException('กรุณาระบุเหตุผลในการยกเลิก Ticket');
        }

        $this->tickets->cancelTicket(
            $ticketId,
            (int) ($viewer['id'] ?? 0),
            $note,
            (string) ($ticket['status'] ?? '')
        );
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.cancelled', (int) ($viewer['id'] ?? 0));
    }

    // ── guard (ด่านตรวจ: ค้นข้อมูลจาก DB + ตรวจ TicketPolicy) ──

    private function requireManageableTicket(int $ticketId, array $viewer): array
    {
        $ticket = $this->reads->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null) {
            throw new DomainException('ไม่พบรายการแจ้งซ่อมที่ต้องการดำเนินการ');
        }

        if (!$this->policy->canManageWorkflow($ticket, $viewer)) {
            throw new DomainException('คุณไม่มีสิทธิ์จัดการ workflow ของรายการนี้');
        }

        return $ticket;
    }

    private function requireTechnicianTicket(int $ticketId, array $viewer): array
    {
        $ticket = $this->reads->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null) {
            throw new DomainException('ไม่พบรายการแจ้งซ่อมที่ต้องการดำเนินการ');
        }

        if (!$this->policy->canTechnicianWork($ticket, $viewer)) {
            throw new DomainException('คุณไม่มีสิทธิ์จัดการงานช่างของรายการนี้');
        }

        return $ticket;
    }

    private function requireRequesterTicket(int $ticketId, array $viewer): array
    {
        $ticket = $this->reads->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null) {
            throw new DomainException('ไม่พบรายการแจ้งซ่อมที่ต้องการดำเนินการ');
        }

        if (!$this->policy->canRequesterManageClosure($ticket, $viewer)) {
            throw new DomainException('คุณไม่มีสิทธิ์ยืนยันผลการซ่อมของรายการนี้');
        }

        return $ticket;
    }

    /**
     * กำหนดเวลา SLA ของรอบใหม่หลัง reopen = คงระยะเวลาที่เคยให้ไว้เดิม (นาทีจากวันแจ้งถึงกำหนดเดิม)
     * แล้วเริ่มนับใหม่จากเวลาที่ reopen — งานที่ส่งกลับมาแก้จะได้เวลาเท่ารอบแรก ไม่ใช่กำหนดเดิมที่ผ่านไปแล้ว.
     * ถ้าข้อมูลเดิมเพี้ยน (อ่านวันที่ไม่ได้ หรือกำหนดอยู่ก่อนวันแจ้ง) จะใช้เวลา reopen เป็นกำหนดทันทีไว้ก่อน
     * ดีกว่าเดาระยะเวลาใหม่.
     */
    private function calculateReopenDueAt(array $ticket, string $dueField, string $reopenedAt): string
    {
        $requestedAt = strtotime((string) ($ticket['requested_at'] ?? ''));
        $currentDueAt = strtotime((string) ($ticket[$dueField] ?? ''));
        $reopenedTimestamp = strtotime($reopenedAt) ?: time();

        if ($requestedAt === false || $currentDueAt === false || $currentDueAt < $requestedAt) {
            return date('Y-m-d H:i:s', $reopenedTimestamp);
        }

        $minutes = max(0, (int) ceil(($currentDueAt - $requestedAt) / 60));

        return date('Y-m-d H:i:s', strtotime('+' . $minutes . ' minutes', $reopenedTimestamp) ?: $reopenedTimestamp);
    }

    private function findReferenceById(array $items, int $id): ?array
    {
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return $item;
            }
        }

        return null;
    }
}
