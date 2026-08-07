<?php
declare(strict_types=1);

/**
 * ตัวทำงานสำรองข้อมูลฐานข้อมูล (database backup worker).
 *
 * dump ฐานข้อมูล MySQL ที่ตั้งค่าไว้ผ่าน mysqldump แล้วบีบอัดด้วย gzip ไปเป็น
 * storage/backups/db-YYYY-MM-DD_HHMMSS.sql.gz จากนั้นลบไฟล์เก่าสุดที่
 * เกินจำนวน --keep (ค่าเริ่มต้น 14) ออก.
 *
 * รหัสผ่านส่งผ่าน environment variable ชื่อ MYSQL_PWD เลยไม่มีทาง
 * ไปโผล่ใน `ps` หรือรายการ process
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

/**
 * หา mysqldump ให้เจอโดยไม่พึ่ง PATH.
 *
 * cron มี PATH แค่ประมาณ /usr/bin:/bin ดังนั้นชื่อสั้น ๆ ว่า "mysqldump" จะหาไม่เจอบนโฮสต์ที่ MySQL ไม่ได้
 * ติดตั้งลงโฟลเดอร์ระบบ — XAMPP/MAMP เป็นแบบนั้นทั้งคู่ ผลคือ backup ตายทุกคืนด้วย exit 127 และเจ้าของระบบ
 * รู้ตัวอีกทีตอนป้าย "สำรองข้อมูลค้างนาน" ขึ้น 48 ชม.ให้หลัง (ถ้าเขาเปิดหน้านั้น).
 *
 * ค่าที่ตั้งเองใน .env ชนะเสมอ — ถ้าตั้งไว้แล้วผิด เราไม่เดาแทน แต่บอกไปตรง ๆ ว่าตั้งอะไรไว้.
 */
function resolve_mysqldump_bin(string $configured): string
{
    if ($configured !== '') {
        return $configured; // เจ้าของระบบสั่งมาเอง — ถ้าพังจะได้เห็นว่าพังเพราะค่าที่ตั้ง ไม่ใช่เพราะเราไปเดาที่อื่น
    }

    // PHP_BINDIR มาก่อน: บน XAMPP/MAMP ตัว mysqldump วางอยู่ข้าง ๆ php ตัวที่กำลังรันสคริปต์นี้อยู่แล้ว
    // ที่เหลือคือที่อยู่มาตรฐานของ MySQL/MariaDB บนโฮสต์จริง แล้วตามด้วยชุด XAMPP/MAMP ซึ่งเป็นชุดที่ผู้ซื้อ
    // มักใช้ทดลองบนเครื่องตัวเอง (php กับ mysql คนละชุดกัน PHP_BINDIR เลยไม่ครอบคลุม)
    $candidates = [
        PHP_BINDIR . '/mysqldump',
        '/usr/bin/mysqldump',
        '/usr/local/bin/mysqldump',
        '/opt/homebrew/bin/mysqldump',
        '/usr/local/mysql/bin/mysqldump',
        '/opt/lampp/bin/mysqldump',                        // XAMPP บน Linux
        '/Applications/XAMPP/xamppfiles/bin/mysqldump',     // XAMPP บน macOS
        '/Applications/MAMP/Library/bin/mysqldump',         // MAMP
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return 'mysqldump'; // หาไม่เจอ — ปล่อยให้ PATH ลองอีกที แล้วรายงานให้ชัดถ้าล้ม
}

/**
 * ข้อความตอนเรียก mysqldump ไม่ได้ — ต้องบอกทางออก ไม่ใช่โยน warning ของ PHP ใส่หน้า log.
 *
 * "เรียกไม่ได้" โผล่มาได้สองหน้าตาแล้วแต่ระบบปฏิบัติการ/รุ่นของ PHP: บางที proc_open สร้างโปรเซสได้แล้วโปรเซส
 * นั้นจบด้วย exit 127, บางที proc_open ล้มตั้งแต่ spawn (posix_spawn) แล้ว return false ไปเลย ทั้งสองแบบคือ
 * เรื่องเดียวกันสำหรับเจ้าของระบบ จึงต้องได้ข้อความเดียวกัน.
 */
function mysqldump_unavailable_message(string $bin): string
{
    $headline = (is_file($bin) && !is_executable($bin))
        ? 'พบไฟล์ mysqldump แต่รันไม่ได้ (สิทธิ์ไม่พอ)'
        : 'ไม่พบคำสั่ง mysqldump';

    return $headline . ' (ที่ลองใช้: ' . $bin . ')' . PHP_EOL
        . 'cron ใช้ PATH แคบกว่าตอนพิมพ์คำสั่งเอง — ตั้ง MYSQLDUMP_BIN=/path/to/mysqldump ใน .env แล้วรันใหม่' . PHP_EOL;
}

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
$mysqldumpBin = resolve_mysqldump_bin((string) env('MYSQLDUMP_BIN', ''));

if ($database === '' || $username === '') {
    fwrite(STDERR, 'Database name/username missing in config.' . PHP_EOL);
    exit(1);
}

$backupDir = storage_path('backups');
if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    fwrite(STDERR, 'Cannot create backup directory: ' . $backupDir . PHP_EOL);
    exit(1);
}

