<?php

declare(strict_types=1);

use App\Repositories\AttachmentRepository;

// Coverage for a previously untested surface. The security-relevant contract is the includeInternal filter:
// attachments under an INTERNAL comment must never reach a viewer who is not allowed to see internal notes.
// Ticket-level attachments (comment_id IS NULL) and attachments under public comments are always visible.

function ar_repo(): AttachmentRepository
{
    return tvm_container()->get(AttachmentRepository::class);
}

function ar_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('AttachmentRepository: getByTicketId hides internal-comment attachments unless includeInternal, and findById surfaces is_internal', function (): void {
    $pdo = ar_pdo();
    $repo = ar_repo();

    $pdo->prepare("INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, approval_status) VALUES (?, ?, 'x', 1, 1, 1, 1, 'submitted', 'pending')")
        ->execute(['ATT-' . bin2hex(random_bytes(4)), 'attachment repo test']);
    $ticketId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal) VALUES (?, 1, ?, 0)')->execute([$ticketId, 'public note']);
    $publicCommentId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal) VALUES (?, 1, ?, 1)')->execute([$ticketId, 'internal note']);
    $internalCommentId = (int) $pdo->lastInsertId();

    try {
        $mk = static fn (?int $commentId, string $tag): int => $repo->create([
            'ticket_id' => $ticketId,
            'comment_id' => $commentId,
            'uploaded_by' => 1,
            'original_name' => $tag . '.pdf',
            'stored_name' => $tag . '_' . bin2hex(random_bytes(3)),
            'disk_path' => '/tmp/' . $tag,
            'mime_type' => 'application/pdf',
            'file_size' => 10,
        ]);
        $ticketLevel = $mk(null, 'ticketlevel');       // attached straight to the ticket
        $onPublic = $mk($publicCommentId, 'onpublic');   // under a public comment
        $onInternal = $mk($internalCommentId, 'oninternal'); // under an internal comment

        $requesterView = array_column($repo->getByTicketId($ticketId, false), 'id');
        assert_true(in_array($ticketLevel, $requesterView, true), 'ticket-level attachment is visible to a requester');
        assert_true(in_array($onPublic, $requesterView, true), 'public-comment attachment is visible to a requester');
        assert_true(!in_array($onInternal, $requesterView, true), 'internal-comment attachment is HIDDEN from a requester (includeInternal=false)');

        $staffView = array_column($repo->getByTicketId($ticketId, true), 'id');
        assert_same(3, count($staffView), 'staff view (includeInternal=true) shows all three');
        assert_true(in_array($onInternal, $staffView, true), 'staff view includes the internal-comment attachment');

        $row = $repo->findById($onInternal);
        assert_true($row !== null, 'findById returns the row');
        assert_same(1, (int) ($row['is_internal'] ?? 0), 'findById joins the linked comment is_internal so the caller can gate downloads');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades comments + attachments
    }
});
