<?php

declare(strict_types=1);

// ux-refactor F6: upload/import limits are a documented .env knob, but the UI hints (and the JS max-files
// check) hard-coded 3 files / 5MB / 500 rows / 2MB / 1MB. A buyer who raised a limit in .env got a UI that
// still claimed the old cap, and client-side JS that disagreed with the backend. Every view now renders the
// limit from config, and app.js reads max-files from a data attribute.

test('ux-6(F6): upload/import views render limits from config, not hardcoded literals', function (): void {
    $root = dirname(__DIR__, 2);
    $views = [
        'app/Views/tickets/create.php' => ["config('uploads.attachment_max_files'", "mb_from_bytes(config('uploads.attachment_max_bytes'"],
        'app/Views/tickets/show.php' => ["config('uploads.attachment_max_files'", "mb_from_bytes(config('uploads.attachment_max_bytes'"],
        'app/Views/assets/import.php' => ["config('uploads.import_asset_max_rows'", "mb_from_bytes(config('uploads.import_asset_max_bytes'"],
        'app/Views/admin/import-users.php' => ["config('uploads.import_user_max_rows'", "mb_from_bytes(config('uploads.import_user_max_bytes'"],
    ];
    foreach ($views as $view => $needles) {
        $html = (string) file_get_contents($root . '/' . $view);
        foreach ($needles as $needle) {
            assert_true(str_contains($html, $needle), "{$view} must render {$needle} (not a hardcoded limit)");
        }
        assert_true(preg_match('/\d+\s?MB/', $html) !== 1, "{$view} must not hardcode an MB literal");
    }

    // the ticket-create input exposes the config max-files, and app.js reads it (no more `const maxFiles = 3`)
    $create = (string) file_get_contents($root . '/app/Views/tickets/create.php');
    assert_true(str_contains($create, 'data-max-files='), 'ticket create input must expose data-max-files');
    $js = (string) file_get_contents($root . '/public/assets/js/app.js');
    assert_true(str_contains($js, "getAttribute('data-max-files')"), 'app.js must read maxFiles from data-max-files');

    // the bytes → MB helper the views use
    assert_same('5', mb_from_bytes(5242880), '5MB');
    assert_same('2', mb_from_bytes(2097152), '2MB');
    assert_same('1.5', mb_from_bytes(1572864), '1.5MB');
});
