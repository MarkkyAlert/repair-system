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

    public function show(string $attachmentId): void
    {
        AuthMiddleware::handle();

        try {
            $file = $this->attachments->getVisibleAttachment((int) $attachmentId, auth()->user() ?? []);
            Response::download($file['content'], $file['file_name'], $file['content_type'], 'inline');
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra (โครงสร้างพื้นฐาน) → ตัวจัดการ error ส่วนกลางจะ log แล้วส่ง 500 แบบทั่วไป ไม่หลุด SQL ออกไป
        } catch (DomainException|RuntimeException $exception) {
            // DomainException ตรงนี้คือ "ไม่พบ / ไม่มีสิทธิ์" (คาดไว้ → 404, ไม่ต้อง log). ส่วน RuntimeException
            // เป็นความผิดพลาดระดับปฏิบัติการ — เช่น มีแถวข้อมูลอยู่ แต่ไฟล์หายไปจากดิสก์ — ต้อง log ไว้ ไม่ใช่
            // เงียบ ๆ แล้วปิดบังเป็น 404.
            if ($exception instanceof RuntimeException) {
                log_caught_exception('attachment.serve', $exception, ['attachment' => (int) $attachmentId]);
            }
            Response::abort(404, 'ไม่พบไฟล์แนบ หรือคุณไม่มีสิทธิ์เปิดดู');
        }
    }
}
