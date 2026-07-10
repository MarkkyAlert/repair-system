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
