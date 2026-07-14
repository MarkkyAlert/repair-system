<?php
declare(strict_types=1);

use App\Services\TicketWorkflowService;

// Integration tests for the ticket lifecycle against the test DB. Each test inserts a fresh
// ticket in a known state, drives the real service (which commits its own transactions), asserts
// the resulting DB state, then deletes the ticket (child rows cascade) + its notifications.
// This is the regression net to have in place BEFORE splitting TicketService/TicketRepository.

function wf_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function wf_service(): TicketWorkflowService
{
    return tvm_container()->get(TicketWorkflowService::class);
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

/**
 * Assert a guard rejects an action: it must throw DomainException AND leave the ticket's
 * status/approval_status/assigned_technician_id untouched — proving the guard fires before any
 * (even partial) DB mutation. Captures the row before, runs the action, then re-reads and compares.
 */
function wf_reject(callable $action, int $ticketId, string $context): void
{
    $before = wf_state($ticketId);
    $threw = false;
    try {
        $action();
    } catch (DomainException) {
        $threw = true;
    }
    assert_true($threw, "$context — must throw DomainException");
    assert_same($before, wf_state($ticketId), "$context — DB state must be unchanged after rejection");
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

test('workflow: separation of duties — an admin CAN approve their own request (SoD exemption)', function (): void {
    // F7 (confirmed business rule): unlike a manager, an admin is exempt from the self-approval block — the
    // deliberate fallback so an org with a single manager (who filed the request) is not deadlocked.
    $id = wf_insert_ticket(['status' => 'pending_approval', 'approval_status' => 'pending', 'requester_id' => 4]);
    try {
        wf_service()->approveTicket($id, ['id' => 4, 'role' => 'admin'], ['note' => 'self-approve as admin']);
        $t = wf_state($id);
        assert_same('approved', $t['status'], 'admin self-approval succeeds (status → approved)');
        assert_same('approved', $t['approval_status'], 'admin self-approval succeeds (approval_status → approved)');
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

// ── negative path: each state guard rejects an out-of-order transition (throw + no state change) ──
// Viewers are chosen so the role/ownership guard passes and the STATUS guard is what rejects:
// admin (id 4) manages any ticket; technician (id 3) owns tickets where assigned_technician_id=3;
// requester (id 1) owns tickets where requester_id=1. Valid inputs are passed so the rejection is
// unambiguously the state guard, not input validation.

test('workflow(neg): approveTicket rejects a ticket that is not pending_approval', function (): void {
    $id = wf_insert_ticket(['status' => 'approved', 'approval_status' => 'approved', 'requester_id' => 1]);
    try {
        wf_reject(fn () => wf_service()->approveTicket($id, ['id' => 4, 'role' => 'admin'], ['note' => 'x']), $id, 'approve non-pending_approval');
    } finally {
        wf_cleanup($id);
    }
});

test('workflow(neg): assignTechnician rejects a ticket that is not approved', function (): void {
    $id = wf_insert_ticket(['status' => 'pending_approval', 'approval_status' => 'pending']);
    try {
        wf_reject(fn () => wf_service()->assignTechnician($id, ['id' => 4, 'role' => 'admin'], ['technician_id' => 3, 'instructions' => 'go']), $id, 'assign non-approved');
    } finally {
        wf_cleanup($id);
    }
});

test('workflow(neg): acceptAssignedWork rejects a ticket that is not assigned', function (): void {
    // technician is on the ticket (so canTechnicianWork passes) but it has not reached "assigned"
    $id = wf_insert_ticket(['status' => 'approved', 'approval_status' => 'approved', 'assigned_technician_id' => 3]);
    try {
        wf_reject(fn () => wf_service()->acceptAssignedWork($id, ['id' => 3, 'role' => 'technician'], ['accept_note' => '']), $id, 'accept non-assigned');
    } finally {
        wf_cleanup($id);
    }
});

test('workflow(neg): startAssignedWork rejects a ticket outside assigned/accepted', function (): void {
    // guard allows assigned OR accepted; "approved" (before assignment) must be rejected
    $id = wf_insert_ticket(['status' => 'approved', 'approval_status' => 'approved', 'assigned_technician_id' => 3]);
    try {
        wf_reject(fn () => wf_service()->startAssignedWork($id, ['id' => 3, 'role' => 'technician'], ['start_note' => '']), $id, 'start non-startable');
    } finally {
        wf_cleanup($id);
    }
});

test('workflow(neg): resolveAssignedWork rejects a ticket that is not in_progress/accepted', function (): void {
    // guard allows accepted OR in_progress; "assigned" (not yet accepted/started) must be rejected
    $id = wf_insert_ticket(['status' => 'assigned', 'approval_status' => 'approved', 'assigned_technician_id' => 3]);
    try {
        wf_reject(fn () => wf_service()->resolveAssignedWork($id, ['id' => 3, 'role' => 'technician'], ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '10']), $id, 'resolve non-in_progress');
    } finally {
        wf_cleanup($id);
    }
});

test('workflow(neg): completeResolvedTicket rejects a ticket that is not resolved', function (): void {
    $id = wf_insert_ticket(['status' => 'in_progress', 'approval_status' => 'approved', 'requester_id' => 1, 'assigned_technician_id' => 3]);
    try {
        wf_reject(fn () => wf_service()->completeResolvedTicket($id, ['id' => 1, 'role' => 'requester'], ['score' => 5, 'closure_note' => '', 'feedback' => '']), $id, 'complete non-resolved');
    } finally {
        wf_cleanup($id);
    }
});

test('workflow(neg): reopenTicket rejects a ticket that is not resolved', function (): void {
    $id = wf_insert_ticket(['status' => 'in_progress', 'approval_status' => 'approved', 'requester_id' => 1, 'assigned_technician_id' => 3]);
    try {
        wf_reject(fn () => wf_service()->reopenTicket($id, ['id' => 1, 'role' => 'requester'], ['reopen_note' => 'ทำใหม่']), $id, 'reopen non-resolved');
    } finally {
        wf_cleanup($id);
    }
});

test('workflow: a completed ticket can no longer be reopened (completed is final)', function (): void {
    // F6 business rule: completed is terminal for reopen — an unhappy requester opens a NEW ticket (duplicate).
    $id = wf_insert_ticket(['status' => 'completed', 'approval_status' => 'approved', 'requester_id' => 1, 'assigned_technician_id' => 3]);
    try {
        wf_reject(fn () => wf_service()->reopenTicket($id, ['id' => 1, 'role' => 'requester'], ['reopen_note' => 'ขอเปิดใหม่']), $id, 'reopen completed is blocked');
    } finally {
        wf_cleanup($id);
    }
});

test('workflow(neg): cancelTicket rejects a ticket in a terminal state', function (): void {
    $id = wf_insert_ticket(['status' => 'completed', 'approval_status' => 'approved', 'requester_id' => 1]);
    try {
        wf_reject(fn () => wf_service()->cancelTicket($id, ['id' => 1, 'role' => 'requester'], ['cancel_note' => 'x']), $id, 'cancel terminal');
    } finally {
        wf_cleanup($id);
    }
});

test('workflow: accept/start are optional shortcuts — start from assigned, resolve from accepted (business-confirmed)', function (): void {
    // Product-owner-confirmed: a technician may skip the explicit accept/start steps. Locks the shortcut so a
    // future tightening to a mandatory assigned→accepted→in_progress→resolved sequence is caught — the neg
    // tests only prove the OUTSIDE boundary, not that the skip itself succeeds.
    $wo = static function (int $ticketId): void {
        wf_pdo()->prepare("INSERT INTO work_orders (work_order_no, ticket_id, technician_id, assigned_by, status) VALUES (?, ?, 3, 4, 'assigned')")
            ->execute(['WFWO-' . bin2hex(random_bytes(4)), $ticketId]);
    };

    // start directly from assigned (skipping accept) → in_progress
    $s = wf_insert_ticket(['status' => 'assigned', 'approval_status' => 'approved', 'assigned_technician_id' => 3]);
    $wo($s);
    try {
        wf_service()->startAssignedWork($s, ['id' => 3, 'role' => 'technician'], ['start_note' => 'go']);
        assert_same('in_progress', wf_state($s)['status'], 'start from assigned (no explicit accept) → in_progress');
    } finally {
        wf_cleanup($s);
    }

    // resolve directly from accepted (skipping start) → resolved
    $r = wf_insert_ticket(['status' => 'accepted', 'approval_status' => 'approved', 'assigned_technician_id' => 3]);
    $wo($r);
    try {
        wf_service()->resolveAssignedWork($r, ['id' => 3, 'role' => 'technician'], ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => 10]);
        assert_same('resolved', wf_state($r)['status'], 'resolve from accepted (no explicit start) → resolved');
    } finally {
        wf_cleanup($r);
    }
});

test('workflow(neg): malformed numeric input (technician/labor/score "Njunk") is rejected without mutation (round F1)', function (): void {
    // (int)"3junk" === 3 etc. would silently pass. strict_int rejects it before any DB change.
    $a = wf_insert_ticket(['status' => 'approved', 'approval_status' => 'approved']);
    try {
        wf_reject(fn () => wf_service()->assignTechnician($a, ['id' => 1, 'role' => 'manager'], ['technician_id' => '3junk']), $a, 'assign technician 3junk');
    } finally {
        wf_cleanup($a);
    }

    $r = wf_insert_ticket(['status' => 'in_progress', 'approval_status' => 'approved', 'assigned_technician_id' => 3]);
    try {
        wf_reject(fn () => wf_service()->resolveAssignedWork($r, ['id' => 3, 'role' => 'technician'], ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '12junk']), $r, 'resolve labor 12junk');
    } finally {
        wf_cleanup($r);
    }

    $c = wf_insert_ticket(['status' => 'resolved', 'approval_status' => 'approved', 'requester_id' => 1, 'assigned_technician_id' => 3]);
    try {
        wf_reject(fn () => wf_service()->completeResolvedTicket($c, ['id' => 1, 'role' => 'requester'], ['score' => '5junk', 'closure_note' => 'x']), $c, 'complete score 5junk');
    } finally {
        wf_cleanup($c);
    }
});

test('workflow(neg): a requester cannot cancel once work has started — assigned/accepted/in_progress (business-confirmed rule)', function (): void {
    // Product-owner-confirmed: cancel is allowed ONLY in pending_approval/approved. Once a technician is
    // assigned and working, the requester can no longer yank the ticket. Locks that boundary so a later policy
    // change that re-allows late cancellation is caught (the existing neg test only covered a terminal state).
    foreach (['assigned', 'accepted', 'in_progress'] as $status) {
        $id = wf_insert_ticket(['status' => $status, 'approval_status' => 'approved', 'requester_id' => 1, 'assigned_technician_id' => 3]);
        try {
            wf_reject(
                fn () => wf_service()->cancelTicket($id, ['id' => 1, 'role' => 'requester'], ['cancel_note' => 'เปลี่ยนใจ']),
                $id,
                "cancel $status"
            );
        } finally {
            wf_cleanup($id);
        }
    }
});

// ── mid-work reassign (logic-review F2, business-confirmed) ──
// Before this rule, once a technician ACCEPTED a ticket no role (admin included) could move the work to
// another technician, and the requester could no longer cancel — a sick/resigned technician left the ticket
// stuck in accepted/in_progress forever. Manager/admin may now reassign mid-work, but must give a reason.

test('workflow: manager reassigns an in_progress ticket to another technician with a reason (F2)', function (): void {
    $rid = bin2hex(random_bytes(4));
    wf_pdo()->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, 'x', ?, 'technician', 1)")
        ->execute(["wf2_$rid", "wf2_$rid@example.com", "WF2 Tech $rid"]);
    $tech2 = (int) wf_pdo()->lastInsertId();
    $id = wf_insert_ticket(['status' => 'pending_approval', 'approval_status' => 'pending', 'requester_id' => 1]);

    try {
        $admin = ['id' => 4, 'role' => 'admin'];
        $tech = ['id' => 3, 'role' => 'technician'];
        wf_service()->approveTicket($id, $admin, ['note' => '']);
        wf_service()->assignTechnician($id, $admin, ['technician_id' => 3, 'instructions' => '']);
        wf_service()->acceptAssignedWork($id, $tech, ['accept_note' => '']);
        wf_service()->startAssignedWork($id, $tech, ['start_note' => '']);
        assert_same('in_progress', wf_state($id)['status']);

        wf_service()->assignTechnician($id, ['id' => 2, 'role' => 'manager'], ['technician_id' => $tech2, 'instructions' => 'ช่างเดิมลาป่วยยาว']);

        $t = wf_state($id);
        assert_same('assigned', $t['status'], 'mid-work reassign sends the ticket back to assigned for the new technician');
        assert_same($tech2, (int) $t['assigned_technician_id'], 'ticket now belongs to the new technician');
        $wo = wf_pdo()->query("SELECT technician_id, status, accepted_at, started_at FROM work_orders WHERE ticket_id=$id")->fetch();
        assert_same($tech2, (int) $wo['technician_id'], 'work order moved to the new technician');
        assert_same('assigned', (string) $wo['status'], 'work order back to assigned');
        assert_true($wo['accepted_at'] === null && $wo['started_at'] === null, 'work order accept/start reset for the new technician');
        $logged = (int) wf_pdo()->query("SELECT COUNT(*) FROM ticket_activity_logs WHERE ticket_id=$id AND action='technician_assigned' AND details LIKE '%ลาป่วยยาว%'")->fetchColumn();
        assert_same(1, $logged, 'the reassign reason is recorded in the activity log');
    } finally {
        wf_cleanup($id);
        wf_pdo()->prepare('DELETE FROM users WHERE id=?')->execute([$tech2]);
    }
});

test('workflow(neg): mid-work reassign without a reason is rejected — accepted and in_progress (F2)', function (): void {
    foreach (['accepted', 'in_progress'] as $status) {
        $id = wf_insert_ticket(['status' => $status, 'approval_status' => 'approved', 'requester_id' => 1, 'assigned_technician_id' => 3]);
        try {
            wf_reject(
                fn () => wf_service()->assignTechnician($id, ['id' => 4, 'role' => 'admin'], ['technician_id' => 3, 'instructions' => '']),
                $id,
                "reassign $status without reason"
            );
        } finally {
            wf_cleanup($id);
        }
    }
});

test('workflow(neg): reassign stays impossible once resolved/completed (F2 boundary)', function (): void {
    foreach (['resolved', 'completed'] as $status) {
        $id = wf_insert_ticket(['status' => $status, 'approval_status' => 'approved', 'requester_id' => 1, 'assigned_technician_id' => 3]);
        try {
            wf_reject(
                fn () => wf_service()->assignTechnician($id, ['id' => 4, 'role' => 'admin'], ['technician_id' => 3, 'instructions' => 'ย้ายงาน']),
                $id,
                "reassign $status"
            );
        } finally {
            wf_cleanup($id);
        }
    }
});

// ── happy path for the three transitions not yet covered ──

test('workflow: completeResolvedTicket resolved → completed + rating stored', function (): void {
    $id = wf_insert_ticket(['status' => 'resolved', 'approval_status' => 'approved', 'requester_id' => 1, 'assigned_technician_id' => 3]);
    try {
        wf_service()->completeResolvedTicket($id, ['id' => 1, 'role' => 'requester'], ['score' => 5, 'closure_note' => 'ขอบคุณ', 'feedback' => 'เยี่ยม']);
        assert_same('completed', wf_state($id)['status']);
        $rated = (int) wf_pdo()->query("SELECT COUNT(*) FROM ticket_ratings WHERE ticket_id=$id AND score=5")->fetchColumn();
        assert_same(1, $rated, 'satisfaction rating stored once');
    } finally {
        wf_cleanup($id);
    }
});

test('workflow: reopenTicket resolved → assigned + reopen logged', function (): void {
    // Drive the real lifecycle so a work_order exists (reopenTicket updates it and requires the row).
    $id = wf_insert_ticket([
        'status' => 'pending_approval', 'approval_status' => 'pending', 'requester_id' => 1,
        'requested_at' => date('Y-m-d H:i:s', time() - 7200),
        'response_due_at' => date('Y-m-d H:i:s', time() + 3600),
        'resolution_due_at' => date('Y-m-d H:i:s', time() + 7200),
    ]);
    try {
        $admin = ['id' => 4, 'role' => 'admin'];
        $tech = ['id' => 3, 'role' => 'technician'];
        $req = ['id' => 1, 'role' => 'requester'];
        wf_service()->approveTicket($id, $admin, ['note' => '']);
        wf_service()->assignTechnician($id, $admin, ['technician_id' => 3, 'instructions' => '']);
        wf_service()->acceptAssignedWork($id, $tech, ['accept_note' => '']);
        wf_service()->startAssignedWork($id, $tech, ['start_note' => '']);
        wf_service()->resolveAssignedWork($id, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '20']);
        assert_same('resolved', wf_state($id)['status']);

        wf_service()->reopenTicket($id, $req, ['reopen_note' => 'ยังไม่หาย ขอให้กลับมาดูอีกครั้ง']);
        assert_same('assigned', wf_state($id)['status'], 'reopen sends a resolved ticket back to assigned');
        $logs = (int) wf_pdo()->query("SELECT COUNT(*) FROM ticket_activity_logs WHERE ticket_id=$id AND action='ticket_reopened'")->fetchColumn();
        assert_same(1, $logs, 'reopen logged once');
    } finally {
        wf_cleanup($id);
    }
});

