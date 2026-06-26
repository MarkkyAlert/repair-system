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
$gzipBin = (string) env('GZIP_BIN', 'gzip');

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

    $cmd = sprintf(
        '%s --host=%s --port=%s --user=%s --single-transaction --quick --default-character-set=%s --no-tablespaces %s | %s > %s',
        escapeshellcmd($mysqldumpBin),
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg($username),
        escapeshellarg($charset),
        escapeshellarg($database),
        escapeshellcmd($gzipBin),
        escapeshellarg($absolutePath)
    );

    $exitCode = 0;
    $output = [];
    exec($cmd . ' 2>&1', $output, $exitCode);

    // Clear sensitive env var asap
    putenv('MYSQL_PWD');

    if ($exitCode !== 0) {
        fwrite(STDERR, 'mysqldump failed (exit ' . $exitCode . '): ' . implode("\n", $output) . PHP_EOL);
        @unlink($absolutePath);
        exit(1);
    }

    $size = is_file($absolutePath) ? (int) filesize($absolutePath) : 0;
    if ($size <= 0) {
        fwrite(STDERR, 'Backup file is empty or missing: ' . $absolutePath . PHP_EOL);
        @unlink($absolutePath);
        exit(1);
    }

    echo '[backup] wrote ' . number_format($size / 1024, 1) . ' KB' . PHP_EOL;
}

// Rotation — keep newest N files, delete the rest
$existing = glob($backupDir . '/db-*.sql.gz') ?: [];
usort($existing, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

$toDelete = array_slice($existing, $keep);
$deletedCount = 0;
foreach ($toDelete as $oldFile) {
    if ($dryRun) {
        echo '[backup] would delete: ' . basename($oldFile) . PHP_EOL;
        $deletedCount++;
    } elseif (@unlink($oldFile)) {
        echo '[backup] deleted: ' . basename($oldFile) . PHP_EOL;
        $deletedCount++;
    } else {
        fwrite(STDERR, '[backup] failed to delete: ' . basename($oldFile) . PHP_EOL);
    }
}

if (!$dryRun) {
    $settings = $container->get(SettingsRepository::class);
    if ($settings instanceof SettingsRepository) {
        $settings->upsert('cron_backup_last_run_at', date('Y-m-d H:i:s'), 'string', false, 0);
    }
}

// In live mode, glob() above runs *after* mysqldump creates the new file, so
// count($existing) already includes the new backup. Dry-run skips creation,
// so the new (hypothetical) file is not in $existing.
$retained = count($existing) - $deletedCount + ($dryRun ? 1 : 0);
echo '[backup] done. retained=' . $retained . ' deleted=' . $deletedCount . PHP_EOL;
exit(0);
