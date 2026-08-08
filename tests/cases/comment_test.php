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
// integer original_version optimistic-lock stamp. We supply valid values so the real permission logic runs.

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
    $version = (int) cm_pdo()->query("SELECT version FROM ticket_comments WHERE id = $id")->fetchColumn();
    return [$id, $version];
}

/** Current integer version (optimistic-lock token) of a comment. */
function cm_version(int $commentId): int
{
    return (int) cm_pdo()->query("SELECT version FROM ticket_comments WHERE id = $commentId")->fetchColumn();
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
            cm_service()->updateComment($ticketId, $cId, cm_tech(), ['body' => 'HACKED', 'original_version' => $upd]);
        } catch (DomainException $e) {
            $threw = true;
            assert_same('คุณไม่มีสิทธิ์แก้ไข comment นี้', $e->getMessage());
        }
        assert_true($threw, 'non-owner non-staff must not edit');
        assert_same('original body', cm_body($cId), 'body is UNCHANGED after a denied edit');

        // allow — owner edits own
        [$c2, $u2] = cm_seed_comment($ticketId, 1, 'owner original');
        cm_service()->updateComment($ticketId, $c2, cm_owner(), ['body' => 'owner edited', 'original_version' => $u2]);
        assert_same('owner edited', cm_body($c2), 'owner can edit own comment');

        // allow — manager edits requester's comment (elevated)
        [$c3, $u3] = cm_seed_comment($ticketId, 1, 'for manager');
        cm_service()->updateComment($ticketId, $c3, cm_manager(), ['body' => 'manager edited', 'original_version' => $u3]);
        assert_same('manager edited', cm_body($c3), 'manager can edit another user\'s comment');

        // allow — admin edits requester's comment
        [$c4, $u4] = cm_seed_comment($ticketId, 1, 'for admin');
        cm_service()->updateComment($ticketId, $c4, cm_admin(), ['body' => 'admin edited', 'original_version' => $u4]);
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
        $throws(fn () => cm_service()->updateComment($ticketId, 999999999, cm_owner(), ['body' => 'hi', 'original_version' => 1]), 'ไม่พบ comment ที่ต้องการแก้ไข', 'non-existent comment (update)');
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

// ── concurrency: optimistic lock on update (integer version) ──

test('comment(concurrency): a stale version is rejected (optimistic lock) and does not overwrite', function (): void {
    $ticketId = cm_seed_ticket();
    try {
        [$cId, $staleVersion] = cm_seed_comment($ticketId, 1, 'original body'); // version 1

        // a concurrent edit lands first (version 1 → 2) — the version we loaded is now out of date
        cm_service()->updateComment($ticketId, $cId, cm_owner(), ['body' => 'edited by someone else', 'original_version' => $staleVersion]);
        assert_same(2, cm_version($cId), 'the first edit bumped the version to 2');

        $threw = false;
        try {
            cm_service()->updateComment($ticketId, $cId, cm_owner(), ['body' => 'my late edit', 'original_version' => $staleVersion]);
        } catch (DomainException $e) {
            $threw = true;
            // B-9: the rejection must also quote what is stored NOW, so the editor can see the other person's
            // text before deciding to retype over it (a bare "someone edited this, refresh" made them refresh
            // and hunt for the difference — or just overwrite it).
            assert_contains_str('Comment ถูกแก้ไขโดยผู้ใช้อื่นแล้ว', $e->getMessage(), 'it still says a conflict happened');
            assert_contains_str('edited by someone else', $e->getMessage(), 'it quotes the text that is stored right now');
            assert_contains_str('ก่อนบันทึกทับ', $e->getMessage(), 'and still tells the user to look before overwriting');
        }
        assert_true($threw, 'a stale version must be rejected (lost update prevented)');
        assert_same('edited by someone else', cm_body($cId), 'the late edit did NOT overwrite the concurrent edit');

        // a missing version stamp is rejected before touching the row
        $threw2 = false;
        try {
            cm_service()->updateComment($ticketId, $cId, cm_owner(), ['body' => 'x', 'original_version' => 0]);
        } catch (DomainException $e) {
            $threw2 = true;
            assert_same('ข้อมูล comment ไม่ครบถ้วน กรุณารีเฟรชหน้าแล้วลองอีกครั้ง', $e->getMessage());
        }
        assert_true($threw2, 'a missing version stamp is rejected');
    } finally {
        cm_cleanup($ticketId);
    }
});

