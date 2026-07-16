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

    public function markSlaBreachedById(int $slaTrackId, string $breachedAt): bool
    {
        // NOTE: :overdue_before binds the same value as :breached_at but must be a DISTINCT placeholder —
        // native prepared statements (PDO::ATTR_EMULATE_PREPARES=false) reject a reused named parameter (HY093).
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

    public function createTicket(array $payload): array
    {
        $requestedAt = (string) ($payload['requested_at'] ?? date('Y-m-d H:i:s'));
        $responseDueAt = (string) ($payload['response_due_at'] ?? '');
        $resolutionDueAt = (string) ($payload['resolution_due_at'] ?? '');
        $submissionToken = (string) ($payload['submission_token'] ?? '');
        $existingTicketId = $this->findTicketIdBySubmissionToken($submissionToken);
        if ($existingTicketId !== null) {
            return ['id' => $existingTicketId, 'created' => false];
        }

        $approverId = $this->findDefaultApproverId();
        $numberLock = 'ticket-number-' . date('Ymd', strtotime($requestedAt) ?: time());
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

    public function assignTechnician(int $ticketId, int $actorId, int $technicianId, string $technicianName, string $instructions, string $currentStatus): void
    {
        $assignedAt = date('Y-m-d H:i:s');
        $workOrderNumberLock = null;

        try {
            $this->db->beginTransaction();
            // accepted/in_progress: mid-work reassign when the technician became unavailable (logic-review F2).
            // Branch on the LOCKED status, not the pre-lock snapshot passed by the caller — a concurrent
            // accept/start between the service's read and this lock must not make a reassign look like a first
            // assign (which would skip the response-SLA reset and log a wrong from_status). (logic-review F2b)
            $lockedStatus = $this->lockTicketForTransition($ticketId, ['approved', 'assigned', 'accepted', 'in_progress'], 'approved');

            $isReassign = in_array($lockedStatus, ['assigned', 'accepted', 'in_progress'], true);

            // Authoritative under-lock re-check of the mid-work-reassign reason rule. The service checks this
            // BEFORE the lock, but a concurrent accept/start could move the ticket into a mid-work state after
            // that check — enforce it here on the LOCKED status so a reason can never be skipped by a race.
            // (logic-review F2c)
            if (in_array($lockedStatus, ['accepted', 'in_progress'], true) && $instructions === '') {
                throw new DomainException('กรุณาระบุเหตุผลในการย้ายงานที่ช่างรับไปแล้ว');
            }

            // Lock the chosen technician's row and re-verify role+active INSIDE the transaction. The service
            // checks this BEFORE the lock, but a concurrent admin deactivate/role-change (which locks the user
            // row + rechecks open work under its own lock) could otherwise interleave: the deactivate sees no
            // open work yet (this assign uncommitted) and disables the account, then this assign commits →
            // is_active=0 + status=assigned, work stuck on a dead account. Locking the user row here serialises
            // the two flows so exactly one wins. (logic-review R7-F1)
            $techStmt = $this->db->prepare('SELECT role, is_active FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
            $techStmt->execute(['id' => $technicianId]);
            $tech = $techStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($tech === null || (string) $tech['role'] !== 'technician' || (int) $tech['is_active'] !== 1) {
                throw new DomainException('ช่างที่เลือกไม่พร้อมใช้งาน (อาจถูกปิดบัญชีหรือเปลี่ยนบทบาท) กรุณาเลือกช่างคนอื่น');
            }

            $existingResponseDueAt = '';
            if ($isReassign) {
                // Refresh response_due_at within the locked row so the SLA reset uses the current target.
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
                // Reassigning a ticket invalidates the previous technician's first response;
                // reset the response SLA row so the new technician starts from pending state.
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

    public function acceptAssignedWork(int $ticketId, int $actorId, string $note, string $currentStatus): void
    {
        $acceptedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $this->lockTicketForTransition($ticketId, ['assigned'], 'approved', 'assigned_technician_id', $actorId);

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

    public function startAssignedWork(int $ticketId, int $actorId, string $note, string $currentStatus): void
    {
        $startedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            // multi-status lock → log from the LOCKED status, not the caller's pre-lock snapshot, so a concurrent
            // accept between the service read and this lock can't record a wrong from_status. (logic-review F5)
            $lockedStatus = $this->lockTicketForTransition($ticketId, ['assigned', 'accepted'], 'approved', 'assigned_technician_id', $actorId);

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

    public function resolveAssignedWork(int $ticketId, int $actorId, string $diagnosisSummary, string $resolutionSummary, int $laborMinutes, string $currentStatus): void
    {
        $resolvedAt = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            // multi-status lock → log from the LOCKED status, not the caller's pre-lock snapshot. (logic-review F5)
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

            // labor_minutes is intentionally NOT reset here: labor already spent on earlier cycles is real,
            // paid effort (as-reported — same principle as the frozen SLA/rating cycles below). The next
            // resolve ADDS its minutes on top, so a reopen never erases recorded labor from the reports.
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

            // As-reported (F1 Phase 2): the previous cycle's rating stays (a re-rate APPENDS a new cycle), and
            // the previous cycle's SLA rows stay frozen — reopen APPENDS a fresh pending cycle instead of
            // resetting, so a past period's SLA verdict / CSAT is immutable.
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

    /**
     * Max id ของ ticket ที่ viewer มีสิทธิ์เห็น (ตาม visibility เดียวกับหน้าคิว) —
     * ใช้เป็น baseline ให้ live poll: ถ้ามี ticket ใหม่เข้ามา (id เพิ่ม) → โชว์ banner โหลดใหม่.
     */

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

    /** Seed work order (labor) — idempotent ผ่าน UNIQUE(ticket_id)/UNIQUE(work_order_no). ใช้ตอนโหลด demo data. */
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

    /** Seed SLA track (response/resolution) — idempotent ผ่าน UNIQUE(ticket_id, metric_type). ใช้ตอนโหลด demo data. */
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

    /** Seed activity log (เช่น ticket_resolved / ticket_reopened) — ใช้ตอนโหลด demo data. */
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
            // Releasing a connection-scoped lock must not hide the original operation result.
        }
    }

    /**
     * Lock the ticket row FOR UPDATE and verify it is in an allowed state, then RETURN its real locked status.
     * Callers must branch on the returned value — never on a status read before the lock — so a concurrent
     * transition that lands the row on a different (still-allowed) status cannot drive stale branch logic
     * (e.g. reassign's SLA reset / audit from_status). The lock only guarantees the transition is valid; the
     * returned status is what makes the *consequences* match reality.
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
        // As-reported (F1 Phase 2): one rating per lifecycle CYCLE. A re-rate after a reopen APPENDS a new
        // cycle's rating (its own created_at) instead of overwriting the previous cycle's — so a past period's
        // CSAT (windowed on created_at) is immutable. Idempotent WITHIN a cycle (re-confirming the same cycle
        // updates that cycle's row rather than violating UNIQUE(ticket_id, cycle)).
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
        // As-reported (F1 Phase 2): SLA tracks are per-cycle; a resolve concludes the LATEST cycle's verdict.
        // An earlier cycle's met/breached record stays frozen. RISK MAP: idempotent from the achieved timestamp.
        $cycle = $this->latestSlaCycle($ticketId, $metricType);
        if ($cycle === 0) {
            return; // no SLA row (should not happen in the real flow — every ticket seeds cycle 1 at creation)
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
     * Reset the LATEST cycle's SLA row to pending (WITHIN-cycle — used by a reassign, which invalidates the
     * previous technician's response but does NOT start a new lifecycle cycle; a reopen APPENDS a cycle instead).
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

    /** Highest SLA cycle for (ticket, metric); 0 if none. */
    private function latestSlaCycle(int $ticketId, string $metricType): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(cycle), 0) FROM ticket_sla_tracks WHERE ticket_id = :ticket_id AND metric_type = :metric_type'
        );
        $stmt->execute(['ticket_id' => $ticketId, 'metric_type' => $metricType]);

        return (int) $stmt->fetchColumn();
    }

    /** The ticket's current lifecycle cycle = highest SLA cycle across metrics (>=1). */
    private function currentTicketCycle(int $ticketId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(cycle), 1) FROM ticket_sla_tracks WHERE ticket_id = :ticket_id');
        $stmt->execute(['ticket_id' => $ticketId]);

        return max(1, (int) $stmt->fetchColumn());
    }

    /** Append a fresh (pending) SLA row for a new lifecycle cycle — as-reported: never overwrites a past cycle. */
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
