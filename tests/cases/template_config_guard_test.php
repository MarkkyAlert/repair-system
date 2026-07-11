<?php
declare(strict_types=1);

// Static, CI-runnable template-readiness guards (same approach as a11y_guard_test / icons_guard_test):
// buyer-configurable values must not be hard-coded in the app chrome, or a buyer who changes them in
// Admin/env won't see them reflected. Encodes findings from the post-refactor template review:
//   F3  the sidebar-footer "system ready" block showed a hard-coded Asia/Bangkok, ignoring the
//       default_timezone setting that bootstrap actually applies to the runtime.

test('template-config F3: sidebar footer renders the timezone setting, not a hard-coded literal', function (): void {
    $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/layouts/app.php');

    assert_true(
        str_contains($layout, "setting('default_timezone'"),
        'sidebar footer must resolve the timezone from setting(default_timezone) — the same value bootstrap applies'
    );
    assert_false(
        str_contains($layout, '<span>Asia/Bangkok</span>'),
        'the hard-coded footer timezone literal (<span>Asia/Bangkok</span>) must be gone'
    );
});
