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

test('a11y F1: confirm modal traps Tab focus within the open dialog (WCAG 2.4.3)', function (): void {
    // app.js is the JS source (no build). The confirm-modal keydown handler must contain Tab-trap logic
    // that keeps focus inside activeModal, so keyboard focus cannot walk out to the obscured background.
    // Behaviour verified live (Tab wraps last->first, Shift+Tab first->last, escaped focus pulled back);
    // re-checkable via the E2E keyboard test.
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
    assert_true(
        preg_match("/e\\.key === 'Tab'[\\s\\S]{0,400}activeModal\\.contains[\\s\\S]{0,200}\\.focus\\(\\)/", $js) === 1,
        'app.js confirm-modal must trap Tab within the open modal (WCAG 2.4.3)'
    );
});

test('a11y F1b: mobile sidebar drawer moves focus in and traps Tab (WCAG 2.4.3)', function (): void {
    // The off-canvas nav drawer (<=1024px) is modal (overlay over content). Opening it must move focus into
    // the nav and trap Tab so keyboard/SR users don't land on / escape to the obscured page behind it.
    // Behaviour verified live (focus enters on open, Tab stays trapped, escaped focus pulled back, Esc closes
    // + returns focus to the toggle); re-checkable via the E2E mobile keyboard test.
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
    assert_true(str_contains($js, 'sidebarDrawerFocusables'), 'app.js missing the sidebar-drawer focus helpers');
    assert_true(
        preg_match("/event\\.key === 'Tab' && drawerOpen[\\s\\S]{0,400}sidebar\\.contains[\\s\\S]{0,200}\\.focus\\(\\)/", $js) === 1,
        'app.js must trap Tab inside the open sidebar drawer (WCAG 2.4.3)'
    );
});

// ── a11y-review round (Accessibility & Responsive deep pass) ──

test('a11y-review ring: the keyboard focus ring clears 3:1 on both themes (WCAG 1.4.11)', function (): void {
    // A translucent focus ring (color-mix indigo @38%) composited only ~1.6:1 against the near-black dark
    // canvas and the near-white light canvas — below the 3:1 for non-text indicators. Resolve the actual
    // outline color + alpha from the served build, composite over each theme's --canvas, and assert >= 3:1.
    // A future palette change (or a return to a low-alpha ring) that dims the ring under 3:1 fails here.
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');

    assert_true(
        preg_match('/a:focus-visible[^{]*\{outline:3px solid ([^;]+);/', $css, $m) === 1,
        'could not isolate the a:focus-visible outline color'
    );
    $ringExpr = trim($m[1]);

    assert_true(preg_match('/--indigo-500:#([0-9a-fA-F]{6})/', $css, $im) === 1, '--indigo-500 not found in build');
    $ir = (int) hexdec(substr($im[1], 0, 2));
    $ig = (int) hexdec(substr($im[1], 2, 2));
    $ib = (int) hexdec(substr($im[1], 4, 2));

    // Solid var() => alpha 1.0; color-mix(in srgb, var(--indigo-500) N%, transparent) => alpha N/100.
    $alpha = 1.0;
    if (preg_match('/color-mix\(in srgb,\s*var\(--indigo-500\)\s*(\d+)%/', $ringExpr, $am)) {
        $alpha = ((int) $am[1]) / 100;
    }

    $lum = static function (float $r, float $g, float $b): float {
        $f = static fn (float $c): float => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    };
    $contrastOver = static function (string $bgHex) use ($ir, $ig, $ib, $alpha, $lum): float {
        $br = (int) hexdec(substr($bgHex, 0, 2));
        $bg = (int) hexdec(substr($bgHex, 2, 2));
        $bb = (int) hexdec(substr($bgHex, 4, 2));
        $rr = $ir * $alpha + $br * (1 - $alpha);
        $rg = $ig * $alpha + $bg * (1 - $alpha);
        $rb = $ib * $alpha + $bb * (1 - $alpha);
        $l1 = $lum($rr, $rg, $rb);
        $l2 = $lum($br, $bg, $bb);
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    };

    // Both --canvas values ship in the build (light :root + dark .dark): the near-white and near-black
    // page backgrounds the ring can sit on.
    preg_match_all('/--canvas:#([0-9a-fA-F]{6})/', $css, $cm);
    $backgrounds = array_values(array_unique($cm[1]));
    assert_true(count($backgrounds) >= 2, 'expected both a light + dark --canvas in the build');

    $failing = [];
    foreach ($backgrounds as $bg) {
        $c = $contrastOver($bg);
        if ($c < 3.0) {
            $failing[] = sprintf('#%s = %.2f:1', $bg, $c);
        }
    }
    assert_same([], $failing, 'focus ring below WCAG 1.4.11 3:1 on: ' . implode(' | ', $failing));
});

