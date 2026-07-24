<?php
declare(strict_types=1);

use App\Repositories\AssetRepository;
use App\Repositories\GuestTicketRequestRepository;
use App\Services\GuestTicketService;
use App\Services\LoginRateLimiter;
use App\Services\NotificationService;
use App\Services\TicketService;

// Regression tests for the Guest QR convert race fix. The advisory lock + status-check-under-lock
// must guarantee: one guest request → at most one ticket, and no orphan (created-but-unlinked) ticket
// on concurrent convert/reject or on createTicket failure. True 2-connection parallelism can't be unit
// tested (GET_LOCK is re-entrant per session), so these exercise the status-check guard — the invariant
// that holds every sequential ordering a race can resolve into.

function gc_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function gc_service(): GuestTicketService
{
    return tvm_container()->get(GuestTicketService::class);
}

function gc_tickets(): TicketService
{
    return tvm_container()->get(TicketService::class);
}

function gc_insert_request(array $overrides = []): int
{
    $cols = array_merge([
        'request_no' => 'GRTEST-' . bin2hex(random_bytes(4)),
        'guest_name' => 'Test Guest',
        'guest_email' => 'guest@example.com',
        'title' => 'guest convert race test',
        'description' => 'x',
        'location_id' => 1,
        'status' => 'new',
    ], $overrides);
    $fields = implode(', ', array_keys($cols));
    $placeholders = implode(', ', array_map(static fn (string $k): string => ":$k", array_keys($cols)));
    gc_pdo()->prepare("INSERT INTO guest_ticket_requests ($fields, created_at) VALUES ($placeholders, NOW())")->execute($cols);

    return (int) gc_pdo()->lastInsertId();
}

function gc_request_status(int $id): string
{
    return (string) gc_pdo()->query("SELECT status FROM guest_ticket_requests WHERE id = $id")->fetchColumn();
}

function gc_ticket_count(): int
{
    return (int) gc_pdo()->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
}

function gc_cleanup(int $requestId, array $ticketIds): void
{
    $pdo = gc_pdo();
    $pdo->prepare('DELETE FROM guest_ticket_requests WHERE id = ?')->execute([$requestId]);
    foreach ($ticketIds as $ticketId) {
        $pdo->prepare("DELETE FROM notification_recipients WHERE notification_id IN (SELECT id FROM notifications WHERE related_type='ticket' AND related_id=?)")->execute([$ticketId]);
        $pdo->prepare("DELETE FROM notifications WHERE related_type='ticket' AND related_id=?")->execute([$ticketId]);
        $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);   // children cascade
    }
}

test('guest convert: a malformed numeric priority/category ("1junk") is rejected, not coerced to 1 (round F1)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    foreach ([['1junk', 1], [1, '2junk']] as [$priority, $category]) {
        $reqId = gc_insert_request();
        $before = gc_ticket_count();
        try {
            $threw = false;
            try {
                gc_service()->convertToTicket($reqId, $admin, $priority, $category, gc_tickets());
            } catch (DomainException) {
                $threw = true;
            }
            assert_true($threw, 'a malformed priority/category must be rejected as non-integer');
            assert_same($before, gc_ticket_count(), 'no ticket is created on rejection');
            assert_same('new', gc_request_status($reqId), 'the request stays new (not converted)');
        } finally {
            gc_cleanup($reqId, []);
        }
    }
});

test('guest convert: convert then convert again → exactly one ticket', function (): void {
    $reqId = gc_insert_request();
    $created = [];
    try {
        $admin = ['id' => 4, 'role' => 'admin'];
        $before = gc_ticket_count();

        $ticketId = gc_service()->convertToTicket($reqId, $admin, 1, 1, gc_tickets());
        $created[] = $ticketId;
        assert_true($ticketId > 0, 'first convert creates a ticket');
        assert_same('converted', gc_request_status($reqId), 'request marked converted');
        assert_same($before + 1, gc_ticket_count(), 'exactly one ticket created');

        $threw = false;
        try {
            gc_service()->convertToTicket($reqId, $admin, 1, 1, gc_tickets());
        } catch (DomainException) {
            $threw = true;
        }
        assert_true($threw, 'second convert on same request throws');
        assert_same($before + 1, gc_ticket_count(), 'no duplicate ticket from second convert');
    } finally {
        gc_cleanup($reqId, $created);
    }
});