// This is the case a second-precision updated_at token missed (F1): two edits that both loaded version 1 and
// save within the SAME wall-clock second. With updated_at the second write matched and silently overwrote the
// first; with an integer version the first bumps to 2, so the second (still holding 1) is rejected.
test('comment(concurrency): two same-second edits from the same loaded version — the second is rejected, not lost (F1)', function (): void {
    $ticketId = cm_seed_ticket();
    try {
        [$cId, $loadedVersion] = cm_seed_comment($ticketId, 1, 'original body'); // version 1, created this second

        // both editors loaded version 1; editor A saves first (same second as creation)
        cm_service()->updateComment($ticketId, $cId, cm_owner(), ['body' => 'edit A', 'original_version' => $loadedVersion]);

        // editor B saves in the same second with the SAME loaded version — must be rejected, not overwrite A
        $threw = false;
        try {
            cm_service()->updateComment($ticketId, $cId, cm_owner(), ['body' => 'edit B', 'original_version' => $loadedVersion]);
        } catch (DomainException $e) {
            $threw = true;
            assert_contains_str('Comment ถูกแก้ไขโดยผู้ใช้อื่นแล้ว', $e->getMessage());
            assert_contains_str('edit A', $e->getMessage(), "B is shown A's stored text, not just told to refresh");
        }
        assert_true($threw, 'the same-second second edit is rejected (the version bumped, unlike a per-second timestamp)');
        assert_same('edit A', cm_body($cId), "A's edit is preserved — B did not overwrite it within the same second");
    } finally {
        cm_cleanup($ticketId);
    }
});

// ── idempotency: resubmitting the same submission_token must not duplicate the comment ──

test('comment(idempotency): the same submission_token twice creates only one comment', function (): void {
    $ticketId = cm_seed_ticket();
    $token = cm_token();
    try {
        cm_service()->createComment($ticketId, cm_owner(), ['body' => 'first', 'submission_token' => $token]);
        cm_service()->createComment($ticketId, cm_owner(), ['body' => 'second (same token)', 'submission_token' => $token]);

        assert_same(
            1,
            (int) cm_pdo()->query('SELECT COUNT(*) FROM ticket_comments WHERE submission_token = ' . cm_pdo()->quote($token))->fetchColumn(),
            'a resubmitted token must not create a duplicate comment'
        );
        assert_same(
            'first',
            (string) cm_pdo()->query('SELECT body FROM ticket_comments WHERE submission_token = ' . cm_pdo()->quote($token))->fetchColumn(),
            'the original comment is kept (the resubmit is a no-op)'
        );
    } finally {
        cm_cleanup($ticketId);
    }
});

// F3 (logic review): notifications after a successful mutation must be best-effort — a failing
// notification must NOT surface as an error to a user whose comment already saved. createComment and
// deleteComment already wrap the notify; updateComment did not (its exception propagated past the commit).
test('comment(resilience): updateComment persists even when the notification throws (F3)', function (): void {
    $ticketId = cm_seed_ticket();
    try {
        [$cId, $ver] = cm_seed_comment($ticketId, 1, 'before edit');

        $throwingNotifier = new class () extends \App\Services\NotificationService {
            public function __construct()
            {
            }

            public function notifyCommentEvent(int $ticketId, int $commentId, int $actorId, bool $isInternal, string $body, string $action): void
            {
                throw new \RuntimeException('notification backend down');
            }
        };
        $service = new \App\Services\CommentService(
            tvm_container()->get(\App\Repositories\CommentRepository::class),
            tvm_container()->get(\App\Repositories\TicketReadRepository::class),
            $throwingNotifier,
            tvm_container()->get(\App\Services\AttachmentService::class),
            tvm_container()->get(PDO::class),
            tvm_container()->get(\App\Services\AuditLogger::class),
        );

        $threw = false;
        try {
            $service->updateComment($ticketId, $cId, cm_owner(), ['body' => 'after edit', 'original_version' => $ver]);
        } catch (\Throwable) {
            $threw = true;
        }

        assert_false($threw, 'a notification failure must not propagate to the caller');
        assert_same('after edit', cm_body($cId), 'the edit is persisted despite the notification failure');
    } finally {
        cm_cleanup($ticketId);
    }
});

