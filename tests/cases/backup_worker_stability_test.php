<?php

declare(strict_types=1);

// error-review-7 F2: the backup worker ran `mysqldump | gzip` via a synchronous exec() with no deadline, so a
// stalled mysqldump (a hung DB endpoint) hung the cron forever — no heartbeat, a possible partial file left on
// disk. It now runs under a configurable BACKUP_TIMEOUT_SECONDS deadline (proc_open + poll), terminates on
// timeout, removes the partial file, and exits non-zero. This drives the REAL bin/backup-database.php in a
// subprocess with a fake mysqldump that SLEEPS, a short deadline, and a generous outer bound — the worker must
// self-terminate well before that bound (proving the deadline fired, not the test's own patience).

test('backup-worker(F2): a stalled mysqldump is killed at the deadline — worker exits non-zero, no partial file, no hang', function (): void {
    // a fake mysqldump that never returns within the deadline (bounded so a BROKEN deadline still fails in finite time)
    $fakeDump = tempnam(sys_get_temp_dir(), 'fakedump_');
    file_put_contents($fakeDump, "#!/bin/sh\nsleep 20\n");
    chmod($fakeDump, 0755);

    $backupDir = BASE_PATH . '/storage/backups';
    $before = glob($backupDir . '/db-*.sql.gz') ?: [];

    try {
        $script = BASE_PATH . '/bin/backup-database.php';
        $cmd = 'DB_NAME=repair_system_test'
            . ' MYSQLDUMP_BIN=' . escapeshellarg($fakeDump)
            . ' BACKUP_TIMEOUT_SECONDS=2'
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --keep=1000';

        $out = [];
        $exitCode = 0;
        $started = microtime(true);
        exec($cmd . ' 2>&1', $out, $exitCode);
        $elapsed = microtime(true) - $started;

        // OUTER WATCHDOG: the fake sleeps 20s; with the 2s deadline the worker must finish in a small multiple of
        // it. Well under 15s proves the worker's own deadline killed the child (not that the sleep just ended).
        assert_true($elapsed < 15.0, 'the worker self-terminates at its deadline (~2s), it does NOT hang for the full stall');
        assert_true($exitCode !== 0, 'a killed/stalled backup exits non-zero (the run failed, so the dashboard/monitor sees it)');
        assert_contains_str('deadline', implode("\n", $out), 'the timeout is reported so the operator knows why the backup failed');

        $after = glob($backupDir . '/db-*.sql.gz') ?: [];
        assert_same($before, $after, 'the partial backup file was removed — no truncated .sql.gz is left behind');
    } finally {
        @unlink($fakeDump);
        // safety net: remove any partial this run may have left if the assertion above ever regresses
        foreach (array_diff(glob($backupDir . '/db-*.sql.gz') ?: [], $before) as $leftover) {
            @unlink($leftover);
        }
    }
});

// error-review-8 F1: after the dump + rotation succeed, recording the heartbeat (cron_backup_last_run_at) needs
// the DB — and this CLI script had no global exception boundary, so a PDO failure there escaped as an UNCAUGHT
// fatal (exit 255). It's now caught → a controlled exit(1). Drives the real worker with a SUCCESSFUL fake dump
// but an unreachable DB port, so it fails precisely at the heartbeat (PDO is resolved lazily, first touched there).
test('backup-worker(F1): a heartbeat DB failure exits controlled (1), not an uncaught fatal (255)', function (): void {
    $fakeDump = tempnam(sys_get_temp_dir(), 'fakedumpok_');
    file_put_contents($fakeDump, "#!/bin/sh\necho '-- fake dump output'\n"); // succeeds with real (small) output
    chmod($fakeDump, 0755);

    $backupDir = BASE_PATH . '/storage/backups';
    $before = glob($backupDir . '/db-*.sql.gz') ?: [];

    try {
        $script = BASE_PATH . '/bin/backup-database.php';
        $cmd = 'DB_NAME=repair_system_test'
            . ' DB_PORT=59999'                       // unreachable → the heartbeat PDO connect fails
            . ' MYSQLDUMP_BIN=' . escapeshellarg($fakeDump)
            . ' BACKUP_TIMEOUT_SECONDS=30'
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --keep=1000';

        $out = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $out, $exitCode);
        $output = implode("\n", $out);

        assert_same(1, $exitCode, 'a post-dump heartbeat DB failure exits 1 (controlled), not 255 (uncaught fatal)');
        assert_true(!str_contains($output, 'Fatal error'), 'no PHP fatal error — the failure is handled');
        assert_true(!str_contains($output, 'Uncaught'), 'no uncaught exception escapes the CLI script');
        assert_contains_str('heartbeat', $output, 'the controlled failure explains that the backup ran but the heartbeat could not be recorded');
    } finally {
        @unlink($fakeDump);
        // the dump succeeds here, so a backup file is written and (correctly) kept — remove this run's file
        foreach (array_diff(glob($backupDir . '/db-*.sql.gz') ?: [], $before) as $leftover) {
            @unlink($leftover);
        }
    }
});

