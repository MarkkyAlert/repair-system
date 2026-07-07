<?php
declare(strict_types=1);

use App\Services\CommentService;

// Permission + validation tests for CommentService. The core is the ownership matrix in
// requireEditableComment(): who may edit/delete whose comment. The ticket is seeded with requester #1
// as owner and technician #3 assigned, so every viewer we test can actually SEE the ticket
// (requireVisibleTicket) and thus reach the comment-permission check. Notifications/tickets/comments
// are cleaned in finally (comments cascade with the ticket).
//
// Note on "tokens": CommentService does NOT check the CSRF (_csrf) token — that is controller-level.
// createComment validates a submission_token (is_submission_token: 64 hex chars); updateComment uses an
// original_updated_at optimistic-lock stamp. We supply valid values so the real permission logic runs.

function cm_service(): CommentService
{
    return tvm_container()->get(CommentService::class);
}

function cm_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function cm_token(): string
{
    return bin2hex(random_bytes(32)); // matches is_submission_token: /^[a-f0-9]{64}$/
}

function cm_owner(): array
{
    return ['id' => 1, 'role' => 'requester'];
}

function cm_tech(): array
{
    return ['id' => 3, 'role' => 'technician']; // assigned to the ticket → can see it, but not staff/owner
}

function cm_manager(): array
{
    return ['id' => 2, 'role' => 'manager'];
}

function cm_admin(): array
{
    return ['id' => 4, 'role' => 'admin'];
}

function cm_seed_ticket(): int
{
    $pdo = cm_pdo();
    $loc = (int) $pdo->query('SELECT COALESCE((SELECT id FROM locations LIMIT 1), 1)')->fetchColumn();
    $cat = (int) $pdo->query('SELECT COALESCE((SELECT id FROM ticket_categories LIMIT 1), 1)')->fetchColumn();
    $pri = (int) $pdo->query('SELECT COALESCE((SELECT id FROM priorities LIMIT 1), 1)')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, approval_status, requested_at)
         VALUES (?, "CM", "x", 1, ?, ?, ?, 3, "in_progress", "approved", NOW())'
    )->execute(['CM-' . bin2hex(random_bytes(4)), $loc, $cat, $pri]);
    return (int) $pdo->lastInsertId();
}

/** Seed a comment owned by $ownerId; returns [commentId, updated_at]. */
function cm_seed_comment(int $ticketId, int $ownerId, string $body = 'original body'): array
{
    cm_pdo()->prepare('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal, created_at, updated_at) VALUES (?, ?, ?, 0, NOW(), NOW())')
        ->execute([$ticketId, $ownerId, $body]);
    $id = (int) cm_pdo()->lastInsertId();
    $updatedAt = (string) cm_pdo()->query("SELECT updated_at FROM ticket_comments WHERE id = $id")->fetchColumn();
    return [$id, $updatedAt];
}

function cm_body(int $commentId): ?string
{
    $val = cm_pdo()->query("SELECT body FROM ticket_comments WHERE id = $commentId")->fetchColumn();
    return $val === false ? null : (string) $val;
}

function cm_cleanup(int $ticketId): void
{
    $pdo = cm_pdo();
    $pdo->prepare("DELETE FROM notification_recipients WHERE notification_id IN (SELECT id FROM notifications WHERE related_type='ticket' AND related_id=?)")->execute([$ticketId]);
    $pdo->prepare("DELETE FROM notifications WHERE related_type='ticket' AND related_id=?")->execute([$ticketId]);
    $pdo->prepare('DELETE FROM tickets WHERE id=?')->execute([$ticketId]); // comments cascade
}

// ── core: update permission matrix ──

test('comment(permission): updateComment — owner/manager/admin allowed; non-owner non-staff denied + unchanged', function (): void {
    $ticketId = cm_seed_ticket();
    try {
        // deny — assigned technician (sees the ticket, not owner, not staff) edits requester #1's comment
        [$cId, $upd] = cm_seed_comment($ticketId, 1, 'original body');
        $threw = false;
        try {
            cm_service()->updateComment($ticketId, $cId, cm_tech(), ['body' => 'HACKED', 'original_updated_at' => $upd]);
        } catch (DomainException $e) {
            $threw = true;
            assert_same('คุณไม่มีสิทธิ์แก้ไข comment นี้', $e->getMessage());
        }
        assert_true($threw, 'non-owner non-staff must not edit');
        assert_same('original body', cm_body($cId), 'body is UNCHANGED after a denied edit');

        // allow — owner edits own
        [$c2, $u2] = cm_seed_comment($ticketId, 1, 'owner original');
        cm_service()->updateComment($ticketId, $c2, cm_owner(), ['body' => 'owner edited', 'original_updated_at' => $u2]);
        assert_same('owner edited', cm_body($c2), 'owner can edit own comment');

        // allow — manager edits requester's comment (elevated)
        [$c3, $u3] = cm_seed_comment($ticketId, 1, 'for manager');
        cm_service()->updateComment($ticketId, $c3, cm_manager(), ['body' => 'manager edited', 'original_updated_at' => $u3]);
        assert_same('manager edited', cm_body($c3), 'manager can edit another user\'s comment');

        // allow — admin edits requester's comment
        [$c4, $u4] = cm_seed_comment($ticketId, 1, 'for admin');
        cm_service()->updateComment($ticketId, $c4, cm_admin(), ['body' => 'admin edited', 'original_updated_at' => $u4]);
        assert_same('admin edited', cm_body($c4), 'admin can edit another user\'s comment');
    } finally {
        cm_cleanup($ticketId);
    }
});

// ── core: delete permission matrix ──