test('guest convert: reject then convert → no orphan ticket', function (): void {
    $reqId = gc_insert_request();
    try {
        $admin = ['id' => 4, 'role' => 'admin'];
        gc_service()->rejectRequest($reqId, $admin, 'ไม่รับคำขอ');
        assert_same('rejected', gc_request_status($reqId));

        $before = gc_ticket_count();
        $threw = false;
        try {
            gc_service()->convertToTicket($reqId, $admin, 1, 1, gc_tickets());
        } catch (DomainException) {
            $threw = true;
        }
        assert_true($threw, 'convert on a rejected request throws');
        assert_same($before, gc_ticket_count(), 'no ticket created for a rejected request');
    } finally {
        gc_cleanup($reqId, []);
    }
});

test('guest convert: convert then reject is blocked (status stays converted)', function (): void {
    $reqId = gc_insert_request();
    $created = [];
    try {
        $admin = ['id' => 4, 'role' => 'admin'];
        $created[] = gc_service()->convertToTicket($reqId, $admin, 1, 1, gc_tickets());

        $threw = false;
        try {
            gc_service()->rejectRequest($reqId, $admin, 'x');
        } catch (DomainException) {
            $threw = true;
        }
        assert_true($threw, 'reject on a converted request throws');
        assert_same('converted', gc_request_status($reqId), 'status stays converted');
    } finally {
        gc_cleanup($reqId, $created);
    }
});

test('guest convert: createTicket failure leaves request new + no orphan ticket', function (): void {
    $reqId = gc_insert_request();
    try {
        $admin = ['id' => 4, 'role' => 'admin'];
        $before = gc_ticket_count();

        $threw = false;
        try {
            // invalid priority/category → createTicket throws before any link happens
            gc_service()->convertToTicket($reqId, $admin, 999999, 999999, gc_tickets());
        } catch (DomainException) {
            $threw = true;
        }
        assert_true($threw, 'convert with invalid refs throws');
        assert_same($before, gc_ticket_count(), 'no ticket created on failure');
        assert_same('new', gc_request_status($reqId), 'request stays new (returned to queue)');
    } finally {
        gc_cleanup($reqId, []);
    }
});

test('guest convert: a claim/link failure AFTER the ticket is created rolls it back — no orphan (atomic)', function (): void {
    // The gap the older tests missed: createTicket SUCCEEDS but claimAndLink fails (abnormal path). Before the
    // atomic-transaction fix that left an orphan ticket (created but the request still 'new'). convert now wraps
    // create + claim/link in one transaction, so the ticket rolls back with the failed link.
    $reqId = gc_insert_request();
    try {
        $admin = ['id' => 4, 'role' => 'admin'];
        $pdo = gc_pdo();
        // real repo everywhere except claimAndLink, which we force to fail (simulate the abnormal path)
        $failingRepo = new class ($pdo) extends GuestTicketRequestRepository {
            public function claimAndLink(int $id, int $ticketId, int $reviewerId): bool
            {
                return false;
            }
        };
        $service = new GuestTicketService(
            $failingRepo,
            tvm_container()->get(AssetRepository::class),
            tvm_container()->get(LoginRateLimiter::class),
            tvm_container()->get(NotificationService::class),
            $pdo
        );

        $before = gc_ticket_count();
        $threw = false;
        try {
            $service->convertToTicket($reqId, $admin, 1, 1, gc_tickets());
        } catch (RuntimeException) {
            $threw = true;
        }
        assert_true($threw, 'convert surfaces the claim/link failure');
        assert_same($before, gc_ticket_count(), 'the created ticket was rolled back — no orphan ticket');
        assert_same('new', gc_request_status($reqId), 'the request is still new (safely retryable)');
    } finally {
        gc_cleanup($reqId, []);
    }
});