// error-review-9 F1 (Critical): the worker ran `mysqldump | gzip` via a shell, which reported gzip's exit code
// (still 0 when mysqldump failed), then only checked the compressed filesize (an empty gzip is > 0 bytes) — so a
// failed dump wrote an empty file, recorded the heartbeat, and reported SUCCESS. mysqldump now runs directly with
// its stdout gzipped in-process, so its real exit code is checked. A failing dump must exit non-zero, leave NO
// backup file, and NOT touch the heartbeat.
test('backup-worker(F1): a FAILED mysqldump exits non-zero, leaves no artifact, and does not record the heartbeat', function (): void {
    $settings = tvm_container()->get(\App\Repositories\SettingsRepository::class);
    $heartbeatBefore = (string) ($settings->getByKey('cron_backup_last_run_at')['setting_value'] ?? '');

    // a fake mysqldump that fails like the real one would (writes to stderr, non-zero exit)
    $fakeDump = tempnam(sys_get_temp_dir(), 'faildump_');
    file_put_contents($fakeDump, "#!/bin/sh\necho 'mysqldump: Got error: 2002: Cannot connect' >&2\nexit 7\n");
    chmod($fakeDump, 0755);

    $backupDir = BASE_PATH . '/storage/backups';
    $before = glob($backupDir . '/db-*.sql.gz') ?: [];

    try {
        $script = BASE_PATH . '/bin/backup-database.php';
        $cmd = 'DB_NAME=repair_system_test'
            . ' MYSQLDUMP_BIN=' . escapeshellarg($fakeDump)
            . ' BACKUP_TIMEOUT_SECONDS=30'
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --keep=1000';

        $out = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $out, $exitCode);
        $output = implode("\n", $out);

        assert_same(1, $exitCode, 'a failed mysqldump makes the worker exit non-zero (no more false success from gzip masking the error)');
        assert_true(!str_contains($output, '[backup] wrote'), 'no "[backup] wrote ..." success line is printed for a failed dump');
        assert_same($before, glob($backupDir . '/db-*.sql.gz') ?: [], 'no backup artifact is left behind for a failed dump');

        $heartbeatAfter = (string) ($settings->getByKey('cron_backup_last_run_at')['setting_value'] ?? '');
        assert_same($heartbeatBefore, $heartbeatAfter, 'the heartbeat is NOT recorded — the dashboard must not show a fresh backup');
    } finally {
        @unlink($fakeDump);
        foreach (array_diff(glob($backupDir . '/db-*.sql.gz') ?: [], $before) as $leftover) {
            @unlink($leftover);
        }
    }
});

// Phase-3 #4: mysqldump can succeed (exit 0, real SQL on stdout) while the *write* of the compressed file fails
// partway — a full disk / exhausted quota. gzwrite then reports a short count and, more reliably, gzclose fails
// to flush the final block. The old worker ignored BOTH return values: it counted bytes read from the pipe
// (sqlBytes), saw sqlBytes > 0 + exit 0, printed "wrote", and recorded a fresh heartbeat for a truncated file.
// A real disk-full cannot be induced deterministically/portably (RLIMIT raises SIGXFSZ which kills the process
// before its cleanup runs; darwin has no /dev/full; the output path isn't redirectable) — so, per this repo's
// convention for un-driveable CLI/exit paths (see docs/security-guards.md source-locks), this pins the worker
// source: gzwrite's result MUST be captured (no bare `gzwrite($gz, …);`), gzclose's result MUST be checked
// (no bare `gzclose($gz);`), and a write-error branch MUST remove the partial file and exit non-zero.
test('backup-worker(#4): the compressed-write path checks gzwrite/gzclose and drops a partial file on write failure', function (): void {
    $src = (string) file_get_contents(BASE_PATH . '/bin/backup-database.php');

    // no bare, result-ignoring gzwrite/gzclose statements (the exact shape of the old bug)
    assert_true(
        preg_match('/^\s*gzwrite\(\$gz\s*,[^;]*\);\s*$/m', $src) === 0,
        'gzwrite result must not be ignored — no bare "gzwrite($gz, …);" statement'
    );
    assert_true(
        preg_match('/^\s*gzclose\(\$gz\)\s*;\s*$/m', $src) === 0,
        'gzclose result must not be ignored — no bare "gzclose($gz);" statement'
    );

    // the write result is actually inspected: captured into a var and compared against the input length
    assert_contains_str('$written = gzwrite($gz, $data)', $src);
    assert_true(
        preg_match('/\$written\s*===\s*false\s*\|\|\s*\$written\s*<\s*strlen\(\$data\)/', $src) === 1,
        'a short/false gzwrite is treated as a write error'
    );
    // the final flush (gzclose) is checked too — disk-full usually surfaces here, not on gzwrite
    assert_contains_str('gzclose($gz) === false', $src);

    // and a write error removes the partial artifact + exits non-zero (never a false success/heartbeat)
    assert_true(
        preg_match('/if\s*\(\$writeError\)\s*\{.*?@unlink\(\$absolutePath\).*?exit\(1\)/s', $src) === 1,
        'on a write error the partial backup is unlinked and the worker exits non-zero'
    );
});

