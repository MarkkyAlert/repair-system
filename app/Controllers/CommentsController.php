<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\CommentService;
use DomainException;
use RuntimeException;

class CommentsController
{
    use HandlesFormSubmission;

    public function __construct(private CommentService $comments)
    {
    }

    public function store(string $ticketId): void
    {
        $this->handleUpdate(
            fn (array $viewer) => $this->comments->createComment((int) $ticketId, $viewer, $_POST, $_FILES['attachments'] ?? []),
            'บันทึก comment เรียบร้อยแล้ว',
            '/tickets/' . (int) $ticketId . '#ticket-comments',
            null,
            '',
            [
                'comment_body' => (string) ($_POST['body'] ?? ''),
                'comment_is_internal' => (string) ($_POST['is_internal'] ?? ''),
                'comment_submission_token' => (string) ($_POST['submission_token'] ?? ''),
            ]
        );
    }

    // Not handleUpdate(): dual-mode response — returns JSON for the inline AJAX editor
    // (X-Requested-With) or falls back to flash+redirect for a plain form POST.
    public function update(string $ticketId, string $commentId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        // use the shared content-negotiation helper (same one the entry point + AuthMiddleware use) rather than
        // re-deriving Accept / X-Requested-With here, so the JSON decision can't drift. (consistency-review)
        $expectsJson = request_wants_json(request()?->server);

        try {
            csrf_validate();
            clear_old_input();
            $updatedComment = $this->comments->updateComment((int) $ticketId, (int) $commentId, $viewer, $_POST);

            if ($expectsJson) {
                Response::jsonSuccess(['comment' => $updatedComment], 'อัปเดต comment เรียบร้อยแล้ว');
            }

            flash('success', 'อัปเดต comment เรียบร้อยแล้ว');
        } catch (\PDOException $__infra) {
            throw $__infra; // infra error → global handler logs + generic 500, never leaks SQL (error-review F1)
        } catch (DomainException|RuntimeException $exception) {
            if ($expectsJson) {
                Response::jsonError($exception->getMessage());
            }

            with_old_input([
                'comment_body' => (string) ($_POST['body'] ?? ''),
                'comment_is_internal' => (string) ($_POST['is_internal'] ?? ''),
                'editing_comment_id' => (string) $commentId,
            ]);
            flash('error', $exception->getMessage());
            Response::redirect('/tickets/' . (int) $ticketId . '?edit_comment=' . (int) $commentId . '#comment-' . (int) $commentId);

            return;
        }

        Response::redirect('/tickets/' . (int) $ticketId . '#comment-' . (int) $commentId);
    }

    public function delete(string $ticketId, string $commentId): void
    {
        $this->handleUpdate(
            fn (array $viewer) => $this->comments->deleteComment((int) $ticketId, (int) $commentId, $viewer),
            'ลบ comment เรียบร้อยแล้ว',
            '/tickets/' . (int) $ticketId . '#ticket-comments'
        );
    }
}
