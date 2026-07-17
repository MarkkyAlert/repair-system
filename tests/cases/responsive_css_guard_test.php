<?php
declare(strict_types=1);

// Guard the mobile horizontal-overflow fix (UX-audit #4). The .dashboard-chart-grid chart cards are grid
// items whose default min-width:auto refused to shrink, pushing /dashboard and /reports/trend ~6px past the
// 375px mobile viewport. The fix pairs the grid's minmax(0,1fr) with min-width:0 on the items.
//
// The CSS build is a manual step (resources/css/app.css -> tools/tailwindcss --minify ->
// public/assets/css/app.css), so this asserts the fix lives in BOTH the source AND the served build: a source
// edit shipped without a rebuild — or a rebuild that drops the rule — fails the suite instead of silently
// regressing the overflow. (Whitespace-tolerant so it matches the readable source and the minified build.)
test('css #4: dashboard-chart-grid min-width:0 overflow fix present in source and built CSS', function (): void {
    $root = dirname(__DIR__, 2);
    $pattern = '/\.dashboard-chart-grid\s*>\s*\*\s*\{[^}]*min-width\s*:\s*0/';

    $source = (string) file_get_contents($root . '/resources/css/app.css');
    assert_true(
        preg_match($pattern, $source) === 1,
        'resources/css/app.css missing .dashboard-chart-grid > * { min-width: 0 } (the #4 overflow fix)'
    );

    $built = (string) file_get_contents($root . '/public/assets/css/app.css');
    assert_true(
        preg_match($pattern, $built) === 1,
        'public/assets/css/app.css missing the min-width:0 fix — rebuild the CSS (source edited without a build)'
    );
});

// Same both-files guard for the ux-review CSS (appended raw rules, no Tailwind rebuild). These distinctive
// selectors are unique to that block, so if any is missing from one file the source + served CSS have drifted.
test('css (ux-review): the ux-review fixes live in BOTH source and built CSS', function (): void {
    $root = dirname(__DIR__, 2);
    $source = (string) file_get_contents($root . '/resources/css/app.css');
    $built = (string) file_get_contents($root . '/public/assets/css/app.css');

    $markers = [
        '.workflow-scroll-fade' => 'mobile stepper scroll fade (F2)',
        '.guest-track-result' => 'guest track result card (F7)',
        '.admin-tab-grouplabel' => 'admin tab group labels (F6)',
    ];
    foreach ($markers as $selector => $what) {
        assert_true(str_contains($source, $selector), "resources/css/app.css missing {$selector} ({$what})");
        assert_true(str_contains($built, $selector), "public/assets/css/app.css missing {$selector} ({$what}) — append it to the built file too");
    }
});

// ux-review-2 mobile fixes — all three must ship in BOTH the readable source and the served build.
test('css (ux-review-2): forgot-pw collapse, mobile export, priority reflow present in both source + built', function (): void {
    $root = dirname(__DIR__, 2);
    $files = [
        'resources/css/app.css' => (string) file_get_contents($root . '/resources/css/app.css'),
        'public/assets/css/app.css' => (string) file_get_contents($root . '/public/assets/css/app.css'),
    ];

    foreach ($files as $label => $css) {
        // F1: /forgot-password's .auth-reset-panel hero-card carries a 2-col grid at (0,2,0) specificity that
        // beats the single-class mobile .hero-card{1fr}; the collapse must be restated at matching specificity.
        assert_true(
            preg_match('/\.auth-reset-panel\s+\.hero-card\s*\{\s*grid-template-columns:\s*1fr/', $css) === 1,
            "{$label} missing .auth-reset-panel .hero-card { grid-template-columns: 1fr } (F1 mobile collapse)"
        );
        // F2: the report export bar must NOT be hidden wholesale on mobile (it stranded phone users).
        assert_true(
            preg_match('/\.report-export-bar\s*\{\s*display:\s*none/', $css) !== 1,
            "{$label} still hides .report-export-bar entirely on mobile (F2 — export unreachable)"
        );
        // F4: on mobile the priority-card meta drops to its own row so the copy column isn't crushed.
        assert_true(
            preg_match('/\.collapsible-meta\s*\{\s*flex-basis:\s*100%/', $css) === 1,
            "{$label} missing mobile .collapsible-meta { flex-basis: 100% } (F4 reflow)"
        );
    }
});
