<?php
// public/check-requirements.php — Pre-install diagnostic for IT support.
//
// Open https://your-site/check-requirements.php BEFORE (and during) install. It runs standalone — no app
// bootstrap — so it works even when the app cannot boot yet, and it is written in conservative PHP so it
// still runs on an OLD PHP version and can tell you "your PHP is too old". It checks: PHP version, required
// extensions, .env presence, database connectivity + version, schema import, storage writability — and prints the exact
// cron command for THIS install. Once the system is set up it hides the details and asks you to delete it.
//
// SECURITY: delete this file after a successful install (it is a diagnostic, not part of the app).

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$root = dirname(__DIR__);
// ขั้นต่ำ PHP 8.2 — ไม่ใช่ 8.1: ไลบรารีที่ล็อกไว้ใน composer.lock (phpspreadsheet → maennchen/zipstream-php)
// ต้องการ php-64bit ^8.2 โฮสต์ที่เป็น 8.1 จะผ่านหน้านี้แบบเก่าแต่ composer/vendor ใช้ไม่ได้จริง เพดานที่ทดสอบคือ 8.5
// (phpspreadsheet ปิดที่ < 8.6.0) โปรดดู README หัวข้อความต้องการระบบ. floor ต้องซิงก์กับ composer.json
// และ ceiling ต้องซิงก์กับ composer.lock โดย requirements_check_test.
$MIN_PHP = '8.2.0';
$MAX_PHP_EXCLUSIVE = '8.6.0';
$MIN_MYSQL = '8.0.0';
$MIN_MARIADB = '10.3.0';

// Required extensions = every ext-* the shipped libraries declare (composer.lock) + the MySQL PDO driver the
// app connects through. Kept in sync with composer.lock by tests/cases/requirements_check_test.php.
$REQUIRED_EXT = array(
    'pdo', 'pdo_mysql', 'mbstring', 'ctype', 'filter', 'hash', 'iconv', 'gd', 'zip', 'zlib',
    'dom', 'libxml', 'xml', 'simplexml', 'xmlreader', 'xmlwriter', 'fileinfo', 'openssl', 'json',
);

// --- conservative .env reader (no app code) ---------------------------------------------------------------
$env = array();
$envPath = $root . '/.env';
$envExists = is_file($envPath);
if ($envExists) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === '#' || strpos($t, '=') === false) {
                continue;
            }
            $pair = explode('=', $t, 2);
            $env[trim($pair[0])] = trim(trim($pair[1]), "\"'");
        }
    }
}
function env_val($env, $key, $default)
{
    if (isset($env[$key]) && $env[$key] !== '') {
        return $env[$key];
    }
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

function php_version_supported($version, $minimum, $maximumExclusive)
{
    return version_compare($version, $minimum, '>=')
        && version_compare($version, $maximumExclusive, '<');
}

// ReportRepository ใช้ ROW_NUMBER() สำหรับรายงานย้อนหลัง ซึ่ง MySQL เพิ่งรองรับตั้งแต่ 8.0
// (MariaDB รองรับก่อนหน้านั้น). ตรวจจาก server จริง ไม่เดาจากชื่อ driver เพราะทั้งคู่ใช้ pdo_mysql.
function database_version_result($rawVersion, $minMysql, $minMariaDb)
{
    $isMariaDb = stripos($rawVersion, 'mariadb') !== false;
    $version = '';
    if ($isMariaDb) {
        // บาง host คืน "5.5.5-10.4.28-MariaDB" เพื่อ compatibility ต้องเลือก 10.4.28 ไม่ใช่ 5.5.5.
        $beforeMariaDb = substr($rawVersion, 0, stripos($rawVersion, 'mariadb'));
        preg_match_all('/\d+\.\d+(?:\.\d+)?/', $beforeMariaDb, $matches);
        if (isset($matches[0]) && count($matches[0]) > 0) {
            $version = (string) $matches[0][count($matches[0]) - 1];
        }
    } elseif (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $rawVersion, $match) === 1) {
        $version = (string) $match[1];
    }
    if ($version === '') {
        return array(false, 'อ่านเวอร์ชันฐานข้อมูลไม่ได้ — ติดต่อผู้ดูแลโฮสต์เพื่อตรวจว่าเป็น MySQL/MariaDB รุ่นที่รองรับ');
    }
    $engine = $isMariaDb ? 'MariaDB' : 'MySQL';
    $minimum = $isMariaDb ? $minMariaDb : $minMysql;
    $ok = version_compare($version, $minimum, '>=');

    return array(
        $ok,
        $engine . ' ' . $version . ($ok
            ? ' (ผ่านขั้นต่ำ ' . $minimum . ')'
            : ' — ต้องอัปเกรดเป็น ' . $minimum . ' ขึ้นไปก่อนติดตั้ง'),
    );
}

