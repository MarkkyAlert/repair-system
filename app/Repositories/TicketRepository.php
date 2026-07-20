<?php
declare(strict_types=1);

namespace App\Repositories;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

class TicketRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * ทำเครื่องหมาย SLA track ว่าเลยกำหนด (pending → breached) เฉพาะแถวที่ยัง pending และ target_at เลยเวลาแล้ว.
     * ผลข้างเคียง: UPDATE ticket_sla_tracks หนึ่งแถว (ไม่ครอบ transaction เอง — เรียกจาก SLA cron; ผู้เรียกครอบ tx เองถ้าต้องการ).
     * @param string $breachedAt เวลาที่ถือว่า breach ใช้เป็นทั้งค่า breached_at และเกณฑ์ตัดสิน (target_at < เวลานี้)
     * @return bool true = มีแถวถูกเปลี่ยนจริง; false = ไม่มีแถวเข้าเงื่อนไข (เช่น เคย breach/met ไปแล้ว — idempotent)
     */
    public function markSlaBreachedById(int $slaTrackId, string $breachedAt): bool
    {
        // :overdue_before ผูกค่าเดียวกับ :breached_at แต่ต้องแยกเป็นคนละ placeholder —
        // native prepared statement (PDO::ATTR_EMULATE_PREPARES=false) ไม่ยอมให้ใช้ named parameter ซ้ำ (HY093)
        $stmt = $this->db->prepare(
            'UPDATE ticket_sla_tracks
             SET breached_at = COALESCE(breached_at, :breached_at),
                 status = :status
             WHERE id = :sla_track_id
               AND status = :pending_status
               AND target_at < :overdue_before'
        );
        $stmt->execute([
            'breached_at' => $breachedAt,
            'status' => 'breached',
            'sla_track_id' => $slaTrackId,
            'pending_status' => 'pending',
            'overdue_before' => $breachedAt,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * สร้าง ticket ใหม่พร้อมข้อมูลตั้งต้นทั้งชุด สถานะเริ่มต้น pending_approval / approval_status pending.
     * ผลข้างเคียง: เขียนใน transaction เดียว (เปิดเองเฉพาะเมื่อผู้เรียกยังไม่ได้ครอบ tx ไว้) — INSERT tickets +
     * ticket_approvals (คำขออนุมัติ pending) + ticket_sla_tracks 2 แถว (response/resolution) + ticket_activity_logs.
     * กันเลข ticket ซ้ำด้วย named lock (GET_LOCK) ต่อวัน และ idempotent ด้วย submission_token (กดซ้ำ/refresh คืนใบเดิม).
     * @param array<string, mixed> $payload ต้องมี 'title','description','requester_id','requester_department_id',
     *        'location_id','asset_id','ticket_category_id','priority_id','impact_level','urgency_level';
     *        ไม่บังคับ 'requested_at','response_due_at','resolution_due_at','submission_token','channel'
     * @return array{id: int, created: bool} created=false เมื่อชน submission_token เดิม (คืน ticket ที่มีอยู่ ไม่สร้างซ้ำ)
     * @throws RuntimeException เมื่อไม่พบผู้อนุมัติเริ่มต้น (ไม่มี user role manager/admin ที่ active)
     * @throws Throwable เมื่อ write ใด ๆ ล้มเหลว (rollback tx ที่เปิดเองก่อน rethrow); การชน submission_token ถูกจับแล้วคืนใบเดิมแทน
     */
    public function createTicket(array $payload): array
    {
        $requestedAt = (string) ($payload['requested_at'] ?? date('Y-m-d H:i:s'));
        $responseDueAt = (string) ($payload['response_due_at'] ?? '');
        $resolutionDueAt = (string) ($payload['resolution_due_at'] ?? '');
        $submissionToken = (string) ($payload['submission_token'] ?? '');
        // idempotency: token ที่เคยสร้าง ticket ไปแล้วให้คืนใบเดิม ไม่สร้างซ้ำ — กันกดส่งซ้ำหรือกด refresh หลัง submit
        // UNIQUE(submission_token) ใน DB เป็นด่านสุดท้ายกัน race ดู catch ด้านล่าง
        $existingTicketId = $this->findTicketIdBySubmissionToken($submissionToken);
        if ($existingTicketId !== null) {
            return ['id' => $existingTicketId, 'created' => false];
        }

        $approverId = $this->findDefaultApproverId();
        // named lock (GET_LOCK) ต่อวัน: เลข ticket มาจาก "อ่านเลขล่าสุดแล้ว +1" — ถ้าไม่ล็อกคร่อมไว้
        // สองคำขอที่สร้างพร้อมกันจะอ่านเลขเดียวกันแล้วได้เลข ticket ซ้ำกัน
        $numberLock = 'ticket-number-' . date('Ymd', strtotime($requestedAt) ?: time());
        // เปิด/ปิด transaction เองเฉพาะเมื่อยังไม่มีใครครอบอยู่ — ผู้เรียก (TicketService) อาจครอบ transaction
        // ใหญ่ของตัวเองมาแล้ว การ commit/rollback ตรงนี้ต้องไม่ไปตัดจบ transaction ของเขากลางคัน
        $startedTransaction = !$this->db->inTransaction();

        if ($approverId === null) {
            throw new RuntimeException('ไม่พบผู้อนุมัติเริ่มต้นในระบบ กรุณาตรวจสอบข้อมูลผู้ใช้งาน role manager หรือ admin');
        }

        try {
            $this->acquireNamedLock($numberLock);
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }
            $ticketNo = $this->generateNextTicketNumber($requestedAt);

            $ticketStmt = $this->db->prepare(
                'INSERT INTO tickets (
                    ticket_no,
                    submission_token,
                    title,
                    description,
                    requester_id,
                    requester_department_id,
                    location_id,
                    asset_id,
                    ticket_category_id,
                    priority_id,
                    assigned_manager_id,
                    assigned_technician_id,
                    approval_status,
                    status,
                    channel,
                    impact_level,
                    urgency_level,
                    requested_at,
                    response_due_at,
                    resolution_due_at,
                    created_at,
                    updated_at
                 ) VALUES (
                    :ticket_no,
                    :submission_token,
                    :title,
                    :description,
                    :requester_id,
                    :requester_department_id,
                    :location_id,
                    :asset_id,
                    :ticket_category_id,
                    :priority_id,
                    :assigned_manager_id,
                    :assigned_technician_id,
                    :approval_status,
                    :status,
                    :channel,
                    :impact_level,
                    :urgency_level,
                    :requested_at,
                    :response_due_at,
                    :resolution_due_at,
                    :created_at,
                    :updated_at
                 )'
            );

            try {
                $ticketStmt->execute([
                    'ticket_no' => $ticketNo,
                    'submission_token' => $submissionToken,
                    'title' => $payload['title'],
                    'description' => $payload['description'],
                    'requester_id' => $payload['requester_id'],
                    'requester_department_id' => $payload['requester_department_id'],
                    'location_id' => $payload['location_id'],
                    'asset_id' => $payload['asset_id'],
                    'ticket_category_id' => $payload['ticket_category_id'],
                    'priority_id' => $payload['priority_id'],
                    'assigned_manager_id' => null,
                    'assigned_technician_id' => null,
                    'approval_status' => 'pending',
                    'status' => 'pending_approval',
                    'channel' => $payload['channel'] ?? 'web',
                    'impact_level' => $payload['impact_level'],
                    'urgency_level' => $payload['urgency_level'],
                    'requested_at' => $requestedAt,
                    'response_due_at' => $responseDueAt,
                    'resolution_due_at' => $resolutionDueAt,
                    'created_at' => $requestedAt,
                    'updated_at' => $requestedAt,
                ]);
            } catch (Throwable $exception) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                // ชน UNIQUE ของ submission_token = แพ้ race ให้คำขอซ้ำที่ commit ก่อนหน้าเราพอดี —
                // มีใบอยู่ในระบบแล้ว จึงคืนใบนั้นแทนการโยน error ใส่ผู้ใช้ (ผลลัพธ์เดียวกับด่านตรวจต้นฟังก์ชัน)
                if ($this->isSubmissionTokenConflict($exception, $submissionToken)) {
                    $existingTicketId = $this->findTicketIdBySubmissionToken($submissionToken);
                    if ($existingTicketId !== null) {
                        return ['id' => $existingTicketId, 'created' => false];
                    }
                }

                throw $exception;
            }

            $ticketId = (int) $this->db->lastInsertId();

            $approvalStmt = $this->db->prepare(
                'INSERT INTO ticket_approvals (ticket_id, approver_id, action, note, acted_at, created_at)
                 VALUES (:ticket_id, :approver_id, :action, :note, :acted_at, :created_at)'
            );
            $approvalStmt->execute([
                'ticket_id' => $ticketId,
                'approver_id' => $approverId,
                'action' => 'pending',
                'note' => 'สร้างคำขออนุมัติอัตโนมัติจากหน้าแจ้งซ่อมใหม่',
                'acted_at' => null,
                'created_at' => $requestedAt,
            ]);

            $slaStmt = $this->db->prepare(
                'INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, achieved_at, breached_at, status, created_at)
                 VALUES (:ticket_id, :metric_type, :target_at, :achieved_at, :breached_at, :status, :created_at)'
            );
            $slaStmt->execute([
                'ticket_id' => $ticketId,
                'metric_type' => 'response',
                'target_at' => $responseDueAt,
                'achieved_at' => null,
                'breached_at' => null,
                'status' => 'pending',
                'created_at' => $requestedAt,
            ]);
            $slaStmt->execute([
                'ticket_id' => $ticketId,
                'metric_type' => 'resolution',
                'target_at' => $resolutionDueAt,
                'achieved_at' => null,
                'breached_at' => null,
                'status' => 'pending',
                'created_at' => $requestedAt,
            ]);

            $activityStmt = $this->db->prepare(
                'INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status, details, created_at)
                 VALUES (:ticket_id, :actor_id, :action, :from_status, :to_status, :details, :created_at)'
            );
            $activityStmt->execute([
                'ticket_id' => $ticketId,
                'actor_id' => $payload['requester_id'],
                'action' => 'ticket_submitted',
                'from_status' => null,
                'to_status' => 'pending_approval',
                'details' => 'ผู้ใช้งานสร้างรายการแจ้งซ่อมผ่านหน้าเว็บ',
                'created_at' => $requestedAt,
            ]);

            if ($startedTransaction) {
                $this->db->commit();
            }

            return ['id' => $ticketId, 'created' => true];
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        } finally {
            $this->releaseNamedLock($numberLock);
        }
    }

    /**
     * อนุมัติ ticket (pending_approval → approved, approval_status → approved).
     * ผลข้างเคียง: ทำใน transaction เดียว — lock แถว ticket ด้วย FOR UPDATE แล้ว re-check สถานะใต้ lock
     * (ต้องเป็น pending_approval + approval_status pending) จากนั้น UPDATE tickets + upsert ticket_approvals +
     * INSERT ticket_activity_logs.
     * @param string $currentStatus สถานะที่ caller เห็นก่อน lock ใช้เป็น from_status ในบันทึก activity log
     * @throws DomainException เมื่อสถานะถูกเปลี่ยนไปแล้ว (re-check ใต้ lock ไม่ผ่าน)
     * @throws Throwable เมื่อ write ใด ๆ ล้มเหลว (rollback tx ก่อน rethrow)
     */
    public function approveTicket(int $ticketId, int $actorId, string $note, string $currentStatus): void
    {
        $actedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $this->lockTicketForTransition($ticketId, ['pending_approval'], 'pending');

            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET approval_status = :approval_status,
                     status = :status,
                     approved_at = :approved_at,
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'approval_status' => 'approved',
                'status' => 'approved',
                'approved_at' => $actedAt,
                'updated_at' => $actedAt,
                'ticket_id' => $ticketId,
            ]);

            $this->upsertApprovalDecision($ticketId, $actorId, 'approved', $note, $actedAt);
            $this->insertActivityLog($ticketId, $actorId, 'ticket_approved', $currentStatus, 'approved', $note !== '' ? $note : 'หัวหน้างานอนุมัติรายการแจ้งซ่อม');

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * ปฏิเสธ ticket (pending_approval → rejected, approval_status → rejected, ล้าง approved_at).
     * ผลข้างเคียง: ทำใน transaction เดียว — lock แถว ticket (FOR UPDATE) + re-check สถานะใต้ lock
     * (pending_approval + approval_status pending) แล้ว UPDATE tickets + upsert ticket_approvals + INSERT ticket_activity_logs.
     * @param string $currentStatus สถานะที่ caller เห็นก่อน lock ใช้เป็น from_status ใน activity log
     * @throws DomainException เมื่อสถานะถูกเปลี่ยนไปแล้ว (re-check ใต้ lock ไม่ผ่าน)
     * @throws Throwable เมื่อ write ใด ๆ ล้มเหลว (rollback tx ก่อน rethrow)
     */
    public function rejectTicket(int $ticketId, int $actorId, string $note, string $currentStatus): void
    {
        $actedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $this->lockTicketForTransition($ticketId, ['pending_approval'], 'pending');

            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET approval_status = :approval_status,
                     status = :status,
                     approved_at = NULL,
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'approval_status' => 'rejected',
                'status' => 'rejected',
                'updated_at' => $actedAt,
                'ticket_id' => $ticketId,
            ]);

            $this->upsertApprovalDecision($ticketId, $actorId, 'rejected', $note, $actedAt);
            $this->insertActivityLog($ticketId, $actorId, 'ticket_rejected', $currentStatus, 'rejected', $note);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * มอบหมาย/ย้ายงานให้ช่าง (approved|assigned|accepted|in_progress → assigned) รองรับทั้ง assign ครั้งแรกและ reassign กลางงาน.
     * ผลข้างเคียง: ทำใน transaction เดียว — lock แถว ticket (FOR UPDATE) + lock แถว users ของช่าง (FOR UPDATE) แล้ว
     * re-check ใต้ lock (สถานะที่อนุญาต + approval_status approved, ช่างยัง role technician+active, ถ้า reassign กลางงานต้องมีเหตุผล)
     * จากนั้น UPDATE tickets + INSERT/UPDATE work_orders (สร้างใหม่ใช้ named lock กันเลข work order ซ้ำ) + INSERT ticket_activity_logs;
     * ถ้าเป็น reassign จะ reset แถว response SLA (ticket_sla_tracks) กลับเป็น pending.
     * @param string $currentStatus สถานะที่ caller เห็นก่อน lock (from_status ที่บันทึกจริงยึดจากสถานะใต้ lock เพื่อกัน race)
     * @throws DomainException เมื่อสถานะถูกเปลี่ยน, ช่างไม่พร้อมใช้งาน (ปิดบัญชี/เปลี่ยน role), หรือ reassign กลางงานโดยไม่ระบุเหตุผล
     * @throws Throwable เมื่อ write ใด ๆ ล้มเหลว (rollback tx ก่อน rethrow)
     */
    public function assignTechnician(int $ticketId, int $actorId, int $technicianId, string $technicianName, string $instructions, string $currentStatus): void
    {
        $assignedAt = date('Y-m-d H:i:s');
        $workOrderNumberLock = null;

        try {
            $this->db->beginTransaction();
            // accepted/in_progress: การ reassign กลางงานตอนช่างทำต่อไม่ได้
            // ให้ตัดสินจากสถานะที่ล็อกไว้ ไม่ใช่ค่าเก่าที่ caller อ่านมาก่อนล็อก — ถ้ามีการ accept/start
            // แทรกเข้ามาพร้อมกันช่วงที่ service อ่านค่ากับตอนล็อกนี้ reassign ต้องไม่ถูกมองเป็นการ assign ครั้งแรก
            // เพราะจะข้ามการ reset response-SLA แล้วบันทึก from_status ผิด
            $lockedStatus = $this->lockTicketForTransition($ticketId, ['approved', 'assigned', 'accepted', 'in_progress'], 'approved');

            $isReassign = in_array($lockedStatus, ['assigned', 'accepted', 'in_progress'], true);

            // ตรวจกฎ "ต้องมีเหตุผลตอน reassign กลางงาน" ซ้ำอีกรอบใต้ lock ให้เป็นตัวตัดสินจริง — service ตรวจกฎนี้
            // ไปแล้วก่อนล็อก แต่การ accept/start ที่เข้ามาพร้อมกันอาจเพิ่งย้าย ticket เข้าสถานะกลางงานหลัง
            // จากตรวจรอบนั้น จึงบังคับกฎซ้ำตรงนี้บนสถานะที่ล็อกไว้ กัน race แอบข้ามการใส่เหตุผล
            if (in_array($lockedStatus, ['accepted', 'in_progress'], true) && $instructions === '') {
                throw new DomainException('กรุณาระบุเหตุผลในการย้ายงานที่ช่างรับไปแล้ว');
            }

            // lock แถวของช่างที่เลือกแล้วตรวจ role+active ซ้ำภายใน transaction — service ตรวจ
            // ไปแล้วก่อนล็อก แต่ admin อาจ deactivate หรือเปลี่ยน role พร้อมกันได้ (ฝั่งนั้น lock แถว user
            // แล้วตรวจงานค้างซ้ำใต้ lock ตัวเอง) สองจังหวะนี้สลับกันได้: ฝั่ง deactivate เห็นว่ายังไม่มี
            // งานค้าง (เพราะ assign นี้ยังไม่ commit) เลยปิดบัญชี จากนั้น assign นี้ค่อย commit →
            // is_active=0 คู่กับ status=assigned งานค้างบนบัญชีที่ปิดไปแล้ว การ lock แถว user ตรงนี้บังคับให้สอง flow เรียงกัน
            // เหลือฝ่ายเดียวที่ชนะ
            $techStmt = $this->db->prepare('SELECT role, is_active FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
            $techStmt->execute(['id' => $technicianId]);
            $tech = $techStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($tech === null || (string) $tech['role'] !== 'technician' || (int) $tech['is_active'] !== 1) {
                throw new DomainException('ช่างที่เลือกไม่พร้อมใช้งาน (อาจถูกปิดบัญชีหรือเปลี่ยนบทบาท) กรุณาเลือกช่างคนอื่น');
            }

            $existingResponseDueAt = '';
            if ($isReassign) {
                // refresh response_due_at ในแถวที่ถูก lock เพื่อให้การ reset SLA ใช้ target ปัจจุบัน
                $dueStmt = $this->db->prepare(
                    'SELECT response_due_at FROM tickets WHERE id = :ticket_id LIMIT 1'
                );
                $dueStmt->execute(['ticket_id' => $ticketId]);
                $existingResponseDueAt = (string) ($dueStmt->fetchColumn() ?: '');
            }

            $firstResponseClause = $isReassign ? 'first_response_at = NULL,' : '';
            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET assigned_technician_id = :technician_id,
                     status = :status,
                     assigned_at = :assigned_at,
                     ' . $firstResponseClause . '
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'technician_id' => $technicianId,
                'status' => 'assigned',
                'assigned_at' => $assignedAt,
                'updated_at' => $assignedAt,
                'ticket_id' => $ticketId,
            ]);

            $existingWorkOrderStmt = $this->db->prepare(
                'SELECT id, work_order_no
                 FROM work_orders
                 WHERE ticket_id = :ticket_id
                 LIMIT 1'
            );
            $existingWorkOrderStmt->execute(['ticket_id' => $ticketId]);
            $existingWorkOrder = $existingWorkOrderStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($existingWorkOrder !== null) {
                $workOrderStmt = $this->db->prepare(
                    'UPDATE work_orders
                     SET technician_id = :technician_id,
                         assigned_by = :assigned_by,
                         status = :status,
                         instructions = :instructions,
                         assigned_at = :assigned_at,
                         accepted_at = NULL,
                         started_at = NULL,
                         completed_at = NULL,
                         updated_at = :updated_at
                     WHERE ticket_id = :ticket_id'
                );
                $workOrderStmt->execute([
                    'technician_id' => $technicianId,
                    'assigned_by' => $actorId,
                    'status' => 'assigned',
                    'instructions' => $instructions !== '' ? $instructions : null,
                    'assigned_at' => $assignedAt,
                    'updated_at' => $assignedAt,
                    'ticket_id' => $ticketId,
                ]);
            } else {
                $workOrderNumberLock = 'work-order-number-' . date('Ymd', strtotime($assignedAt) ?: time());
                $this->acquireNamedLock($workOrderNumberLock);
                $workOrderStmt = $this->db->prepare(
                    'INSERT INTO work_orders (
                        work_order_no,
                        ticket_id,
                        technician_id,
                        assigned_by,
                        status,
                        instructions,
                        diagnosis_summary,
                        resolution_summary,
                        labor_minutes,
                        assigned_at,
                        accepted_at,
                        started_at,
                        completed_at,
                        created_at,
                        updated_at
                     ) VALUES (
                        :work_order_no,
                        :ticket_id,
                        :technician_id,
                        :assigned_by,
                        :status,
                        :instructions,
                        :diagnosis_summary,
                        :resolution_summary,
                        :labor_minutes,
                        :assigned_at,
                        :accepted_at,
                        :started_at,
                        :completed_at,
                        :created_at,
                        :updated_at
                     )'
                );
                $workOrderStmt->execute([
                    'work_order_no' => $this->generateNextWorkOrderNumber($assignedAt),
                    'ticket_id' => $ticketId,
                    'technician_id' => $technicianId,
                    'assigned_by' => $actorId,
                    'status' => 'assigned',
                    'instructions' => $instructions !== '' ? $instructions : null,
                    'diagnosis_summary' => null,
                    'resolution_summary' => null,
                    'labor_minutes' => 0,
                    'assigned_at' => $assignedAt,
                    'accepted_at' => null,
                    'started_at' => null,
                    'completed_at' => null,
                    'created_at' => $assignedAt,
                    'updated_at' => $assignedAt,
                ]);
            }

            $details = 'มอบหมายงานให้ช่างเทคนิค ' . $technicianName;
            if ($instructions !== '') {
                $details .= ' พร้อมคำสั่งงาน: ' . $instructions;
            }

            $this->insertActivityLog($ticketId, $actorId, 'technician_assigned', $lockedStatus, 'assigned', $details);

            if ($isReassign && $existingResponseDueAt !== '') {
                // การ reassign ticket ทำให้ first response ของช่างคนก่อนใช้ไม่ได้;
                // reset แถว response SLA เพื่อให้ช่างคนใหม่เริ่มจากสถานะ pending
                $this->resetSlaTrack($ticketId, 'response', $existingResponseDueAt);
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        } finally {
            if ($workOrderNumberLock !== null) {
                $this->releaseNamedLock($workOrderNumberLock);
            }
        }
    }

    /**
     * ช่างรับงานที่ได้รับมอบหมาย (assigned → accepted) บันทึกเวลาตอบสนองครั้งแรก.
     * ผลข้างเคียง: ทำใน transaction เดียว — lock แถว ticket (FOR UPDATE) + re-check ใต้ lock (ต้องเป็น assigned,
     * approval_status approved และผู้ทำต้องเป็นช่างที่ถูก assign) แล้ว UPDATE tickets (set first_response_at ครั้งแรก) +
     * UPDATE work_orders + mark SLA response met/breached (ticket_sla_tracks) + INSERT ticket_activity_logs.
     * @param string $currentStatus สถานะที่ caller เห็นก่อน lock ใช้เป็น from_status ใน activity log
     * @throws DomainException เมื่อสถานะถูกเปลี่ยนหรือผู้ทำไม่ใช่ช่างที่ถูก assign (re-check ใต้ lock ไม่ผ่าน)
     * @throws RuntimeException เมื่อไม่พบ work order ของ ticket นี้
     * @throws Throwable เมื่อ write ใด ๆ ล้มเหลว (rollback tx ก่อน rethrow)
     */
    public function acceptAssignedWork(int $ticketId, int $actorId, string $note, string $currentStatus): void
    {
        $acceptedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $this->lockTicketForTransition($ticketId, ['assigned'], 'approved', 'assigned_technician_id', $actorId);

            // COALESCE(first_response_at, …) = บันทึกเวลาตอบสนอง "ครั้งแรก" ครั้งเดียวแล้วไม่เขียนทับอีก —
            // ค่านี้คือตัววัด SLA การตอบสนอง ถ้าถูกทับด้วยเวลาหลัง ๆ ตัวเลขรายงานจะดีเกินจริง/แย่เกินจริงทันที
            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET status = :status,
                     first_response_at = COALESCE(first_response_at, :first_response_at),
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'status' => 'accepted',
                'first_response_at' => $acceptedAt,
                'updated_at' => $acceptedAt,
                'ticket_id' => $ticketId,
            ]);

            $workOrderStmt = $this->db->prepare(
                'UPDATE work_orders
                 SET status = :status,
                     accepted_at = COALESCE(accepted_at, :accepted_at),
                     updated_at = :updated_at
                 WHERE ticket_id = :ticket_id'
            );
            $workOrderStmt->execute([
                'status' => 'accepted',
                'accepted_at' => $acceptedAt,
                'updated_at' => $acceptedAt,
                'ticket_id' => $ticketId,
            ]);

            if ($workOrderStmt->rowCount() === 0) {
                throw new RuntimeException('ไม่พบ work order สำหรับ ticket นี้');
            }

            $this->markSlaAchieved($ticketId, 'response', $acceptedAt);
            $this->insertActivityLog($ticketId, $actorId, 'work_accepted', $currentStatus, 'accepted', $note !== '' ? $note : 'ช่างเทคนิครับงานและยืนยันเริ่มดำเนินการ');

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * ช่างเริ่มดำเนินงาน (assigned|accepted → in_progress) ข้ามขั้นรับงานได้ (backfill เวลารับ/ตอบสนองที่ยังว่าง).
     * ผลข้างเคียง: ทำใน transaction เดียว — lock แถว ticket (FOR UPDATE) + re-check ใต้ lock (assigned/accepted,
     * approval_status approved, ผู้ทำเป็นช่างที่ถูก assign) แล้ว UPDATE tickets + UPDATE work_orders +
     * mark SLA response (ticket_sla_tracks) + INSERT ticket_activity_logs.
     * @param string $currentStatus สถานะที่ caller เห็นก่อน lock (from_status ที่บันทึกจริงยึดจากสถานะใต้ lock)
     * @throws DomainException เมื่อสถานะถูกเปลี่ยนหรือผู้ทำไม่ใช่ช่างที่ถูก assign (re-check ใต้ lock ไม่ผ่าน)
     * @throws RuntimeException เมื่อไม่พบ work order ของ ticket นี้
     * @throws Throwable เมื่อ write ใด ๆ ล้มเหลว (rollback tx ก่อน rethrow)
     */
    public function startAssignedWork(int $ticketId, int $actorId, string $note, string $currentStatus): void
    {
        $startedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            // lock ได้หลายสถานะ → บันทึก log จากสถานะที่ล็อกไว้ ไม่ใช่ค่าเก่าที่ caller อ่านมาก่อนล็อก เพื่อให้การ
            // accept ที่เข้ามาพร้อมกันช่วง service อ่านค่ากับล็อกนี้ ไม่บันทึก from_status ผิด
            $lockedStatus = $this->lockTicketForTransition($ticketId, ['assigned', 'accepted'], 'approved', 'assigned_technician_id', $actorId);

            // เริ่มงานข้ามขั้น "รับงาน" ได้ (lock ยอมทั้ง assigned/accepted) — จึงใช้ COALESCE backfill
            // เวลารับงาน/เวลาตอบสนองที่ยังว่างให้ด้วย โดยไม่ทับของเดิมถ้าช่างกดรับมาก่อนแล้ว
            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET status = :status,
                     started_at = COALESCE(started_at, :started_at),
                     first_response_at = COALESCE(first_response_at, :first_response_at),
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'status' => 'in_progress',
                'started_at' => $startedAt,
                'first_response_at' => $startedAt,
                'updated_at' => $startedAt,
                'ticket_id' => $ticketId,
            ]);

            $workOrderStmt = $this->db->prepare(
                'UPDATE work_orders
                 SET status = :status,
                     accepted_at = COALESCE(accepted_at, :accepted_at),
                     started_at = COALESCE(started_at, :started_at),
                     updated_at = :updated_at
                 WHERE ticket_id = :ticket_id'
            );
            $workOrderStmt->execute([
                'status' => 'in_progress',
                'accepted_at' => $startedAt,
                'started_at' => $startedAt,
                'updated_at' => $startedAt,
                'ticket_id' => $ticketId,
            ]);

            if ($workOrderStmt->rowCount() === 0) {
                throw new RuntimeException('ไม่พบ work order สำหรับ ticket นี้');
            }

            $this->markSlaAchieved($ticketId, 'response', $startedAt);
            $this->insertActivityLog($ticketId, $actorId, 'work_started', $lockedStatus, 'in_progress', $note !== '' ? $note : 'ช่างเทคนิคเริ่มดำเนินงานตามที่ได้รับมอบหมาย');

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * ช่างสรุปปิดงาน (accepted|in_progress → resolved) บันทึกผลวิเคราะห์/วิธีแก้ และสะสมเวลาแรงงาน.
     * ผลข้างเคียง: ทำใน transaction เดียว — lock แถว ticket (FOR UPDATE) + re-check ใต้ lock (accepted/in_progress,
     * approval_status approved, ผู้ทำเป็นช่างที่ถูก assign) แล้ว UPDATE tickets + UPDATE work_orders
     * (labor_minutes สะสมบวกเพิ่ม ไม่เขียนทับ) + mark SLA response+resolution (ticket_sla_tracks) + INSERT ticket_activity_logs.
     * @param int $laborMinutes นาทีแรงงานรอบนี้ บวกสะสมเข้ากับ labor_minutes เดิม
     * @param string $currentStatus สถานะที่ caller เห็นก่อน lock (from_status ที่บันทึกจริงยึดจากสถานะใต้ lock)
     * @throws DomainException เมื่อสถานะถูกเปลี่ยนหรือผู้ทำไม่ใช่ช่างที่ถูก assign (re-check ใต้ lock ไม่ผ่าน)
     * @throws RuntimeException เมื่อไม่พบ work order ของ ticket นี้
     * @throws Throwable เมื่อ write ใด ๆ ล้มเหลว (rollback tx ก่อน rethrow)
     */
    public function resolveAssignedWork(int $ticketId, int $actorId, string $diagnosisSummary, string $resolutionSummary, int $laborMinutes, string $currentStatus): void
    {
        $resolvedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            // lock ได้หลายสถานะ → บันทึก log จากสถานะที่ล็อกไว้ ไม่ใช่ค่าเก่าที่ caller อ่านมาก่อนล็อก
            $lockedStatus = $this->lockTicketForTransition($ticketId, ['accepted', 'in_progress'], 'approved', 'assigned_technician_id', $actorId);

            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET status = :status,
                     started_at = COALESCE(started_at, :started_at),
                     first_response_at = COALESCE(first_response_at, :first_response_at),
                     resolved_at = :resolved_at,
                     resolution_summary = :resolution_summary,
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'status' => 'resolved',
                'started_at' => $resolvedAt,
                'first_response_at' => $resolvedAt,
                'resolved_at' => $resolvedAt,
                'resolution_summary' => $resolutionSummary,
                'updated_at' => $resolvedAt,
                'ticket_id' => $ticketId,
            ]);

            $workOrderStmt = $this->db->prepare(
                'UPDATE work_orders
                 SET status = :status,
                     accepted_at = COALESCE(accepted_at, :accepted_at),
                     started_at = COALESCE(started_at, :started_at),
                     completed_at = :completed_at,
                     diagnosis_summary = :diagnosis_summary,
                     resolution_summary = :resolution_summary,
                     labor_minutes = labor_minutes + :labor_minutes,
                     updated_at = :updated_at
                 WHERE ticket_id = :ticket_id'
            );
            $workOrderStmt->execute([
                'status' => 'completed',
                'accepted_at' => $resolvedAt,
                'started_at' => $resolvedAt,
                'completed_at' => $resolvedAt,
                'diagnosis_summary' => $diagnosisSummary,
                'resolution_summary' => $resolutionSummary,
                'labor_minutes' => $laborMinutes,
                'updated_at' => $resolvedAt,
                'ticket_id' => $ticketId,
            ]);

            if ($workOrderStmt->rowCount() === 0) {
                throw new RuntimeException('ไม่พบ work order สำหรับ ticket นี้');
            }

            $this->markSlaAchieved($ticketId, 'response', $resolvedAt);
            $this->markSlaAchieved($ticketId, 'resolution', $resolvedAt);

            $details = 'ช่างเทคนิคสรุปการวิเคราะห์: ' . $diagnosisSummary . ' | วิธีแก้ไข: ' . $resolutionSummary;
            if ($laborMinutes > 0) {
                $details .= ' | ใช้เวลา ' . $laborMinutes . ' นาที';
            }

            $this->insertActivityLog($ticketId, $actorId, 'ticket_resolved', $lockedStatus, 'resolved', $details);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * ผู้แจ้งยืนยันปิดงานและให้คะแนน (resolved → completed).
     * ผลข้างเคียง: ทำใน transaction เดียว — lock แถว ticket (FOR UPDATE) + re-check ใต้ lock (ต้องเป็น resolved,
     * approval_status approved และผู้ทำต้องเป็น requester) แล้ว UPDATE tickets + upsert ticket_ratings ของ cycle ปัจจุบัน +
     * INSERT ticket_activity_logs.
     * @param int|null $technicianId ช่างที่ผูกกับคะแนน (null ได้ถ้าไม่มีช่างในงานนั้น)
     * @param int $score คะแนนความพึงพอใจ 1–5
     * @param string $currentStatus สถานะที่ caller เห็นก่อน lock ใช้เป็น from_status ใน activity log
     * @throws DomainException เมื่อสถานะถูกเปลี่ยนหรือผู้ทำไม่ใช่ requester (re-check ใต้ lock ไม่ผ่าน)
     * @throws Throwable เมื่อ write ใด ๆ ล้มเหลว (rollback tx ก่อน rethrow)
     */
    public function completeResolvedTicket(int $ticketId, int $actorId, ?int $technicianId, string $closureNote, int $score, string $feedback, string $currentStatus): void
    {
        $completedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $this->lockTicketForTransition($ticketId, ['resolved'], 'approved', 'requester_id', $actorId);

            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET status = :status,
                     completed_at = :completed_at,
                     closure_note = :closure_note,
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'status' => 'completed',
                'completed_at' => $completedAt,
                'closure_note' => $closureNote !== '' ? $closureNote : null,
                'updated_at' => $completedAt,
                'ticket_id' => $ticketId,
            ]);

            $this->upsertTicketRating($ticketId, $actorId, $technicianId, $score, $feedback, $completedAt);

            $details = 'ผู้แจ้งยืนยันผลการดำเนินงาน';
            if ($closureNote !== '') {
                $details .= ' | หมายเหตุปิดงาน: ' . $closureNote;
            }
            $details .= ' | คะแนนความพึงพอใจ: ' . $score . '/5';
            if ($feedback !== '') {
                $details .= ' | ความเห็น: ' . $feedback;
            }

            $this->insertActivityLog($ticketId, $actorId, 'ticket_completed', $currentStatus, 'completed', $details);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * ผู้แจ้งเปิดงานซ้ำหลังปิด (resolved → assigned) ล้างเวลา/สรุปของรอบก่อน แล้วตั้ง due ใหม่.
     * ผลข้างเคียง: ทำใน transaction เดียว — lock แถว ticket (FOR UPDATE) + re-check ใต้ lock (resolved,
     * approval_status approved, ผู้ทำเป็น requester) แล้ว UPDATE tickets + UPDATE work_orders (คง labor_minutes ไว้ as-reported) +
     * append แถว SLA cycle ใหม่ (ticket_sla_tracks response/resolution) + INSERT ticket_activity_logs.
     * as-reported: cycle SLA/rating ของงวดก่อนถูก freeze ไว้ ไม่ถูก reset.
     * @param string $currentStatus สถานะที่ caller เห็นก่อน lock ใช้เป็น from_status ใน activity log
     * @param string $responseDueAt กำหนดตอบสนองใหม่ของ cycle ที่เปิดซ้ำ
     * @param string $resolutionDueAt กำหนดปิดงานใหม่ของ cycle ที่เปิดซ้ำ
     * @throws DomainException เมื่อสถานะถูกเปลี่ยนหรือผู้ทำไม่ใช่ requester (re-check ใต้ lock ไม่ผ่าน)
     * @throws RuntimeException เมื่อไม่พบ work order ของ ticket นี้
     * @throws Throwable เมื่อ write ใด ๆ ล้มเหลว (rollback tx ก่อน rethrow)
     */
    public function reopenTicket(int $ticketId, int $actorId, string $note, string $currentStatus, string $responseDueAt, string $resolutionDueAt): void
    {
        $reopenedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $this->lockTicketForTransition($ticketId, ['resolved'], 'approved', 'requester_id', $actorId);

            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET status = :status,
                     assigned_at = :assigned_at,
                     first_response_at = NULL,
                     started_at = NULL,
                     resolved_at = NULL,
                     completed_at = NULL,
                     resolution_summary = NULL,
                     closure_note = NULL,
                     response_due_at = :response_due_at,
                     resolution_due_at = :resolution_due_at,
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'status' => 'assigned',
                'assigned_at' => $reopenedAt,
                'response_due_at' => $responseDueAt,
                'resolution_due_at' => $resolutionDueAt,
                'updated_at' => $reopenedAt,
                'ticket_id' => $ticketId,
            ]);

            // จงใจไม่ reset labor_minutes ตรงนี้: แรงงานที่ใช้ไปใน cycle ก่อน ๆ เป็นงานที่ทำจริงและจ่ายไปแล้ว
            // (as-reported — หลักการเดียวกับ SLA/rating cycle ที่ถูก freeze ด้านล่าง) การ resolve
            // ครั้งถัดไปจะบวกนาทีเพิ่มทับเข้าไป reopen เลยไม่มีวันลบ labor ที่บันทึกไว้ออกจาก report
            $workOrderStmt = $this->db->prepare(
                'UPDATE work_orders
                 SET status = :status,
                     assigned_at = :assigned_at,
                     accepted_at = NULL,
                     started_at = NULL,
                     completed_at = NULL,
                     diagnosis_summary = NULL,
                     resolution_summary = NULL,
                     updated_at = :updated_at
                 WHERE ticket_id = :ticket_id'
            );
            $workOrderStmt->execute([
                'status' => 'assigned',
                'assigned_at' => $reopenedAt,
                'updated_at' => $reopenedAt,
                'ticket_id' => $ticketId,
            ]);

            if ($workOrderStmt->rowCount() === 0) {
                throw new RuntimeException('ไม่พบ work order สำหรับ ticket นี้');
            }

            // As-reported: rating ของ cycle ก่อนยังอยู่ (re-rate จะ append cycle ใหม่) และ
            // แถว SLA ของ cycle ก่อนยังถูก freeze ไว้ — reopen จะ append cycle pending อันใหม่แทนการ
            // reset ผลตัดสิน SLA / CSAT ของงวดในอดีตจึงไม่เปลี่ยน
            $nextCycle = $this->currentTicketCycle($ticketId) + 1;
            $this->appendSlaCycle($ticketId, 'response', $responseDueAt, $nextCycle);
            $this->appendSlaCycle($ticketId, 'resolution', $resolutionDueAt, $nextCycle);
            $this->insertActivityLog($ticketId, $actorId, 'ticket_reopened', $currentStatus, 'assigned', $note);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * ยกเลิก ticket (สถานะปัจจุบันที่ caller ส่งมา → cancelled) ปรับ approval_status ตามจุดที่ยกเลิก.
     * ผลข้างเคียง: ทำใน transaction เดียว — lock แถว ticket (FOR UPDATE) + re-check ใต้ lock (สถานะต้องตรงกับ
     * $currentStatus, approval_status ต้องเป็น pending/approved ตามกรณี, ผู้ทำเป็น requester) แล้ว UPDATE tickets;
     * ถ้ายกเลิกตอนยังรออนุมัติจะ DELETE คำขออนุมัติที่ค้าง (ticket_approvals) ทิ้ง; แล้ว INSERT ticket_activity_logs.
     * @param string $currentStatus สถานะปัจจุบันที่ caller เห็น ใช้ทั้งเป็นเงื่อนไข re-check ใต้ lock และ from_status ใน activity log
     * @throws DomainException เมื่อสถานะถูกเปลี่ยนไปจาก $currentStatus หรือผู้ทำไม่ใช่ requester (re-check ใต้ lock ไม่ผ่าน)
     * @throws Throwable เมื่อ write ใด ๆ ล้มเหลว (rollback tx ก่อน rethrow)
     */
    public function cancelTicket(int $ticketId, int $actorId, string $note, string $currentStatus): void
    {
        $cancelledAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $expectedApprovalStatus = $currentStatus === 'pending_approval' ? 'pending' : 'approved';
            $this->lockTicketForTransition($ticketId, [$currentStatus], $expectedApprovalStatus, 'requester_id', $actorId);

            $approvalStatus = $currentStatus === 'pending_approval' ? 'not_required' : 'approved';
            $ticketStmt = $this->db->prepare(
                'UPDATE tickets
                 SET status = :status,
                     approval_status = :approval_status,
                     cancelled_at = :cancelled_at,
                     closure_note = :closure_note,
                     updated_at = :updated_at
                 WHERE id = :ticket_id'
            );
            $ticketStmt->execute([
                'status' => 'cancelled',
                'approval_status' => $approvalStatus,
                'cancelled_at' => $cancelledAt,
                'closure_note' => $note,
                'updated_at' => $cancelledAt,
                'ticket_id' => $ticketId,
            ]);

            if ($currentStatus === 'pending_approval') {
                // ยกเลิกตั้งแต่ยังรออนุมัติ → ลบคำขออนุมัติที่ค้างอยู่ทิ้ง ไม่ให้เหลือเป็นรายการค้าง
                // ในคิวอนุมัติของ manager ทั้งที่ ticket ถูกยกเลิกไปแล้ว
                $approvalStmt = $this->db->prepare(
                    "DELETE FROM ticket_approvals
                     WHERE ticket_id = :ticket_id AND action = 'pending'"
                );
                $approvalStmt->execute(['ticket_id' => $ticketId]);
            }

            $this->insertActivityLog($ticketId, $actorId, 'ticket_cancelled', $currentStatus, 'cancelled', $note);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /** สร้าง ticket สำหรับ demo (INSERT IGNORE — idempotent, ชน UNIQUE(ticket_no) เดิมจะข้าม); วันที่/สถานะปรับได้ผ่าน $payload. ใช้ตอนโหลด demo data. */
    public function createSeedTicket(array $payload): int
    {
        // วันที่ปรับได้ (สำหรับ demo ที่กระจายช่วงเวลา) — ไม่ส่งมาก็ default = ตอนนี้ / +1h / +8h เหมือนเดิม.
        $requestedAt = (string) ($payload['requested_at'] ?? date('Y-m-d H:i:s'));
        $reqTs = strtotime($requestedAt) ?: time();

        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO tickets (
                ticket_no, title, description, requester_id, requester_department_id, location_id, asset_id, ticket_category_id, priority_id,
                assigned_manager_id, assigned_technician_id, approval_status, status, channel, impact_level, urgency_level,
                requested_at, response_due_at, resolution_due_at, approved_at, resolved_at, completed_at, created_at, updated_at
             ) VALUES (
                :ticket_no, :title, :description, :requester_id, :requester_department_id, :location_id, :asset_id, :ticket_category_id, :priority_id,
                :manager_id, :technician_id, :approval_status, :status, "web", "medium", "medium",
                :requested_at, :response_due_at, :resolution_due_at, :approved_at, :resolved_at, :completed_at, :created_at, NOW()
             )'
        );
        $stmt->execute([
            'ticket_no' => (string) ($payload['ticket_no'] ?? ''),
            'title' => (string) ($payload['title'] ?? ''),
            'description' => (string) ($payload['description'] ?? ''),
            'requester_id' => (int) ($payload['requester_id'] ?? 0),
            'requester_department_id' => isset($payload['requester_department_id']) && (int) $payload['requester_department_id'] > 0 ? (int) $payload['requester_department_id'] : null,
            'location_id' => (int) ($payload['location_id'] ?? 0),
            'asset_id' => isset($payload['asset_id']) && (int) $payload['asset_id'] > 0 ? (int) $payload['asset_id'] : null,
            'ticket_category_id' => (int) ($payload['ticket_category_id'] ?? 0),
            'priority_id' => (int) ($payload['priority_id'] ?? 0),
            'manager_id' => isset($payload['manager_id']) && (int) $payload['manager_id'] > 0 ? (int) $payload['manager_id'] : null,
            'technician_id' => isset($payload['technician_id']) && (int) $payload['technician_id'] > 0 ? (int) $payload['technician_id'] : null,
            'approval_status' => (string) ($payload['approval_status'] ?? 'pending'),
            'status' => (string) ($payload['status'] ?? 'pending_approval'),
            'requested_at' => $requestedAt,
            'created_at' => $requestedAt,
            'response_due_at' => (string) ($payload['response_due_at'] ?? date('Y-m-d H:i:s', $reqTs + 3600)),
            'resolution_due_at' => (string) ($payload['resolution_due_at'] ?? date('Y-m-d H:i:s', $reqTs + 8 * 3600)),
            'approved_at' => $payload['approved_at'] ?? null,
            'resolved_at' => $payload['resolved_at'] ?? null,
            'completed_at' => $payload['completed_at'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** สร้าง work order เริ่มต้นสำหรับ seed (มี labor) — idempotent ผ่าน UNIQUE(ticket_id)/UNIQUE(work_order_no). ใช้ตอนโหลด demo data. */
    public function createSeedWorkOrder(int $ticketId, int $technicianId, int $assignedBy, string $status, int $laborMinutes, string $assignedAt, ?string $completedAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO work_orders (work_order_no, ticket_id, technician_id, assigned_by, status, labor_minutes, assigned_at, completed_at, created_at, updated_at)
             VALUES (:wo, :ticket_id, :tech, :by, :status, :labor, :assigned_at, :completed_at, :created_at, NOW())'
        );
        $stmt->execute([
            'wo' => 'WO-DEMO-' . $ticketId,
            'ticket_id' => $ticketId,
            'tech' => $technicianId,
            'by' => $assignedBy,
            'status' => $status,
            'labor' => max(0, $laborMinutes),
            'assigned_at' => $assignedAt,
            'completed_at' => $completedAt,
            'created_at' => $assignedAt,
        ]);
    }

    /** สร้าง SLA track เริ่มต้นสำหรับ seed (response/resolution) — idempotent ผ่าน UNIQUE(ticket_id, metric_type). ใช้ตอนโหลด demo data. */
    public function createSeedSlaTrack(int $ticketId, string $metricType, string $targetAt, ?string $achievedAt, ?string $breachedAt, string $status): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO ticket_sla_tracks (ticket_id, metric_type, target_at, achieved_at, breached_at, status, created_at)
             VALUES (:ticket_id, :metric, :target, :achieved, :breached, :status, :created)'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'metric' => $metricType,
            'target' => $targetAt,
            'achieved' => $achievedAt,
            'breached' => $breachedAt,
            'status' => $status,
            'created' => $targetAt,
        ]);
    }

    /** สร้าง activity log เริ่มต้นสำหรับ seed (เช่น ticket_resolved / ticket_reopened) — ใช้ตอนโหลด demo data. */
    public function createSeedActivityLog(int $ticketId, int $actorId, string $action, ?string $fromStatus, ?string $toStatus, string $createdAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status, created_at)
             VALUES (:ticket_id, :actor, :action, :from, :to, :created)'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'actor' => $actorId,
            'action' => $action,
            'from' => $fromStatus,
            'to' => $toStatus,
            'created' => $createdAt,
        ]);
    }

    /** สร้าง rating สำหรับ demo (INSERT IGNORE — idempotent, ชน UNIQUE เดิมจะข้าม); score ถูก clamp เป็น 1–5. ใช้ตอนโหลด demo data. */
    public function createSeedRating(int $ticketId, int $requesterId, ?int $technicianId, int $score, string $feedback): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO ticket_ratings (ticket_id, requester_id, technician_id, score, feedback, created_at, updated_at)
             VALUES (:ticket_id, :requester_id, :technician_id, :score, :feedback, NOW(), NOW())'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'requester_id' => $requesterId,
            'technician_id' => $technicianId,
            'score' => max(1, min(5, $score)),
            'feedback' => $feedback,
        ]);
    }

    /** เลข ticket รันถัดไปของวัน (อ่านเลขล่าสุด +1) — ปลอดเลขซ้ำเฉพาะเมื่อเรียกใต้ named lock ต่อวันใน createTicket. */
    private function generateNextTicketNumber(string $requestedAt): string
    {
        $datePart = date('Ymd', strtotime($requestedAt) ?: time());
        $prefix = setting('ticket_prefix', 'MT') . '-' . $datePart . '-';

        $stmt = $this->db->prepare(
            'SELECT ticket_no
             FROM tickets
             WHERE ticket_no LIKE :ticket_prefix
             ORDER BY ticket_no DESC
             LIMIT 1'
        );
        $stmt->execute(['ticket_prefix' => $prefix . '%']);

        $latestTicketNo = (string) ($stmt->fetchColumn() ?: '');
        $nextSequence = 1;

        if ($latestTicketNo !== '') {
            $suffix = substr($latestTicketNo, -4);
            if (ctype_digit($suffix)) {
                $nextSequence = ((int) $suffix) + 1;
            }
        }

        return $prefix . str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);
    }

    /** เลข work order รันถัดไปของวัน — แบบแผนเดียวกับเลข ticket: ต้องเรียกใต้ named lock ของมันเอง (ดู assignTechnician). */
    private function generateNextWorkOrderNumber(string $assignedAt): string
    {
        $datePart = date('Ymd', strtotime($assignedAt) ?: time());
        $prefix = 'WO-' . $datePart . '-';

        $stmt = $this->db->prepare(
            'SELECT work_order_no
             FROM work_orders
             WHERE work_order_no LIKE :work_order_prefix
             ORDER BY work_order_no DESC
             LIMIT 1'
        );
        $stmt->execute(['work_order_prefix' => $prefix . '%']);

        $latestWorkOrderNo = (string) ($stmt->fetchColumn() ?: '');
        $nextSequence = 1;

        if ($latestWorkOrderNo !== '') {
            $suffix = substr($latestWorkOrderNo, -4);
            if (ctype_digit($suffix)) {
                $nextSequence = ((int) $suffix) + 1;
            }
        }

        return $prefix . str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);
    }

    private function findDefaultApproverId(): ?int
    {
        $stmt = $this->db->query(
            "SELECT id
             FROM users
             WHERE is_active = 1 AND role IN ('manager', 'admin')
             ORDER BY CASE role WHEN 'manager' THEN 1 ELSE 2 END, id ASC
             LIMIT 1"
        );

        $approverId = $stmt->fetchColumn();

        return $approverId !== false ? (int) $approverId : null;
    }

    private function upsertApprovalDecision(int $ticketId, int $approverId, string $action, string $note, string $actedAt): void
    {
        $lookupStmt = $this->db->prepare(
            'SELECT id
             FROM ticket_approvals
             WHERE ticket_id = :ticket_id
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $lookupStmt->execute(['ticket_id' => $ticketId]);

        $approvalId = $lookupStmt->fetchColumn();

        if ($approvalId !== false) {
            $updateStmt = $this->db->prepare(
                'UPDATE ticket_approvals
                 SET approver_id = :approver_id,
                     action = :action,
                     note = :note,
                     acted_at = :acted_at
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'action' => $action,
                'approver_id' => $approverId,
                'note' => $note !== '' ? $note : null,
                'acted_at' => $actedAt,
                'id' => (int) $approvalId,
            ]);

            return;
        }

        $insertStmt = $this->db->prepare(
            'INSERT INTO ticket_approvals (ticket_id, approver_id, action, note, acted_at, created_at)
             VALUES (:ticket_id, :approver_id, :action, :note, :acted_at, :created_at)'
        );
        $insertStmt->execute([
            'ticket_id' => $ticketId,
            'approver_id' => $approverId,
            'action' => $action,
            'note' => $note !== '' ? $note : null,
            'acted_at' => $actedAt,
            'created_at' => $actedAt,
        ]);
    }

    private function findTicketIdBySubmissionToken(string $submissionToken): ?int
    {
        if ($submissionToken === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id FROM tickets WHERE submission_token = :submission_token LIMIT 1'
        );
        $stmt->execute(['submission_token' => $submissionToken]);
        $ticketId = $stmt->fetchColumn();

        return $ticketId !== false ? (int) $ticketId : null;
    }

    private function isSubmissionTokenConflict(Throwable $exception, string $submissionToken): bool
    {
        if ($submissionToken === '' || !is_duplicate_key_error($exception)) {
            return false;
        }

        return $this->findTicketIdBySubmissionToken($submissionToken) !== null;
    }

    private function acquireNamedLock(string $name): void
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(:name, 5)');
        $stmt->execute(['name' => $name]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new DomainException('ระบบกำลังสร้างเลข Ticket กรุณาลองอีกครั้ง');
        }
    }

    private function releaseNamedLock(string $name): void
    {
        try {
            $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute(['name' => $name]);
        } catch (Throwable) {
            // การปล่อย lock ที่ผูกกับ connection ต้องไม่บดบังผลลัพธ์ของ operation เดิม
        }
    }

    /**
     * lock แถว ticket ด้วย FOR UPDATE ตรวจว่าอยู่ในสถานะที่อนุญาต แล้วคืนสถานะจริงที่ล็อกไว้ตอนนั้น
     * ผู้เรียกต้องตัดสินจากค่าที่คืนกลับ ห้ามใช้สถานะที่อ่านมาก่อน lock — เพื่อให้ transition ที่เข้ามาพร้อมกัน
     * แล้วพา ticket ไปอยู่สถานะอื่นที่ยังอนุญาต ไม่ไปกระตุ้น logic แตกกิ่งด้วยค่าเก่าที่ล้าสมัย
     * (เช่น การ reset SLA ของ reassign / from_status ใน audit) lock แค่รับประกันว่า transition ถูกต้อง ส่วนสถานะ
     * ที่คืนกลับมาคือสิ่งที่ทำให้ผลลัพธ์ตรงกับความจริง
     */
    private function lockTicketForTransition(
        int $ticketId,
        array $allowedStatuses,
        ?string $expectedApprovalStatus = null,
        ?string $ownerColumn = null,
        ?int $ownerId = null
    ): string {
        $allowedOwnerColumns = ['assigned_technician_id', 'requester_id'];
        if ($ownerColumn !== null && !in_array($ownerColumn, $allowedOwnerColumns, true)) {
            throw new RuntimeException('ไม่สามารถตรวจสอบผู้ดำเนินการของ Ticket ได้');
        }

        $columns = 'status, approval_status';
        if ($ownerColumn !== null) {
            $columns .= ', ' . $ownerColumn;
        }

        // ⚠️ guard สำคัญ (ห้ามเอาออก): FOR UPDATE ล็อกแถว + re-check สถานะใต้ lock กันกดพร้อมกัน — ถอด lock/re-check = อนุมัติ/รับงาน/ปิดงานซ้อนกันได้
        $stmt = $this->db->prepare(
            "SELECT $columns
             FROM tickets
             WHERE id = :ticket_id
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute(['ticket_id' => $ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $valid = $ticket !== null
            && in_array((string) ($ticket['status'] ?? ''), $allowedStatuses, true)
            && ($expectedApprovalStatus === null || (string) ($ticket['approval_status'] ?? '') === $expectedApprovalStatus)
            && ($ownerColumn === null || (int) ($ticket[$ownerColumn] ?? 0) === (int) $ownerId);

        if (!$valid) {
            throw new DomainException('สถานะ Ticket ถูกเปลี่ยนแล้ว กรุณารีเฟรชหน้าแล้วลองอีกครั้ง');
        }

        return (string) ($ticket['status'] ?? '');
    }

    private function insertActivityLog(int $ticketId, int $actorId, string $action, ?string $fromStatus, ?string $toStatus, string $details): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status, details, created_at)
             VALUES (:ticket_id, :actor_id, :action, :from_status, :to_status, :details, :created_at)'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'actor_id' => $actorId,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'details' => $details !== '' ? $details : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function upsertTicketRating(int $ticketId, int $requesterId, ?int $technicianId, int $score, string $feedback, string $createdAt): void
    {
        // As-reported: หนึ่ง rating ต่อหนึ่ง cycle ของ lifecycle การ re-rate หลัง reopen จะ append
        // rating ของ cycle ใหม่ (created_at ของมันเอง) แทนการเขียนทับ cycle ก่อน — CSAT ของงวดในอดีต
        // (ที่ window บน created_at) จึงไม่เปลี่ยน และ idempotent ภายใน cycle เดียวกัน (ยืนยัน cycle เดิมซ้ำ
        // จะ update แถวของ cycle นั้น ไม่ไปชน UNIQUE(ticket_id, cycle))
        $cycle = $this->currentTicketCycle($ticketId);

        $lookupStmt = $this->db->prepare(
            'SELECT id
             FROM ticket_ratings
             WHERE ticket_id = :ticket_id AND cycle = :cycle
             LIMIT 1'
        );
        $lookupStmt->execute(['ticket_id' => $ticketId, 'cycle' => $cycle]);

        $ratingId = $lookupStmt->fetchColumn();

        if ($ratingId !== false) {
            $updateStmt = $this->db->prepare(
                'UPDATE ticket_ratings
                 SET requester_id = :requester_id,
                     technician_id = :technician_id,
                     score = :score,
                     feedback = :feedback,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'requester_id' => $requesterId,
                'technician_id' => $technicianId,
                'score' => $score,
                'feedback' => $feedback !== '' ? $feedback : null,
                'updated_at' => $createdAt,
                'id' => (int) $ratingId,
            ]);

            return;
        }

        $insertStmt = $this->db->prepare(
            'INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, cycle, score, feedback, created_at, updated_at)
             VALUES (:ticket_id, :requester_id, :technician_id, :cycle, :score, :feedback, :created_at, :updated_at)'
        );
        $insertStmt->execute([
            'ticket_id' => $ticketId,
            'requester_id' => $requesterId,
            'technician_id' => $technicianId,
            'cycle' => $cycle,
            'score' => $score,
            'feedback' => $feedback !== '' ? $feedback : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function markSlaAchieved(int $ticketId, string $metricType, string $achievedAt): void
    {
        // As-reported: SLA track เป็นแบบราย cycle การ resolve จะสรุปผลตัดสินของ cycle ล่าสุด
        // ส่วน met/breached ของ cycle ก่อน ๆ ยังถูก freeze ไว้ และ idempotent เพราะยึดจาก achieved timestamp
        $cycle = $this->latestSlaCycle($ticketId, $metricType);
        if ($cycle === 0) {
            return; // ไม่มีแถว SLA (ไม่ควรเกิดใน flow จริง — ทุก ticket จะ seed cycle 1 ตอนสร้าง)
        }
        $stmt = $this->db->prepare(
            'UPDATE ticket_sla_tracks
             SET achieved_at = COALESCE(achieved_at, :achieved_at_value),
                 breached_at = CASE
                     WHEN COALESCE(achieved_at, :achieved_at_compare) > target_at
                         THEN COALESCE(breached_at, COALESCE(achieved_at, :breached_at_value))
                     ELSE breached_at
                 END,
                 status = CASE
                     WHEN COALESCE(achieved_at, :status_achieved_at_compare) > target_at THEN :breached_status
                     ELSE :met_status
                 END
             WHERE ticket_id = :ticket_id AND metric_type = :metric_type AND cycle = :cycle'
        );
        $stmt->execute([
            'achieved_at_value' => $achievedAt,
            'achieved_at_compare' => $achievedAt,
            'breached_at_value' => $achievedAt,
            'status_achieved_at_compare' => $achievedAt,
            'breached_status' => 'breached',
            'met_status' => 'met',
            'ticket_id' => $ticketId,
            'metric_type' => $metricType,
            'cycle' => $cycle,
        ]);
    }

    /**
     * reset แถว SLA ของ cycle ล่าสุดกลับเป็น pending (ยังอยู่ใน cycle เดิม — ใช้ตอน reassign ที่ทำให้ response
     * ของช่างคนก่อนใช้ไม่ได้ แต่ไม่เริ่ม cycle ใหม่ของ lifecycle ส่วน reopen จะ append cycle แทน)
     */
    private function resetSlaTrack(int $ticketId, string $metricType, string $targetAt): void
    {
        $cycle = $this->latestSlaCycle($ticketId, $metricType);
        if ($cycle === 0) {
            return;
        }
        $stmt = $this->db->prepare(
            'UPDATE ticket_sla_tracks
             SET target_at = :target_at,
                 achieved_at = NULL,
                 breached_at = NULL,
                 status = :status
             WHERE ticket_id = :ticket_id AND metric_type = :metric_type AND cycle = :cycle'
        );
        $stmt->execute([
            'target_at' => $targetAt,
            'status' => 'pending',
            'ticket_id' => $ticketId,
            'metric_type' => $metricType,
            'cycle' => $cycle,
        ]);
    }

    /** cycle ของ SLA ที่สูงสุดสำหรับ (ticket, metric); เป็น 0 ถ้าไม่มี. */
    private function latestSlaCycle(int $ticketId, string $metricType): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(cycle), 0) FROM ticket_sla_tracks WHERE ticket_id = :ticket_id AND metric_type = :metric_type'
        );
        $stmt->execute(['ticket_id' => $ticketId, 'metric_type' => $metricType]);

        return (int) $stmt->fetchColumn();
    }

    /** cycle ปัจจุบันของ lifecycle ของ ticket = cycle SLA สูงสุดข้าม metric ทั้งหมด (>=1). */
    private function currentTicketCycle(int $ticketId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(cycle), 1) FROM ticket_sla_tracks WHERE ticket_id = :ticket_id');
        $stmt->execute(['ticket_id' => $ticketId]);

        return max(1, (int) $stmt->fetchColumn());
    }

    /** append แถว SLA ใหม่ (pending) สำหรับ cycle ใหม่ของ lifecycle — as-reported: ไม่มีวันเขียนทับ cycle ในอดีต. */
    private function appendSlaCycle(int $ticketId, string $metricType, string $targetAt, int $cycle): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ticket_sla_tracks (ticket_id, metric_type, cycle, target_at, achieved_at, breached_at, status, created_at)
             VALUES (:ticket_id, :metric_type, :cycle, :target_at, NULL, NULL, :status, :created_at)'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'metric_type' => $metricType,
            'cycle' => $cycle,
            'target_at' => $targetAt,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
