<?php
declare(strict_types=1);

/**
 * Database backup worker.
 *
 * Dumps the configured MySQL database via mysqldump → gzip into
 * storage/backups/db-YYYY-MM-DD_HHMMSS.sql.gz, then prunes the oldest files
 * beyond --keep (default 14).
 *
 * Password is passed via the MYSQL_PWD environment variable so it never
 * appears in `ps`/process listings.
 *
 * Usage:
 *   php bin/backup-database.php                # backup + keep last 14
 *   php bin/backup-database.php --keep=30      # keep last 30
 *   php bin/backup-database.php --dry-run      # show what would happen
 *
 * Recommended cron: daily 02:00
 *   0 2 * * * /path/to/php /path/to/bin/backup-database.php >> /var/log/maintenance-backup.log 2>&1
 */

use App\Repositories\SettingsRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'This script must be run from CLI.' . PHP_EOL);
    exit(2);
}

[$container] = require dirname(__DIR__) . '/bootstrap.php';

$keep = 14;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run' || $arg === '-n') {
        $dryRun = true;
        continue;
    }
    if (str_starts_with($arg, '--keep=')) {
        $keep = max(1, (int) substr($arg, 7));
        continue;
    }
    fwrite(STDERR, 'Unknown argument: ' . $arg . PHP_EOL);
    fwrite(STDERR, 'Usage: backup-database.php [--keep=N] [--dry-run]' . PHP_EOL);
    exit(2);
}

$host = (string) config('db.host', '127.0.0.1');
$port = (string) config('db.port', '3306');
$database = (string) config('db.name', '');
$username = (string) config('db.username', '');
$password = (string) config('db.password', '');
$charset = (string) config('db.charset', 'utf8mb4');
$mysqldumpBin = (string) env('MYSQLDUMP_BIN', 'mysqldump');

if ($database === '' || $username === '') {
    fwrite(STDERR, 'Database name/username missing in config.' . PHP_EOL);
    exit(1);
}

$backupDir = storage_path('backups');
if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    fwrite(STDERR, 'Cannot create backup directory: ' . $backupDir . PHP_EOL);
    exit(1);
}

$timestamp = date('Y-m-d_His');
$filename = sprintf('db-%s.sql.gz', $timestamp);
$absolutePath = $backupDir . '/' . $filename;

echo '[backup] mode=' . ($dryRun ? 'dry-run' : 'live')
    . ' keep=' . $keep
    . ' db=' . $database
    . PHP_EOL;
echo '[backup] target: ' . $absolutePath . PHP_EOL;

