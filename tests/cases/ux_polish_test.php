<?php

declare(strict_types=1);

// ux-review-8: three low-severity polish fixes, each with a RED-on-the-bug power-proof.

test('ux-8(F1): the notification filter tabs get a right-edge scroll fade, toggled by overflow', function (): void {
    $root = dirname(__DIR__, 2);
    foreach (['resources/css/app.css', 'public/assets/css/app.css'] as $rel) {
        $css = (string) file_get_contents($root . '/' . $rel);
        assert_true(
            preg_match('/\.notification-filter-tabs\.can-scroll-right\s*\{[^}]*mask-image/', $css) === 1,
            "{$rel} missing the .notification-filter-tabs.can-scroll-right scroll fade"
        );
    }
    $js = (string) file_get_contents($root . '/public/assets/js/app.js');
    assert_true(
        preg_match("/notification-filter-tabs[\s\S]{0,300}classList\.toggle\(\s*'can-scroll-right'/", $js) === 1,
        'app.js must toggle can-scroll-right on the filter tabs based on scroll overflow'
    );
});

test('ux-8(F2): the email-template edit heading uses Thai "เทมเพลต", not raw "template"', function (): void {
    $php = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/EmailTemplateController.php');
    assert_true(str_contains($php, "'แก้ไขเทมเพลต: '"), 'pageHeading must read "แก้ไขเทมเพลต:"');
    assert_false(str_contains($php, "'แก้ไข template: '"), 'must not use the English "template" in the heading');
});

test('ux-8(F3): the profile "สมาชิกตั้งแต่" is an absolute date (thai_datetime), not relative human_date', function (): void {
    $html = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/auth/profile.php');
    assert_true(
        str_contains($html, "thai_datetime((string) \$profile['created_at'], false)"),
        'member-since must render an absolute Thai date via thai_datetime'
    );
    assert_false(
        str_contains($html, "human_date((string) \$profile['created_at']"),
        'member-since must not use relative human_date (would show "19 ชม. ที่แล้ว")'
    );
});