// F3 (logic review, business-confirmed): the real requester is the GUEST (no account, no department), so the
// converted ticket must NOT inherit the converter's department — otherwise the "แผนกผู้แจ้ง" report dimension
// counts outside-world tickets against the admin/manager's own department and skews per-department analytics.
// requester_id staying on the converter is deliberate (someone internal must hold the closure rights).
test('guest convert: converted ticket does not inherit the converter\'s department (F3)', function (): void {
    $requestId = gc_insert_request();
    $ticketId = 0;
    $converterDept = (int) gc_pdo()->query('SELECT id FROM departments ORDER BY id LIMIT 1')->fetchColumn();
    assert_true($converterDept > 0, 'a department exists to make the inheritance scenario real');

    try {
        $converter = ['id' => 4, 'role' => 'admin', 'department_id' => $converterDept];
        $ticketId = gc_service()->convertToTicket($requestId, $converter, 1, 1, gc_tickets());

        $row = gc_pdo()->query("SELECT requester_id, requester_department_id FROM tickets WHERE id = $ticketId")->fetch();
        assert_same(4, (int) $row['requester_id'], 'converter keeps the closure rights (requester_id, by design)');
        assert_true($row['requester_department_id'] === null, 'guest ticket reports as "ไม่ระบุแผนก" — never the converter\'s department');
    } finally {
        gc_cleanup($requestId, $ticketId > 0 ? [$ticketId] : []);
    }
});

// F2 (logic review): a guest request originates from a QR scan, so the converted ticket must record
// channel = qr — the repo used to hard-code 'web' for every ticket, mislabelling the work source in the
// ticket detail. requester_id/department are covered by the F3 test above; this pins the channel.
test('guest convert: the converted ticket records channel = qr (F2)', function (): void {
    $requestId = gc_insert_request();
    $ticketId = 0;
    try {
        $ticketId = gc_service()->convertToTicket($requestId, ['id' => 4, 'role' => 'admin'], 1, 1, gc_tickets());
        $channel = (string) gc_pdo()->query("SELECT channel FROM tickets WHERE id = $ticketId")->fetchColumn();
        assert_same('qr', $channel, 'a QR-originated guest request converts to a qr-channel ticket, not web');
    } finally {
        gc_cleanup($requestId, $ticketId > 0 ? [$ticketId] : []);
    }
});

// F4 (logic review): "[จาก Guest: <name>] <title>" can exceed the 200-char ticket-title limit (name ≤150 +
// title ≤200), so a successfully-submitted request could fail to convert. The composed title is capped at 200
// and the FULL original title is preserved in the description.
test('guest convert: a long name + max-length title still converts; the original title is preserved (F4)', function (): void {
    $longName = str_repeat('น', 140);   // within guest_name VARCHAR(150)
    $longTitle = str_repeat('ก', 200);  // the maximum ticket title
    $requestId = gc_insert_request(['guest_name' => $longName, 'title' => $longTitle]);
    $ticketId = 0;
    try {
        $ticketId = gc_service()->convertToTicket($requestId, ['id' => 4, 'role' => 'admin'], 1, 1, gc_tickets());
        assert_true($ticketId > 0, 'the oversized composed title did not block conversion');

        $row = gc_pdo()->query("SELECT title, description FROM tickets WHERE id = $ticketId")->fetch();
        assert_true(mb_strlen((string) $row['title']) <= 200, 'the stored title is within the 200-char limit');
        assert_true(str_contains((string) $row['description'], $longTitle), 'the FULL original title survives in the description');
    } finally {
        gc_cleanup($requestId, $ticketId > 0 ? [$ticketId] : []);
    }
});

