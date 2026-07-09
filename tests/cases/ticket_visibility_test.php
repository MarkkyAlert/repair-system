<?php
declare(strict_types=1);

use App\Repositories\TicketReadRepository;

// Tests for ticket-level authorization (IDOR guard). TicketReadRepository::findVisibleTicketById routes
// every read through visibilityClause(), the same role→scope clause used at 13 call sites: a requester
// sees only their own tickets, a technician sees tickets assigned to them or that they raised, and
// manager/admin see all. Attachment IDOR is already covered elsewhere, but the ticket-body scope had no
// direct test — a requester reading another user's ticket by guessing its id is the classic IDOR, and
// this locks that it returns null (not the row). Uses the seeded tickets read-only (no inserts): ticket#1
// is requester=1 / unassigned, ticket#2 is requester=1 / technician=3. Viewer arrays are passed straight
// into the query so the "other requester" case needs no real user row.

function tvis_repo(): TicketReadRepository
{
    return tvm_container()->get(TicketReadRepository::class);
}

test('ticket-visibility(allow): the owning requester, a manager, and an admin can read ticket #1', function (): void {
    $owner = tvis_repo()->findVisibleTicketById(1, ['id' => 1, 'role' => 'requester']);
    assert_true(is_array($owner), 'the owning requester sees their own ticket');
    assert_same(1, (int) $owner['id'], 'the row returned is ticket #1');

    $manager = tvis_repo()->findVisibleTicketById(1, ['id' => 2, 'role' => 'manager']);
    assert_true(is_array($manager), 'a manager sees any ticket');

    $admin = tvis_repo()->findVisibleTicketById(1, ['id' => 4, 'role' => 'admin']);
    assert_true(is_array($admin), 'an admin sees any ticket');
});

test('ticket-visibility(deny/IDOR): a non-owner requester reading someone else\'s ticket gets null', function (): void {
    // ticket #1 belongs to requester 1; requester 999 must not be able to read it by guessing the id
    $stranger = tvis_repo()->findVisibleTicketById(1, ['id' => 999, 'role' => 'requester']);
    assert_same(null, $stranger, 'a requester cannot read a ticket they do not own (IDOR blocked)');

    // a guest / unauthenticated viewer sees nothing at all (0 = 1)
    $guest = tvis_repo()->findVisibleTicketById(1, ['id' => 0, 'role' => 'guest']);
    assert_same(null, $guest, 'a guest cannot read an authenticated ticket');
});

test('ticket-visibility(technician): sees an assigned ticket, but not one neither assigned nor raised', function (): void {
    // ticket #2 is assigned to technician 3 → visible
    $assigned = tvis_repo()->findVisibleTicketById(2, ['id' => 3, 'role' => 'technician']);
    assert_true(is_array($assigned), 'a technician sees a ticket assigned to them');

    // ticket #1 is unassigned and raised by requester 1 → technician 3 must not see it
    $notMine = tvis_repo()->findVisibleTicketById(1, ['id' => 3, 'role' => 'technician']);
    assert_same(null, $notMine, 'a technician cannot read a ticket neither assigned to nor raised by them');
});