/** A CommentService whose notifier always throws — for the best-effort notify resilience + logging tests. */
function cm_throwing_notifier_service(): \App\Services\CommentService
{
    $throwingNotifier = new class () extends \App\Services\NotificationService {
        public function __construct()
        {
        }

        public function notifyCommentEvent(int $ticketId, int $commentId, int $actorId, bool $isInternal, string $body, string $action): void
        {
            throw new \RuntimeException('notification backend down');
        }
    };

    return new \App\Services\CommentService(
        tvm_container()->get(\App\Repositories\CommentRepository::class),
        tvm_container()->get(\App\Repositories\TicketReadRepository::class),
        $throwingNotifier,
        tvm_container()->get(\App\Services\AttachmentService::class),
        tvm_container()->get(PDO::class),
        tvm_container()->get(\App\Services\AuditLogger::class),
    );
}

// error-review F2: createComment/deleteComment already keep a notify failure from surfacing to the user, but
// they SWALLOWED it silently (empty catch) — a broken notifier left no trace. They must log_caught_exception.
test('comment(resilience+log): createComment persists AND records the swallowed notify failure (error-review F2)', function (): void {
    $ticketId = cm_seed_ticket();
    $tmp = tempnam(sys_get_temp_dir(), 'cnotify_') . '.log';
    $originalLog = (string) ini_get('error_log');
    $pdo = tvm_container()->get(PDO::class);

    try {
        $before = (int) $pdo->query("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = $ticketId")->fetchColumn();

        ini_set('error_log', $tmp);
        $threw = false;
        try {
            cm_throwing_notifier_service()->createComment($ticketId, cm_owner(), ['body' => 'saved despite a broken notifier', 'submission_token' => cm_token()]);
        } catch (\Throwable) {
            $threw = true;
        }
        ini_set('error_log', $originalLog);

        assert_false($threw, 'a notification failure must not propagate to the caller');
        $after = (int) $pdo->query("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = $ticketId")->fetchColumn();
        assert_same($before + 1, $after, 'the comment is persisted despite the notification failure');
        assert_contains_str('[comment.create.notify]', (string) @file_get_contents($tmp), 'the swallowed create-notify failure is now logged (was silent)');
    } finally {
        ini_set('error_log', $originalLog);
        @unlink($tmp);
        cm_cleanup($ticketId);
    }
});

test('comment(resilience+log): deleteComment persists AND records the swallowed notify failure (error-review F2)', function (): void {
    $ticketId = cm_seed_ticket();
    $tmp = tempnam(sys_get_temp_dir(), 'dnotify_') . '.log';
    $originalLog = (string) ini_get('error_log');

    try {
        [$cId] = cm_seed_comment($ticketId, 1, 'to be deleted');

        ini_set('error_log', $tmp);
        $threw = false;
        try {
            cm_throwing_notifier_service()->deleteComment($ticketId, $cId, cm_owner());
        } catch (\Throwable) {
            $threw = true;
        }
        ini_set('error_log', $originalLog);

        assert_false($threw, 'a notification failure must not propagate to the caller');
        assert_same(null, cm_body($cId), 'the comment is deleted despite the notification failure');
        assert_contains_str('[comment.delete.notify]', (string) @file_get_contents($tmp), 'the swallowed delete-notify failure is now logged (was silent)');
    } finally {
        ini_set('error_log', $originalLog);
        @unlink($tmp);
        cm_cleanup($ticketId);
    }
});

