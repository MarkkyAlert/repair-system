<?php

declare(strict_types=1);

// The PDF exports embed Sarabun and nothing else, so any character the bundled font files do not carry comes out
// of dompdf as an empty box. Nothing fails, nothing logs — the file is simply wrong for the reader.
//
// That is exactly how it shipped: the CSAT PDF labelled its columns "% พอใจ (≥4★)" and its distribution rows
// "5 ★", and Sarabun has no U+2605. Every star in that report was a box. The screen was fine, because a browser
// falls back to another font; only the printed document was broken, and only someone opening it would see.
//
// This guard reads the cmap out of each bundled .ttf and checks every literal character in every PDF view against
// it. ASCII and the Thai block are skipped — they are the whole point of the font and are covered. What is left is
// the risky set: symbols someone pastes in because it looks good in the editor (★ ✓ → ↑ ⚠ …). ≥ ≤ — · are in the
// font and stay allowed, so the guard is not a ban on punctuation, only on glyphs this font cannot draw.

/** @return array<int, true> code points the font's cmap can render */
function pgc_font_code_points(string $ttf): array
{
    $data = (string) file_get_contents($ttf);
    $tableCount = (int) unpack('n', substr($data, 4, 2))[1];

    $cmapOffset = 0;
    for ($i = 0; $i < $tableCount; $i++) {
        $entry = 12 + $i * 16;
        if (substr($data, $entry, 4) === 'cmap') {
            $cmapOffset = (int) unpack('N', substr($data, $entry + 8, 4))[1];
        }
    }
    assert_true($cmapOffset > 0, basename($ttf) . ' has a cmap table');

    $points = [];
    $subtables = (int) unpack('n', substr($data, $cmapOffset + 2, 2))[1];
    for ($i = 0; $i < $subtables; $i++) {
        $rec = $cmapOffset + 4 + $i * 8;
        $sub = $cmapOffset + (int) unpack('N', substr($data, $rec + 4, 4))[1];
        if ((int) unpack('n', substr($data, $sub, 2))[1] !== 4) {
            continue; // format 4 is the BMP table; it is the one that answers "can this render"
        }

        $segX2 = (int) unpack('n', substr($data, $sub + 6, 2))[1];
        $segments = intdiv($segX2, 2);
        for ($s = 0; $s < $segments; $s++) {
            $end = (int) unpack('n', substr($data, $sub + 14 + $s * 2, 2))[1];
            $start = (int) unpack('n', substr($data, $sub + 16 + $segX2 + $s * 2, 2))[1];
            if ($end - $start > 5000) {
                continue; // the trailing 0xFFFF terminator segment, not real coverage
            }
            for ($c = $start; $c <= $end; $c++) {
                $points[$c] = true;
            }
        }
    }

    return $points;
}

test('pdf glyphs: every character in a PDF view exists in the embedded Thai font', function (): void {
    $fonts = glob(BASE_PATH . '/resources/fonts/*.ttf') ?: [];
    assert_true(count($fonts) >= 2, 'the regular and bold faces both ship (found ' . count($fonts) . ')');

    // a character must be drawable in EVERY bundled face — a heading in bold is as visible as body text
    $coverage = null;
    foreach ($fonts as $ttf) {
        $points = pgc_font_code_points($ttf);
        assert_true(count($points) > 200, basename($ttf) . ' reports real coverage, so a parse failure cannot pass this silently');
        $coverage = $coverage === null ? $points : array_intersect_key($coverage, $points);
    }

    $views = array_merge(
        glob(BASE_PATH . '/app/Views/reports/*pdf*.php') ?: [],
        glob(BASE_PATH . '/app/Views/**/*pdf*.php') ?: [],
        array_filter([BASE_PATH . '/app/Views/layouts/pdf.php'], 'is_file')
    );
    $views = array_values(array_unique($views));
    assert_true(count($views) >= 10, 'the PDF views are still where this expects them (found ' . count($views) . ')');

    $offenders = [];
    foreach ($views as $view) {
        // strip PHP so only text the template prints verbatim is judged; runtime data is the caller's business
        $text = (string) preg_replace(['/<\?php.*?\?>/s', '/<\?=.*?\?>/s', '/<style.*?<\/style>/s'], '', (string) file_get_contents($view));

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $code = mb_ord($char, 'UTF-8');
            if ($code === false || $code < 128 || ($code >= 0x0E00 && $code <= 0x0E7F)) {
                continue; // ASCII and Thai are the font's core job
            }
            if (!isset($coverage[$code])) {
                $offenders[] = sprintf('%s: U+%04X "%s"', basename($view), $code, $char);
            }
        }
    }

    assert_same(
        [],
        array_values(array_unique($offenders)),
        'these characters have no glyph in the bundled font, so they print as empty boxes — use a word instead: '
        . implode(' | ', array_unique($offenders))
    );
});
