<?php

declare(strict_types=1);

use App\Controllers\ReportsController;
use App\Core\Router;

// decision (sample-pack): /reports/sample-pack builds several PDF/XLSX files in one request. It was a GET —
// triggerable by a plain link, a browser prefetch, or a cross-site request, with no throttle, so repeated hits
// hammer the server. Owner decision: POST + CSRF + per-user rate limit. This locks the method (POST, not GET)
// and the per-user rate-limit call. The CSRF gate itself is enforced by csrf_route_gate_test — this route is now
// a non-export POST, so that architecture guard already requires it to csrf_validate.
test('sample-pack (decision): route is POST not GET, and the handler rate-limits per user before generating', function (): void {
    $register = require dirname(__DIR__, 2) . '/config/routes.php';
    $router = new Router();
    $register($router);

    $methods = [];
    foreach ($router->routes() as $route) {
        if (($route['path'] ?? '') === '/reports/sample-pack') {
            $methods[] = $route['method'] ?? '';
        }
    }
    assert_true(in_array('POST', $methods, true), 'sample-pack is served over POST');
    assert_true(!in_array('GET', $methods, true), 'sample-pack is NOT a GET route (no link/prefetch/cross-site trigger)');

    // the handler rate-limits per user before the heavy multi-file generation. Source-locked: samplePack
    // redirect-exits, so it cannot be driven end-to-end in the CLI harness (same technique as the auth guards).
    $rm = new ReflectionMethod(ReportsController::class, 'samplePack');
    $lines = file((string) $rm->getFileName(), FILE_IGNORE_NEW_LINES) ?: [];
    $src = implode("\n", array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
    assert_contains_str('tooManyAttempts(', $src, 'samplePack applies a per-user rate limit before building the pack');
});
