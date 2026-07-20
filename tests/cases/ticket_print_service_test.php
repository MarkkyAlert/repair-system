<?php

declare(strict_types=1);

use App\Services\TicketPrintService;

// Coverage for a previously untested surface. The logic unique to this service is paper-size normalization
// (only a4/a5 are allowed; anything else falls back to a4) and the visibility gate (a ticket the viewer cannot
// see yields null instead of a fabricated print / PDF).

test('TicketPrintService: paper size normalizes to a4/a5 only, and an invisible ticket returns null', function (): void {
    $svc = tvm_container()->get(TicketPrintService::class);
    $pdo = tvm_container()->get(PDO::class);
    $admin = ['id' => 4, 'role' => 'admin'];

    $pdo->prepare("INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, approval_status) VALUES (?, ?, 'x', 1, 1, 1, 1, 'submitted', 'pending')")
        ->execute(['PRT-' . bin2hex(random_bytes(4)), 'print service test']);
    $ticketId = (int) $pdo->lastInsertId();

    try {
        $a5 = $svc->getPrintableTicketData($ticketId, $admin, 'A5');
        assert_true($a5 !== null, 'an admin can print any ticket');
        assert_same('a5', $a5['paper'], 'A5 (any case) normalizes to a5');
        assert_same('A5', $a5['paper_label'], 'paper_label is the upper-cased size');

        assert_same('a4', $svc->getPrintableTicketData($ticketId, $admin, 'letter')['paper'], 'an unsupported paper size falls back to a4');
        assert_same('a4', $svc->getPrintableTicketData($ticketId, $admin, 'a3')['paper'], 'a3 is not allowed → a4');
        assert_same('a4', $svc->getPrintableTicketData($ticketId, $admin)['paper'], 'the default paper is a4');

        assert_true($svc->getPrintableTicketData(2000000000, $admin) === null, 'a ticket the viewer cannot see returns null, not a fabricated print');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
    }
});