test('workflow: bulkApproveTickets approves eligible ones and reports the rest', function (): void {
    $ok1 = wf_insert_ticket(['status' => 'pending_approval', 'approval_status' => 'pending', 'requester_id' => 1]);
    $ok2 = wf_insert_ticket(['status' => 'pending_approval', 'approval_status' => 'pending', 'requester_id' => 1]);
    $bad = wf_insert_ticket(['status' => 'approved', 'approval_status' => 'approved', 'requester_id' => 1]); // not pending → fails guard
    try {
        $result = wf_service()->bulkApproveTickets([$ok1, $ok2, $bad], ['id' => 4, 'role' => 'admin'], 'อนุมัติแบบกลุ่ม');
        assert_same(2, $result['approved'], 'two eligible tickets approved');
        assert_count(1, $result['failed'], 'one ineligible ticket reported as failed');
        assert_same($bad, $result['failed'][0]['ticket_id'], 'the ineligible ticket is the already-approved one');
        assert_same('approved', wf_state($ok1)['status']);
        assert_same('approved', wf_state($ok2)['status']);
        assert_same('approved', wf_state($bad)['status'], 'already-approved ticket left unchanged');
    } finally {
        wf_cleanup($ok1);
        wf_cleanup($ok2);
        wf_cleanup($bad);
    }
});

// ── atomicity: a failing step inside a transition rolls back the whole thing (no partial write) ──

test('workflow(atomicity): a failing step in approveTicket rolls back the status update', function (): void {
    $id = wf_insert_ticket(['status' => 'pending_approval', 'approval_status' => 'pending']);
    try {
        $threw = false;
        try {
            // actor 999999 does not exist → the approval/activity insert violates its user FK AFTER the ticket
            // UPDATE, so the whole transition must roll back (nothing partially applied)
            wf_service()->approveTicket($id, ['id' => 999999, 'role' => 'admin'], ['note' => 'ok']);
        } catch (Throwable) {
            $threw = true;
        }

        assert_true($threw, 'the failing transition must surface an error');
        assert_same('pending_approval', (string) wf_state($id)['status'], 'the ticket status was rolled back (no partial update)');
        assert_same(
            0,
            (int) wf_pdo()->query("SELECT COUNT(*) FROM ticket_activity_logs WHERE ticket_id = $id")->fetchColumn(),
            'no activity log survived the rollback'
        );
    } finally {
        if (wf_pdo()->inTransaction()) {
            wf_pdo()->rollBack(); // defensive: if the transition's own rollback were removed (power-proof), clean up here
        }
        wf_cleanup($id);
    }
});