/** Seed a ticket with a chosen requester + assignees; returns the ticket id. */
function cm_seed_ticket_as(int $requesterId, int $assignedTechId = 0, int $assignedManagerId = 0): int
{
    $pdo = cm_pdo();
    $loc = (int) $pdo->query('SELECT COALESCE((SELECT id FROM locations LIMIT 1), 1)')->fetchColumn();
    $cat = (int) $pdo->query('SELECT COALESCE((SELECT id FROM ticket_categories LIMIT 1), 1)')->fetchColumn();
    $pri = (int) $pdo->query('SELECT COALESCE((SELECT id FROM priorities LIMIT 1), 1)')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_manager_id, assigned_technician_id, status, approval_status, requested_at)
         VALUES (?, "CM", "x", ?, ?, ?, ?, ?, ?, "in_progress", "approved", NOW())'
    )->execute(['CMB3-' . bin2hex(random_bytes(4)), $requesterId, $loc, $cat, $pri, $assignedManagerId ?: null, $assignedTechId ?: null]);
    return (int) $pdo->lastInsertId();
}

// bug-hunt batch3 #2 (completes B2): B2 hid internal notes from a ticket's requester on READ, but a requester who
// is a manager/admin could still edit or DELETE an internal note by id (blind mutate), and one who is a technician
// could see it via notifications (#1). The requester of a ticket now cannot edit/delete its internal notes at all.
test('comment (batch3 #2): a ticket requester cannot edit/delete an internal note on their own ticket, even as manager', function (): void {
    $pdo = cm_pdo();
    $ticketId = cm_seed_ticket_as(2, 3, 2); // requested BY manager id 2
    // an INTERNAL comment authored by the admin (id 4)
    $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal, created_at, updated_at) VALUES (?, 4, "secret internal", 1, NOW(), NOW())')->execute([$ticketId]);
    $commentId = (int) $pdo->lastInsertId();
    $version = (int) $pdo->query("SELECT version FROM ticket_comments WHERE id = $commentId")->fetchColumn();
    $mgrRequester = ['id' => 2, 'role' => 'manager'];

    try {
        $threw = false;
        try {
            cm_service()->updateComment($ticketId, $commentId, $mgrRequester, ['body' => 'HACKED', 'original_version' => $version]);
        } catch (DomainException) {
            $threw = true;
        }
        assert_true($threw, 'the ticket requester (even a manager) cannot EDIT an internal note on their own ticket');
        assert_same('secret internal', cm_body($commentId), 'the internal note body is unchanged');

        $threwDel = false;
        try {
            cm_service()->deleteComment($ticketId, $commentId, $mgrRequester);
        } catch (DomainException) {
            $threwDel = true;
        }
        assert_true($threwDel, 'the ticket requester cannot DELETE the internal note either');
        assert_true(cm_body($commentId) !== null, 'the internal note still exists after the blocked delete');

        // not over-broad: a staff member who is NOT this ticket's requester can still edit it
        cm_service()->updateComment($ticketId, $commentId, cm_admin(), ['body' => 'staff edit', 'original_version' => $version]);
        assert_same('staff edit', cm_body($commentId), 'a non-requester staff member can still manage the internal note');
    } finally {
        cm_cleanup($ticketId);
    }
});

// bug-hunt batch3 #1 (completes B2): the internal-note notification excluded requester_id, but if the requester is
// ALSO the assigned manager/technician (a technician who filed + is assigned their own ticket), they still got it
// via the assigned_* field. The requester is now excluded from internal-note notifications regardless.
test('comment (batch3 #1): an internal-note notification skips the requester even when they are the assigned tech', function (): void {
    $pdo = cm_pdo();
    $ticketId = cm_seed_ticket_as(3, 3, 2); // requester AND assigned technician are the same person (id 3); manager id 2
    $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal, created_at, updated_at) VALUES (?, 4, "internal", 1, NOW(), NOW())')->execute([$ticketId]);
    $commentId = (int) $pdo->lastInsertId();
    $notif = tvm_container()->get(App\Services\NotificationService::class);

    try {
        $notif->notifyCommentEvent($ticketId, $commentId, 4, true, 'internal body', 'added'); // actor = admin id 4

        $recips = static fn (int $userId): int => (int) $pdo->query(
            "SELECT COUNT(*) FROM notification_recipients nr JOIN notifications n ON n.id = nr.notification_id
             WHERE n.related_type = 'ticket' AND n.related_id = {$ticketId} AND nr.user_id = {$userId}"
        )->fetchColumn();

        assert_same(0, $recips(3), 'the requester (even as assigned tech) is NOT notified about an internal note on their own ticket');
        assert_true($recips(2) > 0, 'a non-requester staff member (the manager) still gets the internal-note notification');
    } finally {
        cm_cleanup($ticketId);
    }
});

