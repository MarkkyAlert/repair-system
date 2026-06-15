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
    public function __construct(private CommentService $comments)
    {
    }

    public function store(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            clear_old_input();
            $this->comments->createComment((int) $ticketId, $viewer, $_POST, $_FILES['attachments'] ?? []);
            flash('success', 'บันทึก comment เรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            with_old_input([
                'comment_body' => (string) ($_POST['body'] ?? ''),
                'comment_is_internal' => (string) ($_POST['is_internal'] ?? ''),
                'comment_submission_token' => (string) ($_POST['submission_token'] ?? ''),
            ]);
            flash('error', $exception->getMessage());
        }

        Response::redirect('/tickets/' . (int) $ticketId . '#ticket-comments');
    }

    public function update(string $ticketId, string $commentId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $request = request();
        $acceptHeader = strtolower((string) ($request?->server['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string) ($request?->server['HTTP_X_REQUESTED_WITH'] ?? ''));
        $expectsJson = str_contains($acceptHeader, 'application/json') || $requestedWith === 'xmlhttprequest';

        try {
            csrf_validate();
            clear_old_input();
            $updatedComment = $this->comments->updateComment((int) $ticketId, (int) $commentId, $viewer, $_POST);

            if ($expectsJson) {
                Response::json([
                    'success' => true,
                    'message' => 'อัปเดต comment เรียบร้อยแล้ว',
                    'comment' => $updatedComment,
                ]);
            }

            flash('success', 'อัปเดต comment เรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            if ($expectsJson) {
                Response::json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                ], 422);
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
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            $this->comments->deleteComment((int) $ticketId, (int) $commentId, $viewer);
            flash('success', 'ลบ comment เรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect('/tickets/' . (int) $ticketId . '#ticket-comments');
    }
}
