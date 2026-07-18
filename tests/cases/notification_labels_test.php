<?php

declare(strict_types=1);

use App\Services\NotificationService;

// ux-review-7 F3: the "สถานะ Ticket เปลี่ยน" notification toggle showed its covered events as raw English
// codes (assigned/started/resolved/completed/reopened/cancelled) — internal jargon on a Thai page. Owner
// decision (2026-07-18): Thai labels. This is display-only (the view splits the hint on "·" into chips).

test('notif-labels(F3): the status-change hint lists Thai event labels, not raw English codes', function (): void {
    $hint = NotificationService::NOTIFICATION_TYPE_HINTS['ticket_status_changed'] ?? '';

    foreach (['assigned', 'started', 'resolved', 'completed', 'reopened', 'cancelled'] as $code) {
        assert_false(str_contains($hint, $code), "the chip hint must not show the raw code '{$code}'");
    }
    foreach (['มอบหมาย', 'เริ่มงาน', 'รอตรวจรับ', 'เสร็จสิ้น', 'เปิดซ้ำ', 'ยกเลิก'] as $thai) {
        assert_true(str_contains($hint, $thai), "the chip hint must include the Thai label '{$thai}'");
    }

    // Split the same way the view does — exactly six Thai chips, matching resolved/completed in the status map.
    $chips = array_values(array_filter(array_map('trim', explode('·', $hint))));
    assert_same(6, count($chips), 'six event chips');
    assert_same('รอตรวจรับ', $chips[2], 'resolved chip matches ticket_status_label_th(resolved)');
    assert_same('เสร็จสิ้น', $chips[3], 'completed chip matches ticket_status_label_th(completed)');
});
