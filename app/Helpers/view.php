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

if (!function_exists('human_date')) {
    /**
     * Format a datetime to a human-friendly Thai string.
     * - "เมื่อสักครู่"           (< 60s)
     * - "5 นาทีที่แล้ว"          (< 60m)
     * - "3 ชม. ที่แล้ว"          (< 24h, same day)
     * - "เมื่อวาน 14:20"
     * - "06 มิ.ย. 2026 14:20"
     * Returns "-" if input is empty/invalid.
     */
    function human_date(?string $value, bool $withTime = true): string
    {
        if ($value === null || trim((string) $value) === '' || $value === '-') {
            return '-';
        }
        $ts = strtotime((string) $value);
        if ($ts === false || $ts <= 0) {
            return '-';
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

        $thaiMonths = [
            1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
            'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.',
        ];
        $monthLabel = $thaiMonths[(int) date('n', $ts)] ?? '';
        $year = (int) date('Y', $ts);
        // Convert to Buddhist year if value looks like Gregorian (year < 2500)
        $yearLabel = $year < 2500 ? (string) ($year + 543) : (string) $year;

        $datePart = sprintf('%s %s %s', date('d', $ts), $monthLabel, $yearLabel);
        if ($withTime) {
            $datePart .= ' ' . date('H:i', $ts);
        }
        return $datePart;
    }
}

if (!function_exists('human_date_short')) {
    function human_date_short(?string $value): string
    {
        return human_date($value, false);
    }
}