if (!$dryRun) {
    // Avoid leaking password in process listings — use MYSQL_PWD env var instead of --password=
    putenv('MYSQL_PWD=' . $password);

    // Run mysqldump DIRECTLY (array proc_open — no shell) and gzip its stdout in-process (PHP zlib), so a
    // mysqldump failure is caught via its OWN exit code. A `mysqldump | gzip` shell pipe reported gzip's status
    // (still 0 when mysqldump fails), which let an empty gzip pass as a "successful" backup. (error-review-9 F1)
    $dumpArgs = [
        $mysqldumpBin,
        '--host=' . $host,
        '--port=' . $port,
        '--user=' . $username,
        '--single-transaction',
        '--quick',
        '--default-character-set=' . $charset,
        '--no-tablespaces',
        $database,
    ];

    // Deadline so a stalled dump (a hung DB endpoint) can't hang the cron forever with no heartbeat. (error-review-7 F2)
    $timeoutSeconds = max(1, (int) env('BACKUP_TIMEOUT_SECONDS', 900));
    $exitCode = 0;
    $stderr = '';
    $timedOut = false;
    $sqlBytes = 0; // uncompressed SQL bytes actually produced by mysqldump — the real "did we back anything up" signal

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $proc = proc_open($dumpArgs, $descriptors, $pipes);
    if (!is_resource($proc)) {
        putenv('MYSQL_PWD');
        fwrite(STDERR, 'Cannot start mysqldump.' . PHP_EOL);
        @unlink($absolutePath);
        exit(1);
    }
    $gz = gzopen($absolutePath, 'wb6');
    if ($gz === false) {
        putenv('MYSQL_PWD');
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($proc, 9);
        proc_close($proc);
        fwrite(STDERR, 'Cannot open backup file for writing: ' . $absolutePath . PHP_EOL);
        @unlink($absolutePath);
        exit(1);
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $deadline = microtime(true) + $timeoutSeconds;
    while (true) {
        $chunk = fread($pipes[1], 65536);
        if (is_string($chunk) && $chunk !== '') {
            gzwrite($gz, $chunk);
            $sqlBytes += strlen($chunk);
        }
        $stderr .= (string) stream_get_contents($pipes[2]);

        $status = proc_get_status($proc);
        if (!$status['running']) {
            // drain anything buffered after the process exited
            while (is_string($rest = fread($pipes[1], 65536)) && $rest !== '') {
                gzwrite($gz, $rest);
                $sqlBytes += strlen($rest);
            }
            $stderr .= (string) stream_get_contents($pipes[2]);
            $exitCode = (int) $status['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($proc, 15); // SIGTERM
            usleep(500000);
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc, 9); // SIGKILL
            }
            break;
        }
        if (!is_string($chunk) || $chunk === '') {
            usleep(50000); // nothing ready yet — don't busy-spin
        }
    }

    gzclose($gz);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    putenv('MYSQL_PWD'); // clear the sensitive env var asap

    if ($timedOut) {
        fwrite(STDERR, 'mysqldump exceeded the ' . $timeoutSeconds . 's deadline — terminated; partial backup removed.' . PHP_EOL);
        @unlink($absolutePath);
        exit(1);
    }
    if ($exitCode !== 0) {
        fwrite(STDERR, 'mysqldump failed (exit ' . $exitCode . '): ' . trim($stderr) . PHP_EOL);
        @unlink($absolutePath);
        exit(1);
    }
    if ($sqlBytes === 0) {
        // dump exited 0 but produced no SQL — refuse to record an empty backup as a success (error-review-9 F1)
        fwrite(STDERR, 'mysqldump produced no SQL output — refusing to write an empty backup.' . PHP_EOL);
        @unlink($absolutePath);
        exit(1);
    }

    $size = is_file($absolutePath) ? (int) filesize($absolutePath) : 0;
    echo '[backup] wrote ' . number_format($size / 1024, 1) . ' KB (' . number_format($sqlBytes / 1024, 1) . ' KB SQL)' . PHP_EOL;
}

// Rotation — keep newest N files, delete the rest
$existing = glob($backupDir . '/db-*.sql.gz') ?: [];
usort($existing, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

$toDelete = array_slice($existing, $keep);
$deletedCount = 0;
$deleteFailures = 0;
foreach ($toDelete as $oldFile) {
    if ($dryRun) {
        echo '[backup] would delete: ' . basename($oldFile) . PHP_EOL;
        $deletedCount++;
    } elseif (@unlink($oldFile)) {
        echo '[backup] deleted: ' . basename($oldFile) . PHP_EOL;
        $deletedCount++;
    } else {
        fwrite(STDERR, '[backup] failed to delete: ' . basename($oldFile) . PHP_EOL);
        $deleteFailures++;
    }
}

if (!$dryRun) {
    // The dump + rotation already succeeded; recording the heartbeat needs the DB, which can be unreachable at
    // this point (connection dropped, system_settings unavailable). A throw here would escape as an UNCAUGHT
    // fatal (exit 255) — this CLI script has no global exception boundary — so catch it and exit(1) cleanly, so
    // the scheduler sees a controlled failure instead of a crash. (error-review-8 F1)
    try {
        $settings = $container->get(SettingsRepository::class);
        if ($settings instanceof SettingsRepository) {
            $settings->upsert('cron_backup_last_run_at', date('Y-m-d H:i:s'), 'string', false, 0);
            // record rotation failures so the dashboard warns + the exit code is non-zero (error-review-2 F4)
            $settings->upsert('cron_backup_last_failed', (string) $deleteFailures, 'string', false, 0);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Backup finished but recording the heartbeat failed: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

// In live mode, glob() above runs *after* mysqldump creates the new file, so
// count($existing) already includes the new backup. Dry-run skips creation,
// so the new (hypothetical) file is not in $existing.
$retained = count($existing) - $deletedCount + ($dryRun ? 1 : 0);
echo '[backup] done. retained=' . $retained . ' deleted=' . $deletedCount . ' delete_failed=' . $deleteFailures . PHP_EOL;

// the backup itself succeeded, but a failed rotation delete means stale files pile up (disk fills) — signal it
// with a non-zero exit (2), distinct from a crash's 1, while keeping the heartbeat. (error-review-2 F4)
exit($deleteFailures > 0 ? 2 : 0);
