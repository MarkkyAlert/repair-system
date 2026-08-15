<?php
declare(strict_types=1);

// Guard the mobile horizontal-overflow fix (UX-audit #4). The .dashboard-chart-grid chart cards are grid
// items whose default min-width:auto refused to shrink, pushing /dashboard and /reports/trend ~6px past the
// 375px mobile viewport. The fix pairs the grid's minmax(0,1fr) with min-width:0 on the items.
//
// The CSS build is a manual step — ./build-css.sh (which self-fetches the pinned tools/tailwindcss binary)
// runs resources/css/app.css -> tailwindcss --minify -> public/assets/css/app.css. So this asserts the fix
// lives in BOTH the source AND the served build: a source
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
        '.file-field-button' => 'custom Thai file picker button (ux-review-4 F1)',
        '.file-field-input' => 'hidden-but-focusable native file input (ux-review-4 F1)',
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

    // Match per RULE (one selector of the list + declarations in any order) instead of as one literal string.
    // The minifier is free to alphabetise declarations and to fold a selector into a shared group
    // (".auth-reset-panel .hero-card,.hero-card{…}"); a buyer who rebuilds with the pinned Tailwind gets
    // exactly that, and a text match would then fail on a stylesheet that is perfectly correct. Comments are
    // stripped first — in the readable source they sit between rules and would otherwise glue themselves onto
    // the next selector, quietly weakening every match (a "must NOT be hidden" check that can never see the
    // rule always passes).
    $declares = static function (string $css, string $selectorPattern, array $declarationPatterns): bool {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER) < 1) {
            return false;
        }
        foreach ($rules as $rule) {
            $matched = false;
            foreach (explode(',', $rule[1]) as $selector) {
                if (preg_match($selectorPattern, trim($selector)) === 1) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }
            foreach ($declarationPatterns as $declaration) {
                if (preg_match($declaration, $rule[2]) !== 1) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    };

    foreach ($files as $label => $css) {
        // F1: /forgot-password's .auth-reset-panel hero-card carries a 2-col grid at (0,2,0) specificity that
        // beats the single-class mobile .hero-card{1fr}; the collapse must be restated at matching specificity.
        assert_true(
            $declares($css, '/^\.auth-reset-panel\s+\.hero-card$/', ['/grid-template-columns:\s*1fr\s*(?:;|$)/']),
            "{$label} missing a rule giving .auth-reset-panel .hero-card grid-template-columns: 1fr (F1 mobile collapse)"
        );
        // F2: the report export bar must NOT be hidden wholesale on mobile (it stranded phone users).
        assert_false(
            $declares($css, '/^\.report-export-bar$/', ['/display:\s*none/']),
            "{$label} still hides .report-export-bar entirely on mobile (F2 — export unreachable)"
        );
        // F4: on mobile the priority-card meta drops to its own row so the copy column isn't crushed.
        assert_true(
            $declares($css, '/^\.collapsible-meta$/', ['/flex-basis:\s*100%/']),
            "{$label} missing mobile .collapsible-meta { flex-basis: 100% } (F4 reflow)"
        );
        // F3: on mobile the admin tablist becomes one horizontal-scroll row (nowrap) and its group captions
        // stop forcing their own row (flex:0 0 auto) — desktop keeps the 3-row grouped layout (flex:0 0 100%).
        assert_true(
            $declares($css, '/^\.admin-tabs$/', ['/flex-wrap:\s*nowrap/', '/overflow-x:\s*auto/']),
            "{$label} missing mobile .admin-tabs { flex-wrap: nowrap; overflow-x: auto } (F3 single-row)"
        );
        assert_true(
            $declares($css, '/^\.admin-tab-grouplabel$/', ['/flex:\s*0 0 auto/']),
            "{$label} missing mobile .admin-tab-grouplabel { flex: 0 0 auto } (F3 inline captions)"
        );
        // ux-review-3 F2: on mobile the admin tab row sticks BELOW the 76px topbar (top:76px), not at top:0
        // where the taller, higher-z topbar hid it entirely.
        assert_true(
            $declares($css, '/^\.admin-tabs-scroller$/', ['/top:\s*76px/']),
            "{$label} missing mobile .admin-tabs-scroller { top: 76px } (F2 — tab row hidden behind topbar)"
        );
        // ux-review-3 F4: the field info-buttons must meet the 24x24 minimum target size (WCAG 2.5.8).
        assert_true(
            $declares($css, '/^\.field-info-icon$/', ['/(?<![-\w])width:\s*24px/', '/(?<![-\w])height:\s*24px/']),
            "{$label} .field-info-icon must be >= 24x24 (was 22x22 — below WCAG 2.5.8 min target)"
        );
        // ux-review-5 F2: the filter-chip dismiss button must clear 24x24 (was 1.2rem / 19.2px).
        assert_true(
            $declares($css, '/^\.filter-chip-dismiss$/', ['/min-width:\s*24px/', '/min-height:\s*24px/']),
            "{$label} .filter-chip-dismiss must be >= 24x24 (was 1.2rem/19.2px — below WCAG 2.5.8)"
        );
        // ux-review-7 F2: the mobile create-form sticky action bars sit BELOW the 76px topbar (was top:0,
        // hidden behind it) and drop the redundant helper line.
        assert_true(
            preg_match('/create-action-bar[^}]*top:\s*76px/', $css) === 1,
            "{$label} create-form action bars must stick at top:76px on mobile (F2 — were hidden behind the topbar)"
        );
        assert_true(
            str_contains($css, 'create-action-bar .action-bar-left .helper-text'),
            "{$label} create-form action bars must hide the redundant helper on mobile (F2)"
        );
    }
});