// --- checks -----------------------------------------------------------------------------------------------
$checks = array();
$fatal = 0;

// 1) PHP version
$phpOk = php_version_supported(PHP_VERSION, $MIN_PHP, $MAX_PHP_EXCLUSIVE);
$checks[] = array(
    $phpOk,
    'PHP เวอร์ชัน',
    'ต้องอยู่ในช่วง ' . $MIN_PHP . ' ถึง 8.5.x (dependency ชุดนี้ยังไม่รองรับ 8.6) — เครื่องนี้คือ ' . PHP_VERSION,
    true
);
if (!$phpOk) {
    $fatal++;
}

// 1b) PHP ต้องเป็น 64-bit: zipstream-php (ผ่าน phpspreadsheet) ประกาศ platform เป็น php-64bit ^8.2
// โฮสต์ 32-bit จะติดตั้ง vendor ไม่ผ่าน (composer จะปฏิเสธ) — จับตั้งแต่หน้านี้ก่อนถึงขั้น composer.
$bits64 = (PHP_INT_SIZE === 8);
$checks[] = array($bits64, 'PHP แบบ 64-bit', $bits64 ? 'ใช่ (64-bit)' : 'เครื่องนี้เป็น 32-bit — ต้องใช้ PHP 64-bit ไลบรารีบางตัวรองรับเฉพาะ 64-bit', true);
if (!$bits64) {
    $fatal++;
}

// 2) extensions
$missingExt = array();
foreach ($REQUIRED_EXT as $ext) {
    if (!extension_loaded($ext)) {
        $missingExt[] = $ext;
    }
}
$extOk = count($missingExt) === 0;
$checks[] = array(
    $extOk,
    'ส่วนขยาย PHP (extensions)',
    $extOk ? 'ครบทั้ง ' . count($REQUIRED_EXT) . ' ตัว' : 'ขาด: ' . implode(', ', $missingExt) . ' — ให้ผู้ดูแลโฮสต์เปิดใน cPanel (Select PHP Version → Extensions)',
    true
);
if (!$extOk) {
    $fatal++;
}

// 3) .env present
$checks[] = array(
    $envExists,
    'ไฟล์ .env',
    $envExists ? 'พบแล้ว' : 'ยังไม่มี — คัดลอก .env.example เป็น .env แล้วแก้ค่า DB_* / APP_URL ตามคู่มือ',
    true
);

// 3b) APP_URL — ลิงก์ในอีเมลทุกฉบับสร้างจากค่านี้ ไม่ตั้งไว้ก็ยังใช้งานเว็บได้ปกติ ระบบเลยดูเหมือนไม่มีปัญหา
// จนกระทั่งมีคนกดลิงก์ในอีเมลไม่ได้ ที่หนักสุดคืออีเมลตั้งรหัสผ่านใหม่ ซึ่งคนที่เข้าระบบไม่ได้ต้องพึ่งมันอย่างเดียว
// ค่า localhost ที่ติดมากับ .env.example ก็ยังไม่พอ เพราะเปิดได้แค่บนเครื่อง server เอง
$appUrl = '';
if ($envExists) {
    $envLines = @file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach (($envLines ?: array()) as $line) {
        if (strpos(ltrim($line), 'APP_URL=') === 0) {
            $appUrl = trim(substr(ltrim($line), 8), " \t\"'");
        }
    }
}
$appUrlHost = $appUrl !== '' ? strtolower((string) parse_url($appUrl, PHP_URL_HOST)) : '';
$appUrlOk = $appUrl !== ''
    && preg_match('#^https?://#i', $appUrl) === 1
    && $appUrlHost !== ''
    && !in_array($appUrlHost, array('localhost', '127.0.0.1', '::1'), true);
$checks[] = array(
    $appUrlOk,
    'APP_URL (ที่อยู่เว็บสำหรับลิงก์ในอีเมล)',
    $appUrlOk
        ? $appUrl
        : ($appUrl === ''
            ? 'ยังไม่ได้ตั้ง — ลิงก์ในอีเมลจะกดไม่ได้ รวมถึงลิงก์ตั้งรหัสผ่านใหม่'
            : 'ยังเป็น ' . $appUrl . ' — ผู้รับอีเมลกดแล้วเปิดไม่ได้ ต้องใส่ที่อยู่จริงของเว็บ'),
    false
);

