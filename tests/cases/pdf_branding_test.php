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

test('pdf-brand: ป้ายบนหัวเอกสารเป็นภาษาไทย ตรงกับชื่อเมนูในระบบ', function (): void {
    // เดิมหัว PDF ทุกใบมีบรรทัดตัวพิมพ์ใหญ่ภาษาอังกฤษ (EXECUTIVE SUMMARY, BACKLOG AGING, SLA BREACH
    // ANALYSIS…) เป็นของประดับเหนือหัวเรื่องไทย — เจ้าของเคยให้คงไว้ (2026-07-05) แล้วกลับมติ (2026-08-15)
    // เพราะเอกสารพวกนี้ถูกส่งต่อให้ผู้บริหารที่ไม่ได้เปิดระบบ บรรทัดแรกสุดที่เขาเห็นจึงไม่ควรเป็นภาษาที่อ่านไม่ออก
    $root = dirname(__DIR__, 2);
    $views = array_merge(glob($root . '/app/Views/reports/*pdf*.php') ?: [], [$root . '/app/Views/tickets/pdf.php']);

    $offenders = [];
    foreach ($views as $view) {
        if (preg_match('/<p class="(?:brand|document)-kicker">([^<]*)<\/p>/', (string) file_get_contents($view), $m) !== 1) {
            continue;
        }
        $kicker = trim($m[1]);
        // SLA เป็นคำที่เจ้าของสั่งให้คงไว้ (มีคำอธิบายกำกับที่อื่นแล้ว) ตัดออกก่อนตรวจ
        $withoutKeepList = trim(str_replace('SLA', '', $kicker));
        if (preg_match('/[ก-๙]/u', $withoutKeepList) !== 1 || preg_match('/[A-Za-z]/', $withoutKeepList) === 1) {
            $offenders[] = basename($view) . ' → "' . $kicker . '"';
        }
    }

    assert_same([], $offenders, 'หัวเอกสารที่ยังเป็นอังกฤษ: ' . implode(', ', $offenders));

    // ระยะห่างตัวอักษรแบบอังกฤษตัวใหญ่ทำให้สระบน-ล่างของไทยลอยห่างจากพยัญชนะ
    foreach ($views as $view) {
        $css = (string) file_get_contents($view);
        assert_true(
            preg_match('/\.(?:brand|document)-kicker\s*\{[^}]*letter-spacing/', $css) !== 1,
            basename($view) . ': ป้ายไทยไม่ควรมี letter-spacing แบบตัวอักษรอังกฤษ'
        );
    }
});
