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