test('a11y-review sort-kbd: sortable table headers are keyboard-operable + advertise sort state', function (): void {
    // Sortable data-table headers bound click only — unreachable + untriggerable by keyboard (WCAG 2.1.1),
    // and only exposed aria-sort AFTER the first click, so a screen reader never knew they were sortable.
    // The fix makes each header focusable (tabindex), button-semantic, aria-sort=none up front, and adds an
    // Enter/Space keydown handler. Behaviour re-checkable via the E2E keyboard-sort test.
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
    assert_true(
        preg_match('/th\[data-sort-col\][\s\S]{0,900}setAttribute\(\s*[\'"]tabindex/', $js) === 1,
        'app.js must make a sortable th focusable (tabindex)'
    );
    assert_true(
        preg_match('/setAttribute\(\s*[\'"]aria-sort[\'"]\s*,\s*[\'"]none/', $js) === 1,
        'app.js must set an initial aria-sort=none so SR announces the header is sortable'
    );
    assert_true(
        preg_match('/th\.addEventListener\(\s*[\'"]keydown[\'"][\s\S]{0,200}(Enter|Spacebar|\x27 \x27)/', $js) === 1,
        'app.js must trigger sort on Enter/Space keydown (not click only)'
    );
});

test('a11y-review admin-arrow: admin tabs use roving tabindex + arrow-key navigation (WAI-ARIA tabs)', function (): void {
    // The 12 role="tab" elements advertise the tabs pattern to AT but had no arrow-key nav and were 12
    // separate Tab stops. The fix applies roving tabindex (selected=0, rest=-1) and ArrowLeft/Right/Home/End.
    // Behaviour re-checkable via the E2E admin arrow-nav test.
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');
    assert_true(str_contains($js, "'ArrowRight'"), 'admin.js must handle ArrowRight for tab navigation');
    assert_true(str_contains($js, "'ArrowLeft'"), 'admin.js must handle ArrowLeft for tab navigation');
    assert_true(
        preg_match('/setAttribute\(\s*[\'"]tabindex[\'"]\s*,\s*active\s*\?\s*[\'"]0[\'"]\s*:\s*[\'"]-1/', $js) === 1,
        'admin.js must apply roving tabindex (selected tab 0, others -1)'
    );
});

test('a11y-review tab-group: the first tab of each admin group names its group for screen readers', function (): void {
    // The three visible group captions (จัดการข้อมูล / สิทธิ์ & ตรวจสอบ / ระบบ) are aria-hidden so they don't
    // pollute the tablist — which meant SR users lost the grouping sighted users get. The first tab of each
    // group now carries an aria-label prefixed with its group name (and still contains its visible text, so
    // WCAG 2.5.3 Label-in-Name holds).
    $html = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/index.php');
    $groups = [
        '#tab-users' => 'จัดการข้อมูล',
        '#tab-roles' => 'ตรวจสอบ',
        '#tab-email' => 'ระบบ',
    ];
    foreach ($groups as $href => $group) {
        assert_true(
            preg_match('/href="' . preg_quote($href, '/') . '"[^>]*aria-label="[^"]*' . preg_quote($group, '/') . '/u', $html) === 1,
            "admin tab {$href} must carry an aria-label naming its group ({$group})"
        );
    }
});

test('a11y-review track-alert: the guest track error is a live region (role=alert)', function (): void {
    // A wrong reference/contact on the guest track page re-renders the error inline; without role=alert an
    // SR user submitting by keyboard is never told the lookup failed. (create.php already does this; parity.)
    $html = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/guest/track.php');
    assert_true(
        preg_match('/class="auth-alert auth-alert-danger"[^>]*role="alert"|role="alert"[^>]*class="auth-alert auth-alert-danger"/', $html) === 1,
        'guest track error box must carry role="alert" so SR announces the lookup failure'
    );
});

