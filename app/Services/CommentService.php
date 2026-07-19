<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\CommentRepository;
use App\Repositories\TicketReadRepository;
use DomainException;
use PDO;
use Throwable;

class CommentService
{
    public function __construct(
        private CommentRepository $comments,
        private TicketReadRepository $reads,
        private NotificationService $notifications,
        private AttachmentService $attachments,
        private PDO $db,
    ) {
    }

    public function createComment(int $ticketId, array $viewer, array $input, array $files = []): void
    {
        $ticket = $this->requireVisibleTicket($ticketId, $viewer);
        $validatedFiles = $this->attachments->validateUploads($files);
        $body = trim((string) ($input['body'] ?? ''));
        $isInternal = $this->parseInternalFlag($viewer, $input);
        $submissionToken = strtolower(trim((string) ($input['submission_token'] ?? '')));

        if ($body === '') {
            throw new DomainException('กรุณากรอกข้อความ comment ก่อนบันทึก');
        }

        if (!is_submission_token($submissionToken)) {
            throw new DomainException('แบบฟอร์ม comment หมดอายุ กรุณารีเฟรชหน้าแล้วลองอีกครั้ง');
        }

        $storedPaths = [];
        $commentId = 0;
        $created = false;

        try {
            // RISK MAP: การ insert comment + row/ไฟล์ของ attachment ต้องเป็น atomic (สำเร็จพร้อมกัน); การ cleanup storedPaths ต้องอยู่คู่กับทุก rollback.
            $this->db->beginTransaction();
            $result = $this->comments->createComment(
                $ticketId,
                (int) ($viewer['id'] ?? 0),
                $body,
                $isInternal,
                $submissionToken
            );

            $commentId = (int) ($result['id'] ?? 0);
            $created = (bool) ($result['created'] ?? false);

            if ($created) {
                $storedPaths = $this->attachments->storeValidated($validatedFiles, $ticketId, (int) ($viewer['id'] ?? 0), $commentId);
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // ย้อน (roll back) ไฟล์ที่เก็บไว้สำหรับ comment ที่ล้มเหลวนี้; log ไฟล์ที่ลบไม่ได้
            $this->attachments->purgeStoredFiles($storedPaths, 'comment.create.cleanup', ['ticket' => $ticketId]);
            throw $exception;
        }

        if ($created) {
            try {
                $this->notifications->notifyCommentEvent($ticketId, $commentId, (int) ($viewer['id'] ?? 0), $isInternal, $body, 'created');
            } catch (Throwable $exception) {
                // แจ้งเตือนแบบ best-effort — ต้องไม่ทำให้ comment ที่สร้างสำเร็จแล้วล้มเหลว แต่ความล้มเหลวต้อง
                // มองเห็นได้ (เดิมถูกกลืนแบบเงียบ ๆ ทำให้ notifier ที่พังไม่ทิ้งร่องรอยไว้เลย).
                log_caught_exception('comment.create.notify', $exception, ['ticket' => $ticketId, 'comment' => $commentId]);
            }
        }
    }

    public function updateComment(int $ticketId, int $commentId, array $viewer, array $input): array
    {
        $this->requireVisibleTicket($ticketId, $viewer);
        $comment = $this->requireEditableComment($ticketId, $commentId, $viewer);
        $body = trim((string) ($input['body'] ?? ''));
        $isInternal = $this->parseInternalFlag($viewer, $input, (bool) ($comment['is_internal'] ?? false));
        $originalVersion = (int) ($input['original_version'] ?? 0);

        if ($body === '') {
            throw new DomainException('กรุณากรอกข้อความ comment ก่อนบันทึก');
        }

        if ($originalVersion <= 0) {
            throw new DomainException('ข้อมูล comment ไม่ครบถ้วน กรุณารีเฟรชหน้าแล้วลองอีกครั้ง');
        }

        $this->comments->updateComment($commentId, $body, $isInternal, $originalVersion);
        // แจ้งเตือนแบบ best-effort (ให้เหมือน createComment/deleteComment): การแก้ไขถูกบันทึกไปแล้ว ดังนั้น
        // การแจ้งเตือนล้มเหลวต้องไม่โผล่มาเป็น error ให้ user ที่บันทึก comment สำเร็จเห็น.
        try {
            $this->notifications->notifyCommentEvent($ticketId, $commentId, (int) ($viewer['id'] ?? 0), $isInternal, $body, 'updated');
        } catch (\Throwable $exception) {
            log_caught_exception('comment.update.notify', $exception, ['comment' => $commentId]);
        }

        return [
            'id' => $commentId,
            'body' => $body,
            'is_internal' => $isInternal,
            'visibility_label' => $isInternal ? 'Internal' : 'Public',
            'visibility_tone' => $isInternal ? 'warning' : 'default',
        ];
    }

    public function deleteComment(int $ticketId, int $commentId, array $viewer): void
    {
        $this->requireVisibleTicket($ticketId, $viewer);
        $comment = $this->requireEditableComment($ticketId, $commentId, $viewer);

        $paths = $this->attachments->getCommentFilePaths($commentId);

        try {
            $this->db->beginTransaction();
            $this->comments->deleteComment($commentId);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        // ไฟล์ที่ unlink (ลบ) ไม่ได้จะถูก log ไว้ (ไม่ทิ้งแบบเงียบ ๆ) โดยไม่ทำให้การลบที่ commit ไปแล้ว
        // ล้มเหลว — helper purge ตัวกลางจะบันทึกไฟล์กำพร้าไว้.
        $this->attachments->purgeStoredFiles($paths, 'comment.delete.cleanup', ['comment' => $commentId]);

        try {
            $this->notifications->notifyCommentEvent(
                $ticketId,
                $commentId,
                (int) ($viewer['id'] ?? 0),
                (bool) ($comment['is_internal'] ?? false),
                (string) ($comment['body'] ?? ''),
                'deleted'
            );
        } catch (Throwable $exception) {
            // แจ้งเตือนแบบ best-effort — การลบ commit ไปแล้ว; ให้แจ้งความล้มเหลวออกมาแทนที่จะกลืนมันไว้.
            log_caught_exception('comment.delete.notify', $exception, ['ticket' => $ticketId, 'comment' => $commentId]);
        }
    }

    private function requireVisibleTicket(int $ticketId, array $viewer): array
    {
        $ticket = $this->reads->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null) {
            throw new DomainException('ไม่พบ ticket ที่ต้องการแสดงความคิดเห็น');
        }

        if ((int) ($viewer['id'] ?? 0) <= 0) {
            throw new DomainException('กรุณาเข้าสู่ระบบก่อนดำเนินการ comment');
        }

        return $ticket;
    }

    private function requireEditableComment(int $ticketId, int $commentId, array $viewer): array
    {
        $comment = $this->comments->findCommentById($commentId);
        if ($comment === null || (int) ($comment['ticket_id'] ?? 0) !== $ticketId) {
            throw new DomainException('ไม่พบ comment ที่ต้องการแก้ไข');
        }

        $viewerId = (int) ($viewer['id'] ?? 0);
        $role = (string) ($viewer['role'] ?? 'guest');
        $canManage = (int) ($comment['user_id'] ?? 0) === $viewerId || is_manager_or_admin($role);

        if (!$canManage) {
            throw new DomainException('คุณไม่มีสิทธิ์แก้ไข comment นี้');
        }

        return $comment;
    }

    private function parseInternalFlag(array $viewer, array $input, bool $default = false): bool
    {
        $role = (string) ($viewer['role'] ?? 'guest');
        if (!in_array($role, ['manager', 'admin', 'technician'], true)) {
            return false;
        }

        if (!array_key_exists('is_internal', $input)) {
            return $default;
        }

        return in_array((string) $input['is_internal'], ['1', 'true', 'on', 'yes'], true);
    }
}