// 4) DB connectivity + 5) schema + setup state
$dbOk = false;
$dbVersionOk = false;
$dbVersionMsg = '';
$schemaOk = false;
$alreadyInstalled = false;
$dbMsg = '';
$schemaMsg = '';
if (extension_loaded('pdo_mysql')) {
    $host = env_val($env, 'DB_HOST', '127.0.0.1');
    $port = env_val($env, 'DB_PORT', '3306');
    $name = env_val($env, 'DB_NAME', 'repair_system');
    $charset = env_val($env, 'DB_CHARSET', 'utf8mb4');
    $user = env_val($env, 'DB_USERNAME', 'root');
    $pass = env_val($env, 'DB_PASSWORD', '');
    try {
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=' . $charset;
        $pdo = new PDO($dsn, $user, $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $dbOk = true;
        $dbMsg = 'เชื่อมต่อฐานข้อมูล "' . $name . '" ได้';
        $dbVersionRaw = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $dbVersionResult = database_version_result($dbVersionRaw, $MIN_MYSQL, $MIN_MARIADB);
        $dbVersionOk = (bool) $dbVersionResult[0];
        $dbVersionMsg = (string) $dbVersionResult[1];
        // schema present?
        $hasUsers = $pdo->query("SHOW TABLES LIKE 'users'")->fetch() !== false;
        $hasSettings = $pdo->query("SHOW TABLES LIKE 'system_settings'")->fetch() !== false;
        if ($hasUsers && $hasSettings) {
            $schemaOk = true;
            $schemaMsg = 'ตารางหลักครบ (schema ถูก import แล้ว)';
            $row = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'setup_completed'")->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && (string) $row['setting_value'] === '1') {
                $alreadyInstalled = true;
            }
        } else {
            $schemaMsg = 'ยังไม่มีตาราง — นำเข้า database/schema.sql และ database/seed_reference.sql ผ่าน phpMyAdmin ก่อน';
        }
    } catch (Exception $e) {
        // sanitized: never echo the raw driver message (may leak host/user)
        $dbMsg = 'เชื่อมต่อไม่ได้ — ตรวจ DB_HOST / DB_PORT / DB_NAME / DB_USERNAME / DB_PASSWORD ใน .env และว่าได้สร้างฐานข้อมูลแล้ว';
    }
} else {
    $dbMsg = 'ไม่มีส่วนขยาย pdo_mysql — เปิดก่อนจึงจะต่อฐานข้อมูลได้';
}

$checks[] = array($dbOk, 'การเชื่อมต่อฐานข้อมูล', $dbMsg, true);
if ($dbOk) {
    $checks[] = array($dbVersionOk, 'เวอร์ชันฐานข้อมูล', $dbVersionMsg, true);
}
if ($schemaMsg !== '') {
    $checks[] = array($schemaOk, 'โครงสร้างฐานข้อมูล (schema)', $schemaMsg, true);
}

// 6) storage writable
$writableTargets = array('storage/logs', 'storage/uploads', 'storage/qr-cache', 'storage/mail-logs');
$notWritable = array();
foreach ($writableTargets as $rel) {
    $abs = $root . '/' . $rel;
    if (is_dir($abs) ? !is_writable($abs) : !is_writable(dirname($abs))) {
        $notWritable[] = $rel;
    }
}
$storageOk = count($notWritable) === 0;
$checks[] = array(
    $storageOk,
    'สิทธิ์เขียนโฟลเดอร์ storage/',
    $storageOk ? 'เขียนได้ครบ' : 'เขียนไม่ได้: ' . implode(', ', $notWritable) . ' — ตั้ง permission 755/775',
    true
);

// cron command for THIS install (run-maintenance-cron marks overdue SLA, sends its alerts, and processes email)
$cronScript = $root . '/bin/run-maintenance-cron.php';
$backupScript = $root . '/bin/backup-database.php';

// Full path to the PHP CLI. cron reads no shell profile — its PATH is roughly /usr/bin:/bin — so a line that
// starts with a bare `php` dies with "command not found" wherever PHP is not in a system directory (XAMPP,
// MAMP, multi-version hosts). We promise a paste-ready command, so we have to print the real path.
// PHP_BINARY is no help here: under Apache mod_php it is an empty string, and under php-fpm it names the fpm
// binary rather than the CLI. PHP_BINDIR is a compile-time constant and correct in every SAPI.
// Mirrors php_cli_binary() in app/Helpers/urls.php — kept inline because this page must run with no bootstrap
// and no vendor/; cron_command_test locks the two together.
$phpCliPath = PHP_BINDIR . '/php';
if (!is_file($phpCliPath) || !is_executable($phpCliPath)) {
    $phpCliPath = (PHP_SAPI === 'cli' && PHP_BINARY !== '') ? PHP_BINARY : 'php';
}

