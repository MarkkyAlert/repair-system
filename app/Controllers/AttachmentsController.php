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
            throw $__infra; // infra error → global handler logs + generic 500, never leaks SQL (error-review F1)
        } catch (DomainException|RuntimeException) {
            Response::abort(404, 'ไม่พบไฟล์แนบ หรือคุณไม่มีสิทธิ์เปิดดู');
        }
    }
}
