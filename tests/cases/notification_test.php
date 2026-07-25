<?php
declare(strict_types=1);

use App\Repositories\NotificationRepository;
use App\Services\NotificationService;

// Tests for NotificationService recipient targeting + filters (not every method). Drives the real service
// against the test DB and asserts WHO ends up on each notification via notification_recipients — coverage the
// incidental (dashboard/viewmodel) tests never assert. Each test seeds fresh users + a ticket wired with a
// distinct requester / assigned_manager / assigned_technician, notifies, checks the recipient set, then deletes
// everything in finally (deleting the notification cascades its recipients; deleting a user cascades prefs).
// The notify path touches neither AuditLogger nor request(), so no Request bind is needed; email queueing is
// wrapped in try/catch inside the service and cannot fail a test.

function nt_service(): NotificationService
{
    return tvm_container()->get(NotificationService::class);
}

function nt_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function nt_seed_user(string $role = 'requester'): int
{
    $s = bin2hex(random_bytes(4));
    nt_pdo()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, "x", "NT User", ?, 1, NOW(), NOW())')
        ->execute(["nt_$s", "nt_$s@x.test", $role]);
    return (int) nt_pdo()->lastInsertId();
}

function nt_seed_ticket(int $requesterId, ?int $managerId, ?int $technicianId): int
{
    $pdo = nt_pdo();
    $loc = (int) $pdo->query('SELECT COALESCE((SELECT id FROM locations LIMIT 1), 1)')->fetchColumn();
    $cat = (int) $pdo->query('SELECT COALESCE((SELECT id FROM ticket_categories LIMIT 1), 1)')->fetchColumn();
    $pri = (int) $pdo->query('SELECT COALESCE((SELECT id FROM priorities LIMIT 1), 1)')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO tickets (ticket_no, title, description, requester_id, assigned_manager_id, assigned_technician_id, location_id, ticket_category_id, priority_id, status, requested_at)
         VALUES (?, "NT", "x", ?, ?, ?, ?, ?, ?, "in_progress", NOW())'
    )->execute(['NT-' . bin2hex(random_bytes(4)), $requesterId, $managerId, $technicianId, $loc, $cat, $pri]);
    return (int) $pdo->lastInsertId();
}

/** Newest notification row for a ticket (0 when none). */
function nt_last_notif_id(int $ticketId): int
{
    $stmt = nt_pdo()->prepare("SELECT COALESCE(MAX(id), 0) FROM notifications WHERE related_type = 'ticket' AND related_id = ?");
    $stmt->execute([$ticketId]);
    return (int) $stmt->fetchColumn();
}