test('comment(permission): deleteComment — owner/manager/admin allowed; non-owner non-staff denied + row survives', function (): void {
    $ticketId = cm_seed_ticket();
    try {
        // deny — technician deletes requester #1's comment (shared guard message says "แก้ไข")
        [$cId] = cm_seed_comment($ticketId, 1);
        $threw = false;
        try {
            cm_service()->deleteComment($ticketId, $cId, cm_tech());
        } catch (DomainException $e) {
            $threw = true;
            assert_same('คุณไม่มีสิทธิ์แก้ไข comment นี้', $e->getMessage());
        }
        assert_true($threw, 'non-owner non-staff must not delete');
        assert_same(1, (int) cm_pdo()->query("SELECT COUNT(*) FROM ticket_comments WHERE id = $cId")->fetchColumn(), 'comment still exists after a denied delete');

        // allow — owner / manager / admin
        foreach ([cm_owner(), cm_manager(), cm_admin()] as $actor) {
            [$c] = cm_seed_comment($ticketId, 1);
            cm_service()->deleteComment($ticketId, $c, $actor);
            assert_same(0, (int) cm_pdo()->query("SELECT COUNT(*) FROM ticket_comments WHERE id = $c")->fetchColumn(), 'privileged actor deleted the comment');
        }
    } finally {
        cm_cleanup($ticketId);
    }
});

// ── is_internal privilege ──

test('comment(privilege): staff can mark a comment internal; a requester cannot', function (): void {
    $ticketId = cm_seed_ticket();
    try {
        $internalOf = static function (string $token): int {
            return (int) cm_pdo()->query('SELECT is_internal FROM ticket_comments WHERE submission_token = ' . cm_pdo()->quote($token))->fetchColumn();
        };

        // requester tries to set internal → parseInternalFlag forces false
        $t1 = cm_token();
        cm_service()->createComment($ticketId, cm_owner(), ['body' => 'req wants internal', 'is_internal' => '1', 'submission_token' => $t1]);
        assert_same(0, $internalOf($t1), 'requester CANNOT make a comment internal (flag ignored)');

        // manager can
        $t2 = cm_token();
        cm_service()->createComment($ticketId, cm_manager(), ['body' => 'mgr internal', 'is_internal' => '1', 'submission_token' => $t2]);
        assert_same(1, $internalOf($t2), 'manager can make a comment internal');

        // technician can (technician is in parseInternalFlag's allow-list)
        $t3 = cm_token();
        cm_service()->createComment($ticketId, cm_tech(), ['body' => 'tech internal', 'is_internal' => '1', 'submission_token' => $t3]);
        assert_same(1, $internalOf($t3), 'technician can make a comment internal');
    } finally {
        cm_cleanup($ticketId);
    }
});

// ── validation + not-found guards ──

test('comment(validation): empty body / bad token / missing ticket / missing comment / guest all throw', function (): void {
    $ticketId = cm_seed_ticket();
    try {
        $throws = static function (callable $fn, string $message, string $ctx): void {
            $threw = false;
            try {
                $fn();
            } catch (DomainException $e) {
                $threw = true;
                assert_same($message, $e->getMessage(), $ctx);
            }
            assert_true($threw, "$ctx — must throw");
        };

        $throws(fn () => cm_service()->createComment($ticketId, cm_owner(), ['body' => '', 'submission_token' => cm_token()]), 'กรุณากรอกข้อความ comment ก่อนบันทึก', 'empty body');
        $throws(fn () => cm_service()->createComment($ticketId, cm_owner(), ['body' => 'hi', 'submission_token' => 'not-a-valid-token']), 'แบบฟอร์ม comment หมดอายุ กรุณารีเฟรชหน้าแล้วลองอีกครั้ง', 'bad submission token');
        $throws(fn () => cm_service()->createComment(999999999, cm_owner(), ['body' => 'hi', 'submission_token' => cm_token()]), 'ไม่พบ ticket ที่ต้องการแสดงความคิดเห็น', 'non-existent ticket');
        $throws(fn () => cm_service()->updateComment($ticketId, 999999999, cm_owner(), ['body' => 'hi', 'original_updated_at' => '2020-01-01 00:00:00']), 'ไม่พบ comment ที่ต้องการแก้ไข', 'non-existent comment (update)');
        $throws(fn () => cm_service()->deleteComment($ticketId, 999999999, cm_owner()), 'ไม่พบ comment ที่ต้องการแก้ไข', 'non-existent comment (delete)');
        // a guest cannot even see the ticket → blocked at the visibility guard (not the login message)
        $throws(fn () => cm_service()->createComment($ticketId, ['id' => 0, 'role' => 'guest'], ['body' => 'hi', 'submission_token' => cm_token()]), 'ไม่พบ ticket ที่ต้องการแสดงความคิดเห็น', 'guest blocked');
    } finally {
        cm_cleanup($ticketId);
    }
});

// ── happy create (no attachments; move_uploaded_file path is E2E-only) ──

test('comment: createComment happy path stores the comment (no attachments)', function (): void {
    $ticketId = cm_seed_ticket();
    try {
        $token = cm_token();
        cm_service()->createComment($ticketId, cm_owner(), ['body' => 'hello world', 'submission_token' => $token]);
        $row = cm_pdo()->query('SELECT * FROM ticket_comments WHERE submission_token = ' . cm_pdo()->quote($token))->fetch(PDO::FETCH_ASSOC);
        assert_true($row !== false, 'comment created');
        assert_same('hello world', (string) $row['body'], 'body stored');
        assert_same(1, (int) $row['user_id'], 'author is the actor');
        assert_same($ticketId, (int) $row['ticket_id'], 'linked to the ticket');
        assert_same(0, (int) $row['is_internal'], 'public by default');
    } finally {
        cm_cleanup($ticketId);
    }
});
