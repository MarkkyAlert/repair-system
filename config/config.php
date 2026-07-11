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
// Secure cookies by default when the request is served over HTTPS (server-terminated TLS).
// Stays false on plain HTTP (local dev) so login isn't broken; override explicitly with SESSION_SECURE.
// Behind a TLS-terminating proxy, set SESSION_SECURE=true since $_SERVER['HTTPS'] won't be set here.
$isHttps = ($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) ($_SERVER['HTTPS'] ?? '')) !== 'off';

return [
    'app' => [
        'name' => Env::get('APP_NAME', 'Repair System'),
        'env' => Env::get('APP_ENV', 'production'),
        'debug' => Env::bool('APP_DEBUG', false),
        'url' => $appUrl,
        'base_path' => $basePath,
        'timezone' => Env::get('APP_TIMEZONE', 'Asia/Bangkok'),
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
        'from_name' => Env::get('MAIL_FROM_NAME', (string) Env::get('APP_NAME', 'Repair System')),
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
        // Ticket attachment limits (per submission). Buyers commonly raise these for their environment.
        'attachment_max_files' => (int) Env::get('UPLOAD_ATTACHMENT_MAX_FILES', 3),
        'attachment_max_bytes' => (int) Env::get('UPLOAD_ATTACHMENT_MAX_BYTES', 5 * 1024 * 1024),
        // CSV import limits (asset registry + user import).
        'import_asset_max_bytes' => (int) Env::get('UPLOAD_IMPORT_ASSET_MAX_BYTES', 2 * 1024 * 1024),
        'import_asset_max_rows' => (int) Env::get('UPLOAD_IMPORT_ASSET_MAX_ROWS', 500),
        'import_user_max_bytes' => (int) Env::get('UPLOAD_IMPORT_USER_MAX_BYTES', 1 * 1024 * 1024),
        'import_user_max_rows' => (int) Env::get('UPLOAD_IMPORT_USER_MAX_ROWS', 200),
    ],
    'paths' => [
        'views' => BASE_PATH . '/app/Views',
        'storage' => BASE_PATH . '/storage',
        'public' => BASE_PATH . '/public',
    ],
    'security' => [
        // Ship the Content-Security-Policy in Report-Only mode (observe violations, block nothing) when set.
        // Default is enforcing. Flip via CSP_REPORT_ONLY=true if a deploy surfaces an unexpected violation.
        'csp_report_only' => Env::bool('CSP_REPORT_ONLY', false),
    ],
];
