<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// Logic-review L-01 (High, TOCTOU): AuthMiddleware checks is_active only at the START of a request. A manager's
// reject/assign request could pass that gate, then an admin deactivates the account before the repository
// transaction writes — and reject/assign (unlike approve, which already rechecks the manager under FOR UPDATE)
// would still write, recording a deactivated account as the actor. TicketRepository now locks the actor's users
// row FOR UPDATE and re-verifies role+active BEFORE touching the ticket (users → tickets lock order matches
// AdminRepository::updateUser, so a concurrent deactivation serialises and wins). These prove a deactivated actor
// is refused with NO state change, an active actor still works, and the manager visibility/action policy is intact.

function mdt_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function mdt_seed_manager(int $isActive = 1): int
{
    $sfx = bin2hex(random_bytes(4));
    mdt_pdo()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, "x", "MDT Manager", "manager", ?, NOW(), NOW())'
    )->execute(["mdtmgr_$sfx", "mdtmgr_$sfx@example.com", $isActive]);

    return (int) mdt_pdo()->lastInsertId();
}

/** A fresh ticket at pending_approval, created through the real flow by requester #1. */
function mdt_seed_pending_ticket(): int
{
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();

    return tvm_container()->get(TicketService::class)->createTicket(
        ['id' => 1, 'role' => 'requester'],
        [
            'submission_token' => bin2hex(random_bytes(32)),
            'title' => 'mdt toctou probe',
            'description' => 'x',
            'priority_id' => (int) $ref['priorities'][0]['id'],
            'ticket_category_id' => (int) $ref['categories'][0]['id'],
            'location_id' => (int) $ref['locations'][0]['id'],
            'impact_level' => 'medium',
            'urgency_level' => 'medium',
        ],
        []
    );
}

test('workflow(L-01): a manager deactivated mid-request cannot REJECT, and the ticket is untouched', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $managerId = mdt_seed_manager(1);
    $ticketId = mdt_seed_pending_ticket();

    try {
        // the request passed AuthMiddleware while active; the admin deactivates the account before the write
        mdt_pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$managerId]);

        $blocked = false;
        try {
            $wf->rejectTicket($ticketId, ['id' => $managerId, 'role' => 'manager'], ['note' => 'stale reject']);
        } catch (DomainException $e) {
            $blocked = str_contains($e->getMessage(), 'บัญชีของคุณไม่พร้อมใช้งาน');
        }
        assert_true($blocked, 'the repository rechecks the actor under lock and refuses a deactivated manager');

        $row = mdt_pdo()->query("SELECT status, approval_status FROM tickets WHERE id = $ticketId")->fetch(PDO::FETCH_ASSOC);
        assert_same('pending_approval', (string) $row['status'], 'the ticket status was not changed');
        assert_same('pending', (string) $row['approval_status'], 'the approval verdict was rolled back');
        assert_same(
            0,
            (int) mdt_pdo()->query("SELECT COUNT(*) FROM ticket_activity_logs WHERE ticket_id = $ticketId AND action = 'ticket_rejected'")->fetchColumn(),
            'no rejection was recorded against the deactivated account'
        );
        assert_same(
            0,
            (int) mdt_pdo()->query("SELECT COUNT(*) FROM ticket_approvals WHERE ticket_id = $ticketId AND action = 'rejected'")->fetchColumn(),
            'no rejection decision row leaked'
        );
    } finally {
        mdt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        mdt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$managerId]);
    }
});

test('workflow(L-01): a manager deactivated mid-request cannot ASSIGN, and no work order / SLA mutation happens', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $managerId = mdt_seed_manager(1);
    $ticketId = mdt_seed_pending_ticket();

    try {
        // approve it first (active admin) so it is ready to assign, then deactivate the manager mid-request
        $wf->approveTicket($ticketId, ['id' => 4, 'role' => 'admin'], ['note' => '']);
        mdt_pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$managerId]);

        $blocked = false;
        try {
            $wf->assignTechnician($ticketId, ['id' => $managerId, 'role' => 'manager'], ['technician_id' => 3, 'instructions' => '']);
        } catch (DomainException $e) {
            $blocked = str_contains($e->getMessage(), 'บัญชีของคุณไม่พร้อมใช้งาน');
        }
        assert_true($blocked, 'a deactivated manager cannot assign a technician');

        $row = mdt_pdo()->query("SELECT status, assigned_technician_id FROM tickets WHERE id = $ticketId")->fetch(PDO::FETCH_ASSOC);
        assert_same('approved', (string) $row['status'], 'the ticket stayed approved — it was not moved to assigned');
        assert_true($row['assigned_technician_id'] === null, 'no technician was attached');
        assert_same(
            0,
            (int) mdt_pdo()->query("SELECT COUNT(*) FROM work_orders WHERE ticket_id = $ticketId")->fetchColumn(),
            'no work order was created by the refused assignment'
        );
    } finally {
        mdt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        mdt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$managerId]);
    }
});

