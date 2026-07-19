<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\TicketReadRepository;
use App\Repositories\TicketRepository;
use DomainException;

/**
 * การเปลี่ยนสถานะตลอดวงจรชีวิต (lifecycle) ของ ticket ที่แยกออกมาจาก TicketService: approve/reject/assign (manager),
 * accept/start/resolve (technician), complete/reopen/cancel (requester), + bulk approve (อนุมัติทีละหลายรายการ).
 * แต่ละอันมีด่านตรวจสิทธิ์ผ่าน TicketPolicy (ใช้ร่วมกับ flow หน้าแสดงรายละเอียด), เปลี่ยนข้อมูลผ่าน
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

    public function approveTicket(int $ticketId, array $viewer, array $input, ?array $ticket = null): void
    {
        // $ticket อาจถูกโหลดมาล่วงหน้าโดย bulkApproveTickets (ดึงสิทธิ์การมองเห็นทีเดียวเป็น batch แทนที่จะอ่าน
        // ทีละ ticket); ถ้าถูกโหลดมาแล้วก็ยังต้องบังคับ policy manage-workflow เดียวกับที่ requireManageableTicket ทำ.
        // การตรวจสถานะที่ถือเป็นตัวจริงเกิดขึ้นใต้ row lock ใน TicketRepository::approveTicket.
        if ($ticket === null) {
            $ticket = $this->requireManageableTicket($ticketId, $viewer);
        } elseif (!$this->policy->canManageWorkflow($ticket, $viewer)) {
            throw new DomainException('คุณไม่มีสิทธิ์จัดการ workflow ของรายการนี้');
        }

        if (!$this->policy->canReviewTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ไม่อยู่ในสถานะที่อนุมัติได้');
        }

        // แยกหน้าที่ความรับผิดชอบ (separation of duties): manager ห้ามอนุมัติคำขอที่ตัวเองเป็นผู้แจ้ง — ต้องให้ผู้อนุมัติท่านอื่น.
        // admin ยกเว้น (เป็น fallback ผู้อนุมัติ กัน deadlock องค์กรที่มี manager คนเดียว).
        if ((string) ($viewer['role'] ?? '') === 'manager'
            && (int) ($ticket['requester_id'] ?? 0) === (int) ($viewer['id'] ?? 0)) {
            throw new DomainException('ไม่สามารถอนุมัติคำขอที่คุณเป็นผู้แจ้งเองได้ กรุณาให้ผู้จัดการหรือผู้ดูแลระบบท่านอื่นอนุมัติ');
        }

        $note = trim((string) ($input['note'] ?? ''));
        $this->tickets->approveTicket($ticketId, (int) ($viewer['id'] ?? 0), $note, (string) ($ticket['status'] ?? 'pending_approval'));
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.approved', (int) ($viewer['id'] ?? 0));
    }

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

        // ดึงข้อมูลทีเดียวเป็น batch ตามขอบเขตสิทธิ์การมองเห็นสำหรับทั้งชุดที่เลือก แทนที่จะอ่านทีละ ticket ภายใน
        // approveTicket แต่ละครั้ง. การ approve แต่ละครั้งยังคง lock + ตรวจสถานะซ้ำอย่างเป็นตัวจริง.
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

    /** manager/admin ปฏิเสธ ticket ที่รออนุมัติ (→ rejected). ต้องระบุหมายเหตุการปฏิเสธ. */
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
     * manager/admin มอบหมายช่างที่ยัง active ให้กับ ticket ที่อนุมัติแล้ว (→ assigned).
     * ต้องมี technician_id ที่ถูกต้อง; แจ้งเตือน ticket.assigned.
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

        // การย้ายงานกลางคัน (ช่างรับ/เริ่มงานไปแล้ว) เป็นการกระทำที่ผิดปกติ — ต้องระบุ
        // เหตุผล เพื่อให้ activity log บันทึกว่าทำไมงานถึงถูกย้าย.
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

    /** ช่างที่ถูกมอบหมายกดรับ ticket ของตัวเอง (assigned → accepted). */
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

    /** ช่างที่ถูกมอบหมายเริ่มงาน (accepted → in_progress). */
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
     * ช่างที่ถูกมอบหมายส่งผลวิเคราะห์ (diagnosis) + วิธีแก้ไข (resolution) (in_progress → resolved).
     * ต้องกรอกสรุปทั้งสองอย่าง; labor_minutes (นาทีที่ใช้ทำงาน) ต้อง >= 0.
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

    /** ผู้แจ้งยืนยัน ticket ที่ซ่อมเสร็จและให้คะแนนความพึงพอใจ 1–5 (resolved → completed). */
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

    /** ผู้แจ้งส่ง ticket ที่ซ่อมเสร็จกลับไปแก้ใหม่; คำนวณกำหนดเวลา SLA ใหม่. ต้องระบุเหตุผล. */
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

    /** ผู้แจ้งยกเลิก ticket ของตัวเอง (→ cancelled). ต้องระบุเหตุผล. */
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
     * แล้วเริ่มนับใหม่จากเวลาที่ reopen — งานที่ถูกส่งกลับมาแก้จึงได้เวลาเท่ารอบแรก ไม่ใช่กำหนดเดิมที่ผ่านไปแล้ว.
     * ถ้าข้อมูลเดิมเพี้ยน (อ่านวันที่ไม่ได้ / กำหนดอยู่ก่อนวันแจ้ง) จะใช้เวลา reopen เป็นกำหนดทันทีแบบระวังไว้ก่อน
     * แทนการเดาระยะเวลาใหม่.
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