test('guest convert: missing priority/category is rejected in the service before any lock/DB work', function (): void {
    // The required-input rule lives in convertToTicket (moved out of GuestRequestController) so it holds
    // for every caller and short-circuits before acquireConvertLock — the request must stay 'new'.
    $reqId = gc_insert_request();
    try {
        $admin = ['id' => 4, 'role' => 'admin'];
        $before = gc_ticket_count();

        foreach ([[0, 1], [1, 0], [0, 0], [-1, 5]] as [$priorityId, $categoryId]) {
            $err = '';
            try {
                gc_service()->convertToTicket($reqId, $admin, $priorityId, $categoryId, gc_tickets());
            } catch (DomainException $exception) {
                $err = $exception->getMessage();
            }
            assert_same('กรุณาเลือกความสำคัญและหมวดหมู่', $err, "priority=$priorityId category=$categoryId rejected");
        }

        assert_same($before, gc_ticket_count(), 'no ticket created for invalid priority/category');
        assert_same('new', gc_request_status($reqId), 'request stays new (nothing was locked or written)');
    } finally {
        gc_cleanup($reqId, []);
    }
});

// bug-hunt B3 (2nd pass): a guest request stores an asset/location snapshot at scan time. If the asset was moved
// or retired (or its location deactivated) before a manager converted the request, createTicket re-validated
// against LIVE reference data and threw ("Asset ที่เลือกไม่ได้อยู่ใน Location ที่ระบุ"), leaving the request stuck
// and un-convertible. Owner decision (B3): the convert must SUCCEED keeping the asset link (trusted scan
// snapshot), and raise an admin flag to review the asset status. createTicket now trusts the guest snapshot,
// and convertToTicket notifies approvers on drift.
test('guest convert B3: a moved scanned asset still converts, keeps the asset link, and flags admin for review', function (): void {
    $rid = bin2hex(random_bytes(3));
    $pdo = gc_pdo();
    $pdo->prepare("INSERT INTO assets (asset_code, name, asset_category_id, location_id, status, created_at, updated_at) VALUES (?, 'B3 Asset', 1, 1, 'active', NOW(), NOW())")->execute(["B3A-$rid"]);
    $assetId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO locations (code, name, is_active) VALUES (?, ?, 1)')->execute(["B3L-$rid", "B3 Loc $rid"]);
    $loc2 = (int) $pdo->lastInsertId();
    // guest scanned the asset while it was at location 1
    $reqId = gc_insert_request(['asset_id' => $assetId, 'location_id' => 1]);
    // AFTER the scan, the asset is moved to location 2 — the old code would reject the convert here
    $pdo->prepare('UPDATE assets SET location_id = ? WHERE id = ?')->execute([$loc2, $assetId]);

    $admin = ['id' => 4, 'role' => 'admin'];
    $ticketId = 0;
    try {
        $convertError = null;
        try {
            $ticketId = gc_service()->convertToTicket($reqId, $admin, 1, 1, gc_tickets());
        } catch (Throwable $e) {
            $convertError = $e->getMessage();
        }
        assert_true($ticketId > 0, 'the convert SUCCEEDS despite the asset having moved after the scan (error: ' . (string) $convertError . ')');
        assert_same($assetId, (int) $pdo->query("SELECT asset_id FROM tickets WHERE id = {$ticketId}")->fetchColumn(), 'the ticket keeps the scanned asset link');
        $flagged = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE type = 'asset.needs_review' AND related_type = 'ticket' AND related_id = {$ticketId}")->fetchColumn();
        assert_true($flagged > 0, 'an admin-review flag is raised for the drifted asset');
    } finally {
        gc_cleanup($reqId, $ticketId > 0 ? [$ticketId] : []);
        $pdo->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
        $pdo->prepare('DELETE FROM locations WHERE id = ?')->execute([$loc2]);
    }
});
