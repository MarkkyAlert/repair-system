<?php

declare(strict_types=1);

// Shipped source carried internal review-round attributions in comments — "ux-review-6 F4", "error-review F1",
// "round-8 F1", "(R14-F1)", "perf-review F8", plus later shapes the first guard missed: "(F1 Phase 2)",
// "(audit R17)", "(Phase 2)", "(Finding B)", "(F2/F5)", "(F1-residual)", "(ดู P3 Fix-8)". They meant nothing to
// a buyer reading the source and made it look tied to our internal process. The rationale in each comment was
// kept; only the ID tag was removed. This guard keeps them from creeping back into the sold template.

test('template-hygiene(F3): no internal review-ID tags leak into the shipped source', function (): void {
    $root = dirname(__DIR__, 2);

    // A parenthetical whose leading token is a review/round attribution, plus a "see Fix-N" / "— R12/R13" tail
    // shape. The token must sit right after "(" (or the tail token be Fix-N/dash-R#), so real UI anchors like the
    // admin audit-log tab (#tab-audit — "audit" never leads its own paren) can't match.
    $anchored = '(?:[A-Za-z]+-review|[A-Za-z]+-audit|round[- ]?\d|R\d+[\d\/ -]*F?\d*|F\d[\s\/-]|Phase\s+\d|audit\s+[RF]\d|Finding\s+[A-Z]\b)';
    $pattern = '/\(' . $anchored . '[^)]*\)|\([^)]*(?:Fix-\d|—\s*R\d+\/R\d+)[^)]*\)/u';

    $files = [$root . '/resources/css/app.css', $root . '/public/assets/css/app.css'];
    foreach (['/app', '/bin'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (in_array($file->getExtension(), ['php', 'js'], true)) {
                $files[] = $file->getPathname();
            }
        }
    }
    // shipped root PHP + served JS bundles (where tags hid outside /app and /bin)
    foreach (['/public/index.php', '/public/check-requirements.php'] as $publicPhp) {
        if (is_file($root . $publicPhp)) {
            $files[] = $root . $publicPhp;
        }
    }
    $jsIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public/assets/js', FilesystemIterator::SKIP_DOTS));
    foreach ($jsIt as $file) {
        if ($file->getExtension() === 'js') {
            $files[] = $file->getPathname();
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

// Same idea, different residue: a before/after benchmark quoted in a shipped comment. Two of them said
// "measured at 100,000 tickets: 308 ms → 1.2 ms" — a figure from a rough single run that a careful repeat
// (interleaved, best of 21) did not reproduce; the honest number was closer to threefold, not two-hundredfold.
// So the comment overstated by a wide margin, and it sat in the source a buyer receives.
//
// Even an accurate one does not belong here. It is measured on one machine, one dataset and one database
// version, and none of those are the buyer's — it turns stale without anything failing, and it reads like a
// report rather than a note from whoever maintains the file. Keep the reason the code is shaped this way, and
// point at the test that holds the shape; drop the stopwatch.
//
// Not a blanket ban on timings: an inline note like bcrypt's "~100ms" describes a permanent property of the
// algorithm, not a result from a run. What is banned is the before→after pair.
test('template-hygiene: no before/after benchmark figures are quoted in shipped comments', function (): void {
    $root = dirname(__DIR__, 2);

    // "308 ms → 1.2 ms", "481ms -> 148ms", "2.4s → 0.3s" — a measured pair joined by an arrow
    $pattern = '/[\d.,]+\s*(?:ms|มิลลิวินาที|s\b|วินาที)\s*(?:→|->|—>)\s*[\d.,]+\s*(?:ms|มิลลิวินาที|s\b|วินาที)/u';

    $files = [];
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
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (preg_match($pattern, $token[1], $m)) {
                $offenders[] = basename($path) . ':' . $token[2] . ' — ' . trim($m[0]);
            }
        }
    }

    assert_same(
        [],
        $offenders,
        'a shipped comment quotes a before/after timing, which rots silently and reads as a report — '
        . 'keep the reason, cite the guarding test instead: ' . implode(' | ', $offenders)
    );
});
