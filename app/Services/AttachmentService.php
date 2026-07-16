<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AttachmentRepository;
use App\Repositories\TicketReadRepository;
use DomainException;
use RuntimeException;
use Throwable;

class AttachmentService
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
    ];

    public function __construct(
        private AttachmentRepository $attachments,
        private TicketReadRepository $reads,
    ) {
    }

    public function validateUploads(array $files): array
    {
        $normalized = $this->normalizeFiles($files);
        $maxFiles = (int) config('uploads.attachment_max_files', 3);
        if (count($normalized) > $maxFiles) {
            throw new DomainException('แนบรูปได้สูงสุด ' . $maxFiles . ' รูปต่อครั้ง');
        }

        $maxBytes = (int) config('uploads.attachment_max_bytes', 5242880);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        foreach ($normalized as &$file) {
            if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                throw new DomainException('ไม่สามารถอ่านไฟล์แนบได้ กรุณาลองใหม่');
            }
            if ((int) $file['size'] > $maxBytes) {
                throw new DomainException('รูปแนบแต่ละไฟล์ต้องมีขนาดไม่เกิน ' . (int) ($maxBytes / 1048576) . 'MB');
            }
            $mime = (string) $finfo->file((string) $file['tmp_name']);
            if (!isset(self::MIME_EXTENSIONS[$mime])) {
                throw new DomainException('รองรับไฟล์แนบ: รูปภาพ (JPEG/PNG/WebP) และเอกสาร (PDF/Word/Excel/Text)');
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

    /**
     * Mapped attachments for a specific set of comment ids (live-poll feed). Same disk-existence filter and
     * mapping as getTicketAttachments, but scoped to the comments being rendered. (perf-review F4)
     *
     * @param int[] $commentIds
     * @return array<int, array<string, mixed>>
     */
    public function getAttachmentsForCommentIds(array $commentIds, bool $includeInternal): array
    {
        $rows = array_filter(
            $this->attachments->getByCommentIds($commentIds, $includeInternal),
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
        if ($attachment === null || $this->reads->findVisibleTicketById((int) $attachment['ticket_id'], $viewer) === null) {
            throw new DomainException('ไม่พบไฟล์แนบ');
        }
        if (!empty($attachment['is_internal']) && (string) ($viewer['role'] ?? 'guest') === 'requester') {
            throw new DomainException('ไม่มีสิทธิ์เปิดไฟล์แนบนี้');
        }
        $path = BASE_PATH . '/' . ltrim((string) $attachment['disk_path'], '/');
        if (!is_file($path)) {
            throw new RuntimeException('ไม่พบไฟล์แนบในพื้นที่จัดเก็บ');
        }

        // A read failure (permissions, disk error, a race after the is_file check) must NOT be cast to '' and
        // shipped as a 200 empty download — surface it as an error the controller logs. (error-review-3 O5)
        $content = @file_get_contents($path); // '@' — the failure is surfaced via the throw + controller log, not a raw PHP warning
        if ($content === false) {
            throw new RuntimeException('ไม่สามารถอ่านไฟล์แนบจากพื้นที่จัดเก็บ');
        }

        return [
            'content' => $content,
            'file_name' => (string) $attachment['original_name'],
            'content_type' => (string) $attachment['mime_type'],
        ];
    }

    public function deleteCommentFiles(int $commentId): void
    {
        $this->deleteStoredFiles($this->getCommentFilePaths($commentId));
    }

    public function getCommentFilePaths(int $commentId): array
    {
        return array_values(array_filter(array_map(
            static fn (array $attachment): string => (string) ($attachment['disk_path'] ?? ''),
            $this->attachments->getByCommentId($commentId)
        ), static fn (string $path): bool => trim($path) !== ''));
    }

    /**
     * สแกน storage/uploads/tickets/ หาไฟล์ที่ไม่มีอยู่ใน DB แล้วลบทิ้ง
     * - skip ไฟล์ที่ mtime < $graceSeconds (กัน race condition กับ upload ที่กำลังทำ)
     * - $dryRun = true จะ list อย่างเดียวไม่ลบ
     * คืน array สรุปผล: scanned, orphans, deleted, skipped_recent, kept, errors
     */
    public function cleanupOrphanFiles(int $graceSeconds = 3600, bool $dryRun = false): array
    {
        $root = BASE_PATH . '/storage/uploads/tickets';
        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal)) {
            return [
                'scanned' => 0, 'orphans' => 0, 'deleted' => 0,
                'skipped_recent' => 0, 'kept' => 0, 'errors' => 0,
                'orphan_paths' => [],
            ];
        }

        $known = $this->attachments->getAllStoredPathsLookup();
        $cutoff = time() - max(0, $graceSeconds);
        $scanned = 0;
        $orphans = 0;
        $deleted = 0;
        $skippedRecent = 0;
        $kept = 0;
        $errors = 0;
        $orphanPaths = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootReal, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $scanned++;

            $absolute = $fileInfo->getPathname();
            // ป้องกัน path traversal: ต้องอยู่ภายใต้ rootReal
            if (!str_starts_with($absolute, $rootReal . DIRECTORY_SEPARATOR)) {
                $errors++;
                continue;
            }

            $relative = 'storage/uploads/tickets/' . str_replace('\\', '/', substr($absolute, strlen($rootReal) + 1));

            if (isset($known[$relative])) {
                $kept++;
                continue;
            }

            if ($fileInfo->getMTime() > $cutoff) {
                $skippedRecent++;
                continue;
            }

            $orphans++;
            $orphanPaths[] = $relative;

            if ($dryRun) {
                continue;
            }

            if (@unlink($absolute)) {
                $deleted++;
            } else {
                $errors++;
            }
        }

        return [
            'scanned' => $scanned,
            'orphans' => $orphans,
            'deleted' => $deleted,
            'skipped_recent' => $skippedRecent,
            'kept' => $kept,
            'errors' => $errors,
            'orphan_paths' => $orphanPaths,
        ];
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
