<?php

declare(strict_types=1);

// ux-review-2 F8: Response::abort() renders 404/500 in the guest layout with a single recovery CTA. The CTA
// used to be a hardcoded "กลับหน้าเข้าสู่ระบบ" -> /login for everyone — so a SIGNED-IN user who opened a dead
// link (e.g. a deleted ticket id) was told to log in again, reading like a dropped session. The CTA is now
// session-aware: authenticated users recover to the dashboard, guests still go to login. Rendered through the
// real view (View::capture, no layout) under both auth states.

test('errors(F8): a signed-in user recovers to the dashboard; a guest is sent to login', function (): void {
    auth()->logout();
    foreach (['errors/404', 'errors/500'] as $view) {
        $html = \App\Core\View::capture($view, ['message' => 'x', 'reference' => '']);
        assert_contains_str('กลับหน้าเข้าสู่ระบบ', $html, "guest {$view} CTA label should be the login prompt");
        assert_contains_str('/login', $html, "guest {$view} CTA should link to /login");
        assert_false(str_contains($html, 'กลับแดชบอร์ด'), "guest {$view} CTA must not offer the dashboard");
    }

    auth()->login(['id' => 7, 'name' => 'Ops', 'role' => 'admin', 'email' => 'ops@example.com']);
    try {
        foreach (['errors/404', 'errors/500'] as $view) {
            $html = \App\Core\View::capture($view, ['message' => 'x', 'reference' => '']);
            assert_contains_str('กลับแดชบอร์ด', $html, "authed {$view} CTA label should be the dashboard prompt");
            assert_contains_str('/dashboard', $html, "authed {$view} CTA should link to /dashboard");
            assert_false(str_contains($html, 'กลับหน้าเข้าสู่ระบบ'), "authed {$view} CTA must not read like a logout");
        }
    } finally {
        auth()->logout();
    }
});

test('errors(F1-403): the 403 logout CTA is a POST form with CSRF, not a GET /logout link (which 404s)', function (): void {
    // /logout is POST-only (config/routes.php); a GET link to it renders a 404, so a user recovering from a
    // 403 by logging out hit ANOTHER error. The CTA is now a real POST form + CSRF. (ux-review-7 F1)
    $html = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/errors/403.php');
    assert_true(
        preg_match('/<form method="post"[\s\S]{0,80}logout/', $html) === 1,
        '403 logout must be a POST form to /logout'
    );
    assert_true(str_contains($html, 'csrf_field()'), '403 logout form must include CSRF');
    assert_false(
        preg_match("/'href'\s*=>\s*'\/logout'/", $html) === 1,
        '403 must not use a GET link to /logout (POST-only route → 404)'
    );
});
