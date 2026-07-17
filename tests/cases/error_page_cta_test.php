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
