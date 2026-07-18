<?php

declare(strict_types=1);

// ux-refactor-2 F3: shipped source carried internal review-round attributions in comments — "ux-review-6 F4",
// "error-review F1", "logic-review F2", "round-8 F1", "(R14-F1)", "perf-review F8", etc. They meant nothing to
// a buyer reading the source and made it look tied to our internal process. The rationale in each comment was
// kept; only the ID tag was removed. This guard keeps them from creeping back into the sold template.

test('template-hygiene(F3): no internal review-ID tags leak into the shipped source', function (): void {
    $root = dirname(__DIR__, 2);

    // Parenthetical internal-review attributions. The admin "audit log" tab (#tab-audit / id="tab-audit") is a
    // real UI anchor, not a review tag — it is never parenthesised, so this pattern can't match it.
    $pattern = '/\((?:[A-Za-z]+-review|[A-Za-z]+-audit|round[- ]?\d|R\d+[\d\/ -]*F?\d*)[^)]*\)/';

    $files = [$root . '/resources/css/app.css'];
    foreach (['/app', '/bin'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (in_array($file->getExtension(), ['php', 'js'], true)) {
                $files[] = $file->getPathname();
            }
        }
    }

    $offenders = [];
    foreach ($files as $path) {
        if (preg_match_all($pattern, (string) file_get_contents($path), $m)) {
            foreach ($m[0] as $hit) {
                $offenders[] = basename($path) . ': ' . $hit;
            }
        }
    }

    assert_same(
        [],
        $offenders,
        'internal review-ID tags must not ship in source (keep the rationale, drop the tag): ' . implode(' | ', array_slice($offenders, 0, 10))
    );
});