$allOk = $fatal === 0 && $envExists && $dbOk && $dbVersionOk && $schemaOk && $storageOk;

// ซ่อนรายละเอียดหลังติดตั้งได้ต่อเมื่อทุกข้อยังผ่านจริงเท่านั้น ถ้า PHP/DB/storage เปลี่ยนจนไม่รองรับ
// ต้องแสดงรายการที่ล้มเหลว ไม่ใช่บอก IT ว่า "ติดตั้งเรียบร้อย" แล้วกลบสาเหตุที่ระบบใช้งานไม่ได้.
if ($alreadyInstalled && $allOk) {
    echo '<!doctype html><meta charset="utf-8"><title>ตรวจความพร้อม</title>';
    echo '<div style="font-family:system-ui,sans-serif;max-width:640px;margin:64px auto;padding:24px;border:1px solid #ddd;border-radius:12px">';
    echo '<h1 style="font-size:20px">✅ ระบบติดตั้งเรียบร้อยแล้ว</h1>';
    echo '<p style="color:#444;line-height:1.7">เพื่อความปลอดภัย กรุณา <b>ลบไฟล์ public/check-requirements.php</b> ออกจากเซิร์ฟเวอร์</p>';
    echo '</div>';
    exit;
}
?>
<!doctype html>
<html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ตรวจความพร้อมก่อนติดตั้ง</title></head>
<body style="font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:#f5f4fc;color:#1a1a2e;margin:0;padding:24px">
<div style="max-width:760px;margin:0 auto;background:#fff;border:1px solid #e5e5ef;border-radius:16px;padding:28px 32px">
  <h1 style="font-size:22px;margin:0 0 4px">ตรวจความพร้อมของเซิร์ฟเวอร์</h1>
  <p style="color:#666;margin:0 0 20px">เปิดหน้านี้ก่อนติดตั้ง เพื่อยืนยันว่าโฮสต์พร้อมสำหรับระบบแจ้งซ่อม</p>
  <table style="width:100%;border-collapse:collapse;font-size:15px">
    <?php foreach ($checks as $c): ?>
      <tr style="border-top:1px solid #eee">
        <td style="padding:12px 8px;width:44px;font-size:20px;text-align:center"><?php echo $c[0] ? '✅' : ($c[3] ? '⛔' : '⚠️'); ?></td>
        <td style="padding:12px 8px">
          <div style="font-weight:600"><?php echo htmlspecialchars($c[1], ENT_QUOTES, 'UTF-8'); ?></div>
          <div style="color:#555;font-size:13.5px;line-height:1.5"><?php echo htmlspecialchars($c[2], ENT_QUOTES, 'UTF-8'); ?></div>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <div style="margin-top:24px;padding:16px 18px;background:#f4f3fb;border-radius:12px">
    <div style="font-weight:600;margin-bottom:6px">ตั้ง cron สำหรับการแจ้งเตือน SLA และอีเมล (หลังติดตั้งเสร็จ)</div>
    <div style="color:#555;font-size:13.5px;line-height:1.6">ใน cPanel → Cron Jobs เพิ่มบรรทัดล่าง (บรรทัดแรกจำเป็น — mark SLA ที่เกินกำหนด ส่งการแจ้งเตือน และจัดการคิวอีเมล; บรรทัดสองสำรองข้อมูลรายวัน เป็นทางเลือก). ทั้งสองบรรทัดเติม path จริงของเครื่องนี้ไว้แล้ว — ก็อปไปวางได้เลย:</div>
    <pre style="background:#0e0c2a;color:#d4d6f5;padding:12px;border-radius:8px;overflow-x:auto;font-size:12.5px">*/5 * * * * <?php echo htmlspecialchars($phpCliPath . ' ' . $cronScript, ENT_QUOTES, 'UTF-8') . "\n"; ?>
0 2 * * * <?php echo htmlspecialchars($phpCliPath . ' ' . $backupScript, ENT_QUOTES, 'UTF-8'); ?></pre>
  </div>

  <p style="margin-top:22px;padding-top:16px;border-top:1px solid #eee;color:<?php echo $allOk ? '#0a7d33' : '#b91c1c'; ?>;font-weight:600">
    <?php echo $allOk ? '✅ ผ่านข้อกำหนดขั้นต่ำ — ติดตั้งต่อได้' : '⛔ ยังไม่ผ่านข้อกำหนดขั้นต่ำ — แก้รายการ ⛔ ด้านบนก่อน'; ?>
  </p>
  <p style="color:#999;font-size:12.5px">เพื่อความปลอดภัย ลบไฟล์ <code>public/check-requirements.php</code> หลังติดตั้งเสร็จ</p>
</div>
</body></html>
