<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Role;

/**
 * Pure ticket permission/transition predicates (ticket + viewer arrays → bool), shared by the
 * detail-display flow (TicketService) and the mutation flow (TicketWorkflowService). No DB, no state
 * — single source for "who may do what, from which status", so the two flows can't drift.
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

        // accepted/in_progress included so a manager/admin can REASSIGN work whose technician became
        // unavailable (sick leave / resignation) — otherwise the ticket is stuck forever: only the assigned
        // technician could resolve it and the requester can no longer cancel
        // (business-confirmed). A mid-work reassign requires a reason (enforced in TicketWorkflowService).
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
        // Only a resolved (awaiting confirmation) ticket can be sent back for rework. A completed ticket is
        // final — an unhappy requester opens a NEW ticket via duplicate (canDuplicateTicket), not a reopen.
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
