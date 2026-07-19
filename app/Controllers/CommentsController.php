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

    // ไม่ใช้ handleUpdate(): ตอบได้สองแบบ (dual-mode) — คืน JSON ให้ตัวแก้ไขแบบ AJAX ในหน้า
    // (X-Requested-With) หรือถอยไปใช้ flash+redirect เมื่อเป็นการ POST ฟอร์มธรรมดา.
    public function update(string $ticketId, string $commentId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        // ใช้ helper กลางสำหรับ content-negotiation (ตัวเลือกว่าจะตอบ format ไหน — ตัวเดียวกับที่ entry point + AuthMiddleware ใช้) แทนที่จะ
        // มาไล่เช็ก Accept / X-Requested-With ใหม่ตรงนี้ เพื่อไม่ให้การตัดสินใจว่าจะตอบ JSON หรือไม่คลาดเคลื่อนไปคนละทาง.
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
            throw $__infra; // error ระดับ infra (โครงสร้างพื้นฐาน) → ตัวจัดการ error ส่วนกลางจะ log แล้วส่ง 500 แบบทั่วไป ไม่หลุด SQL ออกไป
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
