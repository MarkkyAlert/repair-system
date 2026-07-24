<?php
declare(strict_types=1);

use App\Core\AuthManager;
use App\Core\Env;
use App\Core\Request;

function app(?string $id = null): mixed
{
    $container = $GLOBALS['app_container'] ?? null;

    if ($id === null) {
        return $container;
    }

    return $container?->get($id);
}

function config(string $key, mixed $default = null): mixed
{
    $config = app('config') ?? [];
    $segments = explode('.', $key);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

function setting(string $key, mixed $default = null): mixed
{
    static $cache = [];

    if ($key === '') {
        return $default;
    }

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $repository = app(\App\Repositories\SettingsRepository::class);
    if (!$repository instanceof \App\Repositories\SettingsRepository) {
        return $default;
    }

    $row = $repository->getByKey($key);
    if (!is_array($row)) {
        $cache[$key] = $default;
        return $default;
    }

    $value = $row['setting_value'] ?? null;
    $type = (string) ($row['value_type'] ?? 'string');

    $resolved = match ($type) {
        'int' => (int) ($value ?? 0),
        'bool' => truthy_input($value ?? '0'),
        'json' => json_decode((string) ($value ?? ''), true) ?? $default,
        default => $value ?? $default,
    };

    $cache[$key] = $resolved;

    return $resolved;
}

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * กันเซลล์ที่เสี่ยงโดน formula-injection ใน CSV/spreadsheet: เติม single quote ไว้ข้างหน้าเมื่อตัวอักษรแรก
 * ที่ไม่ใช่ช่องว่างเป็น = + - หรือ @ spreadsheet จะได้แสดงค่าเป็นข้อความ ไม่เอาไปรันเป็นสูตร
 * ใช้ร่วมกันทุกเส้นทางการ export (AssetService, ReportService) เป็นที่เดียวที่คุมการป้องกันนี้
 */
function sanitize_export_cell(mixed $value): string
{
    $cell = (string) $value;
    $trimmed = ltrim($cell);

    // ตัวเลขติดลบล้วน ("-1", "-1.5", "-1,234.0") เป็นค่าที่พิมพ์มาถูกต้องและ spreadsheet แสดงเป็นตัวเลข
    // ไม่ใช่สูตร เลยต้องคงเป็นตัวเลขและตรงกับที่เห็นบนจอทุก byte (เผื่อ Excel sum/pivot) ส่วนที่มีแค่
    // "-" นำหน้าแล้วตามด้วยอย่างอื่น (เช่น "-2+3") เสี่ยงเป็น formula-injection ส่วน "+ = @" นำหน้าเป็นตัวเปิดสูตร
    // ใน spreadsheet เสมอ (แม้แต่ "+1234" ก็เป็นสูตร) เลยคงการกันไว้
    $isNegativeNumber = preg_match('/^-(\d+|\d{1,3}(,\d{3})+)(\.\d+)?$/', $trimmed) === 1;

    if (!$isNegativeNumber && $trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
        return "'" . $cell;
    }

    return $cell;
}

/**
 * ถอด "เครื่องหมายกันสูตร" (leading apostrophe) ที่ sanitize_export_cell เติมไว้ตอน export ออกตอน import — เพื่อให้
 * ไฟล์ที่ export จากระบบเอง แล้ว import กลับ ได้ค่าตรงเดิมทุก byte. ถอด ' นำหน้าเฉพาะเมื่อสิ่งที่มันกันคือตัวเปิดสูตร
 * (= + @ หรือ - ที่ไม่ใช่ตัวเลขติดลบล้วน) — ให้ตรงกับกฎที่ sanitize_export_cell ใช้เติม. ค่าที่ขึ้นต้นด้วย ' จริง ๆ
 * (เช่น "'hello") ไม่ถูกแตะ.
 */
function unsanitize_import_cell(string $value): string
{
    if ($value === '' || $value[0] !== "'") {
        return $value;
    }

    $rest = substr($value, 1);
    $trimmed = ltrim($rest);
    if ($trimmed === '') {
        return $value;
    }

    $opener = $trimmed[0];
    if (in_array($opener, ['=', '+', '@'], true)) {
        return $rest;
    }
    // '-' ถูกกันไว้เฉพาะตอนที่ "ไม่ใช่" ตัวเลขติดลบล้วน (mirror sanitize_export_cell) จึงถอดเฉพาะกรณีนั้น
    if ($opener === '-' && preg_match('/^-(\d+|\d{1,3}(,\d{3})+)(\.\d+)?$/', $trimmed) !== 1) {
        return $rest;
    }

    return $value;
}

/**
 * คืน true เมื่อ exception เกิดจากการชน unique-constraint / duplicate-key (MySQL 23000 / 1062)
 * ใช้ร่วมกันในบริการนำเข้า CSV เพื่อรายงานแถวที่ซ้ำให้สอดคล้องกัน
 */
function is_duplicate_key_error(\Throwable $exception): bool
{
    if (!$exception instanceof \PDOException) {
        return false;
    }
    $code = (string) $exception->getCode();
    $message = $exception->getMessage();

    return $code === '23000' || str_contains($message, 'Duplicate entry') || str_contains($message, '1062');
}

/**
 * คืน true เมื่อ error คือ "table doesn't exist" (MySQL 1146 / SQLSTATE 42S02) เป็น DB error ตัวเดียวที่ bootstrap
 * ถือว่าเป็นเรื่องปกติของการรันครั้งแรก (ยังไม่ได้โหลด schema) DB error ตัวอื่นทุกตัวต้องเขียน log ไว้ ไม่ใช่
 * กลืนเงียบ ๆ แล้วเข้าใจผิดว่าเป็น "ยังไม่ได้ติดตั้ง"
 */
function is_missing_table_error(\Throwable $exception): bool
{
    if (!$exception instanceof \PDOException) {
        return false;
    }
    $code = (string) $exception->getCode();
    $message = $exception->getMessage();

    return $code === '42S02' || str_contains($message, '1146') || str_contains($message, "doesn't exist");
}

/**
 * เขียน log ของ exception ที่หลุด try/catch ทุกชั้นระดับ controller มาจนถึงตัวจัดการที่จุดเข้าโปรแกรม
 * ทั้งชื่อ class, ข้อความ, file:line และ stack trace เต็ม ๆ จะถูกส่งไป server error log (ปลายทางตั้งใน php.ini
 * เช่น Apache error log / stderr ตอน production) ไว้ debug 500 ที่ไม่คาดคิด ข้อมูลพวกนี้ไม่เคยถูกเขียนลง
 * response ของ HTTP เพราะ response ยังเป็น 500 ทั่วไปผ่าน Response::abort เลยไม่มีข้อมูลอ่อนไหวรั่วไปถึง client
 */
function log_uncaught_exception(\Throwable $exception): void
{
    error_log('[req:' . request_id() . '] [uncaught] ' . $exception);
}

/**
 * รหัสอ้างอิง (correlation id) สั้น ๆ ต่อหนึ่ง request สร้างครั้งเดียวแล้วใช้ซ้ำตลอดทั้ง request ถูกส่งออกเป็น
 * response header ชื่อ X-Request-Id, โชว์บนหน้า 500 ทั่วไป และเติมนำหน้าทุกบรรทัดใน server log เพื่อให้คำพูด
 * ผู้ใช้ "เจอ error เลข reference ABC12345" โยงตรงไปบรรทัด log ที่ตรงกันได้ทันที
 */
function request_id(): string
{
    static $id = null;
    if ($id === null) {
        $id = bin2hex(random_bytes(4)); // hex 8 ตัว: พอให้ใช้โยงหากันได้ และไม่มีข้อมูลส่วนบุคคล (PII)
    }

    return $id;
}

/**
 * บอกว่าผู้เรียกอยากได้ response เป็น JSON ไหม (พวก AJAX/fetch) แทนหน้า HTML ใช้ให้ตัวจัดการ 500
 * ที่จุดเข้าโปรแกรมคืน error เป็น JSON พร้อม reference แทนหน้า HTML ที่จะทำให้ response.json()
 * ฝั่ง client พัง
 *
 * @param array<string, mixed>|null $server ค่าเริ่มต้นคือ $_SERVER
 */
function request_wants_json(?array $server = null): bool
{
    $server ??= $_SERVER;
    $accept = strtolower((string) ($server['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($server['HTTP_X_REQUESTED_WITH'] ?? ''));

    return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
}

/**
 * คืน true เมื่อแอปตั้งค่าไว้แบบที่อาจปล่อย stack trace รั่วไปถึง client: เปิด debug mode ทั้งที่ environment เป็น
 * production (ตัวจัดการที่จุดเข้าโปรแกรมจะโยน exception ซ้ำตอน debug ทำให้ error ตอน production โชว์ trace ออกมา)
 * web entry point เลยปฏิเสธไม่ให้บริการในสถานะนี้ ส่วนเครื่อง dev (APP_ENV=local) ไม่โดนผลกระทบ
 */
function is_unsafe_production_debug(string $appEnv, bool $appDebug): bool
{
    return $appEnv === 'production' && $appDebug === true;
}

/**
 * เขียน log ของ exception ที่ดักได้ในงานเสริมแบบ best-effort (แจ้งเตือน, ล้างข้อมูล, audit) พวกที่
 * ตั้งใจกลืนไว้ไม่ให้ไปทำงานหลักล้ม บันทึกทั้ง marker, context ที่ผู้เรียกส่งมา
 * และ class + ข้อความ + file:line ของ exception พอ debug ได้โดยไม่ต้องมี stack trace เต็มที่รกไป
 *
 * @param array<string, mixed> $context
 */
function log_caught_exception(string $marker, \Throwable $exception, array $context = []): void
{
    $parts = [];
    foreach ($context as $key => $value) {
        $parts[] = $key . '=' . (is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE));
    }
    $suffix = $parts === [] ? '' : ' ' . implode(' ', $parts);

    // ไล่ลงไปหาต้นเหตุที่ถูกห่อไว้ลึกสุด: service ห่อความล้มเหลวของ disk/DB/library ไว้ใน RuntimeException ที่อ่านง่าย
    // ("สร้างไฟล์ไม่ได้") ตัวห่อชั้นนอกเลยบังเหตุผลจริงไว้ เลยต่อท้ายด้วย class/ข้อความ/file:line ของต้นเหตุ
    $root = $exception;
    while ($root->getPrevious() !== null) {
        $root = $root->getPrevious();
    }
    $cause = $root === $exception
        ? ''
        : sprintf(' <- %s: %s in %s:%d', $root::class, $root->getMessage(), $root->getFile(), $root->getLine());

    error_log(sprintf(
        '[req:%s] [%s]%s %s: %s in %s:%d%s',
        request_id(),
        $marker,
        $suffix,
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $cause
    ));
}

/** ตัวตรวจสอบเฉพาะรูปแบบ (ผู้เรียกยังต้องดูแลการเช็ค required/ค่าว่าง และข้อความ error ของตัวเองอยู่) */
function is_valid_email(string $email): bool
{
    // จำกัดความยาวให้พอดีกับคอลัมน์ users.email / *_email (VARCHAR(190)) เพื่อให้อีเมลที่รูปแบบถูกแต่
    // ยาวเกินไป โดนปฏิเสธด้วยข้อความที่อ่านง่าย ไม่ใช่ DB error ดิบ ๆ ตอน strict mode
    return (function_exists('mb_strlen') ? mb_strlen($email) : strlen($email)) <= 190
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * คืนแถวที่ preview ไว้ เฉพาะตอน one-time token ที่ส่งมาตรงกับ batch ที่เก็บไว้ใน session เท่านั้น
 * กันไว้ไม่ให้การเปิด preview อันที่สองในอีกแท็บ (ซึ่งไปทับ batch + token ใน session) ทำให้
 * การกดยืนยันในแท็บแรกนำเข้าแถวของแท็บที่สอง ฟังก์ชันนี้บริสุทธิ์ ไม่แตะ session/HTTP เลยเทสต์ได้ตรง ๆ
 * โดยให้ controller ดูแล Session::get/forget เอง โยน DomainException เมื่อ token ไม่ตรงหรือ batch ว่าง
 *
 *
 * @param mixed $batch batch ใน session: ['token' => string, 'rows' => array<int, mixed>]
 * @return array<int, mixed>
 */
function verified_import_rows(mixed $batch, string $submittedToken): array
{
    $sessionToken = is_array($batch) ? (string) ($batch['token'] ?? '') : '';
    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new \DomainException('การยืนยันนำเข้าไม่ตรงกับไฟล์ที่เพิ่งตรวจสอบ (อาจเปิดไว้หลายแท็บ) กรุณาอัปโหลดและตรวจสอบใหม่');
    }

    $rows = is_array($batch) ? ($batch['rows'] ?? []) : [];
    if (!is_array($rows) || $rows === []) {
        throw new \DomainException('ไม่พบข้อมูลที่ผ่านการตรวจสอบ กรุณาเริ่มกระบวนการนำเข้าใหม่');
    }

    return $rows;
}

/**
 * ปฏิเสธค่าที่ยาวเกินคอลัมน์ใน DB ให้ผู้ใช้เห็นข้อความชัดเจน ไม่ใช่ error ดิบ ๆ
 * อย่าง "Data too long" ตอน MySQL strict mode ค่าที่อยู่ในขอบเขตจะไม่ถูกแตะ
 */
function require_max_length(string $value, int $max, string $label): void
{
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length > $max) {
        throw new \DomainException($label . 'ยาวเกินกำหนด (ไม่เกิน ' . $max . ' ตัวอักษร)');
    }
}

/** รหัสผ่านขั้นต่ำ 8 ตัวอักษร โดยนับอักขระ Unicode ไม่ใช่จำนวนไบต์ UTF-8 */
function password_has_minimum_length(string $password): bool
{
    return (function_exists('mb_strlen') ? mb_strlen($password) : strlen($password)) >= 8;
}

/** bcrypt ใช้อินพุตเพียง 72 ไบต์แรก จึงต้องปฏิเสธค่าที่ยาวกว่านั้นก่อนสร้าง hash */
function password_fits_bcrypt_limit(string $password): bool
{
    return strlen($password) <= 72;
}

/** รูปแบบ username: 3–50 ตัวอักษรจาก a-z, 0-9, จุด, ขีดกลาง, ขีดล่าง ใช้ร่วมกันตอน admin สร้างผู้ใช้ + นำเข้า CSV */
function is_valid_username(string $username): bool
{
    return preg_match('/^[a-z0-9._-]{3,50}$/', $username) === 1;
}

function valid_phone_format(string $phone): bool
{
    return preg_match('/^[0-9+\-() .]{4,30}$/', $phone) === 1;
}

/** คืน true สำหรับ submission/idempotency token ที่เป็น hex ตัวพิมพ์เล็กยาว 64 ตัวอักษร */
function is_submission_token(string $token): bool
{
    return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
}

/** ตีความค่า input จากฟอร์ม/setting ว่าเป็นจริงหรือไม่: true สำหรับ "1"/"true"/"yes"/"on" (ไม่สนตัวพิมพ์เล็กใหญ่) */
function truthy_input(mixed $value): bool
{
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * การแบ่งหน้าแบบใช้ offset: บีบ $page ให้อยู่ในช่วง [1, totalPages] แล้วคำนวณค่า offset ของ SQL
 * @return array{page:int,offset:int,totalPages:int}
 */
function paginate(int $page, int $perPage, int $total): array
{
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));

    return ['page' => $page, 'offset' => ($page - 1) * $perPage, 'totalPages' => $totalPages];
}

/**
 * ปรับตัวกรองช่วงวันที่ from/to ให้เป็นมาตรฐาน เช็คทีละด้าน (YYYY-MM-DD → '' ถ้าไม่ถูกต้อง)
 * ถ้ากลับด้านก็สลับให้ `from` มาก่อน `to` เสมอ แล้วแปลงเป็น datetime ที่ครอบคลุมทั้งวัน
 * (from = 00:00:00, to = 23:59:59) เป็นที่เดียวที่ตัวกรองของ
 * dashboard กับรายงานใช้ร่วมกัน จะได้ไม่เพี้ยนไปคนละทาง
 *
 * @return array{from_date:string,to_date:string,from_datetime:string,to_datetime:string}
 */
function normalize_date_range(string $fromRaw, string $toRaw): array
{
    $normalizeDay = static function (string $value): string {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return '';
        }
        $timestamp = strtotime($value);
        // strtotime จะเลื่อนวันที่ที่รูปแบบถูกแต่ไม่มีอยู่จริง (2026-02-30 → 2026-03-02, 2025-02-29 → 2025-03-01)
        // เลยปฏิเสธเมื่อแปลงไป-กลับแล้ววันเปลี่ยน ให้วันที่ผิดกลายเป็นค่าว่าง ไม่ใช่ข้อมูลของอีกวันหนึ่ง
        if ($timestamp === false || date('Y-m-d', $timestamp) !== $value) {
            return '';
        }

        return $value;
    };

    $fromDate = $normalizeDay($fromRaw);
    $toDate = $normalizeDay($toRaw);

    // ช่วงที่กลับด้าน → สลับวันก่อน เพื่อให้ datetime ถูกคำนวณจากปลายที่ถูกต้องเสมอ
    if ($fromDate !== '' && $toDate !== '' && strcmp($fromDate, $toDate) > 0) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }

    return [
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'from_datetime' => $fromDate !== '' ? $fromDate . ' 00:00:00' : '',
        'to_datetime' => $toDate !== '' ? $toDate . ' 23:59:59' : '',
    ];
}