// bug-hunt R5-4 (owner decision: internal notes are permanent): an internal comment must NOT be downgradable to
// public via edit. The edit form's checkbox previously worked one way only (unchecking it sent nothing, silently
// ignored), and a crafted POST with is_internal=0 would have downgraded it. updateComment now forces an
// already-internal note to stay internal; a public note can still be UPGRADED to internal.
test('comment(R5-4): an internal note stays internal on edit even when is_internal=0 is submitted (permanent)', function (): void {
    $ticketId = cm_seed_ticket();
    try {
        // a staff-authored INTERNAL note (owned by the manager, who is not the ticket requester)
        cm_pdo()->prepare('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal, created_at, updated_at) VALUES (?, 2, "internal note", 1, NOW(), NOW())')->execute([$ticketId]);
        $cId = (int) cm_pdo()->lastInsertId();
        $ver = cm_version($cId);

        // manager edits it and tries to un-mark internal (the downgrade a crafted POST / stale form could attempt)
        $result = cm_service()->updateComment($ticketId, $cId, cm_manager(), [
            'body' => 'still internal',
            'is_internal' => '0',
            'original_version' => $ver,
        ]);

        assert_same(1, (int) cm_pdo()->query("SELECT is_internal FROM ticket_comments WHERE id = $cId")->fetchColumn(), 'the internal note stays internal in the DB — downgrade to public is blocked');
        assert_true((bool) $result['is_internal'], 'the returned view-model still reports internal');
        assert_same('still internal', cm_body($cId), 'the body edit still applied');

        // sanity: a PUBLIC note can still be UPGRADED to internal (only the downgrade direction is locked)
        [$pubId, $pubVer] = cm_seed_comment($ticketId, 2, 'public note');
        cm_service()->updateComment($ticketId, $pubId, cm_manager(), ['body' => 'now internal', 'is_internal' => '1', 'original_version' => $pubVer]);
        assert_same(1, (int) cm_pdo()->query("SELECT is_internal FROM ticket_comments WHERE id = $pubId")->fetchColumn(), 'public → internal upgrade still works');
    } finally {
        cm_cleanup($ticketId);
    }
});

// ── M-4: payload ของการแก้ inline ต้องคืน version ใหม่ ──
// ฟอร์มพก original_version ไว้เป็น optimistic lock และถูก stamp ตอน render หน้าเพียงครั้งเดียว. พอแก้สำเร็จ
// แถวใน DB ขยับเป็น version+1 แต่ payload เดิมไม่ได้บอกเลขใหม่กลับมา ฟอร์มจึงยังถือเลขเก่า → แก้ครั้งที่สอง
// (ยังไม่ได้ refresh หน้า) โดนตีกลับว่า "ถูกแก้ไขโดยผู้ใช้อื่นแล้ว" ทั้งที่เป็นคนเดิมแก้ต่อของตัวเอง
test('comment M-4: a successful inline edit returns the new version, so a second edit is not a false conflict', function (): void {
    $ticketId = cm_seed_ticket();
    [$commentId, $version] = cm_seed_comment($ticketId, cm_owner()['id']);

    try {
        $payload = cm_service()->updateComment($ticketId, $commentId, cm_owner(), [
            'body' => 'แก้ครั้งแรก',
            'submission_token' => cm_token(),
            'original_version' => $version,
        ]);

        assert_same($version + 1, (int) ($payload['version'] ?? 0), 'the response carries the version the row now holds');
        assert_same(cm_version($commentId), (int) ($payload['version'] ?? 0), 'and it matches the database');

        // แก้ครั้งที่สองโดยใช้เลขที่เพิ่งได้กลับมา (คือสิ่งที่หน้าเว็บทำหลัง fix) ต้องผ่าน
        cm_service()->updateComment($ticketId, $commentId, cm_owner(), [
            'body' => 'แก้ครั้งที่สอง',
            'submission_token' => cm_token(),
            'original_version' => (int) $payload['version'],
        ]);
        assert_same('แก้ครั้งที่สอง', cm_body($commentId), 'the second edit lands instead of being rejected as someone else’s change');
    } finally {
        cm_cleanup($ticketId);
    }
});

