<?php
declare(strict_types=1);

use App\Core\Env;

$appUrl = rtrim((string) Env::get('APP_URL', ''), '/');
$appUrlPath = parse_url($appUrl, PHP_URL_PATH);
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$detectedBasePath = '';

if ($scriptName !== '') {
    if (str_ends_with($scriptName, '/public/index.php')) {
        $detectedBasePath = dirname(dirname($scriptName));
    } elseif (str_ends_with($scriptName, '/index.php')) {
        $detectedBasePath = dirname($scriptName);
    }
}

$detectedBasePath = $detectedBasePath === '/' ? '' : rtrim($detectedBasePath, '/');
$configuredBasePath = is_string($appUrlPath) && $appUrlPath !== '/' ? rtrim($appUrlPath, '/') : '';
$basePath = $configuredBasePath !== '' ? $configuredBasePath : $detectedBasePath;
$sessionPath = $basePath !== '' ? $basePath . '/' : '/';
// เปิด secure flag ให้คุกกี้อัตโนมัติเมื่อ request เข้ามาทาง HTTPS (เซิร์ฟเวอร์ถอดรหัส TLS มาให้แล้ว)
// รันบน HTTP ธรรมดาบนเครื่อง dev จะเป็น false ไม่งั้น login พัง อยากบังคับก็ตั้ง SESSION_SECURE เอง
// ถ้าอยู่หลัง proxy ที่ถอดรหัส TLS ให้ ต้องตั้ง SESSION_SECURE=true เพราะ $_SERVER['HTTPS'] จะไม่ถูกเซ็ตตรงนี้
$isHttps = ($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) ($_SERVER['HTTPS'] ?? '')) !== 'off';

