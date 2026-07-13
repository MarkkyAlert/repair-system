<?php
declare(strict_types=1);

use App\Core\Router;

// Architecture guard (CSRF): every STATE-CHANGING POST route must validate the CSRF token at the controller —
// either csrf_validate() directly, or the handleUpdate()/adminUpdate() wrappers that call it internally. This
// is the CSRF counterpart to admin_route_gate_test (which pins the role gate): it walks the real
// config/routes.php and reflects each POST handler's source, so a NEW mutation route can't ship without CSRF
// protection — it reddens in CI, no browser needed.
//
// The ONLY exempt POST routes are the report EXPORTs (/reports/.../export/{csv,excel,pdf}): they are read-only
// (render a file from filter params, change no server state) and auth-gated, and a cross-site POST cannot read
// the file response back — so CSRF adds nothing there. They POST only because filter sets are large. The
// exemption is a PATTERN, not a frozen path list, and each exempt handler must be an export* method — so the
// allowlist can't silently swallow a mutation.

test('csrf: every state-changing POST route gates CSRF (csrf_validate / handleUpdate / adminUpdate); only read-only report exports are exempt', function (): void {
    $register = require dirname(__DIR__, 2) . '/config/routes.php';
    $router = new Router();
    $register($router);

    $gatePatterns = ['csrf_validate(', '->handleUpdate(', '->adminUpdate('];
    // read-only report exports — the sole documented CSRF exemption (see file header)
    $exportExempt = '#^/reports/(.+/)?export/(csv|excel|pdf)$#';

    $postRoutes = 0;
    $exemptCount = 0;
    $ungated = [];
    foreach ($router->routes() as $route) {
        if ($route['method'] !== 'POST') {
            continue;
        }
        $postRoutes++;
        $path = (string) $route['path'];

        // closures can't be source-introspected; none exist today, but treat one as a violation if added
        if (!is_array($route['handler'])) {
            $ungated[] = 'POST ' . $path . ' → closure (cannot verify CSRF)';
            continue;
        }

        [$class, $method] = $route['handler'];
        $rm = new ReflectionMethod($class, $method);
        $lines = file((string) $rm->getFileName()) ?: [];
        $src = implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));

        $gated = false;
        foreach ($gatePatterns as $pattern) {
            if (str_contains($src, $pattern)) {
                $gated = true;
                break;
            }
        }
        if ($gated) {
            continue;
        }

        // not gated → allowed only if it is a read-only report export (path pattern AND an export* handler)
        if (preg_match($exportExempt, $path) && str_starts_with($method, 'export')) {
            $exemptCount++;
            continue;
        }

        $ungated[] = 'POST ' . $path . ' → ' . $class . '::' . $method . '()';
    }

    assert_true($postRoutes >= 90, 'the POST route table is loaded (found ' . $postRoutes . ')');
    assert_true($exemptCount > 0, 'the read-only report-export exemption is exercised (found ' . $exemptCount . ')');
    assert_same([], $ungated, 'every state-changing POST route must gate CSRF; ungated: ' . implode(' | ', $ungated));
});