test('workflow(L-01): an ACTIVE manager still rejects and assigns normally (no over-correction)', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);

    // reject path
    $managerA = mdt_seed_manager(1);
    $rejectTicket = mdt_seed_pending_ticket();
    try {
        $wf->rejectTicket($rejectTicket, ['id' => $managerA, 'role' => 'manager'], ['note' => 'valid reason']);
        assert_same('rejected', (string) mdt_pdo()->query("SELECT status FROM tickets WHERE id = $rejectTicket")->fetchColumn(), 'an active manager rejects normally');
    } finally {
        mdt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$rejectTicket]);
        mdt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$managerA]);
    }

    // assign path
    $managerB = mdt_seed_manager(1);
    $assignTicket = mdt_seed_pending_ticket();
    try {
        $wf->approveTicket($assignTicket, ['id' => 4, 'role' => 'admin'], ['note' => '']);
        $wf->assignTechnician($assignTicket, ['id' => $managerB, 'role' => 'manager'], ['technician_id' => 3, 'instructions' => '']);
        $row = mdt_pdo()->query("SELECT status, assigned_technician_id FROM tickets WHERE id = $assignTicket")->fetch(PDO::FETCH_ASSOC);
        assert_same('assigned', (string) $row['status'], 'an active manager assigns normally');
        assert_same(3, (int) $row['assigned_technician_id'], 'the technician is attached');
        assert_true(
            (int) mdt_pdo()->query("SELECT COUNT(*) FROM work_orders WHERE ticket_id = $assignTicket")->fetchColumn() === 1,
            'a work order was created on the successful assign'
        );
    } finally {
        mdt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$assignTicket]);
        mdt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$managerB]);
    }
});

test('workflow(L-01): a manager demoted to requester mid-request is refused too (role, not just active)', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $managerId = mdt_seed_manager(1);
    $ticketId = mdt_seed_pending_ticket();

    try {
        // still active, but no longer a manager — the actor recheck must reject on role as well
        mdt_pdo()->prepare('UPDATE users SET role = "requester" WHERE id = ?')->execute([$managerId]);

        $blocked = false;
        try {
            $wf->rejectTicket($ticketId, ['id' => $managerId, 'role' => 'manager'], ['note' => 'stale']);
        } catch (DomainException $e) {
            $blocked = str_contains($e->getMessage(), 'บัญชีของคุณไม่พร้อมใช้งาน');
        }
        assert_true($blocked, 'a demoted (active but no-longer-manager) account cannot reject');
        assert_same('pending_approval', (string) mdt_pdo()->query("SELECT status FROM tickets WHERE id = $ticketId")->fetchColumn(), 'ticket untouched');
    } finally {
        mdt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        mdt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$managerId]);
    }
});

// Owner-confirmed policy (Codex asked): a manager SEES the whole organisation's tickets (system-wide overview),
// but may only ACT on the central queue or the ones they own. This locks that current boundary so a future change
// can't silently narrow visibility or widen a manager's action scope onto another manager's owned ticket.
test('policy(manager): sees any ticket, but cannot run workflow on another manager\'s owned ticket', function (): void {
    $reads = tvm_container()->get(TicketReadRepository::class);
    $policy = tvm_container()->get(App\Services\TicketPolicy::class);
    $managerA = mdt_seed_manager(1);
    $managerB = mdt_seed_manager(1);
    $ticketId = mdt_seed_pending_ticket();

    try {
        // give the ticket an owner: manager B
        mdt_pdo()->prepare('UPDATE tickets SET assigned_manager_id = ?, approval_status = "approved", status = "approved" WHERE id = ?')
            ->execute([$managerB, $ticketId]);

        $viewerA = ['id' => $managerA, 'role' => 'manager'];

        // visibility: manager A can SEE manager B's owned ticket (system-wide overview is intended)
        $visible = $reads->findVisibleTicketById($ticketId, $viewerA);
        assert_true($visible !== null, 'a manager can view any ticket in the organisation, not only their own');

        // action scope: manager A may NOT manage a ticket owned by manager B
        assert_false($policy->canManageWorkflow($visible, $viewerA), 'a manager cannot act on another manager\'s owned ticket');

        // but the owner (manager B) can, and an unowned/central-queue ticket is open to any manager
        assert_true($policy->canManageWorkflow($visible, ['id' => $managerB, 'role' => 'manager']), 'the owning manager can act');
        $visible['assigned_manager_id'] = 0;
        assert_true($policy->canManageWorkflow($visible, $viewerA), 'an unowned central-queue ticket is actionable by any manager');
    } finally {
        mdt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        mdt_pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$managerA, $managerB]);
    }
});

