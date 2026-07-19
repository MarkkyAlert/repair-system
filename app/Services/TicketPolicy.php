<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Role;

/**
 * predicate (ฟังก์ชันที่คืน true/false) ล้วน ๆ สำหรับสิทธิ์และการเปลี่ยนสถานะของ ticket (รับ array ของ ticket + viewer แล้วคืน bool) ใช้ร่วมกันทั้ง
 * flow แสดงรายละเอียด (TicketService) และ flow แก้ไขข้อมูล (TicketWorkflowService). ไม่มี DB ไม่มี state
 * — เป็น single source ของ "ใครทำอะไรได้จากสถานะไหน" กันไม่ให้สอง flow เพี้ยนไปคนละทาง.
 */
class TicketPolicy
{
    public function canManageWorkflow(array $ticket, array $viewer): bool
    {
        $role = (string) ($viewer['role'] ?? Role::GUEST);
        $viewerId = (int) ($viewer['id'] ?? 0);
        $managerId = (int) ($ticket['assigned_manager_id'] ?? 0);

        if ($role === Role::ADMIN) {
            return true;
        }

        // งานที่ยังไม่มี manager เจ้าของ (id=0) เปิดให้ manager ทุกคนหยิบจัดการได้; งานที่มีเจ้าของแล้ว
        // จำกัดเฉพาะ manager คนนั้น — manager คนอื่นเข้ามาจัดการงานข้ามมือกันไม่ได้ (admin ผ่านเงื่อนไขข้างบนเสมอ)
        return $role === Role::MANAGER && $viewerId > 0 && ($managerId === 0 || $managerId === $viewerId);
    }

    public function canReviewTicket(array $ticket, array $viewer): bool
    {
        return $this->canManageWorkflow($ticket, $viewer)
            && (string) ($ticket['approval_status'] ?? '') === 'pending'
            && (string) ($ticket['status'] ?? '') === 'pending_approval';
    }

    public function canAssignTicket(array $ticket, array $viewer): bool
    {
        if (!$this->canManageWorkflow($ticket, $viewer)) {
            return false;
        }

        // รวม accepted/in_progress ไว้ด้วย manager/admin จะได้มอบหมายช่างใหม่ได้ถ้าช่างเดิม
        // ไม่ว่าง (ลาป่วย / ลาออก) — ไม่งั้น ticket จะค้างตลอดกาล เพราะมีแต่ช่างที่ถูกมอบหมาย
        // เท่านั้นที่ปิดงานได้ และ requester ก็ยกเลิกไม่ได้แล้ว
        // (ยืนยันโดยฝั่งธุรกิจ). การมอบหมายใหม่ระหว่างทำงานต้องมีเหตุผล (บังคับใน TicketWorkflowService).
        return (string) ($ticket['approval_status'] ?? '') === 'approved'
            && in_array((string) ($ticket['status'] ?? ''), ['approved', 'assigned', 'accepted', 'in_progress'], true);
    }

    public function canTechnicianWork(array $ticket, array $viewer): bool
    {
        return (string) ($viewer['role'] ?? Role::GUEST) === Role::TECHNICIAN
            && (int) ($viewer['id'] ?? 0) > 0
            && (int) ($ticket['assigned_technician_id'] ?? 0) === (int) ($viewer['id'] ?? 0);
    }

    public function canAcceptTechnicianWork(array $ticket, array $viewer): bool
    {
        return $this->canTechnicianWork($ticket, $viewer)
            && (string) ($ticket['approval_status'] ?? '') === 'approved'
            && (string) ($ticket['status'] ?? '') === 'assigned';
    }

    public function canStartTechnicianWork(array $ticket, array $viewer): bool
    {
        return $this->canTechnicianWork($ticket, $viewer)
            && (string) ($ticket['approval_status'] ?? '') === 'approved'
            && in_array((string) ($ticket['status'] ?? ''), ['assigned', 'accepted'], true);
    }

    public function canResolveTechnicianWork(array $ticket, array $viewer): bool
    {
        return $this->canTechnicianWork($ticket, $viewer)
            && (string) ($ticket['approval_status'] ?? '') === 'approved'
            && in_array((string) ($ticket['status'] ?? ''), ['accepted', 'in_progress'], true);
    }

    public function canRequesterManageClosure(array $ticket, array $viewer): bool
    {
        return (int) ($viewer['id'] ?? 0) > 0
            && (int) ($ticket['requester_id'] ?? 0) === (int) ($viewer['id'] ?? 0);
    }

    public function canRequesterCompleteTicket(array $ticket, array $viewer): bool
    {
        return $this->canRequesterManageClosure($ticket, $viewer)
            && (string) ($ticket['approval_status'] ?? '') === 'approved'
            && (string) ($ticket['status'] ?? '') === 'resolved';
    }

    public function canRequesterReopenTicket(array $ticket, array $viewer): bool
    {
        // มีแต่ ticket สถานะ resolved (รอการยืนยัน) เท่านั้นที่ส่งกลับไปทำใหม่ได้. ticket ที่ completed แล้วถือเป็น
        // สถานะสุดท้าย — requester ที่ไม่พอใจให้เปิด ticket ใหม่ผ่าน duplicate (canDuplicateTicket) ไม่ใช่ reopen.
        return $this->canRequesterManageClosure($ticket, $viewer)
            && (string) ($ticket['approval_status'] ?? '') === 'approved'
            && (string) ($ticket['status'] ?? '') === 'resolved';
    }

    public function canRequesterCancelTicket(array $ticket, array $viewer): bool
    {
        return $this->canRequesterManageClosure($ticket, $viewer)
            && in_array((string) ($ticket['status'] ?? ''), ['pending_approval', 'approved'], true);
    }

    public function canDuplicateTicket(array $ticket, array $viewer): bool
    {
        return $this->canRequesterManageClosure($ticket, $viewer)
            && in_array((string) ($ticket['status'] ?? ''), ['completed', 'rejected', 'cancelled', 'closed'], true);
    }
}
