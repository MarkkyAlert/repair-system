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
        '.hero-card-single' => 'centered guest track card (F7)',
        '.admin-tab-group-start' => 'admin tab grouping (F6)',
    ];
    foreach ($markers as $selector => $what) {
        assert_true(str_contains($source, $selector), "resources/css/app.css missing {$selector} ({$what})");
        assert_true(str_contains($built, $selector), "public/assets/css/app.css missing {$selector} ({$what}) — append it to the built file too");
    }
});
