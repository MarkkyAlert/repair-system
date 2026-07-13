<?php
declare(strict_types=1);

use App\Services\AttachmentService;

// Safety lock for the orphan-attachment cleanup cron (bin/cleanup-orphan-attachments.php →
// AttachmentService::cleanupOrphanFiles). The job deletes files under storage/uploads/tickets/ that have no
// row in ticket_attachments, to reclaim disk from partial-failure leftovers. The DANGEROUS part is the grace
// window: a file that was just written but whose DB row is not committed yet (an upload still in flight) must
// NOT be deleted — cleanupOrphanFiles skips any file whose mtime is newer than (now - graceSeconds). This test
// pins that guard: an OLD orphan is deleted, a RECENT orphan is skipped. Remove the mtime check and the recent
// file gets deleted → the test reddens (power-proof). (safety-review gap G4)

test('cleanup(grace): an old orphan is deleted but a RECENT orphan is skipped — grace guard vs in-flight uploads (G4)', function (): void {
    $subdir = BASE_PATH . '/storage/uploads/tickets/g4_' . bin2hex(random_bytes(6));
    if (!is_dir($subdir) && !mkdir($subdir, 0775, true) && !is_dir($subdir)) {
        throw new RuntimeException('could not create the test upload subdir');
    }

    $oldOrphan = $subdir . '/old_' . bin2hex(random_bytes(4)) . '.bin';
    $recentOrphan = $subdir . '/recent_' . bin2hex(random_bytes(4)) . '.bin';

    file_put_contents($oldOrphan, 'old orphan — not referenced in ticket_attachments');
    file_put_contents($recentOrphan, 'recent orphan — simulates an upload still in flight');
    touch($oldOrphan, time() - 7200); // 2h ago → older than the 1h grace → eligible for deletion

    $service = tvm_container()->get(AttachmentService::class);
    try {
        $result = $service->cleanupOrphanFiles(3600, false); // real delete, 1-hour grace window

        assert_false(is_file($oldOrphan), 'the old orphan (mtime past the grace window) is deleted');
        assert_true(
            is_file($recentOrphan),
            'the RECENT orphan is NOT deleted — the grace window guards an upload still in flight whose DB row has not committed yet'
        );
        assert_true((int) ($result['skipped_recent'] ?? 0) >= 1, 'the recent orphan is counted as skipped_recent');
    } finally {
        @unlink($oldOrphan);
        @unlink($recentOrphan);
        @rmdir($subdir);
    }
});