// L-01 family completeness (owner-confirmed principle: deactivation withdraws trust and must win for EVERY
// ticket-writing action, admin included). Beyond approve/reject/assign, these lock the actor under FOR UPDATE
// too: admin approve (managerId=null path), admin close-on-behalf (H-3), requester reopen/cancel, technician
// accept/start/resolve. Each proves a deactivated actor is refused with no state change.

function mdt_seed_user(string $role): int
{
    $sfx = bin2hex(random_bytes(4));
    mdt_pdo()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, "x", "MDT actor", ?, 1, NOW(), NOW())'
    )->execute(["mdtu_$sfx", "mdtu_$sfx@example.com", $role]);

    return (int) mdt_pdo()->lastInsertId();
}

/** Drive a ticket by $requesterId through the real flow to a target state, using admin #4 to approve/assign. */
function mdt_drive(int $requesterId, int $techId, string $stopAt): int
{
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $tech = ['id' => $techId, 'role' => 'technician'];
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $id = tvm_container()->get(TicketService::class)->createTicket(
        ['id' => $requesterId, 'role' => 'requester'],
        [
            'submission_token' => bin2hex(random_bytes(32)), 'title' => 'mdt drive', 'description' => 'x',
            'priority_id' => (int) $ref['priorities'][0]['id'], 'ticket_category_id' => (int) $ref['categories'][0]['id'],
            'location_id' => (int) $ref['locations'][0]['id'], 'impact_level' => 'medium', 'urgency_level' => 'medium',
        ],
        []
    );
    if ($stopAt === 'pending_approval') {
        return $id;
    }
    $wf->approveTicket($id, $admin, ['note' => '']);
    $wf->assignTechnician($id, $admin, ['technician_id' => $techId, 'instructions' => '']);
    $wf->acceptAssignedWork($id, $tech, ['accept_note' => '']);
    if ($stopAt === 'accepted') {
        return $id;
    }
    $wf->startAssignedWork($id, $tech, ['start_note' => '']);
    $wf->resolveAssignedWork($id, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '10']);

    return $id; // resolved
}

test('workflow(L-01 family): a deactivated ADMIN cannot approve (managerId=null path)', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $adminId = mdt_seed_user('admin');
    $ticketId = mdt_seed_pending_ticket();
    try {
        mdt_pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$adminId]);
        $blocked = false;
        try {
            $wf->approveTicket($ticketId, ['id' => $adminId, 'role' => 'admin'], ['note' => '']);
        } catch (DomainException $e) {
            $blocked = str_contains($e->getMessage(), 'บัญชีของคุณไม่พร้อมใช้งาน');
        }
        assert_true($blocked, 'a deactivated admin approver is refused — "admin is trusted" does not survive deactivation');
        $row = mdt_pdo()->query("SELECT status, approval_status FROM tickets WHERE id = $ticketId")->fetch(PDO::FETCH_ASSOC);
        assert_same('pending_approval', (string) $row['status'], 'ticket not approved');
        assert_same('pending', (string) $row['approval_status'], 'approval still pending');
    } finally {
        mdt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        mdt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$adminId]);
    }
});

test('workflow(L-01 family): a deactivated admin cannot CLOSE ON BEHALF (H-3), ticket stays resolved', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $adminId = mdt_seed_user('admin');
    $ticketId = mdt_drive(1, 3, 'resolved');
    try {
        mdt_pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$adminId]);
        $blocked = false;
        try {
            $wf->completeResolvedTicket($ticketId, ['id' => $adminId, 'role' => 'admin'], ['closure_note' => 'requester left']);
        } catch (DomainException $e) {
            $blocked = str_contains($e->getMessage(), 'บัญชีของคุณไม่พร้อมใช้งาน');
        }
        assert_true($blocked, 'a deactivated admin cannot close a ticket on behalf of the requester');
        assert_same('resolved', (string) mdt_pdo()->query("SELECT status FROM tickets WHERE id = $ticketId")->fetchColumn(), 'ticket stays resolved');
    } finally {
        mdt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        mdt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$adminId]);
    }
});

