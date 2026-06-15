<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AttachmentRepository;
use App\Repositories\TicketRepository;
use DomainException;
use RuntimeException;
use Throwable;

class AttachmentService
{
    private const MAX_FILES = 3;
    private const MAX_SIZE = 5242880;
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private AttachmentRepository $attachments,
        private TicketRepository $tickets,
    ) {
    }

    public function validateUploads(array $files): array
    {
        $normalized = $this->normalizeFiles($files);
        if (count($normalized) > self::MAX_FILES) {
            throw new DomainException('แนบรูปได้สูงสุด 3 รูปต่อครั้ง');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        foreach ($normalized as &$file) {
            if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                throw new DomainException('ไม่สามารถอ่านไฟล์แนบได้ กรุณาลองใหม่');
            }
            if ((int) $file['size'] > self::MAX_SIZE) {
                throw new DomainException('รูปแนบแต่ละไฟล์ต้องมีขนาดไม่เกิน 5MB');
            }
            $mime = (string) $finfo->file((string) $file['tmp_name']);
            if (!isset(self::MIME_EXTENSIONS[$mime])) {
                throw new DomainException('รองรับไฟล์แนบเฉพาะ JPEG, PNG และ WebP');
            }
            $file['mime_type'] = $mime;
            $file['extension'] = self::MIME_EXTENSIONS[$mime];
        }
        unset($file);

        return $normalized;
    }

    public function storeValidated(array $files, int $ticketId, int $uploadedBy, ?int $commentId = null): array
    {
        if ($files === []) {
            return [];
        }

        $relativeDirectory = 'storage/uploads/tickets/' . $ticketId;
        $directory = BASE_PATH . '/' . $relativeDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('ไม่สามารถสร้างพื้นที่จัดเก็บไฟล์แนบได้');
        }

        $storedPaths = [];

        try {
            foreach ($files as $file) {
                $storedName = bin2hex(random_bytes(20)) . '.' . (string) $file['extension'];
                $relativePath = $relativeDirectory . '/' . $storedName;
                if (!move_uploaded_file((string) $file['tmp_name'], BASE_PATH . '/' . $relativePath)) {
                    throw new RuntimeException('ไม่สามารถบันทึกไฟล์แนบได้');
                }

                $storedPaths[] = $relativePath;
                $this->attachments->create([
                    'ticket_id' => $ticketId,
                    'comment_id' => $commentId,
                    'uploaded_by' => $uploadedBy,
                    'original_name' => function_exists('mb_substr')
                        ? mb_substr(basename((string) $file['name']), 0, 255)
                        : substr(basename((string) $file['name']), 0, 255),
                    'stored_name' => $storedName,
                    'disk_path' => $relativePath,
                    'mime_type' => (string) $file['mime_type'],
                    'file_size' => (int) $file['size'],
                ]);
            }
        } catch (Throwable $exception) {
            $this->deleteStoredFiles($storedPaths);
            throw $exception;
        }

        return $storedPaths;
    }

    public function getTicketAttachments(int $ticketId, bool $includeInternal): array
    {
        $rows = array_filter(
            $this->attachments->getByTicketId($ticketId, $includeInternal),
            static fn (array $row): bool => is_file(BASE_PATH . '/' . ltrim((string) ($row['disk_path'] ?? ''), '/'))
        );

        return array_map(fn (array $row): array => $this->mapAttachment($row), array_values($rows));
    }

    public function deleteStoredFiles(array $paths): void
    {
        $paths = array_values(array_unique(array_filter(
            array_map('strval', $paths),
            static fn (string $path): bool => trim($path) !== ''
        )));

        foreach ($paths as $path) {
            $fullPath = BASE_PATH . '/' . ltrim($path, '/');
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    public function getVisibleAttachment(int $attachmentId, array $viewer): array
    {
        $attachment = $this->attachments->findById($attachmentId);
        if ($attachment === null || $this->tickets->findVisibleTicketById((int) $attachment['ticket_id'], $viewer) === null) {
            throw new DomainException('ไม่พบไฟล์แนบ');
        }
        if (!empty($attachment['is_internal']) && (string) ($viewer['role'] ?? 'guest') === 'requester') {
            throw new DomainException('ไม่มีสิทธิ์เปิดไฟล์แนบนี้');
        }
        $path = BASE_PATH . '/' . ltrim((string) $attachment['disk_path'], '/');
        if (!is_file($path)) {
            throw new RuntimeException('ไม่พบไฟล์แนบในพื้นที่จัดเก็บ');
        }

        return [
            'content' => (string) file_get_contents($path),
            'file_name' => (string) $attachment['original_name'],
            'content_type' => (string) $attachment['mime_type'],
        ];
    }

    public function deleteCommentFiles(int $commentId): void
    {
        foreach ($this->attachments->getByCommentId($commentId) as $attachment) {
            $path = BASE_PATH . '/' . ltrim((string) $attachment['disk_path'], '/');
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function normalizeFiles(array $files): array
    {
        if ($files === [] || !isset($files['name'])) {
            return [];
        }
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        $normalized = [];
        foreach ($names as $index => $name) {
            $error = is_array($files['error'] ?? null) ? ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) : ($files['error'] ?? UPLOAD_ERR_NO_FILE);
            if ((int) $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $normalized[] = [
                'name' => (string) $name,
                'tmp_name' => (string) (is_array($files['tmp_name'] ?? null) ? ($files['tmp_name'][$index] ?? '') : ($files['tmp_name'] ?? '')),
                'size' => (int) (is_array($files['size'] ?? null) ? ($files['size'][$index] ?? 0) : ($files['size'] ?? 0)),
                'error' => (int) $error,
            ];
        }

        return $normalized;
    }

    private function mapAttachment(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'comment_id' => (int) ($row['comment_id'] ?? 0),
            'name' => (string) $row['original_name'],
            'mime_type' => (string) $row['mime_type'],
            'size_label' => number_format(((int) $row['file_size']) / 1024, 0) . ' KB',
            'url' => '/attachments/' . (int) $row['id'],
        ];
    }
}
