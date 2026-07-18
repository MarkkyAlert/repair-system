<?php

declare(strict_types=1);

// ux-refactor F4 (packaging): the app ships pre-compiled CSS (public/assets/css/app.css), but recolouring the
// brand used to mean editing resources/css/app.css and re-running the Tailwind build — a toolchain the target
// buyer (cPanel/XAMPP, non-dev) does not have. theme.css is a plain, hand-editable override loaded AFTER
// app.css: a buyer changes brand hex values and refreshes, no build required.
//
// These guards keep that contract honest:
//   1. Every screen layout that pulls app.css also pulls theme.css, AFTER it (so the override actually wins).
//   2. theme.css defaults EXACTLY match the shipped app.css brand tokens — shipping the file is a visual no-op,
//      and a future brand-colour change in app.css can't silently diverge from the documented override defaults.

test('theme(F4): every layout loads theme.css after app.css', function (): void {
    $layouts = glob(dirname(__DIR__, 2) . '/app/Views/layouts/*.php') ?: [];
    assert_true($layouts !== [], 'found the layout files');

    $checked = 0;
    foreach ($layouts as $layout) {
        $html = (string) file_get_contents($layout);
        $appPos = strpos($html, "asset('css/app.css')");
        if ($appPos === false) {
            continue; // layout doesn't use the main stylesheet (nothing to override)
        }
        $checked++;
        $themePos = strpos($html, "asset('css/theme.css')");
        assert_true($themePos !== false, basename($layout) . ' must also link theme.css (the brand override)');
        assert_true($themePos > $appPos, basename($layout) . ' must link theme.css AFTER app.css so the override wins the cascade');
    }
    assert_true($checked >= 3, "expected the screen layouts to link app.css (checked {$checked})");
});

test('theme(F4): theme.css defaults match the shipped app.css brand tokens exactly (no-op by default, no drift)', function (): void {
    $root = dirname(__DIR__, 2);
    $theme = (string) file_get_contents($root . '/public/assets/css/theme.css');
    $built = (string) file_get_contents($root . '/public/assets/css/app.css');

    // Pull every brand-colour token the override file declares.
    preg_match_all('/(--(?:indigo|violet|fuchsia|sky)-\d+)\s*:\s*(#[0-9a-fA-F]{3,8})/', $theme, $m, PREG_SET_ORDER);
    assert_true(count($m) >= 15, 'theme.css should expose the full brand ramp (' . count($m) . ' tokens found)');

    $normalize = static fn (string $hex): string => strtolower($hex);
    foreach ($m as [$whole, $token, $value]) {
        // The token's DEFINITION in built app.css (var(--x) usages end in ')' and won't match the ':' here).
        assert_true(
            preg_match('/' . preg_quote($token, '/') . '\s*:\s*(#[0-9a-fA-F]{3,8})/', $built, $bm) === 1,
            "app.css must define {$token} (theme.css exposes a token the app doesn't ship)"
        );
        assert_same(
            $normalize($value),
            $normalize($bm[1]),
            "theme.css default for {$token} ({$value}) must match the shipped app.css value ({$bm[1]}) — rebrand-default drifted from the real theme"
        );
    }
});
