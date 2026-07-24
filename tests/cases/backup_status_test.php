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
        // a REAL, non-empty gzip (getStatus now only counts restorable gzips, not any bytes). (error-review-9 F1)
        file_put_contents($tmpPath, (string) gzencode(str_repeat("-- SQL backup line;\n", 100)));
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
        clearstatcache(true, $tmpPath);
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

// error-review-9 F1: an empty/truncated/junk db-*.sql.gz (e.g. left by the old backup bug that let mysqldump
// failures through) must NOT be counted as a real backup or keep the status "fresh".
test('backup status: a non-gzip / empty-gzip file is not counted as a restorable backup', function (): void {
    $svc = tvm_container()->get(BackupService::class);
    $settings = tvm_container()->get(SettingsRepository::class);
    $pdo = tvm_container()->get(PDO::class);

    $original = $settings->getByKey('cron_backup_last_run_at');
    $dir = storage_path('backups');
    @mkdir($dir, 0775, true);
    $suffix = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $junkPath = $dir . '/db-2099-02-02_' . $suffix . '.sql.gz';   // not a gzip at all
    $emptyGzPath = $dir . '/db-2099-02-03_' . $suffix . '.sql.gz'; // valid gzip of EMPTY input (ISIZE 0)

    // isolate: no other valid backups + no cron heartbeat, so freshness depends solely on these files
    $existingValid = array_filter(glob($dir . '/db-*.sql.gz') ?: [], static fn (string $p): bool => is_file($p));

    try {
        file_put_contents($junkPath, str_repeat('x', 2048)); // 2KB of non-gzip bytes
        file_put_contents($emptyGzPath, (string) gzencode(''));
        touch($junkPath, time());
        touch($emptyGzPath, time());
        clearstatcache();
        $settings->upsert('cron_backup_last_run_at', '', 'string', false, 0); // no heartbeat evidence

        $status = $svc->getStatus();
        // neither junk file is the newest/counted backup
        assert_true(basename((string) ($status['newest_file'] ?? '')) !== basename($junkPath), 'a non-gzip file is not the newest backup');
        assert_true(basename((string) ($status['newest_file'] ?? '')) !== basename($emptyGzPath), 'an empty-input gzip is not the newest backup');
        if ($existingValid === []) {
            assert_true($status['has_backups'] === false, 'with only junk files present, there are NO valid backups');
            assert_true($status['is_stale'] === true, 'junk files do not keep the backup status fresh');
        }
    } finally {
        @unlink($junkPath);
        @unlink($emptyGzPath);
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

// Phase-3 #3: a gzip whose HEADER and TRAILER still look valid but whose BODY was cut off mid-write (the shape a
// disk-full/interrupted backup leaves) must NOT be counted as restorable. The old check only read the magic bytes
// + the last-4-byte ISIZE, so a truncated body with a plausible trailing ISIZE slipped through. isRestorableBackup
// now streams the whole gzip through zlib and requires a clean ZLIB_STREAM_END (CRC32 + ISIZE verified by zlib).
test('backup status: a gzip truncated mid-body (valid header + fake ISIZE) is NOT restorable', function (): void {
    $svc = tvm_container()->get(BackupService::class);
    $check = new ReflectionMethod(BackupService::class, 'isRestorableBackup');
    $check->setAccessible(true);
    $isRestorable = static fn (string $p): bool => (bool) $check->invoke($svc, $p);

    $good = (string) gzencode(str_repeat("INSERT INTO tickets VALUES (1,'x');\n", 400), 6);
    $len = strlen($good);

    // truncated: keep the header + first half of the compressed body, drop the rest, append a plausible non-zero
    // ISIZE. Old predicate: magic 1f8b ✓, last-4 ISIZE = 4096 > 0 ✓, size >= 18 ✓ → wrongly "restorable".
    $truncated = substr($good, 0, max(14, intdiv($len, 2))) . pack('V', 4096);
    // corrupt-in-the-middle: header + trailer intact, body bytes flipped → zlib data error before STREAM_END
    $corruptBody = $good;
    for ($i = intdiv($len, 2); $i < intdiv($len, 2) + 12 && $i < $len - 8; $i++) {
        $corruptBody[$i] = chr(ord($corruptBody[$i]) ^ 0xFF);
    }

    $goodPath = tempnam(sys_get_temp_dir(), 'bkpgood_');
    $truncPath = tempnam(sys_get_temp_dir(), 'bkptrunc_');
    $corruptPath = tempnam(sys_get_temp_dir(), 'bkpcorrupt_');

    try {
        file_put_contents($goodPath, $good);
        file_put_contents($truncPath, $truncated);
        file_put_contents($corruptPath, $corruptBody);

        assert_true($isRestorable($goodPath), 'a fully valid gzip backup is restorable');
        assert_true($isRestorable($truncPath) === false, 'a body-truncated gzip (fake ISIZE) is NOT restorable');
        assert_true($isRestorable($corruptPath) === false, 'a gzip with a corrupt body is NOT restorable');
    } finally {
        @unlink($goodPath);
        @unlink($truncPath);
        @unlink($corruptPath);
    }
});
