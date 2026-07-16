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

// error-review-6 F2: the cron recorded its heartbeat (last_run_at) even when it left delete failures, and only
// print/exit 1 — so the dashboard (which checks freshness) showed a run that couldn't delete orphans as healthy.
// Now it records cron_orphan_cleanup_last_failed (count) and exits 2 (ran-with-failures), like the backup cron.
// Runs the REAL bin entrypoint in a subprocess pointed at the test DB (DB_NAME env → Env::get getenv fallback).
test('cleanup-cron(F2): a run that cannot delete an orphan records the failure count and exits non-zero', function (): void {
    $settings = tvm_container()->get(\App\Repositories\SettingsRepository::class);
    $origFailed = $settings->getByKey('cron_orphan_cleanup_last_failed');
    $origRun = $settings->getByKey('cron_orphan_cleanup_last_run_at');

    // an OLD orphan inside a READ-ONLY directory → cleanup finds it (past grace) but its @unlink fails → errors>=1
    $subdir = BASE_PATH . '/storage/uploads/tickets/f2ro_' . bin2hex(random_bytes(6));
    if (!is_dir($subdir) && !mkdir($subdir, 0775, true) && !is_dir($subdir)) {
        throw new RuntimeException('could not create the test upload subdir');
    }
    $orphan = $subdir . '/orphan_' . bin2hex(random_bytes(4)) . '.bin';
    file_put_contents($orphan, 'orphan not referenced in ticket_attachments');
    touch($orphan, time() - 7200); // older than the 1h grace → eligible for deletion
    chmod($subdir, 0555); // read-only dir → the unlink fails

    $script = BASE_PATH . '/bin/cleanup-orphan-attachments.php';
    $run = static function () use ($script): int {
        $out = [];
        $exitCode = 0;
        exec('DB_NAME=repair_system_test ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --grace=3600 2>/dev/null', $out, $exitCode);

        return $exitCode;
    };

    try {
        $exit = $run();
        assert_same(2, $exit, 'a completed run that left delete failures exits 2 (distinct from a crash 1 / success 0)');
        $failed = (int) ($settings->getByKey('cron_orphan_cleanup_last_failed')['setting_value'] ?? -1);
        assert_true($failed >= 1, 'the delete-failure count is recorded so the dashboard can warn (was heartbeat-only)');
        assert_true(is_file($orphan), 'the orphan still exists (it could not be unlinked)');

        // a CLEAN run (blocker removed) records 0 failures and exits 0 → the dashboard warning clears
        chmod($subdir, 0775);
        @unlink($orphan);
        $exit2 = $run();
        assert_same(0, $exit2, 'a clean run exits 0');
        assert_same('0', (string) ($settings->getByKey('cron_orphan_cleanup_last_failed')['setting_value'] ?? ''), 'a clean run writes 0 failures, clearing the warning');
    } finally {
        @chmod($subdir, 0775);
        @unlink($orphan);
        @rmdir($subdir);
        $pdo = tvm_container()->get(PDO::class);
        foreach (['cron_orphan_cleanup_last_failed' => $origFailed, 'cron_orphan_cleanup_last_run_at' => $origRun] as $key => $orig) {
            if ($orig === null) {
                $pdo->prepare('DELETE FROM system_settings WHERE setting_key = ?')->execute([$key]);
            } else {
                $settings->upsert($key, (string) ($orig['setting_value'] ?? ''), (string) ($orig['value_type'] ?? 'string'), (bool) ($orig['is_public'] ?? false), (int) ($orig['updated_by'] ?? 0));
            }
        }
    }
});
