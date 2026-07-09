<?php
declare(strict_types=1);

use App\Services\AttachmentService;

// Locks the file+DB partial-write cleanup in AttachmentService::storeValidated: each file is moved to disk and
// then its attachment row is inserted; if an insert fails the catch must delete the already-moved file so no
// orphan is left behind (the same deleteStoredFiles() helper the Comment/Ticket services call on rollback).
// Runs for real via the move_uploaded_file shadow in tests/shadow_functions.php. Regression target: drop the
// cleanup and a failed DB write silently leaks a file on disk.

function astore_service(): AttachmentService
{
    return tvm_container()->get(AttachmentService::class);
}

function astore_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** A validated-upload entry shaped like AttachmentService::validateUploads() output, backed by a real temp file. */
function astore_entry(): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'astore_');
    file_put_contents($tmp, 'attachment safety test payload');

    return [
        'tmp_name' => $tmp,
        'extension' => 'txt',
        'name' => 'note.txt',
        'mime_type' => 'text/plain',
        'size' => (int) filesize($tmp),
    ];
}

/** Count files currently stored under a ticket's upload directory. */
function astore_file_count(int $ticketId): int
{
    $dir = BASE_PATH . '/storage/uploads/tickets/' . $ticketId;

    return is_dir($dir) ? count(glob($dir . '/*') ?: []) : 0;
}

test('attachment(atomicity): a failed attachment insert deletes the already-moved file (no orphan)', function (): void {
    $entry = astore_entry();
    $filesBefore = astore_file_count(1);
    $rowsBefore = (int) astore_pdo()->query('SELECT COUNT(*) FROM ticket_attachments WHERE ticket_id = 1')->fetchColumn();

    try {
        $threw = false;
        try {
            // uploaded_by = 999999 does not exist → fk_ticket_attachments_uploaded_by fails AFTER the file is moved
            astore_service()->storeValidated([$entry], 1, 999999);
        } catch (Throwable) {
            $threw = true;
        }

        assert_true($threw, 'a failing attachment insert must surface as an exception');
        assert_same($filesBefore, astore_file_count(1), 'the moved file is cleaned up — no orphan left on disk');
        assert_same(
            $rowsBefore,
            (int) astore_pdo()->query('SELECT COUNT(*) FROM ticket_attachments WHERE ticket_id = 1')->fetchColumn(),
            'no partial attachment row survives'
        );
    } finally {
        @unlink($entry['tmp_name']); // the shadow's rename usually consumes it; clean up if the copy-fallback ran
    }
});

test('attachment(happy): a valid store keeps the file on disk and records exactly one row', function (): void {
    $entry = astore_entry();
    $baselineId = (int) astore_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM ticket_attachments')->fetchColumn();
    $rowsBefore = (int) astore_pdo()->query('SELECT COUNT(*) FROM ticket_attachments WHERE ticket_id = 1')->fetchColumn();
    $stored = [];

    try {
        $stored = astore_service()->storeValidated([$entry], 1, 1); // uploaded_by = 1 exists (seed requester)
        assert_count(1, $stored, 'one stored path returned');
        assert_true(is_file(BASE_PATH . '/' . $stored[0]), 'the stored file exists on disk');
        assert_same(
            $rowsBefore + 1,
            (int) astore_pdo()->query('SELECT COUNT(*) FROM ticket_attachments WHERE ticket_id = 1')->fetchColumn(),
            'exactly one attachment row was recorded'
        );
    } finally {
        astore_service()->deleteStoredFiles($stored);
        astore_pdo()->prepare('DELETE FROM ticket_attachments WHERE id > ?')->execute([$baselineId]);
        @unlink($entry['tmp_name']);
    }
});
