<?php

declare(strict_types=1);

use App\Services\AttachmentService;
use App\Services\TicketService;

// bug-hunt B2 (2nd pass): internal comment/attachment visibility was gated on role !== 'requester' only. A staff
// member (technician/manager) who is the REQUESTER of a ticket (e.g. a technician who reported their own broken
// equipment) therefore saw the team-only internal notes/files on their own ticket. Visibility is now also gated
// on "viewer is not the requester of THIS ticket", so the ticket's requester never sees its internal content,
// whatever their role.
test('internal visibility B2: a staff member who is the ticket requester does NOT see its internal comments/attachments', function (): void {
    $pdo = tvm_container()->get(PDO::class);
    $tickets = tvm_container()->get(TicketService::class);
    $attachSvc = tvm_container()->get(AttachmentService::class);

    $techId = 3; // seeded technician — here also the REQUESTER of the ticket
    $pdo->prepare("INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, approval_status) VALUES (?, 'B2', 'x', ?, 1, 1, 1, 'in_progress', 'approved')")
        ->execute(['B2-' . bin2hex(random_bytes(4)), $techId]);
    $ticketId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal) VALUES (?, 4, ?, 0)')->execute([$ticketId, 'public note']);
    $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal) VALUES (?, 4, ?, 1)')->execute([$ticketId, 'internal note']);
    $internalCommentId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO ticket_attachments (ticket_id, comment_id, uploaded_by, original_name, stored_name, disk_path, mime_type, file_size, created_at) VALUES (?, ?, 4, 'int.pdf', ?, '/tmp/does-not-exist', 'application/pdf', 10, NOW())")
        ->execute([$ticketId, $internalCommentId, 'int_' . bin2hex(random_bytes(3))]);
    $internalAttId = (int) $pdo->lastInsertId();

    $techViewer = ['id' => $techId, 'role' => 'technician'];
    try {
        $state = $tickets->getTicketLiveState($ticketId, $techViewer);
        assert_true($state !== null, 'the technician-requester can see their own ticket');
        assert_same(1, (int) ($state['comment_count'] ?? -1), 'only the PUBLIC comment counts — the internal note is hidden from the ticket requester even though they are a technician');

        // the internal attachment download must be refused with an access-denied error (not fall through to file-read)
        $err = null;
        try {
            $attachSvc->getVisibleAttachment($internalAttId, $techViewer);
        } catch (Throwable $e) {
            $err = $e;
        }
        assert_true(
            $err instanceof DomainException && str_contains($err->getMessage(), 'ไม่มีสิทธิ์'),
            'the internal attachment download is refused for the ticket requester (any role) with an access-denied error'
        );
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades comments + attachments
    }
});
