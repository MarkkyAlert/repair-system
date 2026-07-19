<?php
declare(strict_types=1);

/**
 * ตัวทำงานสำรองข้อมูลฐานข้อมูล (database backup worker).
 *
 * dump ฐานข้อมูล MySQL ที่ตั้งค่าไว้ผ่าน mysqldump แล้วบีบอัดด้วย gzip ไปเป็น
 * storage/backups/db-YYYY-MM-DD_HHMMSS.sql.gz จากนั้นลบไฟล์เก่าสุดที่
 * เกินจำนวน --keep (ค่าเริ่มต้น 14) ออก.
 *
 * รหัสผ่านถูกส่งผ่าน environment variable ชื่อ MYSQL_PWD จึงไม่มีทาง
 * โผล่ใน `ps`/รายการ process (process listings).
 *
 * วิธีใช้:
 *   php bin/backup-database.php                # สำรองข้อมูล + เก็บ 14 ไฟล์ล่าสุด
 *   php bin/backup-database.php --keep=30      # เก็บ 30 ไฟล์ล่าสุด
 *   php bin/backup-database.php --dry-run      # แสดงว่าจะเกิดอะไรขึ้น (ไม่ทำจริง)
 *
 * cron ที่แนะนำ: รายวัน 02:00
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
    // เลี่ยงรหัสผ่านรั่วในรายการ process (process listings) — ใช้ env var ชื่อ MYSQL_PWD แทน --password=
    putenv('MYSQL_PWD=' . $password);

    // รัน mysqldump ตรง ๆ (proc_open แบบ array — ไม่ผ่าน shell) แล้ว gzip เอา stdout ของมันในตัว process เอง (PHP zlib) เพื่อให้
    // ความล้มเหลวของ mysqldump ถูกจับผ่าน exit code ของมันเอง. การต่อท่อแบบ shell `mysqldump | gzip` จะรายงานสถานะของ gzip
    // (ยังเป็น 0 แม้ mysqldump ล้มเหลว) ซึ่งทำให้ gzip เปล่า ๆ ผ่านเป็น backup ที่ "สำเร็จ" ได้.
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

    // เส้นตาย (deadline) กันไม่ให้ dump ที่ค้าง (DB endpoint ค้าง) ทำ cron ค้างตลอดกาลโดยไม่มี heartbeat.
    $timeoutSeconds = max(1, (int) env('BACKUP_TIMEOUT_SECONDS', 900));
    $exitCode = 0;
    $stderr = '';
    $timedOut = false;
    $sqlBytes = 0; // จำนวน byte ของ SQL ที่ยังไม่บีบอัด ซึ่ง mysqldump ผลิตออกมาจริง — สัญญาณจริงว่า "เราสำรองอะไรได้หรือไม่"

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
            // ระบายข้อมูลที่ค้างใน buffer หลัง process จบไปแล้ว
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
            usleep(50000); // ยังไม่มีอะไรพร้อม — อย่าวนรอแบบกิน CPU (busy-spin)
        }
    }

    gzclose($gz);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    putenv('MYSQL_PWD'); // เคลียร์ env var ที่อ่อนไหวโดยเร็วที่สุด

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
        // dump จบด้วย exit code 0 แต่ไม่ได้ผลิต SQL ออกมา — ปฏิเสธที่จะบันทึก backup เปล่าว่าสำเร็จ
        fwrite(STDERR, 'mysqldump produced no SQL output — refusing to write an empty backup.' . PHP_EOL);
        @unlink($absolutePath);
        exit(1);
    }

    $size = is_file($absolutePath) ? (int) filesize($absolutePath) : 0;
    echo '[backup] wrote ' . number_format($size / 1024, 1) . ' KB (' . number_format($sqlBytes / 1024, 1) . ' KB SQL)' . PHP_EOL;
}

// การหมุนเวียนไฟล์ (rotation) — เก็บไฟล์ใหม่สุด N ไฟล์ ลบที่เหลือ
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
    // ตัว dump + การหมุนเวียนไฟล์ (rotation) สำเร็จไปแล้ว; การบันทึก heartbeat ต้องใช้ DB ซึ่งอาจติดต่อไม่ได้ ณ
    // จุดนี้ (connection หลุด, system_settings ใช้ไม่ได้). การ throw ตรงนี้จะหลุดออกไปเป็น fatal ที่ไม่ถูกจับ (UNCAUGHT)
    // (exit 255) — สคริปต์ CLI นี้ไม่มีขอบเขตดักจับ exception ระดับ global — จึงดักมันไว้แล้ว exit(1) อย่างสะอาด เพื่อให้
    // ตัวจัดตารางงาน (scheduler) เห็นความล้มเหลวที่ควบคุมได้ แทนที่จะเป็นการ crash.
    try {
        $settings = $container->get(SettingsRepository::class);
        if ($settings instanceof SettingsRepository) {
            $settings->upsert('cron_backup_last_run_at', date('Y-m-d H:i:s'), 'string', false, 0);
            // บันทึกความล้มเหลวของการหมุนเวียนไฟล์ (rotation) เพื่อให้ dashboard เตือน + exit code เป็นค่าที่ไม่ใช่ศูนย์
            $settings->upsert('cron_backup_last_failed', (string) $deleteFailures, 'string', false, 0);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Backup finished but recording the heartbeat failed: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

// ในโหมด live, glob() ด้านบนรัน *หลังจาก* mysqldump สร้างไฟล์ใหม่แล้ว ดังนั้น
// count($existing) จึงรวม backup ใหม่ไว้ด้วยแล้ว. ส่วน dry-run ข้ามการสร้างไฟล์
// ไฟล์ใหม่ (แบบสมมติ) จึงไม่อยู่ใน $existing.
$retained = count($existing) - $deletedCount + ($dryRun ? 1 : 0);
echo '[backup] done. retained=' . $retained . ' deleted=' . $deletedCount . ' delete_failed=' . $deleteFailures . PHP_EOL;

// ตัว backup เองสำเร็จ แต่การลบระหว่างหมุนเวียนไฟล์ (rotation) ที่ล้มเหลวหมายถึงไฟล์เก่าค้างสะสม (disk เต็ม) — ส่งสัญญาณมัน
// ด้วย exit ที่ไม่ใช่ศูนย์ (2) ต่างจาก 1 ของการ crash โดยยังคง heartbeat ไว้.
exit($deleteFailures > 0 ? 2 : 0);
