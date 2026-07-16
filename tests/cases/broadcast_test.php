<?php

declare(strict_types=1);

use App\Services\NotificationService;

// R8-F2: a broadcast carries a one-time submission token so a retry (network hiccup) or a second tab does not
// re-notify the whole org (in-app + email). notifySystemAnnouncement is the chokepoint both the admin form and
// any caller go through. Drives the real service against the test DB and cleans up every row it creates.

function bc_service(): NotificationService
{
    return tvm_container()->get(NotificationService::class);
}

function bc_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function bc_notif_count(string $token): int
{
    $stmt = bc_pdo()->prepare('SELECT COUNT(*) FROM notifications WHERE submission_token = ?');
    $stmt->execute([$token]);

    return (int) $stmt->fetchColumn();
}

test('broadcast idempotency (R8-F2): the same token sends once; a replay is a no-op; a new token sends again', function (): void {
    $token = bin2hex(random_bytes(32));
    $token2 = bin2hex(random_bytes(32));
    $emailFloor = (int) bc_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM email_queue')->fetchColumn();

    try {
        // first send — reaches recipients and writes exactly one broadcast notification carrying the token
        $r1 = bc_service()->notifySystemAnnouncement('Maintenance tonight', 'Please save your work', 4, null, $token);
        assert_true(($r1['in_app_count'] ?? 0) > 0, 'the first send reaches at least one recipient');
        assert_same(1, bc_notif_count($token), 'exactly one broadcast notification is created for the token');
        $recipientsAfterFirst = (int) bc_pdo()->query('SELECT COUNT(*) FROM notification_recipients nr JOIN notifications n ON n.id = nr.notification_id WHERE n.submission_token = ' . bc_pdo()->quote($token))->fetchColumn();
        assert_true($recipientsAfterFirst > 0, 'recipients were written');

        $emailsAfterFirst = (int) bc_pdo()->query("SELECT COUNT(*) FROM email_queue WHERE id > $emailFloor")->fetchColumn();

        // replay the SAME token (retry / second tab) → no-op, no second notification, no extra recipients, no extra emails
        $r2 = bc_service()->notifySystemAnnouncement('Maintenance tonight', 'Please save your work', 4, null, $token);
        assert_true(($r2['duplicate'] ?? false) === true, 'the replay is reported as a duplicate no-op');
        assert_same(1, bc_notif_count($token), 'still exactly one notification — the replay did not re-send');
        assert_same($emailsAfterFirst, (int) bc_pdo()->query("SELECT COUNT(*) FROM email_queue WHERE id > $emailFloor")->fetchColumn(), 'the replay queued NO additional emails');
        $recipientsAfterReplay = (int) bc_pdo()->query('SELECT COUNT(*) FROM notification_recipients nr JOIN notifications n ON n.id = nr.notification_id WHERE n.submission_token = ' . bc_pdo()->quote($token))->fetchColumn();
        assert_same($recipientsAfterFirst, $recipientsAfterReplay, 'no extra recipients from the replay');

        // a genuinely new broadcast (fresh token) still goes out
        $r3 = bc_service()->notifySystemAnnouncement('Second notice', 'A different announcement', 4, null, $token2);
        assert_true(($r3['in_app_count'] ?? 0) > 0, 'a fresh token sends a new broadcast');
        assert_same(1, bc_notif_count($token2), 'the new token has its own notification');
    } finally {
        foreach ([$token, $token2] as $t) {
            bc_pdo()->prepare('DELETE FROM notifications WHERE submission_token = ?')->execute([$t]); // cascades recipients
        }
        bc_pdo()->prepare('DELETE FROM email_queue WHERE id > ?')->execute([$emailFloor]);
    }
});

// error-review F3: a broadcast reported the INTENDED email recipient count even when the enqueue failed, so the
// admin saw "email: N sent" for zero queued. The result must report the real outcome + an email_failed flag.
test('broadcast (F3): an email-enqueue failure is reported, not counted as sent', function (): void {
    $token = bin2hex(random_bytes(32));
    $c = tvm_container();

    $throwingEmails = new class () extends \App\Services\EmailQueueService {
        public function __construct()
        {
        }

        public function queueSystemAnnouncementEmails(array $recipientIds, string $title, string $message): void
        {
            throw new \RuntimeException('email queue backend down');
        }
    };
    $service = new \App\Services\NotificationService(
        $c->get(\App\Repositories\NotificationRepository::class),
        $c->get(\App\Repositories\TicketReadRepository::class),
        $throwingEmails,
        $c->get(\App\Repositories\NotificationPreferenceRepository::class),
        $c->get(\App\Repositories\UserRepository::class),
    );

    try {
        $result = $service->notifySystemAnnouncement('Maintenance', 'Please save your work', 4, null, $token);

        assert_true(($result['in_app_count'] ?? 0) > 0, 'the in-app announcement still posted');
        assert_same(0, (int) ($result['email_count'] ?? -1), 'no emails were queued → email_count is 0, not the intended count');
        assert_true(($result['email_failed'] ?? false) === true, 'the email failure is surfaced to the caller');
    } finally {
        bc_pdo()->prepare('DELETE FROM notifications WHERE submission_token = ?')->execute([$token]); // cascades recipients
    }
});
