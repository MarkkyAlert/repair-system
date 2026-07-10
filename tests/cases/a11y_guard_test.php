<?php
declare(strict_types=1);

// Static, CI-runnable a11y guards for the view layer (no browser needed) — the same approach as
// icons_guard_test.php. Encodes two conventions the UX audit surfaced:
//   #3  every full-page view must carry a top-level <h1> (the accessible page title) — either its own
//       (pages on the shared hero pair a visually-hidden <h1 class="sr-only"> with the hero's visible
//       <h2>; standalone pages like asset create/edit/print and the guest QR scan carry their own),
//       or via the page-header partial (guarded to emit one). A page without any <h1> has no document
//       heading for screen readers.
//   #6  a <label class="field-label"> must be programmatically associated with its control via for=
//       (id on the input), including disabled fields — otherwise the label announces to nothing.

test('a11y #3: every full-page view carries a top-level <h1> (its own or via page-header)', function (): void {
    $viewDir = dirname(__DIR__, 2) . '/app/Views';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewDir, FilesystemIterator::SKIP_DOTS));

    // Fragments render inside a page, not as one, so they carry no <h1> of their own and are exempt:
    // view partials, email bodies, layouts, and the admin/tabs/* includes.
    $missing = [];
    $checked = 0;
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $rel = str_replace($viewDir . '/', '', $file->getPathname());
        if (preg_match('#^(partials|emails|layouts)/#', $rel) || str_contains($rel, '/tabs/')) {
            continue;
        }
        $checked++;
        $src = (string) file_get_contents($file->getPathname());
        if (!str_contains($src, '<h1') && !str_contains($src, 'components/page-header')) {
            $missing[] = $rel;
        }
    }

    assert_true($checked > 40, 'walked the full-page views (' . $checked . ')');
    sort($missing);
    assert_same([], $missing, 'full-page views with no <h1> (and no page-header): ' . implode(' | ', $missing));
});

test('a11y #6: every <label class="field-label"> is associated with a control via for=', function (): void {
    $viewDir = dirname(__DIR__, 2) . '/app/Views';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewDir, FilesystemIterator::SKIP_DOTS));

    // A visible field label must carry for= (its control carries the matching id) — including disabled
    // fields. A radio group is labelled by its <fieldset>/<legend>, not a stray <label>, so a group
    // caption is a <p class="field-label">, not a <label>, and is correctly not matched here. Each label
    // sits on its own line in the views.
    $unassociated = [];
    $seen = 0;
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $index => $line) {
            if (!str_contains($line, '<label class="field-label"')) {
                continue;
            }
            $seen++;
            if (!str_contains($line, 'for=')) {
                $unassociated[] = basename(dirname($file->getPathname())) . '/' . basename($file->getPathname()) . ':' . ($index + 1);
            }
        }
    }

    assert_true($seen > 20, 'walked the field-label elements (' . $seen . ')');
    sort($unassociated);
    assert_same([], $unassociated, 'field-label without for= (radio groups should use <fieldset>/<legend>): ' . implode(' | ', $unassociated));
});

test('a11y F5: app layout has a skip link targeting <main id="main-content"> + styling ships in built CSS', function (): void {
    $root = dirname(__DIR__, 2);
    $layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

    // A keyboard skip link must be a real anchor to the main landmark, and <main> must carry the matching id.
    assert_true(
        preg_match('/<a[^>]*\bhref="#main-content"[^>]*\bclass="skip-link"|<a[^>]*\bclass="skip-link"[^>]*\bhref="#main-content"/', $layout) === 1,
        'app layout missing <a class="skip-link" href="#main-content">'
    );
    assert_true(
        preg_match('/<main[^>]*\bid="main-content"/', $layout) === 1,
        '<main> must carry id="main-content" as the skip-link target'
    );

    // The visually-hidden-until-focus styling must ship in the served build (CSS build is manual — a source
    // edit without a hand-sync would leave the link permanently off-screen or always visible).
    $built = (string) file_get_contents($root . '/public/assets/css/app.css');
    assert_true(str_contains($built, '.skip-link{'), 'public/assets/css/app.css missing the .skip-link rule (rebuild/hand-sync the CSS)');
    assert_true(preg_match('/\.skip-link:focus[^{]*\{[^}]*left:\s*0/', $built) === 1, 'built CSS missing .skip-link:focus { left:0 } (link never reveals on focus)');
});

test('a11y F4: app.js makes an overflowing .table-wrap keyboard-focusable', function (): void {
    // A horizontally-overflowing scroll container must become keyboard-focusable so its clipped columns are
    // reachable without a mouse (WCAG 2.1.1). app.js is the source (no JS build); the enhancement is gated on
    // scrollWidth overflow. Behavioural coverage is the axe scrollable-region-focusable check in E2E.
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
    assert_true(
        preg_match('/\.table-wrap[\s\S]{0,500}scrollWidth[\s\S]{0,300}setAttribute\(\s*[\'"]tabindex/', $js) === 1,
        'app.js must set tabindex on an overflowing .table-wrap (WCAG 2.1.1 keyboard scroll)'
    );
});

test('a11y F2: primary-button gradient stops meet WCAG AA for white text (>= 4.5:1)', function (): void {
    // The .btn-primary label is white 13px/600 (normal text). Resolve each gradient stop token from the
    // served build and assert white text clears 4.5:1 on it, so a future palette/gradient change that dims
    // the primary CTA below AA fails the suite. (The min occurs at a stop here, so checking stops suffices.)
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
    assert_true(preg_match('/\.btn-primary\{background:(.*?)\}/s', $css, $m) === 1, 'could not isolate the .btn-primary rule');
    preg_match_all('/var\((--[a-z0-9-]+)\)/', $m[1], $tm);
    $tokens = array_values(array_unique($tm[1]));
    assert_true(count($tokens) >= 2, 'primary button should use gradient tokens (found: ' . implode(',', $tokens) . ')');

    $lum = static function (int $r, int $g, int $b): float {
        $f = static fn (float $c): float => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    };
    $failing = [];
    foreach ($tokens as $tok) {
        assert_true(preg_match('/' . preg_quote($tok, '/') . ':#([0-9a-fA-F]{6})/', $css, $hm) === 1, "gradient token {$tok} has no hex value in :root");
        $hex = strtolower($hm[1]);
        $ratio = 1.05 / ($lum((int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))) + 0.05);
        if ($ratio < 4.5) {
            $failing[] = sprintf('%s #%s = %.2f:1', $tok, $hex, $ratio);
        }
    }
    assert_same([], $failing, 'primary-button gradient stops below WCAG AA 4.5:1 for white text: ' . implode(' | ', $failing));
});
