<?php

declare(strict_types=1);

// ux-refactor F2: ticket + all 10 report PDFs hardcoded "MAINTENANCE OPERATIONS"/"ASSET MAINTENANCE" and
// carried NO org name/logo, while the UI/email/QR already use the app_name + logo from Admin settings. A buyer
// who rebranded had to edit 11 PHP views. Every PDF now includes a shared brand header that reads app_name +
// app_tagline + the uploaded logo from settings.

test('pdf-brand(F2): every PDF header renders the org brand from Admin settings (custom brand appears)', function (): void {
    $root = dirname(__DIR__, 2);

    // Structural: every PDF export view includes the shared brand header.
    $pdfViews = array_merge(glob($root . '/app/Views/reports/*pdf*.php') ?: [], [$root . '/app/Views/tickets/pdf.php']);
    assert_true(count($pdfViews) >= 11, 'found the PDF export views (' . count($pdfViews) . ')');
    foreach ($pdfViews as $view) {
        assert_true(
            str_contains((string) file_get_contents($view), "render_partial('partials/print/pdf-brand')"),
            basename($view) . ' must include the shared PDF brand header'
        );
    }

    // Behavioural: set a custom app_name + tagline, render the brand in a FRESH process (setting() has a static
    // cache, so an in-process test can't prove it re-reads), and assert the custom brand appears. Restore in finally.
    if (!function_exists('shell_exec') || PHP_BINARY === '') {
        return;
    }
    $pdo = tvm_container()->get(PDO::class);
    $original = [];
    foreach (['app_name', 'app_tagline'] as $key) {
        $original[$key] = $pdo->query('SELECT setting_value FROM system_settings WHERE setting_key = ' . $pdo->quote($key))->fetchColumn();
    }
    $set = $pdo->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?');

    try {
        $set->execute(['BRANDTEST Co', 'app_name']);
        $set->execute(['สโลแกนองค์กรทดสอบ', 'app_tagline']);

        $rootLit = var_export($root, true);
        $code = '$_ENV["DB_NAME"]="repair_system_test"; require ' . $rootLit . ' . "/vendor/autoload.php"; [$c]=require ' . $rootLit . ' . "/bootstrap.php"; echo App\\Core\\View::capture("partials/print/pdf-brand");';
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -r ' . escapeshellarg($code) . ' 2>/dev/null');

        assert_contains_str('BRANDTEST Co', $out, 'the PDF brand must show the custom app_name from Admin settings');
        assert_contains_str('สโลแกนองค์กรทดสอบ', $out, 'the PDF brand must show the custom app_tagline');
    } finally {
        foreach ($original as $key => $value) {
            $set->execute([$value === false ? '' : (string) $value, $key]);
        }
    }
});
