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

    // ไม่ใช้ handleUpdate(): ตอบได้สองแบบ — คืน JSON ให้ตัวแก้ไขแบบ AJAX ในหน้า
    // (X-Requested-With) หรือถอยไปใช้ flash+redirect เมื่อเป็นการ POST ฟอร์มธรรมดา.
    /**
     * แก้ไข comment ของ ticket (POST, ต้องล็อกอิน + CSRF) ผ่าน CommentService::updateComment.
     * ผลข้างเคียง: เขียนแถว comment ด้วย optimistic version lock + แจ้งเตือนแบบ best-effort (แก้สำเร็จแม้แจ้งเตือนพัง).
     * ตอบสองแบบ: คำขอที่ต้องการ JSON (AJAX แก้ inline) → Response::jsonSuccess/jsonError; POST ฟอร์มธรรมดา → flash แล้ว redirect กลับหน้า ticket.
     */
    public function update(string $ticketId, string $commentId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        // ใช้ helper กลางตัวเดียวกับที่ entry point + AuthMiddleware ใช้ตัดสินว่าจะตอบ format ไหน แทนที่จะ
        // มาไล่เช็ก Accept / X-Requested-With เองตรงนี้ กันไม่ให้การตัดสินใจว่าจะตอบ JSON ไหมเพี้ยนไปคนละทาง.
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
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
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