test('comment M-4: a genuine concurrent edit is still rejected', function (): void {
    $ticketId = cm_seed_ticket();
    [$commentId, $version] = cm_seed_comment($ticketId, cm_owner()['id']);

    try {
        // คนแรกบันทึกไปแล้ว
        cm_service()->updateComment($ticketId, $commentId, cm_owner(), [
            'body' => 'ของคนแรก',
            'submission_token' => cm_token(),
            'original_version' => $version,
        ]);

        // คนที่สองยังถือเลขเก่าจากหน้าที่เปิดค้างไว้ ต้องโดนกัน ไม่ใช่เขียนทับเงียบ ๆ
        $threw = false;
        try {
            cm_service()->updateComment($ticketId, $commentId, cm_owner(), [
                'body' => 'ของคนที่สอง',
                'submission_token' => cm_token(),
                'original_version' => $version,
            ]);
        } catch (DomainException $e) {
            $threw = true;
            assert_contains_str('ผู้ใช้อื่น', $e->getMessage(), 'and is told someone else got there first');
        }
        assert_true($threw, 'a stale version from another tab is still refused — the lock is not weakened');
        assert_same('ของคนแรก', cm_body($commentId), 'the first writer’s text survives');
    } finally {
        cm_cleanup($ticketId);
    }
});

test('comment M-4: the inline editor writes the returned version back into the form', function (): void {
    $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/ticket-detail.js');
    assert_contains_str('original_version', $js, 'the editor knows about the lock field');
    assert_contains_str('.version', $js, 'and reads the version the server returned');
});

// ── M-5: ป้ายกำกับต้องเป็นภาษาเดียวกันทั้งตอน render และตอนแก้ inline ──
// สองทางเคยเขียนสตริงของตัวเองแยกกัน ฝั่ง AJAX เผลอเป็นอังกฤษ ผู้ใช้แก้ comment ทีป้ายก็เปลี่ยนจาก
// "สาธารณะ" เป็น "Public" คาหน้าจอ กลายเป็นสองภาษาปนกันในเธรดเดียวจนกว่าจะรีเฟรช
test('comment M-5: the inline edit response uses the same Thai labels the page renders', function (): void {
    $ticketId = cm_seed_ticket();
    [$commentId, $version] = cm_seed_comment($ticketId, cm_owner()['id']);

    try {
        $payload = cm_service()->updateComment($ticketId, $commentId, cm_owner(), [
            'body' => 'แก้แล้ว',
            'submission_token' => cm_token(),
            'original_version' => $version,
        ]);
        assert_same('สาธารณะ', (string) ($payload['visibility_label'] ?? ''), 'a public comment stays "สาธารณะ" after an inline edit');

        // เทียบกับป้ายที่หน้าเว็บ render จริงสำหรับ comment ตัวเดียวกัน ต้องตรงกันเป๊ะ
        $detail = tvm_container()->get(App\Services\TicketService::class)
            ->getTicketDetailData($ticketId, cm_owner(), []);
        $rendered = null;
        foreach ($detail['comments'] as $row) {
            if ((int) ($row['id'] ?? 0) === $commentId) {
                $rendered = $row;
                break;
            }
        }
        assert_true($rendered !== null, 'the comment is on the page');
        assert_same(
            (string) $rendered['visibility_label'],
            (string) $payload['visibility_label'],
            'the AJAX label and the rendered label are the same string — the badge cannot change language mid-thread'
        );
        assert_same((string) $rendered['visibility_tone'], (string) $payload['visibility_tone'], 'and the same tone');

        // โน้ตภายใน: ต้องเป็น comment ของช่างเอง (แก้ของคนอื่นไม่ได้ และ requester ตั้ง internal ไม่ได้)
        [$techCommentId, $techVersion] = cm_seed_comment($ticketId, cm_tech()['id']);
        $internal = cm_service()->updateComment($ticketId, $techCommentId, cm_tech(), [
            'body' => 'ทำเป็นโน้ตภายใน',
            'submission_token' => cm_token(),
            'original_version' => $techVersion,
            'is_internal' => '1',
        ]);
        assert_same('ภายใน', (string) ($internal['visibility_label'] ?? ''), 'and an internal note reads "ภายใน", not "Internal"');
    } finally {
        cm_cleanup($ticketId);
    }
});

