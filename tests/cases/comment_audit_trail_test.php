<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\CommentService;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// Deleting a comment removes the text AND its photos from disk, permanently, and a manager or admin may do it
// to somebody else's comment. The system already records who deleted a DEPARTMENT — but erasing a technician's
// repair notes left nothing at all. In an argument about whether work was really done, there was no way to know
// a note had ever existed, let alone who removed it.
//
// Editing has the same shape: the new text overwrites the old one (only updated_at and a version counter
// remain), and again a manager can do it to another person's words.
//
// Creating is deliberately NOT audited: the comment itself is the record. Only removing and rewriting destroy
// information, so those are what the trail has to cover.

function cat_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** @return array{ticket: int, comment: int} a ticket with one comment written by the technician */
function cat_seed_comment(string $body): array
{
    $pdo = cat_pdo();
    $tickets = tvm_container()->get(TicketService::class);
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();

    $ticketId = $tickets->createTicket(['id' => 1, 'role' => 'requester'], [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => 'audit trail probe',
        'description' => 'x',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);
    $wf->approveTicket($ticketId, ['id' => 4, 'role' => 'admin'], ['note' => '']);

    // written by the technician (user 3) so the manager below is acting on somebody else's words
    $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal, version, created_at, updated_at) VALUES (?, 3, ?, 0, 1, NOW(), NOW())')
        ->execute([$ticketId, $body]);

    return ['ticket' => $ticketId, 'comment' => (int) $pdo->lastInsertId()];
}

test('comment(audit): deleting somebody else\'s repair note leaves a record of who did it', function (): void {
    $pdo = cat_pdo();
    $comments = tvm_container()->get(CommentService::class);
    $manager = ['id' => 2, 'role' => 'manager'];

    $seed = cat_seed_comment('เปลี่ยนคอมเพรสเซอร์แล้ว ทดสอบผ่าน');
    $floor = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();

    try {
        $comments->deleteComment($seed['ticket'], $seed['comment'], $manager);

        // the comment really is gone — this is not a soft delete
        $left = (int) $pdo->query('SELECT COUNT(*) FROM ticket_comments WHERE id = ' . $seed['comment'])->fetchColumn();
        assert_same(0, $left, 'the comment is removed for good, which is exactly why the trail matters');

        $stmt = $pdo->prepare('SELECT user_id, action, entity_type, entity_id, context FROM audit_logs WHERE id > ? AND action = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$floor, 'comment.deleted']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        assert_true($row !== null, 'deleting a comment is recorded, the same way deleting a department already was');
        assert_same(2, (int) $row['user_id'], 'the record names who deleted it');
        assert_same($seed['comment'], (int) $row['entity_id'], 'and which comment');

        // the comment row is gone, so the trail is the only place these can still be read
        $context = json_decode((string) $row['context'], true);
        assert_same($seed['ticket'], (int) ($context['ticket_id'] ?? 0), 'the trail keeps the ticket it belonged to');
        assert_same(3, (int) ($context['author_id'] ?? 0), 'and who had written it — unrecoverable from anywhere else');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$seed['ticket']]);
        $pdo->prepare('DELETE FROM audit_logs WHERE id > ?')->execute([$floor]);
    }
});

test('comment(audit): rewriting somebody else\'s note is recorded too', function (): void {
    $pdo = cat_pdo();
    $comments = tvm_container()->get(CommentService::class);
    $manager = ['id' => 2, 'role' => 'manager'];

    $seed = cat_seed_comment('ข้อความเดิมก่อนถูกแก้');
    $floor = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();

    try {
        $comments->updateComment($seed['ticket'], $seed['comment'], $manager, [
            'body' => 'ข้อความใหม่ที่เขียนทับ',
            'original_version' => 1,
        ]);

        // the old wording is genuinely gone — nothing keeps a previous revision
        $body = (string) $pdo->query('SELECT body FROM ticket_comments WHERE id = ' . $seed['comment'])->fetchColumn();
        assert_same('ข้อความใหม่ที่เขียนทับ', $body, 'the edit overwrites in place');

        $stmt = $pdo->prepare('SELECT user_id, entity_id, context FROM audit_logs WHERE id > ? AND action = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$floor, 'comment.updated']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        assert_true($row !== null, 'an edit to another person\'s words is recorded');
        assert_same(2, (int) $row['user_id'], 'naming the editor');
        assert_same(3, (int) (json_decode((string) $row['context'], true)['author_id'] ?? 0), 'and the original author');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$seed['ticket']]);
        $pdo->prepare('DELETE FROM audit_logs WHERE id > ?')->execute([$floor]);
    }
});

test('comment(audit): writing a comment is NOT audited — the comment is its own record', function (): void {
    // a deliberate line, not an oversight: every ticket collects comments constantly, and an existing comment
    // proves itself. Auditing creation would bury the entries that matter under noise.
    $pdo = cat_pdo();
    $source = (string) file_get_contents(BASE_PATH . '/app/Services/CommentService.php');

    assert_contains_str("'comment.deleted'", $source, 'deletion is recorded');
    assert_contains_str("'comment.updated'", $source, 'so is an edit');
    assert_true(!str_contains($source, "'comment.created'"), 'creation is deliberately left out');

    // and the audit table itself keeps no created rows to contradict that
    $created = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'comment.created'")->fetchColumn();
    assert_same(0, $created, 'no comment-created entries exist');
});
