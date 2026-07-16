<?php

declare(strict_types=1);

// error-review-7 F1: a crafted body `_csrf[]=x` (an ARRAY where a scalar token is expected) reached
// Csrf::validate(?string) and threw an uncaught TypeError → HTTP 500 on /login (no auth required). After the
// fix, csrf_validate() normalizes the non-string to null, so the state-changing action rejects it as an expected
// CSRF failure and REDIRECTS (302) — no 500, no login, no fatal/stack leak. Drives the real controller action in
// a subprocess (Response::* exits), capturing the HTTP status via a shutdown function, like
// auth_json_negotiation_test.

/** POST to /login with the given `_csrf` value; return "<body>\nHTTP_STATUS:<code>". */
function csrf_login_capture(string $csrfExpr): string
{
    $bootstrap = dirname(__DIR__, 2) . '/bootstrap.php';
    $code = '$_SERVER["REQUEST_METHOD"]="POST";'
        . '$_SERVER["HTTP_ACCEPT"]="text/html";'
        . 'register_shutdown_function(function () { echo "\nHTTP_STATUS:" . http_response_code(); });'
        . 'require ' . var_export($bootstrap, true) . ';'
        . '$_SESSION["_csrf_token"]="realtoken1234567890abcdef12345678";'
        . '$_POST["_csrf"]=' . $csrfExpr . ';'
        . '$_POST["login"]="admin"; $_POST["password"]="whatever";'
        . '$ctrl = new \App\Controllers\AuthController(app(\App\Services\AuthService::class), app(\App\Repositories\NotificationPreferenceRepository::class), app(\App\Repositories\UserRepository::class), app(\App\Services\RememberMeService::class));'
        . '$ctrl->login();'
        . 'echo "REACHED_END";';

    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
}

test('csrf-http(F1): POST /login with an array _csrf is a stable 302 (no HTTP 500, no login, no fatal)', function (): void {
    $out = csrf_login_capture('["x"]'); // the malformed `_csrf[]=x` body

    assert_contains_str('HTTP_STATUS:302', $out, 'the malformed token yields a normal 302 redirect, not a crash');
    assert_true(!str_contains($out, 'HTTP_STATUS:500'), 'it is NOT an HTTP 500 (the uncaught TypeError is gone)');
    assert_true(!str_contains($out, 'REACHED_END'), 'the action stops at the CSRF rejection — it never reaches the login attempt');
    assert_true(
        !str_contains($out, 'TypeError') && !str_contains($out, 'Fatal error') && !str_contains($out, 'Stack trace'),
        'no TypeError / fatal / stack trace leaks'
    );
});

test('csrf-http(F1): a scalar mismatching _csrf also redirects 302 (baseline — same handling)', function (): void {
    $out = csrf_login_capture('"not-the-token"');

    assert_contains_str('HTTP_STATUS:302', $out, 'a plain wrong token is also a 302 redirect (the array case now matches this)');
    assert_true(!str_contains($out, 'REACHED_END'), 'the action stops at the CSRF rejection');
});
