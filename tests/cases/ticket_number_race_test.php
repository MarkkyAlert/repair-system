<?php

declare(strict_types=1);

use App\Repositories\TicketRepository;

// Pre-ship sweep M-2: the guest→ticket conversion opens its transaction (GuestTicketService) BEFORE createTicket
// acquires the per-day ticket-number lock, so the InnoDB REPEATABLE-READ snapshot is pinned before the lock. If
// another session commits a same-day ticket while the convert waits for the lock, a plain snapshot read of the
// latest number misses it → a duplicate ticket_no → the INSERT fails on UNIQUE(ticket_no) → a bare 500 and the
// conversion rolls back. The normal create path is safe (it locks before beginTransaction); only the nested path
// loses that. The fix makes the number read a CURRENT read (FOR UPDATE) so it bypasses the caller-pinned snapshot.
//
// This reproduces the exact hazard with two real connections: A pins a snapshot, B commits a number, then A must
// still observe B's number when it generates the next one.

test('M-2: the next-ticket-number read sees a concurrent commit made after the snapshot was pinned (no duplicate)', function (): void {
    $cfg = tvm_container()->get('config')['db'];
    $connect = static fn (): PDO => new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4",
        (string) $cfg['username'],
        (string) $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $main = tvm_container()->get(PDO::class);
    // A far-future day so the prefix has no pre-existing tickets; clean any residue from an earlier aborted run.
    $requestedAt = '2099-03-15 10:00:00';
    $main->exec("DELETE FROM tickets WHERE ticket_no LIKE '%20990315%'");

    $locId = (int) $main->query('SELECT id FROM locations ORDER BY id LIMIT 1')->fetchColumn();
    $catId = (int) $main->query('SELECT id FROM ticket_categories ORDER BY id LIMIT 1')->fetchColumn();
    $priId = (int) $main->query('SELECT id FROM priorities ORDER BY id LIMIT 1')->fetchColumn();

    $connA = $connect();
    $connB = $connect();
    $gen = new ReflectionMethod(TicketRepository::class, 'generateNextTicketNumber');
    $gen->setAccessible(true);

    try {
        // The number B is about to take (no 2099-03-15 tickets exist yet → sequence 0001).
        $firstNumber = (string) $gen->invoke(new TicketRepository($connB), $requestedAt);

        // (1) A opens a transaction and pins its REPEATABLE-READ snapshot with a read — BEFORE B commits anything.
        $connA->beginTransaction();
        $connA->query('SELECT COUNT(*) FROM tickets')->fetchColumn();

        // (2) B commits a same-day ticket carrying that number, while A's snapshot is already frozen.
        $connB->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at)
             VALUES (?, 'race', 'race', 1, ?, ?, ?, 'pending_approval', ?)"
        )->execute([$firstNumber, $locId, $catId, $priId, $requestedAt]);

        // (3) A now generates the next number from inside its (stale-snapshot) transaction. With the FOR UPDATE
        // current read it must see B's freshly-committed row and move past it; a plain snapshot read would not.
        $aNumber = (string) $gen->invoke(new TicketRepository($connA), $requestedAt);

        assert_true(
            $aNumber !== $firstNumber,
            "the number generated after a concurrent commit must not collide with it (B took $firstNumber, A also produced $aNumber — a duplicate that would fail UNIQUE and 500 the conversion)"
        );
        // and it should be exactly the next sequence, proving it read B's value, not merely a different guess
        assert_same(
            substr($firstNumber, 0, -4) . str_pad((string) ((int) substr($firstNumber, -4) + 1), 4, '0', STR_PAD_LEFT),
            $aNumber,
            'A must produce B\'s number + 1'
        );
    } finally {
        if ($connA->inTransaction()) {
            $connA->rollBack();
        }
        $main->exec("DELETE FROM tickets WHERE ticket_no LIKE '%20990315%'");
    }
});
