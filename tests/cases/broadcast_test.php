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

// error-review-2 F1: the in-app write goes through dispatchNotification, which SWALLOWED a createNotification
// failure and returned — so the broadcast reported in_app_count = intended even when zero were written. The
// dispatch now returns success/failure; a swallowed in-app failure must surface as in_app_failed + count 0.
test('broadcast (F1): a SWALLOWED in-app write failure is reported (in_app_failed), not counted as posted', function (): void {
    $token = bin2hex(random_bytes(32));
    $c = tvm_container();

    $throwingRepo = new class ($c->get(PDO::class)) extends \App\Repositories\NotificationRepository {
        public function createNotification(array $payload, array $recipientIds): int
        {
            throw new \RuntimeException('notifications table unavailable');
        }
    };
    $service = new \App\Services\NotificationService(
        $throwingRepo,
        $c->get(\App\Repositories\TicketReadRepository::class),
        $c->get(\App\Services\EmailQueueService::class),
        $c->get(\App\Repositories\NotificationPreferenceRepository::class),
        $c->get(\App\Repositories\UserRepository::class),
    );

    $result = $service->notifySystemAnnouncement('Maintenance', 'Please save your work', 4, null, $token);

    assert_same(0, (int) ($result['in_app_count'] ?? -1), 'no in-app notifications were written → in_app_count is 0, not the intended count');
    assert_true(($result['in_app_failed'] ?? false) === true, 'the swallowed in-app failure is surfaced to the caller');
    // (no notification row was written, so nothing to clean up)
});

// bug-hunt HIGH#2: the in-app notification carries UNIQUE(submission_token); the email queue does not. Two
// concurrent broadcasts of the same token both pass the pre-check, then the loser's in-app INSERT fails the
// UNIQUE — but email had no such guard, so the whole org got a duplicate blast. Email must be gated on the
// in-app write winning the token claim.
test('broadcast (HIGH#2): email is NOT queued when the in-app token-claim is lost (no duplicate org-wide emails)', function (): void {
    $token = bin2hex(random_bytes(32));
    $c = tvm_container();

    $inAppLostRace = new class ($c->get(PDO::class)) extends \App\Repositories\NotificationRepository {
        public function createNotification(array $payload, array $recipientIds): int
        {
            throw new \RuntimeException('lost the UNIQUE(submission_token) race'); // the concurrent loser
        }
    };
    $emailSpy = new class () extends \App\Services\EmailQueueService {
        public int $calls = 0;
        public function __construct()
        {
        }
        public function queueSystemAnnouncementEmails(array $recipientIds, string $title, string $message): void
        {
            $this->calls++;
        }
    };
    $service = new \App\Services\NotificationService(
        $inAppLostRace,
        $c->get(\App\Repositories\TicketReadRepository::class),
        $emailSpy,
        $c->get(\App\Repositories\NotificationPreferenceRepository::class),
        $c->get(\App\Repositories\UserRepository::class),
    );

    $result = $service->notifySystemAnnouncement('Maintenance', 'msg', 4, null, $token);

    assert_same(0, $emailSpy->calls, 'email must NOT be queued when the in-app token-claim is lost, else duplicate org-wide emails');
    assert_true(($result['email_failed'] ?? false) === true, 'the skipped email is reported as not-sent');
});

// error-review-3 O4: the audit trail recorded 'broadcast.sent' unconditionally — even when a channel failed —
// contradicting what the admin is shown. The action must reflect the real outcome + carry the failure flags.
test('broadcast (O4): the audit action reflects the real outcome (partial on an email-only failure)', function (): void {
    $c = tvm_container();
    $pdo = bc_pdo();
    $auditFloor = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();
    $token = bin2hex(random_bytes(32));

    $throwingEmails = new class () extends \App\Services\EmailQueueService {
        public function __construct()
        {
        }

        public function queueSystemAnnouncementEmails(array $recipientIds, string $title, string $message): void
        {
            throw new \RuntimeException('email queue backend down');
        }
    };
    $notifier = new \App\Services\NotificationService(
        $c->get(\App\Repositories\NotificationRepository::class),
        $c->get(\App\Repositories\TicketReadRepository::class),
        $throwingEmails,
        $c->get(\App\Repositories\NotificationPreferenceRepository::class),
        $c->get(\App\Repositories\UserRepository::class),
    );
    $svc = new \App\Services\BroadcastService(
        $notifier,
        $c->get(\App\Services\MailerService::class),
        $c->get(\App\Services\EmailTemplateService::class),
        $c->get(\App\Services\AuditLogger::class),
    );

    try {
        $svc->sendBroadcast(['id' => 4, 'role' => 'admin', 'full_name' => 'Admin'], ['title' => 'Notice', 'message' => 'Body', 'submission_token' => $token]);

        $row = $pdo->query("SELECT action, context FROM audit_logs WHERE id > $auditFloor AND action LIKE 'broadcast.%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        assert_same('broadcast.partial', (string) ($row['action'] ?? ''), 'an email failure is audited as partial, not sent');
        assert_contains_str('"email_failed":true', (string) ($row['context'] ?? ''), 'the failure flag is recorded in the audit context');
    } finally {
        $pdo->prepare('DELETE FROM notifications WHERE submission_token = ?')->execute([$token]); // cascades recipients
        $pdo->prepare('DELETE FROM audit_logs WHERE id > ?')->execute([$auditFloor]);
    }
});
