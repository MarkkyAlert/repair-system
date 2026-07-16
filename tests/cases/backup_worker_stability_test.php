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
