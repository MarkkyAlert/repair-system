<?php
declare(strict_types=1);

use App\Repositories\TicketRepository;
use App\Services\SlaService;

// Tests for SlaService::processOverdueBreaches() — the cron batch that flips overdue pending SLA tracks to
// 'breached' and notifies the ticket's people once. Drives the real service against the test DB.
//
// ISOLATION NOTE: the batch is GLOBAL (getPendingOverdueSlaBreaches scans the whole DB), and the seed ships
// 3 pending-overdue tracks. So a bare run would breach those too and inflate `processed`. The first/last
// tests here act as a fixture: the first DRAINS the pre-existing overdue tracks to a known baseline (0) —
// after which every real test's `processed` reflects only the tracks IT seeds — and the last RESTORES the
// seed tracks and PURGES every notification created during this file's run, leaving shared state untouched.
// NotificationService's SLA path touches neither AuditLogger nor request() (verified), so no Request bind is
// needed here (email queueing is wrapped in try/catch inside notifySlaBreached and cannot fail the test).

function sla_service(): SlaService
{
    return tvm_container()->get(SlaService::class);
}

function sla_repo(): TicketRepository
{
    return tvm_container()->get(TicketRepository::class);
}

function sla_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function sla_seed_user(): int
{
    $s = bin2hex(random_bytes(4));
    sla_pdo()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, "x", "SLA User", "requester", 1, NOW(), NOW())')
        ->execute(["sla_$s", "sla_$s@x.t"]);
    return (int) sla_pdo()->lastInsertId();
}

/** A non-terminal ticket owned by $requesterId (its requester is a recipient of the breach notification). */
function sla_seed_ticket(int $requesterId, string $status = 'in_progress'): int
{
    $pdo = sla_pdo();
    $loc = (int) $pdo->query('SELECT COALESCE((SELECT id FROM locations LIMIT 1), 1)')->fetchColumn();
    $cat = (int) $pdo->query('SELECT COALESCE((SELECT id FROM ticket_categories LIMIT 1), 1)')->fetchColumn();
    $pri = (int) $pdo->query('SELECT COALESCE((SELECT id FROM priorities LIMIT 1), 1)')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at)
         VALUES (?, "SLA", "x", ?, ?, ?, ?, ?, NOW())'
    )->execute(['SLA-' . bin2hex(random_bytes(4)), $requesterId, $loc, $cat, $pri, $status]);
    return (int) $pdo->lastInsertId();
}

/** Seed a SLA track. $offsetSeconds < 0 → overdue (target_at in the past); > 0 → not yet due. */
function sla_seed_track(int $ticketId, string $metric, int $offsetSeconds, string $status = 'pending'): int
{
    $targetAt = date('Y-m-d H:i:s', time() + $offsetSeconds);
    sla_pdo()->prepare('INSERT INTO ticket_sla_tracks (ticket_id, metric_type, target_at, status, created_at) VALUES (?, ?, ?, ?, NOW())')
        ->execute([$ticketId, $metric, $targetAt, $status]);
    return (int) sla_pdo()->lastInsertId();
}

