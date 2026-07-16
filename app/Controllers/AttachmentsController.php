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
        } catch (DomainException|RuntimeException $exception) {
            // A DomainException here is "not found / no permission" (expected → 404, quiet). A RuntimeException
            // is operational — e.g. the row exists but the file is missing from disk — and must be logged, not
            // silently masked as a 404. (error-review-2 F2)
            if ($exception instanceof RuntimeException) {
                log_caught_exception('attachment.serve', $exception, ['attachment' => (int) $attachmentId]);
            }
            Response::abort(404, 'ไม่พบไฟล์แนบ หรือคุณไม่มีสิทธิ์เปิดดู');
        }
    }
}
