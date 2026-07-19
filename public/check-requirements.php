<?php
// public/check-requirements.php — Pre-install diagnostic for IT support (deploy-review D2/D3/D5).
//
// Open https://your-site/check-requirements.php BEFORE (and during) install. It runs standalone — no app
// bootstrap — so it works even when the app cannot boot yet, and it is written in conservative PHP so it
// still runs on an OLD PHP version and can tell you "your PHP is too old". It checks: PHP version, required
// extensions, .env presence, database connectivity, schema import, storage writability — and prints the exact
// cron command for THIS install. Once the system is set up it hides the details and asks you to delete it.
//
// SECURITY: delete this file after a successful install (it is a diagnostic, not part of the app).

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$root = dirname(__DIR__);
$MIN_PHP = '8.1.0';

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

// --- checks -----------------------------------------------------------------------------------------------
$checks = array();
$fatal = 0;

// 1) PHP version
$phpOk = version_compare(PHP_VERSION, $MIN_PHP, '>=');
$checks[] = array($phpOk, 'PHP เวอร์ชัน', 'ต้อง ' . $MIN_PHP . ' ขึ้นไป — เครื่องนี้คือ ' . PHP_VERSION, true);
if (!$phpOk) {
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

// 4) DB connectivity + 5) schema + setup state
$dbOk = false;
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
        // schema present?
        $hasUsers = $pdo->query("SHOW TABLES LIKE 'users'")->fetch() !== false;
        $hasSettings = $pdo->query("SHOW TABLES LIKE 'system_settings'")->fetch() !== false;
        if ($hasUsers && $hasSettings) {
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

// Already installed → minimal, non-revealing output + ask to delete this file.
if ($alreadyInstalled) {
    echo '<!doctype html><meta charset="utf-8"><title>ตรวจความพร้อม</title>';
    echo '<div style="font-family:system-ui,sans-serif;max-width:640px;margin:64px auto;padding:24px;border:1px solid #ddd;border-radius:12px">';
    echo '<h1 style="font-size:20px">✅ ระบบติดตั้งเรียบร้อยแล้ว</h1>';
    echo '<p style="color:#444;line-height:1.7">เพื่อความปลอดภัย กรุณา <b>ลบไฟล์ public/check-requirements.php</b> ออกจากเซิร์ฟเวอร์</p>';
    echo '</div>';
    exit;
}

$checks[] = array($dbOk, 'การเชื่อมต่อฐานข้อมูล', $dbMsg, false);
if ($schemaMsg !== '') {
    $schemaOk = strpos($schemaMsg, 'ครบ') !== false;
    $checks[] = array($schemaOk, 'โครงสร้างฐานข้อมูล (schema)', $schemaMsg, false);
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
    false
);

// cron command for THIS install (run-maintenance-cron does BOTH SLA + the email queue in one run)
$cronScript = $root . '/bin/run-maintenance-cron.php';
$backupScript = $root . '/bin/backup-database.php';

$allOk = ($fatal === 0);
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
    <div style="font-weight:600;margin-bottom:6px">ตั้ง cron สำหรับ SLA และอีเมล (หลังติดตั้งเสร็จ)</div>
    <div style="color:#555;font-size:13.5px;line-height:1.6">ใน cPanel → Cron Jobs เพิ่มบรรทัดล่าง (บรรทัดแรกจำเป็น — จัดการทั้ง SLA และคิวอีเมลในตัว; บรรทัดสองสำรองข้อมูลรายวัน เป็นทางเลือก). แทน <code>php</code> ด้วย path ของ PHP CLI บนโฮสต์ของคุณถ้าจำเป็น:</div>
    <pre style="background:#0e0c2a;color:#d4d6f5;padding:12px;border-radius:8px;overflow-x:auto;font-size:12.5px">*/5 * * * * php <?php echo htmlspecialchars($cronScript, ENT_QUOTES, 'UTF-8'); ?>
0 2 * * * php <?php echo htmlspecialchars($backupScript, ENT_QUOTES, 'UTF-8'); ?></pre>
  </div>

  <p style="margin-top:22px;padding-top:16px;border-top:1px solid #eee;color:<?php echo $allOk ? '#0a7d33' : '#b91c1c'; ?>;font-weight:600">
    <?php echo $allOk ? '✅ ผ่านข้อกำหนดขั้นต่ำ — ติดตั้งต่อได้' : '⛔ ยังไม่ผ่านข้อกำหนดขั้นต่ำ — แก้รายการ ⛔ ด้านบนก่อน'; ?>
  </p>
  <p style="color:#999;font-size:12.5px">เพื่อความปลอดภัย ลบไฟล์ <code>public/check-requirements.php</code> หลังติดตั้งเสร็จ</p>
</div>
</body></html>