function request(): ?Request
{
    $resolved = app(Request::class);

    return $resolved instanceof Request ? $resolved : null;
}

function auth(): AuthManager
{
    return app(AuthManager::class);
}

/**
 * ตีความ field ในฟอร์มที่ต้องเป็นจำนวนเต็ม ค่าว่าง/ไม่มี → $default; string ที่ไม่ใช่จำนวนเต็มอย่าง
 * "12junk" จะโยน exception (การ cast (int) ของ PHP จะเก็บ "12" ไว้เงียบ ๆ) service เป็นเจ้าของการตรวจ input
 * ฟังก์ชันนี้เลยโยน DomainException ให้ผู้เรียกเอาไปแสดงเป็นข้อความที่อ่านง่าย
 */
function strict_int(mixed $raw, string $label, int $default = 0): int
{
    if ($raw === null) {
        return $default;
    }
    $value = trim((string) $raw);
    if ($value === '') {
        return $default;
    }
    if (preg_match('/^-?\d+$/', $value) !== 1) {
        throw new \DomainException($label . 'ต้องเป็นตัวเลขจำนวนเต็ม');
    }

    return (int) $value;
}

/** เหมือน strict_int แต่สำหรับเลขทศนิยม ("abc" จะโยน exception แทนที่ (float) จะคืน 0.0 เงียบ ๆ) */
function strict_float(mixed $raw, string $label, float $default = 0.0): float
{
    if ($raw === null) {
        return $default;
    }
    $value = trim((string) $raw);
    if ($value === '') {
        return $default;
    }
    if (!is_numeric($value)) {
        throw new \DomainException($label . 'ต้องเป็นตัวเลข');
    }

    return (float) $value;
}
