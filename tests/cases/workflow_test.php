<?php
declare(strict_types=1);

use App\Services\TicketService;

// Integration tests for the ticket lifecycle against the test DB. Each test inserts a fresh
// ticket in a known state, drives the real service (which commits its own transactions), asserts
// the resulting DB state, then deletes the ticket (child rows cascade) + its notifications.
// This is the regression net to have in place BEFORE splitting TicketService/TicketRepository.

function wf_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function wf_service(): TicketService
{
    return tvm_container()->get(TicketService::class);
}

function wf_insert_ticket(array $overrides): int
{
    $cols = array_merge([
        'ticket_no' => 'WFTEST-' . bin2hex(random_bytes(4)),
        'title' => 'workflow test',
        'description' => 'x',
        'requester_id' => 1,
        'location_id' => 1,
        'ticket_category_id' => 1,
        'priority_id' => 1,
        'status' => 'submitted',
        'approval_status' => 'pending',
    ], $overrides);
    $fields = implode(', ', array_keys($cols));
    $placeholders = implode(', ', array_map(static fn (string $k): string => ":$k", array_keys($cols)));
    wf_pdo()->prepare("INSERT INTO tickets ($fields) VALUES ($placeholders)")->execute($cols);

    return (int) wf_pdo()->lastInsertId();
}

function wf_state(int $ticketId): array
{
    return wf_pdo()->query("SELECT status, approval_status, assigned_technician_id FROM tickets WHERE id = $ticketId")->fetch() ?: [];
}

function wf_cleanup(int $ticketId): void
{
    $pdo = wf_pdo();
    $pdo->prepare("DELETE FROM notification_recipients WHERE notification_id IN (SELECT id FROM notifications WHERE related_type='ticket' AND related_id=?)")->execute([$ticketId]);
    $pdo->prepare("DELETE FROM notifications WHERE related_type='ticket' AND related_id=?")->execute([$ticketId]);
    $pdo->prepare('DELETE FROM tickets WHERE id=?')->execute([$ticketId]);   // children cascade
}

test('workflow: approveTicket pending_approval → approved + activity log', function (): void {
    $id = wf_insert_ticket(['status' => 'pending_approval', 'approval_status' => 'pending', 'requester_id' => 1]);
    try {
        wf_service()->approveTicket($id, ['id' => 4, 'role' => 'admin'], ['note' => 'ok']);
        $t = wf_state($id);
        assert_same('approved', $t['status']);
        assert_same('approved', $t['approval_status']);
        $logs = (int) wf_pdo()->query("SELECT COUNT(*) FROM ticket_activity_logs WHERE ticket_id=$id AND action='ticket_approved'")->fetchColumn();
        assert_same(1, $logs, 'approval logged once');
    } finally {
        wf_cleanup($id);
    }
});

test('workflow: separation of duties — manager cannot approve own request', function (): void {
    $id = wf_insert_ticket(['status' => 'pending_approval', 'approval_status' => 'pending', 'requester_id' => 2]);
    try {
        $threw = false;
        try {
            wf_service()->approveTicket($id, ['id' => 2, 'role' => 'manager'], ['note' => '']);
        } catch (DomainException $e) {
            $threw = true;
        }
        assert_true($threw, 'manager approving own request must throw');
        assert_same('pending_approval', wf_state($id)['status'], 'status unchanged after guard');
    } finally {
        wf_cleanup($id);
    }
});

test('workflow: rejectTicket pending_approval → rejected', function (): void {
    $id = wf_insert_ticket(['status' => 'pending_approval', 'approval_status' => 'pending', 'requester_id' => 1]);
    try {
        wf_service()->rejectTicket($id, ['id' => 4, 'role' => 'admin'], ['note' => 'ไม่อนุมัติ']);
        assert_same('rejected', wf_state($id)['status']);
    } finally {
        wf_cleanup($id);
    }
});

test('workflow: assignTechnician approved → assigned to technician', function (): void {
    $id = wf_insert_ticket(['status' => 'approved', 'approval_status' => 'approved']);
    try {
        wf_service()->assignTechnician($id, ['id' => 4, 'role' => 'admin'], ['technician_id' => 3, 'instructions' => 'go']);
        $t = wf_state($id);
        assert_same('assigned', $t['status']);
        assert_same(3, (int) $t['assigned_technician_id']);
    } finally {
        wf_cleanup($id);
    }
});

test('workflow: full happy path approve → assign → accept → start → resolve', function (): void {
    $id = wf_insert_ticket([
        'status' => 'pending_approval', 'approval_status' => 'pending', 'requester_id' => 1,
        'response_due_at' => date('Y-m-d H:i:s', time() + 3600),
        'resolution_due_at' => date('Y-m-d H:i:s', time() + 7200),
    ]);
    try {
        $admin = ['id' => 4, 'role' => 'admin'];
        $tech = ['id' => 3, 'role' => 'technician'];
        wf_service()->approveTicket($id, $admin, ['note' => '']);
        wf_service()->assignTechnician($id, $admin, ['technician_id' => 3, 'instructions' => '']);
        wf_service()->acceptAssignedWork($id, $tech, ['accept_note' => '']);
        assert_same('accepted', wf_state($id)['status']);
        wf_service()->startAssignedWork($id, $tech, ['start_note' => '']);
        assert_same('in_progress', wf_state($id)['status']);
        wf_service()->resolveAssignedWork($id, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '30']);
        assert_same('resolved', wf_state($id)['status']);
    } finally {
        wf_cleanup($id);
    }
});

test('workflow: cancelTicket by requester (pending_approval → cancelled)', function (): void {
    // Cancel is a requester action (requireRequesterTicket) with a required cancel_note.
    $id = wf_insert_ticket(['status' => 'pending_approval', 'approval_status' => 'pending', 'requester_id' => 1]);
    try {
        wf_service()->cancelTicket($id, ['id' => 1, 'role' => 'requester'], ['cancel_note' => 'ยกเลิกเพราะแก้เองแล้ว']);
        assert_same('cancelled', wf_state($id)['status']);
    } finally {
        wf_cleanup($id);
    }
});
