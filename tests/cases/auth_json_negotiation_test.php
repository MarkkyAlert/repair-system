<?php

declare(strict_types=1);

// error-review-5 F2: AuthMiddleware::handle() redirected (302 → HTML login page) on EVERY auth failure — even
// for JSON endpoints like GET /notifications/feed (the notification poller). A 302+HTML breaks response.json()
// and hands the client no reference. Now a JSON/AJAX caller gets a 401 JSON envelope with a reference, while a
// plain browser (HTML) request still redirects. Response::* exits, so this drives the real middleware in a
// subprocess that boots the app unauthenticated and inspects the emitted body.

/**
 * Boot the app unauthenticated with the given Accept header, run AuthMiddleware, return its stdout. A shutdown
 * function (runs even though Response::* exits) appends HTTP_STATUS:<code> so the test can assert the actual
 * HTTP status, not just the body — the status is set via http_response_code(), which a body-only capture missed.
 */
function auth_mw_capture(string $accept): string
{
    $bootstrap = dirname(__DIR__, 2) . '/bootstrap.php';
    $code = '$_SERVER["HTTP_ACCEPT"] = ' . var_export($accept, true) . ';'
        . '$_SERVER["REQUEST_URI"] = "/notifications/feed";'
        . '$_SERVER["REQUEST_METHOD"] = "GET";'
        . 'register_shutdown_function(function () { echo "\nHTTP_STATUS:" . http_response_code(); });'
        . 'require ' . var_export($bootstrap, true) . ';'
        . '\App\Middleware\AuthMiddleware::handle();'
        . 'echo "REACHED_END";'; // only prints if handle() failed to stop the request
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');

    return (string) $out;
}

test('auth-json(F2): an unauthenticated JSON/AJAX request gets a 401 JSON body with a reference, not HTML', function (): void {
    $out = auth_mw_capture('application/json');
    $body = explode('HTTP_STATUS:', $out)[0]; // strip the shutdown-appended status marker before decoding
    $data = json_decode($body, true);

    assert_true(is_array($data), 'the response body is JSON (not an HTML login page)');
    assert_same(false, $data['success'] ?? null, 'the envelope reports the request was not authenticated');
    assert_true((string) ($data['reference'] ?? '') !== '', 'a reference is returned so a failed poll ties to a server log line');
    // error-review-6 coverage gap: assert the HTTP STATUS, not just the body — the reviewer showed a body-only
    // test stayed green when the 401 was changed to 200.
    assert_contains_str('HTTP_STATUS:401', $out, 'the JSON auth failure is a real 401 (not a 200 with an error body)');
    assert_true(!str_contains($out, 'REACHED_END'), 'handle() stops the request — it never falls through to the controller');
    assert_true(
        !str_contains($out, '<html') && !str_contains($out, 'Fatal error') && !str_contains($out, 'Stack trace'),
        'no HTML page, fatal, or stack trace leaks into the JSON response'
    );
});

test('auth-json(F2): a plain browser (HTML) request still redirects — no JSON body is emitted', function (): void {
    $out = auth_mw_capture('text/html');

    assert_true(!str_contains($out, 'REACHED_END'), 'an unauthenticated HTML request is also stopped (302 redirect)');
    assert_contains_str('HTTP_STATUS:302', $out, 'the HTML path is a 302 redirect (not a 401) — the browser flow is unchanged');
    assert_true(!str_contains(explode('HTTP_STATUS:', $out)[0], '{'), 'the HTML path emits NO JSON body — it redirects to the login page');
});
