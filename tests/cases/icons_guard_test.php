<?php
declare(strict_types=1);

// Guard: every icon name referenced in a view — either lucide('name') directly, or 'icon' => 'name' passed
// to the button / page-header partials — MUST exist in the icons.php set. An unknown name makes lucide()
// render a loud red data-missing-icon fallback in the live UI (the exact bug the UX audit caught:
// arrow-left on ~10 pages + alert-triangle on the asset-detail page showed broken red icons).
//
// This is the static, CI-runnable version of the Playwright missing-icon guard (which only covered
// login/dashboard) — it walks EVERY view, so a new page referencing an unmapped icon fails the suite
// instead of shipping a red glyph. No browser needed.
test('icons: every icon referenced in a view is in the lucide set (no data-missing-icon fallback)', function (): void {
    $viewDir = dirname(__DIR__, 2) . '/app/Views';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewDir, FilesystemIterator::SKIP_DOTS));

    /** @var array<string, string> $referenced icon name → first file that references it */
    $referenced = [];
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        foreach (["/lucide\\(\\s*'([a-z0-9-]+)'/", "/'icon'\\s*=>\\s*'([a-z0-9-]+)'/"] as $pattern) {
            if (preg_match_all($pattern, $src, $matches)) {
                foreach ($matches[1] as $name) {
                    $referenced[$name] ??= $file->getPathname();
                }
            }
        }
    }

    assert_true(count($referenced) > 30, 'collected a realistic set of icon references (' . count($referenced) . ')');

    $missing = [];
    foreach ($referenced as $name => $where) {
        if (str_contains(lucide($name), 'data-missing-icon')) {
            $missing[] = $name . ' → ' . basename(dirname($where)) . '/' . basename($where);
        }
    }
    sort($missing);
    assert_same([], $missing, 'icon names not in icons.php (render red fallback): ' . implode(' | ', $missing));
});