return [
    'app' => [
        'name' => Env::get('APP_NAME', 'Repair System'),
        'env' => Env::get('APP_ENV', 'production'),
        'debug' => Env::bool('APP_DEBUG', false),
        'url' => $appUrl,
        'base_path' => $basePath,
        'timezone' => Env::get('APP_TIMEZONE', 'Asia/Bangkok'),
        // ปิดเป็นค่าเริ่มต้น — กัน admin เผลอโหลดข้อมูลตัวอย่างลง production; เปิดเฉพาะรอบทดลอง/เดโม
        'allow_demo_data' => Env::bool('ALLOW_DEMO_DATA', false),
    ],
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_NAME', 'repair_system'),
        'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
        'username' => Env::get('DB_USERNAME', 'root'),
        'password' => Env::get('DB_PASSWORD', ''),
    ],
    'mail' => [
        'driver' => Env::get('MAIL_DRIVER', 'log'),
        'host' => Env::get('MAIL_HOST', '127.0.0.1'),
        'port' => (int) Env::get('MAIL_PORT', 1025),
        'timeout' => (int) Env::get('MAIL_TIMEOUT', 15),
        'username' => Env::get('MAIL_USERNAME', ''),
        'password' => Env::get('MAIL_PASSWORD', ''),
        'encryption' => Env::get('MAIL_ENCRYPTION', ''),
        'from_address' => Env::get('MAIL_FROM_ADDRESS', 'noreply@example.com'),
        // ว่าง = ให้ MailerService::resolveFromName() fallback ไปชื่อระบบใน Admin (setting app_name); ตั้งค่าเพื่อ override
        'from_name' => Env::get('MAIL_FROM_NAME', ''),
        'reply_to_address' => Env::get('MAIL_REPLY_TO_ADDRESS', ''),
        'reply_to_name' => Env::get('MAIL_REPLY_TO_NAME', ''),
        'queue_batch_size' => (int) Env::get('MAIL_QUEUE_BATCH_SIZE', 10),
        'retry_delay_seconds' => (int) Env::get('MAIL_RETRY_DELAY_SECONDS', 300),
        'processing_timeout_seconds' => (int) Env::get('MAIL_PROCESSING_TIMEOUT_SECONDS', 900),
        'log_path' => Env::get('MAIL_LOG_PATH', BASE_PATH . '/storage/mail-logs'),
    ],
    'session' => [
        'name' => Env::get('SESSION_NAME', 'repair_system_session'),
        'lifetime' => 7200,
        'path' => $sessionPath,
        'secure' => Env::bool('SESSION_SECURE', $isHttps),
        'httponly' => true,
        'same_site' => 'Strict',
        'idle_timeout_minutes' => (int) Env::get('SESSION_IDLE_TIMEOUT_MINUTES', 60),
    ],
    'uploads' => [
        // เพดานไฟล์แนบต่อการแจ้งซ่อมหนึ่งครั้ง. ผู้ซื้อมักปรับเพิ่มค่านี้ให้เข้ากับองค์กรตัวเอง
        'attachment_max_files' => (int) Env::get('UPLOAD_ATTACHMENT_MAX_FILES', 3),
        'attachment_max_bytes' => (int) Env::get('UPLOAD_ATTACHMENT_MAX_BYTES', 5 * 1024 * 1024),
        // เพดานการนำเข้าไฟล์ CSV (ทะเบียนทรัพย์สิน + นำเข้าผู้ใช้)
        'import_asset_max_bytes' => (int) Env::get('UPLOAD_IMPORT_ASSET_MAX_BYTES', 2 * 1024 * 1024),
        'import_asset_max_rows' => (int) Env::get('UPLOAD_IMPORT_ASSET_MAX_ROWS', 500),
        'import_user_max_bytes' => (int) Env::get('UPLOAD_IMPORT_USER_MAX_BYTES', 1 * 1024 * 1024),
        // ตั้งค่าเริ่มต้นแบบทำทันที (synchronous) ไว้ไม่สูง เพราะผู้ใช้ที่นำเข้าแต่ละคนจะถูก bcrypt-hash ในคำขอเดียวกัน (จงใจให้ช้าเพื่อความปลอดภัย)
        // ถ้าชุดใหญ่เกินไป admin ต้องรอนาน และเสี่ยงที่ web server จะ timeout กลางคัน (ได้ผลลัพธ์ไม่ครบ)
        // ~50 แถวใช้เวลาไม่กี่วินาที; จะเพิ่มผ่าน env ก็ต่อเมื่ออยู่บนโฮสต์ที่เร็วและตั้ง max_execution_time ไว้เผื่อพอ
        'import_user_max_rows' => (int) Env::get('UPLOAD_IMPORT_USER_MAX_ROWS', 50),
        // ที่เก็บโลโก้องค์กร (path อ้างอิงจากรากของแอป). ปรับได้ เผื่อการ deploy อยากชี้ไปยัง volume ที่เขียนได้/ใช้ร่วมกัน
        'branding_dir' => trim((string) Env::get('BRANDING_UPLOAD_DIR', 'storage/uploads/branding'), '/'),
    ],
    'paths' => [
        'views' => BASE_PATH . '/app/Views',
        'storage' => BASE_PATH . '/storage',
        'public' => BASE_PATH . '/public',
    ],
    'security' => [
        // ส่ง Content-Security-Policy (CSP) แบบ Report-Only เมื่อเปิดค่านี้ (แค่เฝ้าดูการละเมิด ไม่บล็อกอะไร)
        // ค่าเริ่มต้นคือบังคับใช้จริง. เปิด CSP_REPORT_ONLY=true เมื่อการ deploy เจอการละเมิดที่ไม่คาดคิด
        'csp_report_only' => Env::bool('CSP_REPORT_ONLY', false),
    ],
    'reports' => [
        // แถวสูงสุดที่ตารางรายงานสุขภาพทรัพย์สินแสดง — การ์ดสรุปคำนวณจากทรัพย์สินทั้งหมดเสมอ (ไม่ติดเพดานนี้)
        'asset_display_limit' => max(1, (int) Env::get('REPORT_ASSET_DISPLAY_LIMIT', 500)),
    ],
];