// เลย์เอาต์มือถือรอบ 2026-08-16: หน้าล็อกอินเอาฟอร์มขึ้นก่อนครึ่งโฆษณา และหน้าคิวงานทำการ์ดสรุปเป็นรางเลื่อน
// ทั้งคู่เป็นกฎใหม่ที่ต้องอยู่ใน "ไฟล์ที่เว็บโหลดจริง" ด้วย ไม่ใช่แค่ในไฟล์ต้นทาง — การแก้ resources/ แล้วลืมรัน
// ./build-css.sh คือความผิดพลาดที่ไม่มีอะไรเตือนเลย (หน้าเว็บจะเหมือนเดิมเป๊ะ ๆ)
test('css (mobile-reach): the login reorder + ticket stat rail ship in BOTH source and built CSS', function (): void {
    $root = dirname(__DIR__, 2);
    $markers = [
        '.auth-login-panel' => 'login form-first reorder (scoped to /login only)',
        '.ticket-stat-scroll' => 'ticket queue KPI rail',
        '[data-ticket-queue-panel]' => 'queue panel-head icon hidden on mobile',
    ];

    foreach (['resources/css/app.css', 'public/assets/css/app.css'] as $rel) {
        $css = (string) file_get_contents($root . '/' . $rel);
        foreach ($markers as $selector => $what) {
            assert_true(str_contains($css, $selector), "{$rel} missing {$selector} ({$what})");
        }
    }

    // การจะกลายเป็นรางเลื่อนได้ ต้องมีคลาสอยู่บน element จริงด้วย ไม่ใช่มีแต่กฎ CSS ลอย ๆ
    $queue = (string) file_get_contents($root . '/app/Views/tickets/index.php');
    assert_true(str_contains($queue, 'class="stat-grid ticket-stat-scroll"'), 'the queue stat grid must carry the rail class');
    assert_true(str_contains($queue, 'aria-label="สรุปสถานะงานแจ้งซ่อม'), 'the rail needs its own label — the auto-derived one would say the page title');

    $login = (string) file_get_contents($root . '/app/Views/auth/login.php');
    assert_true(str_contains($login, 'class="guest-panel auth-login-panel"'), 'the login page must carry the scoping class');

    // รางที่เลื่อนได้ต้องโฟกัสด้วยคีย์บอร์ดได้ (WCAG 2.1.1) — ตัวจัดการอยู่ใน app.js ตัวเดียวกับ .table-wrap
    $js = (string) file_get_contents($root . '/public/assets/js/app.js');
    assert_true(
        preg_match('/querySelectorAll\(\s*\'[^\']*\.ticket-stat-scroll/', $js) === 1,
        'app.js must make the new rail keyboard-focusable when it overflows, like the other scroll rails'
    );
});