test('workflow(L-01 family): a deactivated REQUESTER cannot reopen or cancel their own ticket', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);

    // reopen (from resolved)
    $reqA = mdt_seed_user('requester');
    $resolved = mdt_drive($reqA, 3, 'resolved');
    try {
        mdt_pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$reqA]);
        $blocked = false;
        try {
            $wf->reopenTicket($resolved, ['id' => $reqA, 'role' => 'requester'], ['reopen_note' => 'still broken']);
        } catch (DomainException $e) {
            $blocked = str_contains($e->getMessage(), 'บัญชีของคุณไม่พร้อมใช้งาน');
        }
        assert_true($blocked, 'a deactivated requester cannot reopen');
        assert_same('resolved', (string) mdt_pdo()->query("SELECT status FROM tickets WHERE id = $resolved")->fetchColumn(), 'ticket stays resolved');
    } finally {
        mdt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$resolved]);
        mdt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$reqA]);
    }

    // cancel (from pending_approval)
    $reqB = mdt_seed_user('requester');
    $pending = mdt_drive($reqB, 3, 'pending_approval');
    try {
        mdt_pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$reqB]);
        $blocked = false;
        try {
            $wf->cancelTicket($pending, ['id' => $reqB, 'role' => 'requester'], ['cancel_note' => 'never mind']);
        } catch (DomainException $e) {
            $blocked = str_contains($e->getMessage(), 'บัญชีของคุณไม่พร้อมใช้งาน');
        }
        assert_true($blocked, 'a deactivated requester cannot cancel');
        assert_same('pending_approval', (string) mdt_pdo()->query("SELECT status FROM tickets WHERE id = $pending")->fetchColumn(), 'ticket not cancelled');
    } finally {
        mdt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$pending]);
        mdt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$reqB]);
    }
});

test('workflow(L-01 family): a deactivated TECHNICIAN cannot resolve their accepted work, ticket unchanged', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $techId = mdt_seed_user('technician');
    $ticketId = mdt_drive(1, $techId, 'accepted');
    try {
        mdt_pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$techId]);
        $blocked = false;
        try {
            $wf->resolveAssignedWork($ticketId, ['id' => $techId, 'role' => 'technician'], ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '5']);
        } catch (DomainException $e) {
            $blocked = str_contains($e->getMessage(), 'บัญชีของคุณไม่พร้อมใช้งาน');
        }
        assert_true($blocked, 'a deactivated technician cannot resolve their assigned work');
        assert_same('accepted', (string) mdt_pdo()->query("SELECT status FROM tickets WHERE id = $ticketId")->fetchColumn(), 'ticket stays accepted, not resolved');
    } finally {
        mdt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        mdt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$techId]);
    }
});

test('workflow(L-01 family): active throwaway actors still complete every path (no over-correction)', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);

    // active throwaway admin approves + closes on behalf
    $adminId = mdt_seed_user('admin');
    $ticketId = mdt_drive(1, 3, 'resolved');
    try {
        $wf->completeResolvedTicket($ticketId, ['id' => $adminId, 'role' => 'admin'], ['closure_note' => 'closing for absent requester']);
        assert_same('completed', (string) mdt_pdo()->query("SELECT status FROM tickets WHERE id = $ticketId")->fetchColumn(), 'an ACTIVE admin can still close on behalf');
    } finally {
        mdt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        mdt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$adminId]);
    }

    // active requester reopens their own resolved ticket
    $reqA = mdt_seed_user('requester');
    $resolved = mdt_drive($reqA, 3, 'resolved');
    try {
        $wf->reopenTicket($resolved, ['id' => $reqA, 'role' => 'requester'], ['reopen_note' => 'still broken']);
        assert_true(
            in_array((string) mdt_pdo()->query("SELECT status FROM tickets WHERE id = $resolved")->fetchColumn(), ['assigned', 'approved'], true),
            'an ACTIVE requester can still reopen'
        );
    } finally {
        mdt_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$resolved]);
        mdt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$reqA]);
    }
});
