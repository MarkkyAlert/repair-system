<?php

declare(strict_types=1);

// ux-review-3 F1: a 500 raised BY a database outage used to render through the guest layout, whose first line
// is setting('app_name') (a DB read), and errors/500.php called auth() (resolving the repository, whose PDO
// connects eagerly). Both throw when the DB is down, so the error page collapsed to raw HTML with no layout/
// CTA. The fix renders errors through a DB-free `error` layout, reads auth from the session only
// (AuthManager::checkSession), and wraps abort() in a static last-resort fallback. These prove the render is
// self-contained: a full styled shell, and ZERO database queries (so it survives an outage).

function render_error_page(int $status): string
{
    ob_start();
    try {
        \App\Core\View::render(
            'errors/' . $status,
            ['title' => (string) $status, 'message' => 'ระบบเกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง', 'reference' => 'REF-TEST-1'],
            'error'
        );
    } catch (\Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    return (string) ob_get_clean();
}

test('errors(F1): the 500 page renders a complete styled shell (doctype, lang, title, stylesheet, CTA, reference)', function (): void {
    $html = render_error_page(500);

    assert_contains_str('<!DOCTYPE html>', $html, 'a full HTML document, not a raw partial');
    assert_contains_str('lang="th"', $html, 'the html element carries lang="th"');
    assert_contains_str('<title>', $html, 'a non-empty document title is present');
    assert_contains_str('assets/css/app.css', $html, 'the styled shell links the (static) stylesheet');
    assert_contains_str('REF-TEST-1', $html, 'the correlation reference is shown for support');
    assert_true(str_contains($html, '/login') || str_contains($html, '/dashboard'), 'a recovery CTA link is present');
});

test('errors(F1): the 500 page renders in a subprocess against an UNREACHABLE database (real outage)', function (): void {
    // The real proof: boot the app in a fresh process (cold setting() cache) with the DB pointed at a dead
    // port, and render errors/500. The DB-free `error` layout produces a full page; the old `guest` layout
    // (whose first line is a DB read) produces nothing. In-process this can't be shown — setting()'s static
    // cache is already warm from earlier tests — so it must run out-of-process.
    if (!function_exists('shell_exec') || PHP_BINARY === '') {
        return; // no subprocess here — the structural + in-process render guards still cover the fix
    }

    $root = dirname(__DIR__, 2);
    $renderWithDeadDb = static function (string $layout) use ($root): string {
        $rootLit = var_export($root, true);
        $code = 'require ' . $rootLit . ' . "/vendor/autoload.php";'
            . '[$c] = require ' . $rootLit . ' . "/bootstrap.php";'
            . 'ob_start();'
            . 'try { App\\Core\\View::render("errors/500", ["title" => "500", "message" => "boom", "reference" => "REF-SUB"], ' . var_export($layout, true) . '); }'
            . ' catch (\\Throwable $e) {}'
            . 'echo ob_get_clean();';
        $cmd = 'DB_HOST=127.0.0.1 DB_PORT=1 ' . escapeshellarg(PHP_BINARY)
            . ' -d error_reporting=0 -r ' . escapeshellarg($code) . ' 2>/dev/null';

        return (string) shell_exec($cmd);
    };

    $errorHtml = $renderWithDeadDb('error');
    assert_contains_str('<!DOCTYPE html>', $errorHtml, 'the DB-free error layout renders a full page even with the database unreachable');
    assert_contains_str('lang="th"', $errorHtml, 'the outage page still carries lang="th"');
    assert_contains_str('REF-SUB', $errorHtml, 'the correlation reference survives the outage');

    $guestHtml = $renderWithDeadDb('guest');
    assert_false(
        str_contains($guestHtml, '<!DOCTYPE html>'),
        'the old guest layout CANNOT render during an outage (its setting() read hits the dead DB) — this is exactly why the error path had to become DB-free'
    );
});

test('errors(F1): the error path is DB-free by construction (error layout, session-only auth, static fallback)', function (): void {
    $root = dirname(__DIR__, 2);

    $layout = (string) file_get_contents($root . '/app/Views/layouts/error.php');
    assert_false(str_contains($layout, 'setting('), 'the error layout must not call setting() (DB) — use config()');

    foreach (['errors/404', 'errors/500'] as $view) {
        $src = (string) file_get_contents($root . '/app/Views/' . $view . '.php');
        assert_false(str_contains($src, 'auth()'), "{$view} must not call auth() (resolves the DB-backed repository)");
        assert_true(str_contains($src, 'checkSession'), "{$view} must read auth via the DB-free checkSession()");
    }

    $response = (string) file_get_contents($root . '/app/Core/Response.php');
    assert_true(str_contains($response, "'error')"), "abort() must render errors through the DB-free 'error' layout");
    assert_true(str_contains($response, 'minimalErrorHtml'), 'abort() must have a last-resort static fallback');
});
