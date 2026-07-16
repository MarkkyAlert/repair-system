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

// error-review-4 F1: the ticket create/reset flows caught DomainException|RuntimeException together and only
// flashed — an operational RuntimeException (disk write, PDF/QR render) left no trace; the PDF/QR handlers went
// further and reported it as a 404 "not found". Split them: DomainException stays quiet (flash/404), a
// RuntimeException is logged. Source-lock (the controller actions exit via Response, so aren't harness-drivable).
test('F1: ticket/auth flows log the operational RuntimeException instead of flashing or 404-masking it', function (): void {
    $root = dirname(__DIR__, 2);
    $tickets = (string) file_get_contents($root . '/app/Controllers/TicketsController.php');
    $auth = (string) file_get_contents($root . '/app/Controllers/AuthController.php');

    assert_contains_str("log_caught_exception('ticket.store'", $tickets, 'store() logs an operational RuntimeException (was flashed with no trace)');
    assert_contains_str("log_caught_exception('ticket.jobpdf'", $tickets, 'printPdf routes an operational failure to a logged 500');
    assert_contains_str("log_caught_exception('ticket.qrpng'", $tickets, 'printQr routes an operational failure to a logged 500 (was a silent 404)');
    assert_contains_str("log_caught_exception('auth.reset'", $auth, 'resetPassword logs an operational RuntimeException');

    // no export handler may catch a RuntimeException as a 404 any more — that mis-reported an operational
    // failure as "not found" and skipped the log.
    assert_true(
        preg_match('/catch \(DomainException\|RuntimeException \$exception\)\s*\{\s*Response::abort\(404/', $tickets) === 0,
        'no ticket handler catches DomainException|RuntimeException together and aborts 404 (operational → 500 + logged)'
    );
});

// error-review-6 F3: sendResetLink() (request a reset link) caught DomainException|RuntimeException together and
// only flashed — but createPasswordReset() writes the reset row then RENDERS the email template, and a missing
// template throws a RuntimeException. That operational failure left no server trace. It must now be logged (with
// a reference, and without leaking the raw email/token). Drives the real controller in a subprocess with a
// service stub that throws RuntimeException (Response::redirect exits, so this can't run in-process).
test('F3 (auth): sendResetLink logs an operational RuntimeException with a reference, without leaking the email', function (): void {
    $bootstrap = dirname(__DIR__, 2) . '/bootstrap.php';
    $parts = [
        '$_SERVER["REQUEST_METHOD"]="POST";',
        '$_SERVER["HTTP_ACCEPT"]="text/html";',
        'require ' . var_export($bootstrap, true) . ';',
        '$logtmp = tempnam(sys_get_temp_dir(),"arl").".log";',
        'ini_set("error_log",$logtmp);',
        'register_shutdown_function(function() use ($logtmp){ echo "\n===LOG===\n".@file_get_contents($logtmp); @unlink($logtmp); });',
        '$_SESSION["_csrf_token"]="tok"; $_POST["_csrf"]="tok"; $_POST["email"]="probe@example.com";',
        '$stub = new class() extends \App\Services\AuthService { public function __construct(){} public function createPasswordReset(string $e): ?string { throw new \RuntimeException("email template render failed"); } };',
        '$ctrl = new \App\Controllers\AuthController($stub, app(\App\Repositories\NotificationPreferenceRepository::class), app(\App\Repositories\UserRepository::class), app(\App\Services\RememberMeService::class));',
        '$ctrl->sendResetLink();',
        'echo "REACHED_END";',
    ];
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(implode("\n", $parts)) . ' 2>/dev/null');

    assert_contains_str('[auth.reset.request]', $out, 'the operational RuntimeException is logged under the auth.reset.request marker');
    assert_true(preg_match('/\[req:[a-f0-9]{8}]/', $out) === 1, 'the log carries a request reference for correlation');
    assert_contains_str('RuntimeException', $out, 'the exception class is recorded');
    assert_true(!str_contains($out, 'REACHED_END'), 'the action stops (flash + redirect) — it never reports success after the failure');
    assert_true(!str_contains($out, 'probe@example.com'), 'the raw email is NOT written to the log (no PII leak)');
});
