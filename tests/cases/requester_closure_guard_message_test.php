<?php

declare(strict_types=1);

use App\Services\TicketWorkflowService;

// Acceptance-test finding: an admin pressing "ยกเลิก" on a completed ticket was told
// "คุณไม่มีสิทธิ์ยืนยันผลการซ่อมของรายการนี้" — a message about confirming repair results, for a button that says
// cancel. The block itself is right: requireRequesterTicket guards three different endpoints (ตรวจรับ / เปิดใหม่ /
// ยกเลิก) and asks exactly one question — is the person pressing the button the requester of this ticket? The
// message named one of the three actions instead of stating that rule, so whoever hit it went looking for a
// permissions problem rather than realising they were not the requester.
//
// This locks the guard to a message that describes the rule and covers all three doors. It deliberately asserts on
// the rule wording, not on an exact sentence, so the text can still be improved without a test rewrite.

function rcgm_workflow(): TicketWorkflowService
{
    return tvm_container()->get(TicketWorkflowService::class);
}

/** @return array{ticket_id:int, requester_id:int, other_id:int} */
function rcgm_seed(): array
{
    $pdo = tvm_container()->get(PDO::class);
    $sfx = bin2hex(random_bytes(4));

    $mkUser = static function (string $role, string $tag) use ($pdo, $sfx): int {
        $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, full_name, role, is_active, version, created_at, updated_at)
             VALUES (:u, :e, :p, :f, :r, 1, 1, NOW(), NOW())'
        )->execute([
            'u' => "rcgm_{$tag}_{$sfx}",
            'e' => "rcgm_{$tag}_{$sfx}@example.test",
            'p' => password_hash('rcgm-secret-2026', PASSWORD_BCRYPT),
            'f' => "RCGM {$tag} {$sfx}",
            'r' => $role,
        ]);

        return (int) $pdo->lastInsertId();
    };

    $requester = $mkUser('requester', 'owner');
    // a manager, not a stranger and not an admin: a stranger cannot see the ticket at all and stops at the visibility
    // guard one step earlier, while an admin is allowed past this guard on purpose (close-on-behalf). A manager sees
    // every ticket but has no such privilege, so they land exactly on the guard under test.
    $other = $mkUser('manager', 'mgr');

    $pdo->prepare(
        'INSERT INTO tickets (ticket_no, submission_token, title, description, requester_id, location_id,
                              ticket_category_id, priority_id, approval_status, status, channel, requested_at, created_at, updated_at)
         VALUES (:no, :tok, :t, :d, :req, 1, 1, 2, "approved", "resolved", "web", NOW(), NOW(), NOW())'
    )->execute([
        'no' => 'RCGM-' . $sfx,
        'tok' => bin2hex(random_bytes(16)),
        't' => 'RCGM guard message',
        'd' => 'seeded by requester_closure_guard_message_test',
        'req' => $requester,
    ]);

    return ['ticket_id' => (int) $pdo->lastInsertId(), 'requester_id' => $requester, 'other_id' => $other];
}

test('closure guard: a non-requester is told the rule, not the name of one of the three actions', function (): void {
    $seed = rcgm_seed();
    $workflow = rcgm_workflow();
    $stranger = ['id' => $seed['other_id'], 'role' => 'manager', 'department_id' => null];

    // all three doors share requireRequesterTicket, so all three must answer the same way
    $attempts = [
        'cancel' => static fn () => $workflow->cancelTicket($seed['ticket_id'], $stranger, ['reason' => 'rcgm']),
        'reopen' => static fn () => $workflow->reopenTicket($seed['ticket_id'], $stranger, ['reopen_note' => 'rcgm']),
        'complete' => static fn () => $workflow->completeResolvedTicket($seed['ticket_id'], $stranger, ['closure_note' => 'rcgm']),
    ];

    try {
        foreach ($attempts as $label => $call) {
            $message = null;
            try {
                $call();
            } catch (DomainException $e) {
                $message = $e->getMessage();
            } catch (Throwable $e) {
                // a ticket this viewer cannot even see would be a different guard; keep the failure readable
                $message = get_class($e) . ': ' . $e->getMessage();
            }

            assert_true($message !== null, "{$label} by a non-requester is refused");
            assert_contains_str('ผู้แจ้ง', (string) $message, "{$label}: the refusal names the rule — only the requester may do this");
            assert_true(
                !str_contains((string) $message, 'ยืนยันผลการซ่อม'),
                "{$label}: the refusal no longer describes confirming repair results, which is wrong for cancel and reopen "
                . "(got: {$message})"
            );
        }
    } finally {
        // clean up even when an assertion fails: leftover users accumulate across runs, and an extra admin row once
        // broke the unrelated "sole active admin" test — a red run must not leave the database worse than it found it
        $pdo = tvm_container()->get(PDO::class);
        $pdo->prepare('DELETE FROM tickets WHERE id = :id')->execute(['id' => $seed['ticket_id']]);
        $pdo->prepare('DELETE FROM users WHERE id IN (:a, :b)')->execute(['a' => $seed['requester_id'], 'b' => $seed['other_id']]);
    }
});