/** Sorted, de-duplicated user_id set that received a given notification. */
function nt_recipients_of(int $notifId): array
{
    $stmt = nt_pdo()->prepare('SELECT user_id FROM notification_recipients WHERE notification_id = ? ORDER BY user_id');
    $stmt->execute([$notifId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function nt_set(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);
    return $ids;
}

function nt_cleanup(array $ticketIds, array $userIds): void
{
    $pdo = nt_pdo();
    foreach ($ticketIds as $ticketId) {
        $pdo->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
    }
    foreach ($ticketIds as $ticketId) {
        $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
    }
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
}

// ── filterRecipientIds (cross-cutting) ──

test('notify(IDOR): a user cannot mark ANOTHER user\'s notification as read', function (): void {
    // markAsRead scopes UPDATE … WHERE user_id = :viewer AND notification_id, and the viewer id comes from the
    // authenticated session (requireViewerId) — so passing someone else's notification id is a no-op. Lock it:
    // drop the user_id scope and user B silently marks user A's notification read.
    $userA = nt_seed_user('requester');
    $userB = nt_seed_user('requester');
    nt_pdo()->prepare("INSERT INTO notifications (type, title, message, related_type, related_id, created_at) VALUES ('test', 'x', 'x', 'ticket', 0, NOW())")->execute();
    $notifId = (int) nt_pdo()->lastInsertId();
    nt_pdo()->prepare('INSERT INTO notification_recipients (notification_id, user_id, is_read, read_at, created_at) VALUES (?, ?, 0, NULL, NOW())')->execute([$notifId, $userA]);

    try {
        // user B tries to mark A's notification read → must be a no-op for A's row
        nt_service()->markAsRead($notifId, ['id' => $userB, 'role' => 'requester']);
        $row = nt_pdo()->query("SELECT is_read, read_at FROM notification_recipients WHERE notification_id = $notifId AND user_id = $userA")->fetch();
        assert_same(0, (int) $row['is_read'], "A's notification stays unread — B cannot mark it");
        assert_true($row['read_at'] === null, "A's read_at is untouched");

        // sanity: the real owner CAN mark their own
        nt_service()->markAsRead($notifId, ['id' => $userA, 'role' => 'requester']);
        assert_same(1, (int) nt_pdo()->query("SELECT is_read FROM notification_recipients WHERE notification_id = $notifId AND user_id = $userA")->fetchColumn(), 'the owner can mark their own notification read');
    } finally {
        nt_pdo()->prepare('DELETE FROM notification_recipients WHERE notification_id = ?')->execute([$notifId]);
        nt_pdo()->prepare('DELETE FROM notifications WHERE id = ?')->execute([$notifId]);
        nt_cleanup([], [$userA, $userB]);
    }
});

// Phase-3 #6: the notification page applied the category filter AFTER slicing to a 25-row DB page. A user whose
// first page is all workflow notifications, with a single 'comment' notification further back, saw an EMPTY list
// under the "comment" filter — matching items on later pages were hidden by a page-1 miss. getNotificationPageData
// now aggregates + filters the FULL set first, then paginates the filtered result.
test('notify(page filter): a matching notification beyond raw page 1 still appears under its filter', function (): void {
    $uid = nt_seed_user('requester');
    $viewer = ['id' => $uid, 'role' => 'requester'];
    $insNotif = nt_pdo()->prepare(
        "INSERT INTO notifications (type, title, message, related_type, related_id, created_at) VALUES (?, ?, 'x', 'system', 0, ?)"
    );
    $insRecip = nt_pdo()->prepare(
        'INSERT INTO notification_recipients (notification_id, user_id, is_read, read_at, created_at) VALUES (?, ?, 0, NULL, NOW())'
    );
    $notifIds = [];

    try {
        // 30 newest notifications are all 'workflow' (type has neither .comment. nor .sla_breached.); each has a
        // distinct related_id-less identity so they stay separate threads (30 > the 25-row first page).
        for ($i = 0; $i < 30; $i++) {
            $insNotif->execute(['test.workflow.filler', 'filler ' . $i, sprintf('2099-01-01 00:%02d:00', $i)]);
            $id = (int) nt_pdo()->lastInsertId();
            $insRecip->execute([$id, $uid]);
            $notifIds[] = $id;
        }
        // the single 'comment' notification is the OLDEST → it lands on raw "page 2", never in the first 25 rows
        $insNotif->execute(['test.comment.new', 'a comment', '2000-01-01 00:00:00']);
        $commentId = (int) nt_pdo()->lastInsertId();
        $insRecip->execute([$commentId, $uid]);
        $notifIds[] = $commentId;

        $data = nt_service()->getNotificationPageData($viewer, ['filter' => 'comment', 'page' => 1]);
        $shownIds = array_map(static fn (array $n): int => (int) ($n['id'] ?? 0), $data['notifications']);

        assert_true(in_array($commentId, $shownIds, true), 'the comment notification appears under the comment filter even though it is beyond raw page 1');
        assert_same(1, (int) $data['pagination']['total'], 'filtered pagination total counts only the comment thread, not all 31 rows');
        assert_same('comment', $data['selectedFilter'], 'the selected filter is echoed back');

        // sanity: the unfiltered view still paginates the full aggregated set (31 threads → 25 on page 1)
        $all = nt_service()->getNotificationPageData($viewer, ['filter' => 'all', 'page' => 1]);
        assert_same(31, (int) $all['pagination']['total'], 'unfiltered total is every thread');
        assert_same(2, (int) $all['pagination']['totalPages'], '31 threads at 25/page → 2 pages');
        assert_same(25, count($all['notifications']), 'page 1 of the unfiltered view holds a full page');
    } finally {
        foreach ($notifIds as $id) {
            nt_pdo()->prepare('DELETE FROM notification_recipients WHERE notification_id = ?')->execute([$id]);
            nt_pdo()->prepare('DELETE FROM notifications WHERE id = ?')->execute([$id]);
        }
        nt_cleanup([], [$uid]);
    }
});

test('notify(filterRecipientIds): the actor is never notified of their own action', function (): void {
    $requester = nt_seed_user('requester');
    $manager = nt_seed_user('manager');
    $technician = nt_seed_user('technician');
    $ticketId = nt_seed_ticket($requester, $manager, $technician);
    try {
        // 'ticket.resolved' → [requester, manager]; the manager IS the actor here, so must be dropped
        nt_service()->notifyTicketEvent($ticketId, 'ticket.resolved', $manager);

        $recipients = nt_recipients_of(nt_last_notif_id($ticketId));
        assert_same([$requester], $recipients, 'only the requester is notified — the acting manager is excluded from their own event');
        assert_false(in_array($manager, $recipients, true), 'the actor did NOT notify themselves');
    } finally {
        nt_cleanup([$ticketId], [$requester, $manager, $technician]);
    }
});

test('notify(filterRecipientIds): duplicate recipient ids collapse to a single notification row', function (): void {
    // requester and manager are the SAME user — 'ticket.accepted' → [requester, manager] would be [X, X].
    $shared = nt_seed_user('manager');
    $technician = nt_seed_user('technician');
    $ticketId = nt_seed_ticket($shared, $shared, $technician);
    try {
        // actor = technician (not in the recipient list), so nothing is excluded by the actor rule
        nt_service()->notifyTicketEvent($ticketId, 'ticket.accepted', $technician);

        $recipients = nt_recipients_of(nt_last_notif_id($ticketId));
        assert_same([$shared], $recipients, 'the duplicated id is de-duped to one recipient (no UNIQUE violation, no double row)');
    } finally {
        nt_cleanup([$ticketId], [$shared, $technician]);
    }
});

test('notify(filterRecipientIds): an unassigned (0) recipient is dropped, not written', function (): void {
    $requester = nt_seed_user('requester');
    $manager = nt_seed_user('manager');
    // technician not yet assigned → NULL → context yields 0
    $ticketId = nt_seed_ticket($requester, $manager, null);
    try {
        // 'ticket.assigned' → [assigned_technician_id (0), requester]
        nt_service()->notifyTicketEvent($ticketId, 'ticket.assigned', $manager);

        $recipients = nt_recipients_of(nt_last_notif_id($ticketId));
        assert_same([$requester], $recipients, 'only the requester is notified');
        assert_false(in_array(0, $recipients, true), 'no notification_recipients row was written for user_id 0');
    } finally {
        nt_cleanup([$ticketId], [$requester, $manager]);
    }
});

// ── notifyTicketEvent recipient targeting ──

test('notify(targeting): assigned / completed / approved each reach exactly the mapped recipients', function (): void {
    $requester = nt_seed_user('requester');
    $manager = nt_seed_user('manager');
    $technician = nt_seed_user('technician');
    $other = nt_seed_user('admin'); // an actor who is never a mapped recipient of these events
    $ticketId = nt_seed_ticket($requester, $manager, $technician);
    try {
        // assigned → technician + requester (manager NOT notified)
        nt_service()->notifyTicketEvent($ticketId, 'ticket.assigned', $other);
        assert_same(nt_set([$technician, $requester]), nt_recipients_of(nt_last_notif_id($ticketId)), 'assigned → technician + requester');

        // completed → technician + manager (requester NOT notified)
        nt_service()->notifyTicketEvent($ticketId, 'ticket.completed', $other);
        $completed = nt_recipients_of(nt_last_notif_id($ticketId));
        assert_same(nt_set([$technician, $manager]), $completed, 'completed → technician + manager');
        assert_false(in_array($requester, $completed, true), 'the requester is NOT notified of completion');

        // approved → requester only
        nt_service()->notifyTicketEvent($ticketId, 'ticket.approved', $manager);
        assert_same([$requester], nt_recipients_of(nt_last_notif_id($ticketId)), 'approved → requester only');
    } finally {
        nt_cleanup([$ticketId], [$requester, $manager, $technician, $other]);
    }
});

// ── notifyCommentEvent internal boundary (confidentiality) ──

test('notify(comment/internal-boundary): an internal note excludes the requester; a public comment includes them', function (): void {
    $requester = nt_seed_user('requester');
    $manager = nt_seed_user('manager');
    $technician = nt_seed_user('technician');
    $actor = nt_seed_user('admin'); // acts, never a mapped recipient
    $ticketId = nt_seed_ticket($requester, $manager, $technician);
    try {
        // internal → manager + technician only; the requester must NOT be reachable
        nt_service()->notifyCommentEvent($ticketId, 111, $actor, true, 'confidential staff note', 'created');
        $internalRecipients = nt_recipients_of(nt_last_notif_id($ticketId));
        assert_same(nt_set([$manager, $technician]), $internalRecipients, 'internal note → manager + technician');
        assert_false(in_array($requester, $internalRecipients, true), 'the requester does NOT receive the internal note (no confidentiality leak)');

        // public → requester + manager + technician
        nt_service()->notifyCommentEvent($ticketId, 112, $actor, false, 'public reply', 'created');
        $publicRecipients = nt_recipients_of(nt_last_notif_id($ticketId));
        assert_same(nt_set([$requester, $manager, $technician]), $publicRecipients, 'public comment → requester + manager + technician');
        assert_true(in_array($requester, $publicRecipients, true), 'the requester DOES receive a public comment');
    } finally {
        nt_cleanup([$ticketId], [$requester, $manager, $technician, $actor]);
    }
});

// ── filterByPreference (per-channel opt-out) ──

test('notify(filterByPreference): an email opt-out drops the user from email but keeps them for in-app', function (): void {
    $optedOut = nt_seed_user('requester');
    $manager = nt_seed_user('manager');
    $technician = nt_seed_user('technician');
    try {
        // opt out of comment_added EMAIL only (opt-out model: is_enabled = 0)
        nt_pdo()->prepare('INSERT INTO notification_preferences (user_id, notification_type, channel, is_enabled) VALUES (?, "comment_added", "email", 0)')
            ->execute([$optedOut]);

        $recipients = [$optedOut, $manager, $technician];
        $inApp = call_private(nt_service(), 'filterByPreference', [$recipients, 'comment_added', 'in_app']);
        $email = call_private(nt_service(), 'filterByPreference', [$recipients, 'comment_added', 'email']);

        assert_true(in_array($optedOut, $inApp, true), 'the user still receives in-app (only email was disabled)');
        assert_false(in_array($optedOut, $email, true), 'the user is dropped from email recipients (opt-out respected)');
        assert_same(nt_set([$manager, $technician]), nt_set($email), 'the other recipients still receive email');
    } finally {
        // deleting the users cascades their notification_preferences
        nt_cleanup([], [$optedOut, $manager, $technician]);
    }
});

// ── markAsRead ownership scope ──

test('notify(markAsRead): a user cannot mark another user\'s notification as read (scoped by recipient)', function (): void {
    $requester = nt_seed_user('requester');
    $manager = nt_seed_user('manager');
    $technician = nt_seed_user('technician');
    $outsider = nt_seed_user('admin'); // not a recipient of the event below
    $ticketId = nt_seed_ticket($requester, $manager, $technician);
    try {
        // 'ticket.resolved' → [requester, manager]; actor = technician so both stay
        nt_service()->notifyTicketEvent($ticketId, 'ticket.resolved', $technician);
        $notifId = nt_last_notif_id($ticketId);

        $readState = static function (int $userId) use ($notifId): ?int {
            $stmt = nt_pdo()->prepare('SELECT is_read FROM notification_recipients WHERE notification_id = ? AND user_id = ?');
            $stmt->execute([$notifId, $userId]);
            $v = $stmt->fetchColumn();
            return $v === false ? null : (int) $v;
        };
        assert_same(0, $readState($manager), 'precondition: the manager\'s notification is unread');

        // an outsider (not a recipient) tries to mark it → no row of theirs exists → nothing changes
        nt_service()->markAsRead($notifId, ['id' => $outsider, 'role' => 'admin']);
        assert_same(0, $readState($manager), 'the outsider did NOT mark the manager\'s notification read');

        // the owner marks it → only their own recipient row flips
        nt_service()->markAsRead($notifId, ['id' => $manager, 'role' => 'manager']);
        assert_same(1, $readState($manager), 'the owner marked their own notification read');
        assert_same(0, $readState($requester), 'the other recipient\'s row is untouched (per-recipient scope)');
    } finally {
        nt_cleanup([$ticketId], [$requester, $manager, $technician, $outsider]);
    }
});

// ── ⭐ atomicity (G2): a failing recipient insert rolls back the parent notification — no orphan ──
// createNotification inserts the parent notification then N notification_recipients rows in one transaction
// (NotificationRepository owns the tx — single layer). Forcing a recipient insert to throw must roll the parent
// back, or an orphan notification with zero recipients would linger (visible to no one, skews any count-by-type).
// Uses the shared FailingPdo (tests/failing_pdo.php). Power-proof: flip the repo's rollBack()→commit() → red.
test('notify(atomicity): a failing recipient insert rolls back the parent notification — no orphan (G2)', function (): void {
    $marker = 'G2-atomicity-' . bin2hex(random_bytes(6)); // unique title so we can find any orphan
    $userId = nt_seed_user('requester'); // a real recipient so a notification_recipients insert actually runs
    $threw = false;

    with_failing_pdo('notification_recipients', function () use ($marker, $userId, &$threw): void {
        try {
            // fresh repo picks up the swapped failing PDO; parent notification inserts, then the recipient
            // insert throws mid-transaction → the whole thing must roll back
            tvm_container()->get(NotificationRepository::class)->createNotification(
                ['type' => 'ticket', 'title' => $marker, 'message' => 'atomicity probe', 'related_type' => 'ticket', 'related_id' => null],
                [$userId]
            );
        } catch (Throwable) {
            $threw = true;
        }
    });

    try {
        assert_true($threw, 'the injected recipient-insert failure must surface an error');

        $count = nt_pdo()->prepare('SELECT COUNT(*) FROM notifications WHERE title = ?');
        $count->execute([$marker]);
        assert_same(0, (int) $count->fetchColumn(), 'the parent notification was rolled back — no orphan notification without recipients');
    } finally {
        // defensive: if the rollback were defeated (power-proof), clean the orphan + its recipients
        $ids = nt_pdo()->prepare('SELECT id FROM notifications WHERE title = ?');
        $ids->execute([$marker]);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $nid) {
            nt_pdo()->prepare('DELETE FROM notification_recipients WHERE notification_id = ?')->execute([(int) $nid]);
            nt_pdo()->prepare('DELETE FROM notifications WHERE id = ?')->execute([(int) $nid]);
        }
        nt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
});

// ── ⭐ atomicity (F3): the notification-preference matrix save is all-or-nothing ──
// upsertMatrix writes one cell per (type, channel); a mid-save failure must not persist a half-saved matrix.
// It wraps the per-cell upserts in a transaction. Inject a failure on the SECOND notification_preferences write
// (skip=1) → assert NO cell persisted. Remove the transaction and the first cell commits → red (power-proof).
test('notify(pref atomicity): a failing cell rolls back the whole preference matrix — no half-save (F3)', function (): void {
    $userId = nt_seed_user('requester');
    $matrix = ['ticket.assigned' => ['email' => true, 'in_app' => false]]; // 2 cells → 2 upserts
    $threw = false;

    with_failing_pdo('notification_preferences', function () use ($userId, $matrix, &$threw): void {
        try {
            tvm_container()->get(\App\Repositories\NotificationPreferenceRepository::class)->upsertMatrix($userId, $matrix);
        } catch (Throwable) {
            $threw = true;
        }
    }, 1); // let the first cell write through, fail the second

    try {
        assert_true($threw, 'the injected second-cell failure must surface');
        $count = nt_pdo()->prepare('SELECT COUNT(*) FROM notification_preferences WHERE user_id = ?');
        $count->execute([$userId]);
        assert_same(0, (int) $count->fetchColumn(), 'no cell persisted — the matrix save is all-or-nothing');
    } finally {
        nt_pdo()->prepare('DELETE FROM notification_preferences WHERE user_id = ?')->execute([$userId]);
        nt_cleanup([], [$userId]);
    }
});

// bug-hunt R6-B2: a system-announcement broadcast is idempotent via a submission_token stored on the notifications
// row (UNIQUE). But dispatchNotification early-returned on an empty recipient set WITHOUT writing that row — so a
// broadcast whose in-app recipients were all opted out (in-app off, email on) queued emails yet never persisted the
// token. A retry then saw no token and re-sent duplicate emails to the whole group. dispatchNotification now still
// claims the token (writes the notifications row) when a submission_token is present, even with zero recipients.
test('notify(R6-B2): a broadcast with zero in-app recipients still claims its submission_token (no duplicate on retry)', function (): void {
    $svc = nt_service();
    $repo = tvm_container()->get(NotificationRepository::class);
    $token = bin2hex(random_bytes(16));

    $dispatch = new ReflectionMethod(NotificationService::class, 'dispatchNotification');
    $dispatch->setAccessible(true);

    try {
        assert_false($repo->broadcastTokenExists($token), 'precondition: token not yet used');

        // the "everyone opted out of in-app" branch: dispatch with a token but an EMPTY recipient set
        $ok = (bool) $dispatch->invoke($svc, [
            'type' => 'system.announcement',
            'title' => 't',
            'message' => 'm',
            'payload' => null,
            'related_type' => 'system',
            'related_id' => null,
            'submission_token' => $token,
        ], []);

        assert_true($ok, 'dispatch reports success');
        assert_true($repo->broadcastTokenExists($token), 'the submission_token is persisted even with zero recipients — a retry sees it and will NOT re-send emails');
    } finally {
        nt_pdo()->prepare('DELETE FROM notifications WHERE submission_token = ?')->execute([$token]);
    }
});
