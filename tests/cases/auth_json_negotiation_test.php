<?php

declare(strict_types=1);

// error-review-5 F2: AuthMiddleware::handle() redirected (302 → HTML login page) on EVERY auth failure — even
// for JSON endpoints like GET /notifications/feed (the notification poller). A 302+HTML breaks response.json()
// and hands the client no reference. Now a JSON/AJAX caller gets a 401 JSON envelope with a reference, while a
// plain browser (HTML) request still redirects. Response::* exits, so this drives the real middleware in a
// subprocess that boots the app unauthenticated and inspects the emitted body.

/** Boot the app unauthenticated with the given Accept header, run AuthMiddleware, return its stdout. */
function auth_mw_capture(string $accept): string
{
    $bootstrap = dirname(__DIR__, 2) . '/bootstrap.php';
    $code = '$_SERVER["HTTP_ACCEPT"] = ' . var_export($accept, true) . ';'
        . '$_SERVER["REQUEST_URI"] = "/notifications/feed";'
        . '$_SERVER["REQUEST_METHOD"] = "GET";'
        . 'require ' . var_export($bootstrap, true) . ';'
        . '\App\Middleware\AuthMiddleware::handle();'
        . 'echo "REACHED_END";'; // only prints if handle() failed to stop the request
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');

    return (string) $out;
}

test('auth-json(F2): an unauthenticated JSON/AJAX request gets a 401 JSON body with a reference, not HTML', function (): void {
    $out = auth_mw_capture('application/json');
    $data = json_decode($out, true);

    assert_true(is_array($data), 'the response body is JSON (not an HTML login page)');
    assert_same(false, $data['success'] ?? null, 'the envelope reports the request was not authenticated');
    assert_true((string) ($data['reference'] ?? '') !== '', 'a reference is returned so a failed poll ties to a server log line');
    assert_true(!str_contains($out, 'REACHED_END'), 'handle() stops the request — it never falls through to the controller');
    assert_true(
        !str_contains($out, '<html') && !str_contains($out, 'Fatal error') && !str_contains($out, 'Stack trace'),
        'no HTML page, fatal, or stack trace leaks into the JSON response'
    );
});

test('auth-json(F2): a plain browser (HTML) request still redirects — no JSON body is emitted', function (): void {
    $out = auth_mw_capture('text/html');

    assert_true(!str_contains($out, 'REACHED_END'), 'an unauthenticated HTML request is also stopped (302 redirect)');
    assert_true(json_decode($out, true) === null, 'the HTML path emits NO JSON body — it redirects to the login page');
});