// ── B-7: ส่งคอมเมนต์ซ้ำแล้วแนบไฟล์เพิ่ม ต้องไม่บอกว่าสำเร็จทั้งที่ไฟล์หาย ──
// token เดิม = ไม่สร้าง comment ซ้ำ (ถูกต้อง) แต่ไฟล์ที่แนบมารอบที่สองอยู่ใน if ($created) จึงถูกทิ้งเงียบ ๆ
// ทั้งที่ผ่านการตรวจไฟล์มาแล้ว — ผู้ใช้เห็นข้อความ "บันทึกเรียบร้อย" แล้วเข้าใจว่าไฟล์แนบไปด้วย
function cm_fake_upload(): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'cmb7_');
    // PNG 1x1 ของจริง — ตัวตรวจไฟล์อ่านชนิดจากเนื้อไฟล์ (finfo) ไม่ใช่จากนามสกุล
    file_put_contents($tmp, (string) base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=="));
    return [
        'name' => ['shot.png'],
        'type' => ['image/png'],
        'tmp_name' => [$tmp],
        'error' => [UPLOAD_ERR_OK],
        'size' => [(int) filesize($tmp)],
    ];
}

test('comment B-7: a resubmit carrying new files says so instead of reporting a false success', function (): void {
    $ticketId = cm_seed_ticket();
    $token = cm_token();
    $upload = cm_fake_upload();

    try {
        // ส่งครั้งแรก (ไม่มีไฟล์) — comment ถูกสร้าง
        cm_service()->createComment($ticketId, cm_owner(), ['body' => 'ข้อความแรก', 'submission_token' => $token], []);
        $countAfterFirst = (int) cm_pdo()->query("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = $ticketId")->fetchColumn();
        assert_same(1, $countAfterFirst, 'sanity: one comment exists');

        // ส่งซ้ำด้วย token เดิม คราวนี้แนบไฟล์มาด้วย
        $threw = false;
        try {
            cm_service()->createComment($ticketId, cm_owner(), ['body' => 'ข้อความแรก', 'submission_token' => $token], $upload);
        } catch (DomainException $e) {
            $threw = true;
            assert_contains_str('บันทึกไปแล้ว', $e->getMessage(), 'the user is told the message was already saved');
            assert_contains_str('ไฟล์แนบ', $e->getMessage(), 'and that the files from this attempt were NOT added');
        }
        assert_true($threw, 'a resubmit that would silently drop files must not report success');

        assert_same(1, (int) cm_pdo()->query("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = $ticketId")->fetchColumn(), 'still no duplicate comment');
        assert_same(0, (int) cm_pdo()->query("SELECT COUNT(*) FROM ticket_attachments WHERE ticket_id = $ticketId")->fetchColumn(), 'and nothing half-attached');
    } finally {
        foreach ($upload['tmp_name'] as $tmp) {
            @unlink($tmp);
        }
        cm_cleanup($ticketId);
    }
});

test('comment B-7: a plain double-click with no files stays a silent success', function (): void {
    $ticketId = cm_seed_ticket();
    $token = cm_token();

    try {
        cm_service()->createComment($ticketId, cm_owner(), ['body' => 'กดรัว', 'submission_token' => $token], []);
        cm_service()->createComment($ticketId, cm_owner(), ['body' => 'กดรัว', 'submission_token' => $token], []);

        assert_same(
            1,
            (int) cm_pdo()->query("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = $ticketId")->fetchColumn(),
            'the ordinary double-submit is still deduped quietly — no scary error for the common case'
        );
    } finally {
        cm_cleanup($ticketId);
    }
});
