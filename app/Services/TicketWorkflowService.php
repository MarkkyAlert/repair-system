<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\TicketReadRepository;
use App\Repositories\TicketRepository;
use DomainException;

/**
 * Ticket lifecycle mutations split out of TicketService: approve/reject/assign (manager),
 * accept/start/resolve (technician), complete/reopen/cancel (requester), + bulk approve.
 * Each guards via TicketPolicy (shared with the detail-display flow), mutates through
 * TicketRepository, and emits a notification. Covered by tests/cases/workflow_test.php.
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

    public function approveTicket(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireManageableTicket($ticketId, $viewer);

        if (!$this->policy->canReviewTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ไม่อยู่ในสถานะที่อนุมัติได้');
        }

        // Separation of duties: manager ห้ามอนุมัติคำขอที่ตัวเองเป็นผู้แจ้ง — ต้องให้ผู้อนุมัติท่านอื่น.
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
        if (count($ticketIds) > 50) {
            throw new DomainException('Approve ได้สูงสุด 50 รายการต่อครั้ง');
        }

        $approved = 0;
        $failed = [];

        foreach ($ticketIds as $ticketId) {
            try {
                $this->approveTicket($ticketId, $viewer, ['note' => $note]);
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

    /** Manager/admin rejects a pending-approval ticket (→ rejected). Rejection note required. */
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
     * Manager/admin assigns an active technician to an approved ticket (→ assigned).
     * Requires a valid technician_id; notifies ticket.assigned.
     */
    public function assignTechnician(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireManageableTicket($ticketId, $viewer);

        if (!$this->policy->canAssignTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการมอบหมายช่าง');
        }

        $technicianId = strict_int($input['technician_id'] ?? null, 'ช่างเทคนิค'); // round F1: reject "3junk"
        if ($technicianId <= 0) {
            throw new DomainException('กรุณาเลือกช่างเทคนิคที่ต้องการมอบหมาย');
        }

        $technician = $this->findReferenceById($this->reads->getActiveTechnicians(), $technicianId);
        if ($technician === null) {
            throw new DomainException('ไม่พบช่างเทคนิคที่เลือก หรือช่างไม่พร้อมใช้งาน');
        }

        $instructions = trim((string) ($input['instructions'] ?? ''));

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

    /** Assigned technician accepts their ticket (assigned → accepted). */
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

    /** Assigned technician starts work (accepted → in_progress). */
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
     * Assigned technician submits diagnosis + resolution (in_progress → resolved).
     * Both summaries required; labor_minutes must be >= 0.
     */
    public function resolveAssignedWork(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireTechnicianTicket($ticketId, $viewer);

        if (!$this->policy->canResolveTechnicianWork($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการสรุปผลการซ่อม');
        }

        $diagnosisSummary = trim((string) ($input['diagnosis_summary'] ?? ''));
        $resolutionSummary = trim((string) ($input['resolution_summary'] ?? ''));
        $laborMinutes = strict_int($input['labor_minutes'] ?? null, 'จำนวนเวลาที่ใช้'); // round F1: reject "12junk"

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

    /** Requester confirms a resolved ticket and rates satisfaction 1–5 (resolved → completed). */
    public function completeResolvedTicket(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireRequesterTicket($ticketId, $viewer);

        if (!$this->policy->canRequesterCompleteTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการยืนยันปิดงาน');
        }

        $closureNote = trim((string) ($input['closure_note'] ?? ''));
        $score = strict_int($input['score'] ?? null, 'คะแนนความพึงพอใจ'); // round F1: reject "5junk"
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

    /** Requester sends a resolved ticket back for rework; recomputes SLA due dates. Reason required. */
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

    /** Requester cancels their own ticket (→ cancelled). Reason required. */
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

    // ── guards (DB lookup + TicketPolicy check) ──

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
