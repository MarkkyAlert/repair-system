<?php
declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// data-integrity invariant (integrity audit 2026-07-25): a non-terminal ticket must NEVER be assigned to a
// deactivated technician. A technician can be deactivated while holding a RESOLVED-but-unconfirmed ticket (the
// deactivate guard hasOpenTechnicianWork only counts assigned/accepted/in_progress, not resolved). If the requester
// then REOPENS it, reopen used to bind the live ticket back to the now-inactive tech — work stuck with a "ghost",
// and technician-workload analytics attribute active work to a deactivated user. reopenTicket now routes such a
// ticket to 'approved' (รอมอบหมาย) with no technician, so a manager reassigns an active one (assignTechnician only
// accepts active techs). An active technician on reopen is unchanged (keeps the same tech).

function rit_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function rit_seed_tech(): int
{
    rit_pdo()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, "x", "Ghost Tech", "technician", 1, NOW(), NOW())')
        ->execute(['ghost_' . bin2hex(random_bytes(4)), 'ghost_' . bin2hex(random_bytes(4)) . '@x.test']);

    return (int) rit_pdo()->lastInsertId();
}

/** Drive a ticket through the REAL workflow to 'resolved', assigned to $techId. Returns the ticket id. */
function rit_make_resolved(int $techId): int
{
    $tickets = tvm_container()->get(TicketService::class);
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $admin = ['id' => 4, 'role' => 'admin'];
    $req = ['id' => 1, 'role' => 'requester'];
    $tech = ['id' => $techId, 'role' => 'technician'];

    $id = $tickets->createTicket($req, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => 'reopen-inactive-tech',
        'description' => 'x',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);
    $wf->approveTicket($id, $admin, ['note' => '']);
    $wf->assignTechnician($id, $admin, ['technician_id' => $techId, 'instructions' => '']);
    $wf->acceptAssignedWork($id, $tech, ['accept_note' => '']);
    $wf->startAssignedWork($id, $tech, ['start_note' => '']);
    $wf->resolveAssignedWork($id, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '10']);

    return $id;
}

test('reopen(integrity): a resolved ticket whose technician was deactivated reopens to รอมอบหมาย, never bound to a ghost', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $req = ['id' => 1, 'role' => 'requester'];
    $ghost = rit_seed_tech();
    $id = rit_make_resolved($ghost);

    try {
        $priorAssignedAt = (string) rit_pdo()->query(
            "SELECT assigned_at FROM work_orders WHERE ticket_id = $id"
        )->fetchColumn();
        rit_pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$ghost]); // the technician leaves
        $wf->reopenTicket($id, $req, ['reopen_note' => 'ยังไม่หาย']);

        $row = rit_pdo()->query("SELECT status, assigned_technician_id FROM tickets WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        assert_same('approved', (string) $row['status'], 'reopened to รอมอบหมาย (approved), not re-assigned to the departed tech');
        assert_true($row['assigned_technician_id'] === null, 'no technician is bound to the reopened live ticket');

        // the invariant: no non-terminal ticket bound to an inactive technician
        $viol = (int) rit_pdo()->query("SELECT COUNT(*) FROM tickets t JOIN users u ON u.id = t.assigned_technician_id WHERE u.is_active = 0 AND t.status NOT IN ('completed','rejected','cancelled','closed') AND t.id = $id")->fetchColumn();
        assert_same(0, $viol, 'invariant holds: the reopened ticket is not bound to a deactivated technician');

        // the departed tech's labor from the prior cycle is preserved (as-reported)
        assert_same(10, (int) rit_pdo()->query("SELECT labor_minutes FROM work_orders WHERE ticket_id = $id")->fetchColumn(), 'prior labor is preserved through the unassigning reopen');

        $workOrder = rit_pdo()->query(
            "SELECT status, assigned_at FROM work_orders WHERE ticket_id = $id"
        )->fetch(PDO::FETCH_ASSOC);
        assert_same('cancelled', (string) $workOrder['status'], 'the stale work order is closed while the ticket waits for reassignment');
        assert_same($priorAssignedAt, (string) $workOrder['assigned_at'], 'historical assignment time is not rewritten as if the departed tech were assigned again');

        $reopenLogStatus = (string) rit_pdo()->query(
            "SELECT to_status FROM ticket_activity_logs
             WHERE ticket_id = $id AND action = 'ticket_reopened'
             ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        assert_same('approved', $reopenLogStatus, 'history records the same waiting-for-assignment status shown by the ticket');

        // positive path: a manager can reassign an ACTIVE technician and work resumes
        $wf->assignTechnician($id, ['id' => 4, 'role' => 'admin'], ['technician_id' => 3, 'instructions' => '']);
        $after = rit_pdo()->query("SELECT status, assigned_technician_id FROM tickets WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        assert_same('assigned', (string) $after['status'], 'a manager reassigns an active tech → assigned');
        assert_same(3, (int) $after['assigned_technician_id'], 'the active technician is now assigned');
    } finally {
        rit_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]); // cascades work_orders / sla / logs
        rit_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$ghost]);
    }
});

test('reopen(integrity): reopening with an ACTIVE technician keeps the same tech (unchanged behavior)', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $req = ['id' => 1, 'role' => 'requester'];
    $id = rit_make_resolved(3); // seed technician 3 stays active

    try {
        $wf->reopenTicket($id, $req, ['reopen_note' => 'ยังไม่หาย']);
        $row = rit_pdo()->query("SELECT status, assigned_technician_id FROM tickets WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        assert_same('assigned', (string) $row['status'], 'an active tech → reopen keeps it assigned');
        assert_same(3, (int) $row['assigned_technician_id'], 'the same active technician stays assigned');
        assert_same(
            'assigned',
            (string) rit_pdo()->query("SELECT status FROM work_orders WHERE ticket_id = $id")->fetchColumn(),
            'the active technician work order is reopened for work'
        );
        assert_same(
            'assigned',
            (string) rit_pdo()->query(
                "SELECT to_status FROM ticket_activity_logs
                 WHERE ticket_id = $id AND action = 'ticket_reopened'
                 ORDER BY id DESC LIMIT 1"
            )->fetchColumn(),
            'history keeps the normal active-technician destination'
        );
    } finally {
        rit_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
    }
});
