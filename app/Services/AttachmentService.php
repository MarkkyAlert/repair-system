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
            // ⚠️ guard สำคัญ (ห้ามเอาออก): is_uploaded_file + ตรวจ MIME จากเนื้อไฟล์จริง (ด้านล่าง) กันอัปโหลดไฟล์อันตราย/หลอกให้อ่านไฟล์ระบบ
            // เช็ค is_uploaded_file ก่อนเสมอ ยืนยันว่าเป็นไฟล์ที่อัปโหลดมากับ request นี้จริง ไม่ใช่ path
            // ที่ถูกยัดค่ามาให้ชี้ไปไฟล์อื่นในเครื่อง (เช่น config) แล้วดูดออกไปเป็นไฟล์แนบ
            if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                throw new DomainException('ไม่สามารถอ่านไฟล์แนบได้ กรุณาลองใหม่');
            }
            if ((int) $file['size'] > $maxBytes) {
                throw new DomainException('รูปแนบแต่ละไฟล์ต้องมีขนาดไม่เกิน ' . (int) ($maxBytes / 1048576) . 'MB');
            }
            // ตัดสินชนิดไฟล์จากเนื้อไฟล์จริง (finfo อ่าน byte ต้นไฟล์) นามสกุลหรือชนิดที่ browser ส่งมา
            // ผู้ใช้ปลอมได้ตามใจ เช่นสคริปต์อันตรายเปลี่ยนชื่อเป็น .jpg; ชนิดที่ไม่อยู่ใน whitelist ปฏิเสธหมด
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
                // ชื่อไฟล์บนดิสก์สุ่มใหม่ทั้งหมด นามสกุลเอาจาก whitelist ตาม MIME ที่ตรวจจากเนื้อไฟล์เท่านั้น
                // ชื่อที่ผู้ใช้ตั้งไม่ได้ใช้บนดิสก์เลย กัน path traversal เช่น ../, ชื่อชนกัน, นามสกุลอันตราย
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
            // ลบไฟล์ที่ย้ายไปแล้วของการอัปโหลดที่ล้มเหลวนี้ทิ้ง; log ไฟล์ที่ลบไม่ได้ไว้ การอัปโหลดที่ค้างครึ่งทาง
            // จะได้ไม่ทิ้งไฟล์กำพร้า (orphan) ไว้แบบเงียบ ๆ.
            $this->purgeStoredFiles($storedPaths, 'attachment.store.cleanup', ['ticket' => $ticketId]);
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
     * attachment ที่ map แล้วของชุด comment id ที่ระบุ (สำหรับ feed แบบ live-poll). ใช้ตัวกรองไฟล์มีอยู่บนดิสก์จริง
     * และการ map ชุดเดียวกับ getTicketAttachments แต่จำกัดเฉพาะ comment ที่กำลัง render.
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

    /**
     * ลบไฟล์ที่เก็บไว้ แล้วคืน path ที่ unlink ไม่สำเร็จ ผู้เรียกจะได้เอาไป log ไฟล์กำพร้าได้ ไม่ปล่อยทิ้ง
     * ไว้บนดิสก์แบบเงียบ ๆ — ค่า boolean ของ @unlink เดิมถูกทิ้งไป.
     *
     * @param string[] $paths
     * @return string[] path ที่มีอยู่จริงแต่ลบไม่ได้
     */
    public function deleteStoredFiles(array $paths): array
    {
        $paths = array_values(array_unique(array_filter(
            array_map('strval', $paths),
            static fn (string $path): bool => trim($path) !== ''
        )));

        $failed = [];
        foreach ($paths as $path) {
            $fullPath = BASE_PATH . '/' . ltrim($path, '/');
            if (is_file($fullPath) && !@unlink($fullPath)) {
                $failed[] = $path;
            }
        }

        return $failed;
    }

    /**
     * ลบไฟล์ที่เก็บไว้ แล้ว log ไฟล์ที่ลบไม่ได้ผ่าน logger ตัวกลาง ไฟล์กำพร้าจะได้ทิ้งร่องรอยไว้ให้ทีม
     * support ไม่ถูกทิ้งแบบเงียบ ๆ. ทุก path ของ rollback/cleanup ควรเรียกตัวนี้ ไม่ใช่เรียก
     * deleteStoredFiles() ตรง ๆ (ซึ่งค่า boolean ที่คืนมาถูกลืมทิ้งได้ง่าย).
     *
     * @param string[] $paths
     * @param array<string, mixed> $context
     */
    public function purgeStoredFiles(array $paths, string $marker, array $context = []): void
    {
        $orphaned = $this->deleteStoredFiles($paths);
        if ($orphaned !== []) {
            log_caught_exception(
                $marker,
                new \RuntimeException('attachment file(s) could not be deleted from disk'),
                $context + ['orphans' => count($orphaned)]
            );
        }
    }

    public function getVisibleAttachment(int $attachmentId, array $viewer): array
    {
        $attachment = $this->attachments->findById($attachmentId);
        // กัน IDOR คือไล่เดา id เพื่อเปิดไฟล์ของคนอื่น: ทุกครั้งที่ดาวน์โหลดต้องพิสูจน์ซ้ำว่า viewer มองเห็น
        // ticket ต้นทางของไฟล์นี้ได้จริงตามสิทธิ์ ไม่ใช่แค่เช็คว่าไฟล์มีอยู่
        if ($attachment === null || $this->reads->findVisibleTicketById((int) $attachment['ticket_id'], $viewer) === null) {
            throw new DomainException('ไม่พบไฟล์แนบ');
        }
        // ไฟล์ที่แนบใน comment ภายใน (ทีมงานคุยกันเอง) ต้องไม่หลุดถึงผู้แจ้ง แม้จะรู้ id ไฟล์ตรง ๆ ก็ตาม
        if (!empty($attachment['is_internal']) && (string) ($viewer['role'] ?? 'guest') === 'requester') {
            throw new DomainException('ไม่มีสิทธิ์เปิดไฟล์แนบนี้');
        }
        $path = BASE_PATH . '/' . ltrim((string) $attachment['disk_path'], '/');
        if (!is_file($path)) {
            throw new RuntimeException('ไม่พบไฟล์แนบในพื้นที่จัดเก็บ');
        }

        // การอ่านไฟล์ล้มเหลว (permission, disk error, หรือ race หลังเช็ค is_file) ต้องไม่ถูกแปลงเป็น '' แล้ว
        // ส่งออกไปเป็นดาวน์โหลดเปล่า ๆ พร้อมสถานะ 200 — ให้ throw เป็น error ที่ controller จะ log แทน.
        $content = @file_get_contents($path); // '@' — ความล้มเหลวถูกแจ้งผ่านการ throw + controller log ไม่ใช่ผ่าน warning ดิบ ๆ ของ PHP
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
        $this->purgeStoredFiles($this->getCommentFilePaths($commentId), 'attachment.comment.cleanup', ['comment' => $commentId]);
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