test('backup-worker(concurrency): a second live worker is rejected before it can write the same backup', function (): void {
    $fakeDump = tempnam(sys_get_temp_dir(), 'slowdump_');
    $startedMarker = tempnam(sys_get_temp_dir(), 'slowdump_started_');
    @unlink($startedMarker);
    file_put_contents(
        $fakeDump,
        "#!/bin/sh\n"
        . 'touch ' . escapeshellarg($startedMarker) . "\n"
        . "sleep 3\n"
        . "echo '-- concurrent backup probe'\n"
    );
    chmod($fakeDump, 0755);

    $settings = tvm_container()->get(\App\Repositories\SettingsRepository::class);
    $pdo = tvm_container()->get(PDO::class);
    $settingKeys = ['cron_backup_last_run_at', 'cron_backup_last_failed'];
    $originalSettings = [];
    foreach ($settingKeys as $key) {
        $originalSettings[$key] = $settings->getByKey($key);
    }

    $backupDir = BASE_PATH . '/storage/backups';
    $before = glob($backupDir . '/db-*.sql.gz') ?: [];
    $script = BASE_PATH . '/bin/backup-database.php';
    $cmd = 'DB_NAME=repair_system_test'
        . ' MYSQLDUMP_BIN=' . escapeshellarg($fakeDump)
        . ' BACKUP_TIMEOUT_SECONDS=10'
        . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --keep=1000';
    $firstPipes = [];
    $first = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $firstPipes);

    try {
        assert_true(is_resource($first), 'the first backup worker starts');

        $markerDeadline = microtime(true) + 5.0;
        while (!is_file($startedMarker) && microtime(true) < $markerDeadline) {
            usleep(50000);
        }
        assert_true(is_file($startedMarker), 'the first worker reached mysqldump and still holds the process lock');

        $secondOutput = [];
        $secondExit = 0;
        $secondStartedAt = microtime(true);
        exec($cmd . ' 2>&1', $secondOutput, $secondExit);
        $secondElapsed = microtime(true) - $secondStartedAt;

        assert_same(2, $secondExit, 'the overlapping worker exits 2 instead of writing another backup');
        assert_contains_str('already running', implode("\n", $secondOutput), 'the operator sees why the run was skipped');
        assert_true($secondElapsed < 2.0, 'the duplicate is rejected immediately, not after running mysqldump');
    } finally {
        if (is_resource($first)) {
            foreach ($firstPipes as $pipe) {
                if (is_resource($pipe)) {
                    stream_get_contents($pipe);
                    fclose($pipe);
                }
            }
            proc_close($first);
        }

        @unlink($fakeDump);
        @unlink($startedMarker);
        foreach (array_diff(glob($backupDir . '/db-*.sql.gz') ?: [], $before) as $leftover) {
            @unlink($leftover);
        }
        foreach ($originalSettings as $key => $row) {
            if ($row === null) {
                $pdo->prepare('DELETE FROM system_settings WHERE setting_key = ?')->execute([$key]);
                continue;
            }
            $settings->upsert(
                $key,
                $row['setting_value'] ?? null,
                (string) ($row['value_type'] ?? 'string'),
                (bool) ($row['is_public'] ?? false),
                (int) ($row['updated_by'] ?? 0)
            );
        }
    }
});
