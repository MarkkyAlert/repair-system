<?php
declare(strict_types=1);

use App\Repositories\SettingsRepository;
use App\Services\BackupService;

// Tests for BackupService::getStatus() — the read-only view-model behind the admin "สำรอง & กู้คืน" tab.
// Verifies staleness by last-run age (fresh/old/empty), real file listing from storage/backups/, and that the
// restore view-model carries the configured DB name + newest filename. Drops a temp db-*.sql.gz and restores
// the cron_backup_last_run_at setting to its original state (deletes it if it did not exist before).

test('backup status: staleness by last-run age + file listing + restore command', function (): void {
    $svc = tvm_container()->get(BackupService::class);
    $settings = tvm_container()->get(SettingsRepository::class);
    $pdo = tvm_container()->get(PDO::class);

    $original = $settings->getByKey('cron_backup_last_run_at');
    $dir = storage_path('backups');
    @mkdir($dir, 0775, true);
    $tmpName = 'db-2099-01-01_' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) . '.sql.gz';
    $tmpPath = $dir . '/' . $tmpName;

    try {
        file_put_contents($tmpPath, str_repeat('x', 2048));
        touch($tmpPath, time()); // ensure it is the newest file
        clearstatcache(true, $tmpPath); // getStatus() reads filemtime(); drop any cached stat so touch() is seen

        // fresh last-run → not stale, and the temp file is detected as newest
        $settings->upsert('cron_backup_last_run_at', date('Y-m-d H:i:s'), 'string', false, 0);
        $fresh = $svc->getStatus();
        assert_true($fresh['is_stale'] === false, 'recent last-run → not stale');
        assert_true($fresh['has_backups'] === true, 'detects backup files');
        assert_same($tmpName, $fresh['newest_file'], 'newest file is the temp file');
        assert_true($fresh['file_count'] >= 1, 'counts at least the temp file');
        assert_true(($fresh['newest_at'] ?? '') !== '', 'exposes newest file mtime');
        assert_same((string) config('db.name', 'repair_system'), (string) $fresh['restore']['db_name'], 'restore uses configured db name');
        assert_same($tmpName, (string) $fresh['restore']['newest_file'], 'restore references the newest file');

        // file-aware freshness: a recent backup FILE keeps status fresh even with no cron timestamp
        $settings->upsert('cron_backup_last_run_at', '', 'string', false, 0);
        assert_true($svc->getStatus()['is_stale'] === false, 'recent file → not stale even without cron timestamp');

        // stale only when the most recent evidence (file mtime AND cron timestamp) is old
        touch($tmpPath, time() - (BackupService::STALE_MINUTES + 60) * 60);
        clearstatcache(true, $tmpPath); // filemtime() caches per-process; without this getStatus() re-reads the pre-touch mtime
        $settings->upsert('cron_backup_last_run_at', date('Y-m-d H:i:s', time() - (BackupService::STALE_MINUTES + 60) * 60), 'string', false, 0);
        assert_true($svc->getStatus()['is_stale'] === true, 'old file + old last-run → stale');
    } finally {
        @unlink($tmpPath);
        if ($original === null) {
            $pdo->prepare('DELETE FROM system_settings WHERE setting_key = ?')->execute(['cron_backup_last_run_at']);
        } else {
            $settings->upsert(
                'cron_backup_last_run_at',
                $original['setting_value'] ?? null,
                (string) ($original['value_type'] ?? 'string'),
                (bool) ($original['is_public'] ?? false),
                (int) ($original['updated_by'] ?? 0)
            );
        }
    }
});
