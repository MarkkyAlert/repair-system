<?php
declare(strict_types=1);

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