function sla_track_row(int $trackId): ?array
{
    $stmt = sla_pdo()->prepare('SELECT status, breached_at, target_at FROM ticket_sla_tracks WHERE id = ?');
    $stmt->execute([$trackId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** SLA-breach notifications created for a ticket. */
function sla_notif_count(int $ticketId): int
{
    $stmt = sla_pdo()->prepare("SELECT COUNT(*) FROM notifications WHERE related_type = 'ticket' AND related_id = ? AND type LIKE 'ticket.sla_breached.%'");
    $stmt->execute([$ticketId]);
    return (int) $stmt->fetchColumn();
}

/** Pending-overdue tracks the batch would process right now — mirrors getPendingOverdueSlaBreaches' WHERE exactly. */
function sla_count_pending_overdue(): int
{
    return (int) sla_pdo()->query(
        "SELECT COUNT(*)
         FROM ticket_sla_tracks ts
         INNER JOIN tickets t ON t.id = ts.ticket_id
         WHERE ts.status = 'pending' AND ts.target_at < NOW() AND t.status NOT IN (" . ticket_terminal_statuses_sql() . ")"
    )->fetchColumn();
}

/** Delete notifications for the tickets (cascades recipients), then the tickets (cascades their tracks), then the users. */
function sla_cleanup(array $ticketIds, array $userIds): void
{
    $pdo = sla_pdo();
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

// ── fixture: drain pre-existing seed overdue tracks so each test's `processed` isolates to what it seeds ──

test('sla(fixture): drain pre-existing overdue SLA tracks to a known baseline', function (): void {
    // snapshot the tracks about to be breached (restored by the teardown test) + the notification id floor
    // (so the teardown can purge every notification created during this file's run).
    $ids = sla_pdo()->query(
        "SELECT ts.id
         FROM ticket_sla_tracks ts
         INNER JOIN tickets t ON t.id = ts.ticket_id
         WHERE ts.status = 'pending' AND ts.target_at < NOW() AND t.status NOT IN (" . ticket_terminal_statuses_sql() . ")"
    )->fetchAll(PDO::FETCH_COLUMN);
    $GLOBALS['__sla_drained_ids'] = array_map('intval', $ids);
    $GLOBALS['__sla_notif_floor'] = (int) sla_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM notifications')->fetchColumn();

    sla_service()->processOverdueBreaches(); // breach every pre-existing overdue track now

    assert_same(0, sla_count_pending_overdue(), 'no pre-existing overdue pending tracks remain after the drain');
});

// ── the behaviour under test ──

test('sla: a notify failure is counted as notify_failed, NOT as notified (error-review F3)', function (): void {
    $userId = sla_seed_user();
    $ticketId = sla_seed_ticket($userId);
    $trackId = sla_seed_track($ticketId, 'resolution', -3600); // 1 hour overdue

    try {
        $throwingNotifier = new class () extends \App\Services\NotificationService {
            public function __construct()
            {
            }

            public function notifySlaBreached(int $ticketId, string $metricType): bool
            {
                throw new \RuntimeException('notification backend down');
            }
        };
        $service = new \App\Services\SlaService(
            tvm_container()->get(\App\Repositories\TicketRepository::class),
            tvm_container()->get(\App\Repositories\TicketReadRepository::class),
            $throwingNotifier,
        );

        $result = $service->processOverdueBreaches();

        assert_same(1, (int) $result['processed'], 'the breach is still marked (committed) despite the notify failure');
        assert_same(1, (int) $result['notify_failed'], 'the failed notification is counted as a failure');
        assert_same(0, (int) $result['notified'], 'and is NOT reported as a successful notification');
        assert_same('breached', sla_track_row($trackId)['status'], 'the track is breached regardless of the notify outcome');

        $mine = null;
        foreach ($result['items'] as $item) {
            if ((int) ($item['ticket_id'] ?? 0) === $ticketId) {
                $mine = $item;
                break;
            }
        }
        assert_true($mine !== null && ($mine['notified'] ?? null) === false, 'the item records notified=false');
    } finally {
        sla_cleanup([$ticketId], [$userId]);
    }
});

test('sla (F1): a SWALLOWED in-app dispatch failure is counted as notify_failed (real path, not just a thrown override)', function (): void {
    // The earlier test overrides notifySlaBreached to THROW. The real gap is subtler: dispatchNotification
    // catches the createNotification failure, logs it, and RETURNS — so notifySlaBreached never threw and the
    // failure was counted as a success. Drive the REAL NotificationService with only its repository throwing.
    $userId = sla_seed_user();
    $ticketId = sla_seed_ticket($userId);
    $trackId = sla_seed_track($ticketId, 'resolution', -3600);

    try {
        $c = tvm_container();
        $throwingRepo = new class ($c->get(PDO::class)) extends \App\Repositories\NotificationRepository {
            public function createNotification(array $payload, array $recipientIds): int
            {
                throw new \RuntimeException('notifications table unavailable');
            }
        };
        $realNotifier = new \App\Services\NotificationService(
            $throwingRepo,
            $c->get(\App\Repositories\TicketReadRepository::class),
            $c->get(\App\Services\EmailQueueService::class),
            $c->get(\App\Repositories\NotificationPreferenceRepository::class),
            $c->get(\App\Repositories\UserRepository::class),
        );
        $service = new \App\Services\SlaService(
            $c->get(\App\Repositories\TicketRepository::class),
            $c->get(\App\Repositories\TicketReadRepository::class),
            $realNotifier,
        );

        $result = $service->processOverdueBreaches();

        assert_same(1, (int) $result['processed'], 'the breach is still marked');
        assert_same(1, (int) $result['notify_failed'], 'the SWALLOWED dispatch failure is now counted (was 0 — reported as success)');
        assert_same(0, (int) $result['notified'], 'and is not reported as notified');
    } finally {
        sla_cleanup([$ticketId], [$userId]);
    }
});

test('sla: an overdue pending track is marked breached (status + breached_at) and counted', function (): void {
    $userId = sla_seed_user();
    $ticketId = sla_seed_ticket($userId);
    $trackId = sla_seed_track($ticketId, 'resolution', -3600); // 1 hour overdue
    try {
        $result = sla_service()->processOverdueBreaches();

        $row = sla_track_row($trackId);
        assert_same('breached', $row['status'], 'the overdue track is now breached');
        assert_true($row['breached_at'] !== null, 'breached_at is stamped');
        assert_same(1, (int) $result['processed'], 'exactly one track was processed (baseline drained)');

        $ticketIds = array_map(static fn (array $i): int => (int) $i['ticket_id'], $result['items']);
        assert_true(in_array($ticketId, $ticketIds, true), 'the breached ticket appears in the returned items');
    } finally {
        sla_cleanup([$ticketId], [$userId]);
    }
});

test('sla: a breach notifies the ticket recipients (a notification row is written)', function (): void {
    $userId = sla_seed_user();
    $ticketId = sla_seed_ticket($userId); // requester = fresh user with no opt-out → an in-app recipient
    $trackId = sla_seed_track($ticketId, 'resolution', -1800);
    try {
        assert_same(0, sla_notif_count($ticketId), 'precondition: no breach notification yet');

        sla_service()->processOverdueBreaches();

        assert_same(1, sla_notif_count($ticketId), 'notifySlaBreached wrote exactly one SLA-breach notification for the ticket');
    } finally {
        sla_cleanup([$ticketId], [$userId]);
    }
});

test('sla(idempotency): a second run breaches nothing new and does NOT notify again', function (): void {
    $userId = sla_seed_user();
    $ticketId = sla_seed_ticket($userId);
    $trackId = sla_seed_track($ticketId, 'resolution', -7200);
    try {
        $first = sla_service()->processOverdueBreaches();
        assert_same(1, (int) $first['processed'], 'first run breaches the track');
        assert_same('breached', sla_track_row($trackId)['status'], 'track is breached after the first run');
        assert_same(1, sla_notif_count($ticketId), 'first run notifies once');

        // cron fires again over the same (now-breached) track
        $second = sla_service()->processOverdueBreaches();
        assert_same(0, (int) $second['processed'], 'second run processes nothing (markSlaBreachedById returns false → skip)');
        assert_same(1, sla_notif_count($ticketId), 'NO duplicate notification on the second run');
    } finally {
        sla_cleanup([$ticketId], [$userId]);
    }
});

test('sla: a pending track that is not yet due is left untouched (not breached, not notified)', function (): void {
    $userId = sla_seed_user();
    $ticketId = sla_seed_ticket($userId);
    $trackId = sla_seed_track($ticketId, 'resolution', 3600); // due in 1 hour → NOT overdue
    try {
        $result = sla_service()->processOverdueBreaches();

        assert_same(0, (int) $result['processed'], 'a not-yet-due track is not processed');
        $row = sla_track_row($trackId);
        assert_same('pending', $row['status'], 'the track stays pending');
        assert_same(null, $row['breached_at'], 'breached_at stays null');
        assert_same(0, sla_notif_count($ticketId), 'no notification for a track that has not breached');
    } finally {
        sla_cleanup([$ticketId], [$userId]);
    }
});

test('sla: several overdue tracks are all processed and each is notified once', function (): void {
    $userId = sla_seed_user();
    $ticketIds = [];
    try {
        for ($i = 0; $i < 3; $i++) {
            $ticketId = sla_seed_ticket($userId);
            sla_seed_track($ticketId, 'resolution', -600 * ($i + 1));
            $ticketIds[] = $ticketId;
        }

        $result = sla_service()->processOverdueBreaches();

        assert_same(3, (int) $result['processed'], 'all three overdue tracks were processed');
        foreach ($ticketIds as $ticketId) {
            assert_same(1, sla_notif_count($ticketId), "ticket $ticketId got exactly one breach notification");
        }
    } finally {
        sla_cleanup($ticketIds, [$userId]);
    }
});

test('sla: markSlaBreachedById is the idempotency backstop — false for unknown / already-breached / not-yet-due tracks', function (): void {
    // These are exactly the cases SlaService skips via `if (!$marked) continue;`.
    $userId = sla_seed_user();
    $ticketId = sla_seed_ticket($userId);
    $overdueTrack = sla_seed_track($ticketId, 'resolution', -3600);
    $futureTrack = sla_seed_track($ticketId, 'response', 3600);
    try {
        $now = date('Y-m-d H:i:s');
        assert_false(sla_repo()->markSlaBreachedById(999999999, $now), 'an unknown track id marks nothing (→ skipped, no crash)');
        assert_true(sla_repo()->markSlaBreachedById($overdueTrack, $now), 'a pending overdue track is marked once');
        assert_false(sla_repo()->markSlaBreachedById($overdueTrack, $now), 'the already-breached track is NOT re-marked (idempotent)');
        assert_false(sla_repo()->markSlaBreachedById($futureTrack, $now), 'a not-yet-due track is not marked');
    } finally {
        sla_cleanup([$ticketId], [$userId]);
    }
});

// F3 (logic review): the cron processes a BATCH, and each breach is already committed before notifying. A
// failing notification for one ticket must not abort the loop — otherwise the remaining breaches go unmarked
// this run and the failed one is never retried (next run it is no longer "pending"). Notify is now best-effort.
test('sla(resilience): a failing notification does not abort the batch — every overdue track is still breached (F3)', function (): void {
    $userId = sla_seed_user();
    $ticketId = sla_seed_ticket($userId);
    $t1 = sla_seed_track($ticketId, 'response', -3600);
    $t2 = sla_seed_track($ticketId, 'resolution', -1800);
    try {
        $throwingNotifier = new class () extends \App\Services\NotificationService {
            public function __construct()
            {
            }

            public function notifySlaBreached(int $ticketId, string $metricType): bool
            {
                throw new \RuntimeException('notification backend down');
            }
        };
        $service = new \App\Services\SlaService(
            sla_repo(),
            tvm_container()->get(\App\Repositories\TicketReadRepository::class),
            $throwingNotifier,
        );

        $service->processOverdueBreaches(); // must NOT throw even though every notify fails

        $status = static fn (int $id): string => (string) sla_pdo()->query("SELECT status FROM ticket_sla_tracks WHERE id = $id")->fetchColumn();
        assert_same('breached', $status($t1), 'the first overdue track is breached even though its notification threw');
        assert_same('breached', $status($t2), 'the batch CONTINUED past the first failure and breached the second track too');
    } finally {
        sla_cleanup([$ticketId], [$userId]);
    }
});

// ── fixture teardown: undo everything this file did to shared state ──

test('sla(fixture): restore seed SLA tracks + purge notifications created during this run', function (): void {
    $ids = $GLOBALS['__sla_drained_ids'] ?? [];
    if ($ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        sla_pdo()->prepare("UPDATE ticket_sla_tracks SET status = 'pending', breached_at = NULL WHERE id IN ($placeholders)")->execute($ids);
    }
    $floor = (int) ($GLOBALS['__sla_notif_floor'] ?? 0);
    sla_pdo()->prepare('DELETE FROM notifications WHERE id > ?')->execute([$floor]); // cascades notification_recipients

    assert_same(count($ids), sla_count_pending_overdue(), 'seed overdue tracks are restored and no test tracks leaked');
});