$timeoutSeconds = max(1, (int) env('BACKUP_TIMEOUT_SECONDS', 900));
if (!$dryRun) {
    // flag "e" คือ close-on-exec: mysqldump และโปรเซสลูกหลานของมันจะไม่สืบทอด lock นี้ไป ถ้า worker สั่ง kill ทิ้ง.
    // ถือ handle ไว้ตลอดตั้งแต่ dump + หมุนไฟล์เก่า + heartbeat เพื่อไม่ให้ worker สองตัวเขียนไฟล์ชื่อเดียวกันชนกัน.
    $lockHandle = @fopen($backupDir . '/.backup.lock', 'ce');
    if (!is_resource($lockHandle)) {
        fwrite(STDERR, 'Cannot open the backup process lock.' . PHP_EOL);
        exit(1);
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        fwrite(STDERR, 'Another backup process is already running; this run did not start.' . PHP_EOL);
        exit(2);
    }
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
    // เลี่ยงรหัสผ่านรั่วในรายการ process — ใช้ env var ชื่อ MYSQL_PWD แทน --password=
    putenv('MYSQL_PWD=' . $password);

    // รัน mysqldump ตรง ๆ (proc_open แบบ array ไม่ผ่าน shell) แล้ว gzip เอา stdout ของมันในโปรเซสนี้เอง (PHP zlib) จะได้
    // จับ error ของ mysqldump จาก exit code ของมันเองได้ ถ้าต่อท่อแบบ shell `mysqldump | gzip` มันจะรายงาน status ของ gzip
    // (ยังเป็น 0 แม้ mysqldump ล้มเหลว) กลายเป็นว่า gzip เปล่า ๆ ผ่านเป็น backup ที่ "สำเร็จ" ได้
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
    $exitCode = 0;
    $stderr = '';
    $timedOut = false;
    $sqlBytes = 0; // จำนวน byte ของ SQL ที่ยังไม่บีบอัด ซึ่ง mysqldump ผลิตออกมาจริง — สัญญาณจริงว่า "เราสำรองอะไรได้หรือไม่"

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    // @ ไว้เพราะเราจัดการเคสล้มเองด้านล่างแล้ว: ถ้าปล่อย warning ของ proc_open ไปลง log ของ cron
    // เจ้าของระบบจะเห็นแต่ posix_spawn ซึ่งไม่ได้บอกว่าต้องทำอะไรต่อ
    $proc = @proc_open($dumpArgs, $descriptors, $pipes);
    if (!is_resource($proc)) {
        putenv('MYSQL_PWD');
        fwrite(STDERR, mysqldump_unavailable_message($mysqldumpBin));
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

    $writeError = false;
    // เขียนก้อนข้อมูลลง gzip พร้อม "ยืนยันว่าเขียนครบ" — gzwrite คืนจำนวน byte ที่เขียนได้จริง เขียนได้น้อยกว่าที่ส่ง
    // (หรือ false) แปลว่าเขียนไฟล์ไม่สำเร็จ (ดิสก์เต็ม/โควตาเต็ม) เดิมทิ้งค่าคืนนี้ เลยเขียนไฟล์ขาดครึ่งแล้วยังรายงานสำเร็จ
    $writeChunk = static function (string $data) use ($gz, &$writeError, &$sqlBytes): bool {
        if ($data === '') {
            return true;
        }
        $written = gzwrite($gz, $data);
        if ($written === false || $written < strlen($data)) {
            $writeError = true;
            return false;
        }
        $sqlBytes += strlen($data);
        return true;
    };

    $deadline = microtime(true) + $timeoutSeconds;
    while (true) {
        $chunk = fread($pipes[1], 65536);
        if (is_string($chunk) && $chunk !== '' && !$writeChunk($chunk)) {
            break; // เขียนไฟล์ล้มเหลว — หยุดทันที (จัดการเป็น write error หลังลูป)
        }
        $stderr .= (string) stream_get_contents($pipes[2]);

        $status = proc_get_status($proc);
        if (!$status['running']) {
            // ระบายข้อมูลที่ค้างใน buffer หลัง process จบไปแล้ว
            while (is_string($rest = fread($pipes[1], 65536)) && $rest !== '') {
                if (!$writeChunk($rest)) {
                    break;
                }
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

    // ปิด gzip stream — flush ก้อนสุดท้ายลงดิสก์เกิดตรงนี้ ดิสก์เต็มมักโผล่ตอน gzclose ไม่ใช่ตอน gzwrite เลยต้องเช็คด้วย
    if (gzclose($gz) === false) {
        $writeError = true;
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    // ถ้าเลิกลูปเพราะเขียนไฟล์ล้มเหลว process อาจยังรันอยู่ (เรายังอ่าน stdout ไม่หมด) — ต้องฆ่าทิ้ง ไม่งั้นค้าง
    if (proc_get_status($proc)['running']) {
        proc_terminate($proc, 15);
        usleep(200000);
        if (proc_get_status($proc)['running']) {
            proc_terminate($proc, 9);
        }
    }
    proc_close($proc);
    putenv('MYSQL_PWD'); // เคลียร์ env var ที่อ่อนไหวโดยเร็วที่สุด

    if ($writeError) {
        // เขียน .sql.gz ไม่ครบ (ดิสก์เต็ม?) — ไฟล์ที่ได้ขาดครึ่ง ห้ามนับเป็น backup สำเร็จ ลบทิ้งแล้ว exit ไม่ใช่ศูนย์
        fwrite(STDERR, 'Writing the compressed backup failed (disk full?) — partial backup removed.' . PHP_EOL);
        @unlink($absolutePath);
        exit(1);
    }

    if ($timedOut) {
        fwrite(STDERR, 'mysqldump exceeded the ' . $timeoutSeconds . 's deadline — terminated; partial backup removed.' . PHP_EOL);
        @unlink($absolutePath);
        exit(1);
    }
    if ($exitCode !== 0) {
        // 127 = shell/OS บอกว่า "ไม่มีคำสั่งนี้" ซึ่งบน cron แปลว่าหา mysqldump ไม่เจอ ไม่ใช่ dump ล้มเหลว
        // ข้อความต้องบอกทางออกไปเลย ไม่ใช่ให้เจ้าของระบบไปไล่อ่าน warning ของ proc_open เอง
        if ($exitCode === 127) {
            fwrite(STDERR, mysqldump_unavailable_message($mysqldumpBin));
        } else {
            fwrite(STDERR, 'mysqldump failed (exit ' . $exitCode . '): ' . trim($stderr) . PHP_EOL);
        }
        @unlink($absolutePath);
        exit(1);
    }
    if ($sqlBytes === 0) {
        // dump จบด้วย exit code 0 แต่ไม่มี SQL ออกมาเลย — ไม่ยอมบันทึก backup เปล่า ๆ ว่าสำเร็จ
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
    // ตัว dump กับการหมุนเวียนไฟล์ (rotation) สำเร็จไปแล้ว การบันทึก heartbeat ต้องใช้ DB ซึ่งอาจติดต่อไม่ได้ ณ
    // จุดนี้ (connection หลุด, system_settings ใช้ไม่ได้) ถ้า throw ตรงนี้จะกลายเป็น fatal ที่ไม่มีใครดัก
    // (exit 255) เพราะสคริปต์ CLI นี้ไม่มีตัวดัก exception ระดับ global — เลยดักเองแล้ว exit(1) ให้เรียบร้อย เพื่อให้
    // ตัวจัดตารางงาน (scheduler) เห็นเป็นความล้มเหลวที่คุมได้ ไม่ใช่การ crash
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

// ในโหมด live, glob() ด้านบนรัน *หลังจาก* mysqldump สร้างไฟล์ใหม่แล้ว
// count($existing) เลยรวม backup ใหม่ไว้ด้วย ส่วน dry-run ข้ามการสร้าง
// ไฟล์ใหม่ (แบบสมมติ) เลยไม่อยู่ใน $existing
$retained = count($existing) - $deletedCount + ($dryRun ? 1 : 0);
echo '[backup] done. retained=' . $retained . ' deleted=' . $deletedCount . ' delete_failed=' . $deleteFailures . PHP_EOL;

// ตัว backup เองสำเร็จ แต่ถ้าลบระหว่างหมุนเวียนไฟล์ (rotation) ล้มเหลว แปลว่าไฟล์เก่าค้างสะสม (disk เต็ม) — บอกด้วย
// exit ที่ไม่ใช่ศูนย์ (2) ต่างจาก 1 ของการ crash โดยยังคง heartbeat ไว้
exit($deleteFailures > 0 ? 2 : 0);
