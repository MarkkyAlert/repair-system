<?php

declare(strict_types=1);

// Chart.js is loaded by the layout only for an explicit list of paths (keeping the CDN off every other page).
// That makes a silent failure possible: add a <canvas> to a new page, forget the layout list, and the chart
// simply never appears — no error, no console message, nothing for a reviewer to notice. The page just looks
// like it has no data. This guard closes that loop by deriving both sides from the source.
//
// It also pins the other half: the CDN host must stay allowed by the Content-Security-Policy, or the script is
// blocked and the same silent blank appears on the pages that DO declare a chart.

/** Map a view file to the route path the layout's is_path() gate would see. */
function chart_view_route(string $viewFile): string
{
    $relative = str_replace([BASE_PATH . '/app/Views/', '.php'], '', $viewFile);
    $parts = explode('/', $relative);
    if (end($parts) === 'index') {
        array_pop($parts);
    }

    return '/' . implode('/', $parts);
}

test('charts: every view that draws a canvas is on the layout list that loads Chart.js', function (): void {
    $layout = (string) file_get_contents(BASE_PATH . '/app/Views/layouts/app.php');

    // the guard block around the Chart.js <script>: take the is_path() paths it names
    assert_true(
        preg_match('/<\?php if \(([^)]*is_path[^>]*?)\): \?>\s*<script src="https:\/\/cdn\.jsdelivr\.net\/npm\/chart\.js"/', $layout, $m) === 1,
        'the layout must still gate the Chart.js script behind an is_path() condition'
    );
    preg_match_all("/is_path\('([^']+)'\)/", $m[1], $pathMatches);
    $gated = $pathMatches[1] ?? [];
    assert_true($gated !== [], 'the gate must name at least one path');

    $viewsWithCanvas = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/app/Views', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        if (str_contains((string) file_get_contents($file->getPathname()), '<canvas')) {
            $viewsWithCanvas[] = $file->getPathname();
        }
    }
    assert_true($viewsWithCanvas !== [], 'sanity: the app does draw charts somewhere');

    foreach ($viewsWithCanvas as $view) {
        $route = chart_view_route($view);
        assert_true(
            in_array($route, $gated, true),
            str_replace(BASE_PATH . '/', '', $view) . " draws a <canvas> but {$route} is not in the layout's Chart.js gate — the chart would silently never render"
        );
    }

    // and nothing pays for the CDN it does not use
    foreach ($gated as $route) {
        $candidates = [
            BASE_PATH . '/app/Views' . $route . '/index.php',
            BASE_PATH . '/app/Views' . $route . '.php',
        ];
        $draws = false;
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && str_contains((string) file_get_contents($candidate), '<canvas')) {
                $draws = true;
            }
        }
        assert_true($draws, "{$route} loads the Chart.js CDN but draws no <canvas> — drop it from the gate");
    }
});

test('charts: the CSP still allows the CDN the gate depends on', function (): void {
    // a chart page that passes the gate but is blocked by policy fails in exactly the same silent way
    $policy = (string) file_get_contents(BASE_PATH . '/app/Helpers/security.php');
    assert_contains_str(
        'https://cdn.jsdelivr.net',
        $policy,
        'script-src must keep allowing the Chart.js CDN, or every gated chart page renders blank'
    );
});
