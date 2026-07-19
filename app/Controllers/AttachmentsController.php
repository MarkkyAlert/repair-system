<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\AttachmentService;
use DomainException;
use RuntimeException;

class AttachmentsController
{
    public function __construct(private AttachmentService $attachments)
    {
    }

    /**
     * ส่งไฟล์แนบของ ticket แบบ inline (GET, ต้องล็อกอิน) ผ่าน AttachmentService::getVisibleAttachment.
     * ผลข้างเคียง: ไม่เขียน DB — อ่านไฟล์จากดิสก์แล้ว stream ออก (Response::download inline, exit).
     * ความปลอดภัย: service กัน IDOR โดยเช็คว่า viewer เห็น ticket ต้นทางได้จริง + ไฟล์ใน comment ภายในไม่หลุดถึง requester; ไม่พบ/ไม่มีสิทธิ์/ไฟล์หาย → 404 (RuntimeException ถูก log ก่อน).
     */
    public function show(string $attachmentId): void
    {
        AuthMiddleware::handle();

        try {
            $file = $this->attachments->getVisibleAttachment((int) $attachmentId, auth()->user() ?? []);
            Response::download($file['content'], $file['file_name'], $file['content_type'], 'inline');
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
        } catch (DomainException|RuntimeException $exception) {
            // DomainException ตรงนี้คือ "ไม่พบ / ไม่มีสิทธิ์" (คาดไว้ → 404, ไม่ต้อง log). ส่วน RuntimeException
            // เป็นปัญหาระดับปฏิบัติการ — เช่น มีแถวข้อมูลอยู่ แต่ไฟล์หายไปจากดิสก์ — ต้อง log ไว้ อย่าปล่อย
            // เงียบ ๆ แล้วกลบเป็น 404.
            if ($exception instanceof RuntimeException) {
                log_caught_exception('attachment.serve', $exception, ['attachment' => (int) $attachmentId]);
            }
            Response::abort(404, 'ไม่พบไฟล์แนบ หรือคุณไม่มีสิทธิ์เปิดดู');
        }
    }
}
