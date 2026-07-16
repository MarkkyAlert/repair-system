<?php

declare(strict_types=1);

// error-review-3 O2: RuntimeException was used for BOTH expected retry/conflict conditions (logged as noise by
// the shared handler) AND genuine operational failures (some flashed with no log). Split them: retry/conflict
// is a DomainException (expected, quiet); an operational RuntimeException is logged. Source-lock (same style as
// error_taxonomy_test), since the controller catches + private lock methods aren't harness-drivable.

test('O2: retry/conflict conditions are DomainException (expected), not RuntimeException operational noise', function (): void {
    $root = dirname(__DIR__, 2);
    $retryMessages = [
        'app/Repositories/TicketRepository.php' => ['ระบบกำลังสร้างเลข Ticket', 'สถานะ Ticket ถูกเปลี่ยนแล้ว'],
        'app/Repositories/AssetRepository.php' => ['ไม่สามารถสร้าง QR token ที่ไม่ซ้ำ'],
        'app/Repositories/GuestTicketRequestRepository.php' => ['ระบบกำลังประมวลผลคำขอนี้'],
    ];

    foreach ($retryMessages as $file => $messages) {
        $src = (string) file_get_contents($root . '/' . $file);
        foreach ($messages as $msg) {
            $quoted = preg_quote($msg, '/');
            assert_true(
                preg_match('/throw new RuntimeException\(\'' . $quoted . '/u', $src) === 0,
                "$msg must NOT be thrown as RuntimeException (it would be logged as operational noise on every retry)"
            );
            assert_true(
                preg_match('/throw new DomainException\(\'' . $quoted . '/u', $src) === 1,
                "$msg must be thrown as DomainException (an expected, user-retryable condition)"
            );
        }
    }
});

test('O2: operational (RuntimeException) failures at the export/import controllers are logged before responding', function (): void {
    $root = dirname(__DIR__, 2);
    foreach (['AssetsController', 'ReportsController', 'UserImportController'] as $controller) {
        $src = (string) file_get_contents($root . '/app/Controllers/' . $controller . '.php');
        assert_contains_str('controller.operational', $src, "$controller must log an operational RuntimeException, not just flash it");
    }
});
