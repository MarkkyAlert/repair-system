<?php
declare(strict_types=1);

function render_partial(string $view, array $data = []): string
{
    $file = rtrim((string) config('paths.views'), '/') . '/' . trim($view, '/') . '.php';

    if (!is_file($file)) {
        return '';
    }

    extract($data, EXTR_SKIP);

    ob_start();
    include $file;
    return (string) ob_get_clean();
}

function notification_bell_data(): array
{
    $viewer = auth()->user() ?? [];
    if (!is_array($viewer) || (int) ($viewer['id'] ?? 0) <= 0) {
        return [
            'unreadCount' => 0,
            'items' => [],
        ];
    }

    $service = app(\App\Services\NotificationService::class);

    return $service instanceof \App\Services\NotificationService
        ? $service->getBellData($viewer)
        : ['unreadCount' => 0, 'items' => []];
}

if (!function_exists('thai_datetime')) {
    /**
     * รูปแบบวันที่ไทยแบบสัมบูรณ์เพียงรูปแบบเดียวที่ใช้แสดงผลทั้งระบบ: "dd ชื่อเดือนย่อ <พ.ศ.> [HH:MM]"
     * (เช่น "06 ก.พ. 2569 09:05") ปี พ.ศ. ใช้ตัวนี้ทุกที่ที่แสดงวันที่ให้ผู้ใช้เห็น เพื่อให้
     * ทั้งแอปอ่านปฏิทินเดียวกัน — เดิม service ใช้ date('d/m/Y') (ค.ศ.) ซึ่งขัดกับ
     * วันที่ พ.ศ. ของไทยบนหน้า ticket คืน "-" เมื่อ input ว่างเปล่า/ไม่ถูกต้อง
     *
     * @param int|string|null $value unix timestamp, string วันเวลา, หรือ null
     */
    function thai_datetime(int|string|null $value, bool $withTime = true): string
    {
        if ($value === null || $value === '' || $value === '-') {
            return '-';
        }
        $ts = is_int($value) ? $value : strtotime((string) $value);
        if ($ts === false || $ts <= 0) {
            return '-';
        }

        $thaiMonths = [
            1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
            'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.',
        ];
        $monthLabel = $thaiMonths[(int) date('n', $ts)] ?? '';
        $year = (int) date('Y', $ts);
        // แปลงปี ค.ศ. (< 2500) เป็นปี พ.ศ.; ถ้าค่าอยู่ในช่วงปี พ.ศ. อยู่แล้วก็คงไว้ตามเดิม
        $yearLabel = $year < 2500 ? (string) ($year + 543) : (string) $year;

        $out = sprintf('%s %s %s', date('d', $ts), $monthLabel, $yearLabel);
        if ($withTime) {
            $out .= ' ' . date('H:i', $ts);
        }

        return $out;
    }
}

if (!function_exists('mb_from_bytes')) {
    /**
     * จัดรูปแบบจำนวน byte เป็นป้าย MB สั้น ๆ สำหรับข้อความช่วยเหลือใน UI ("5", "1.5") เพื่อให้ขีดจำกัดการอัปโหลด/นำเข้า
     * ถูกแสดงจากค่า config แทนที่จะฝังตายตัวในแต่ละ view
     *
     * @param int|string $bytes
     */
    function mb_from_bytes(int|string $bytes): string
    {
        $mb = (int) $bytes / (1024 * 1024);

        return rtrim(rtrim(number_format($mb, 1), '0'), '.');
    }
}

if (!function_exists('thai_year')) {
    /**
     * แสดงปีในปฏิทินพุทธ (พ.ศ.) ให้เข้าชุดกับ thai_datetime() และรายงานต่าง ๆ ค่าปีที่เก็บใน DB /
     * ใช้ query ยังคงเป็น ค.ศ. — ตัวนี้ใช้แสดงผลอย่างเดียว ค่าที่อยู่ในช่วงปี พ.ศ.
     * (>= 2500) อยู่แล้วจะถูกคืนโดยไม่เปลี่ยน จึงเรียกซ้ำสองครั้งได้อย่างปลอดภัย
     *
     * @param int|string $year ปี ค.ศ. (หรือที่เป็น พ.ศ. อยู่แล้ว)
     */
    function thai_year(int|string $year): string
    {
        $y = (int) $year;

        return (string) ($y > 0 && $y < 2500 ? $y + 543 : $y);
    }
}

if (!function_exists('human_date')) {
    /**
     * จัดรูปแบบวันเวลาให้เป็นข้อความไทยที่อ่านง่าย
     * - "เมื่อสักครู่"           (< 60 วินาที)
     * - "5 นาทีที่แล้ว"          (< 60 นาที)
     * - "3 ชม. ที่แล้ว"          (< 24 ชม. วันเดียวกัน)
     * - "เมื่อวาน 14:20"
     * - "06 มิ.ย. 2026 14:20"
     * คืน "-" ถ้า input ว่างเปล่า/ไม่ถูกต้อง
     */
    function human_date(?string $value, bool $withTime = true): string
    {
        if ($value === null || trim((string) $value) === '' || $value === '-') {
            return '-';
        }
        $ts = strtotime((string) $value);
        if ($ts === false || $ts <= 0) {
            // แปลงเป็นวันที่ไม่ได้ — ถือว่าเป็นข้อความที่จัดรูปแบบไว้แล้ว (หลาย service จัดรูปแบบช่องวันที่ล่วงหน้า
            // ซึ่งบางหน้าเอาไปแสดงดิบ ๆ แต่บางหน้าส่งผ่าน human_date() อีกที)
            // จึงคืนค่าเดิมโดยไม่แตะ เพื่อให้ human_date() ทำซ้ำกับผลลัพธ์ตัวเองได้ (idempotent) แทนที่จะยุบ
            // วันที่ไทยที่จัดรูปแบบมาแล้วให้กลายเป็น "-"
            return trim((string) $value);
        }

        $now = time();
        $diff = $now - $ts;

        if ($diff >= 0 && $diff < 60) {
            return 'เมื่อสักครู่';
        }
        if ($diff >= 60 && $diff < 3600) {
            return (int) floor($diff / 60) . ' นาทีที่แล้ว';
        }

        $today = strtotime(date('Y-m-d', $now));
        $valueDate = strtotime(date('Y-m-d', $ts));

        if ($diff >= 3600 && $valueDate === $today) {
            return (int) floor($diff / 3600) . ' ชม. ที่แล้ว';
        }
        if ($valueDate === $today - 86400) {
            return 'เมื่อวาน' . ($withTime ? ' ' . date('H:i', $ts) : '');
        }

        // เก่ากว่า "เมื่อวาน" → ใช้รูปแบบวันที่ไทยแบบเต็ม (absolute) ที่เป็นแหล่งเดียวร่วมกัน
        return thai_datetime($ts, $withTime);
    }
}

if (!function_exists('human_date_short')) {
    function human_date_short(?string $value): string
    {
        return human_date($value, false);
    }
}