test('a11y-review contrast: the undeclared --text-muted fallback is gone; --muted clears AA on light', function (): void {
    // --text-muted is never declared, so every `var(--text-muted, #94a3b8)` silently rendered #94a3b8 —
    // only ~2.3:1 on the light canvas (fails WCAG 1.4.3). breadcrumb, confirm-modal + broadcast were
    // switched to the real --muted token. Assert the bad fallback is gone from CSS (both files) + the
    // broadcast inline styles, and that --muted itself clears AA. (ux-review F5, F6)
    $root = dirname(__DIR__, 2);
    foreach ([
        'resources/css/app.css',
        'public/assets/css/app.css',
        'app/Views/admin/broadcast.php',
    ] as $rel) {
        $src = (string) file_get_contents($root . '/' . $rel);
        assert_true(
            !str_contains($src, 'var(--text-muted'),
            "{$rel} still uses the undeclared var(--text-muted…) fallback (fails AA on light surfaces)"
        );
    }

    $css = (string) file_get_contents($root . '/public/assets/css/app.css');
    assert_true(preg_match('/:root\{[^}]*--muted:#([0-9a-fA-F]{6})/', $css, $mm) === 1, 'light --muted hex not found in :root');
    assert_true(preg_match('/--canvas:#([0-9a-fA-F]{6})/', $css, $cm) === 1, 'light --canvas hex not found');

    $lum = static function (string $hex): float {
        $r = (int) hexdec(substr($hex, 0, 2));
        $g = (int) hexdec(substr($hex, 2, 2));
        $b = (int) hexdec(substr($hex, 4, 2));
        $f = static fn (float $c): float => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    };
    $l1 = $lum($mm[1]);
    $l2 = $lum($cm[1]);
    $ratio = (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    assert_true($ratio >= 4.5, sprintf('--muted #%s on --canvas #%s is %.2f:1, below WCAG AA 4.5:1', $mm[1], $cm[1], $ratio));
});

test('a11y-review accent-contrast: dark-theme indigo accents get a darker light value (breadcrumb, modal, broadcast)', function (): void {
    // #a5b4fc / #818cf8 are dark-theme accents that only clear ~1.7-2.7:1 on light surfaces. The light rules
    // now use the darker --indigo-700 (the light-indigo is confined to a .dark override), and the confirm
    // modal card is opaque so the dark backdrop can't gray out its labels. Runtime-verified by the axe
    // ticket-detail + broadcast + modal e2e. (ux-review F5, F6)
    $root = dirname(__DIR__, 2);
    $css = (string) file_get_contents($root . '/public/assets/css/app.css');
    $broadcast = (string) file_get_contents($root . '/app/Views/admin/broadcast.php');

    assert_true(
        preg_match('/\.breadcrumb li\[aria-current=page\]\{[^}]*color:var\(--indigo-700\)/', $css) === 1,
        'breadcrumb current item must use --indigo-700 on light (was #a5b4fc → 1.8:1)'
    );
    assert_true(
        preg_match('/\.confirm-modal-summary strong\{[^}]*color:var\(--indigo-700\)/', $css) === 1,
        'confirm-modal emphasis must use --indigo-700 on light'
    );
    assert_true(
        preg_match('/\.confirm-modal-card\{[^}]*background:var\(--surface-strong\)/', $css) === 1,
        'confirm-modal card must be opaque (--surface-strong) so the backdrop cannot gray out its text'
    );
    assert_true(
        preg_match('/\.broadcast-badge \{[^}]*color: var\(--indigo-700\)/', $broadcast) === 1,
        'broadcast badge must use --indigo-700 on light'
    );

    // --indigo-700 clears AA for normal text on the light canvas.
    assert_true(preg_match('/--indigo-700:#([0-9a-fA-F]{6})/', $css, $im) === 1, '--indigo-700 hex not found');
    assert_true(preg_match('/--canvas:#([0-9a-fA-F]{6})/', $css, $cm) === 1, '--canvas hex not found');
    $lum = static function (string $hex): float {
        $r = (int) hexdec(substr($hex, 0, 2));
        $g = (int) hexdec(substr($hex, 2, 2));
        $b = (int) hexdec(substr($hex, 4, 2));
        $f = static fn (float $c): float => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    };
    $l1 = $lum($im[1]);
    $l2 = $lum($cm[1]);
    $ratio = (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    assert_true($ratio >= 4.5, sprintf('--indigo-700 on --canvas is %.2f:1, below AA', $ratio));
});

test('a11y-review file-picker: every native file input is wrapped in the Thai .file-field control', function (): void {
    // A bare <input type="file" class="input"> renders the browser-locale "Choose File / No file chosen",
    // clashing with the Thai UI on 5 upload/import flows. Each is now wrapped in .file-field with a Thai
    // .file-field-button label; app.js mirrors the selected name into the aria-live readout. (ux-review-4 F1)
    $root = dirname(__DIR__, 2);
    $views = [
        'app/Views/tickets/create.php',
        'app/Views/tickets/show.php',
        'app/Views/assets/import.php',
        'app/Views/admin/import-users.php',
        'app/Views/admin/tabs/settings.php',
    ];
    foreach ($views as $rel) {
        $html = (string) file_get_contents($root . '/' . $rel);
        assert_true(str_contains($html, 'type="file"'), "{$rel} should still have a file input");
        assert_true(str_contains($html, 'class="file-field"'), "{$rel} file input must be wrapped in .file-field");
        assert_true(str_contains($html, 'class="file-field-button"'), "{$rel} must provide a Thai .file-field-button (replaces the browser 'Choose File')");
        assert_true(str_contains($html, 'class="file-field-input"'), "{$rel} native file input must use .file-field-input (hidden but focusable)");
        assert_true(
            preg_match('/type="file"[^>]*class="input"|class="input"[^>]*type="file"/', $html) !== 1,
            "{$rel} still has a bare native file input (class=\"input\") showing the browser-locale picker"
        );
    }

    $js = (string) file_get_contents($root . '/public/assets/js/app.js');
    assert_true(
        str_contains($js, "matches('.file-field-input')") && str_contains($js, 'data-file-field-name'),
        'app.js must mirror the chosen file name into the .file-field-name aria-live readout'
    );
});

test('a11y-review setup-labels: first-run setup admin fields carry a visible <label for>, not placeholder-only', function (): void {
    // The /setup admin-account fields had only a group <legend> + placeholders; a placeholder vanishes on
    // input, so the installer loses the field's identity mid-form (WCAG 3.3.2). Each now has an id + label.
    // (ux-review-3 F3) — axe can't catch this because a placeholder satisfies the accessible-name check.
    $html = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/setup/index.php');
    foreach (['admin_username', 'admin_email', 'admin_full_name', 'admin_password'] as $field) {
        assert_true(
            preg_match('/<label for="' . preg_quote($field, '/') . '"/', $html) === 1,
            "setup field {$field} must have a persistent <label for> (WCAG 3.3.2), not just a placeholder"
        );
        assert_true(
            preg_match('/<input id="' . preg_quote($field, '/') . '"/', $html) === 1,
            "setup field {$field} input must carry the matching id"
        );
    }
});

test('a11y-review pagination: hand-rolled admin pagination prev/next carry accessible names', function (): void {
    // The Audit + Users tabs hand-roll pagination (custom page params + #tab hash), and their prev/next
    // arrows held only an aria-hidden SVG — axe link-name (serious). They now carry the same aria set as
    // the shared pagination partial. (ux-review F7)
    $root = dirname(__DIR__, 2);
    foreach (['app/Views/admin/tabs/audit.php', 'app/Views/admin/tabs/users.php'] as $rel) {
        $html = (string) file_get_contents($root . '/' . $rel);
        assert_true(str_contains($html, 'aria-label="หน้าก่อนหน้า"'), "{$rel} prev pagination link missing aria-label (WCAG 2.4.4/4.1.2)");
        assert_true(str_contains($html, 'aria-label="หน้าถัดไป"'), "{$rel} next pagination link missing aria-label");
        assert_true(str_contains($html, 'aria-current="page"'), "{$rel} active page link missing aria-current");
    }
});
