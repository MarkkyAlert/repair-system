<?php
declare(strict_types=1);

use App\Core\Router;

// Architecture guard (Broken-Access-Control prevention): every /admin/* route handler must enforce a role
// gate AT THE CONTROLLER — either require_role() directly (hard 403), or the adminUpdate()/handleUpdate()
// wrappers that call require_role() internally. Relying only on a service-layer assert_admin() is not enough
// (that yields a soft flash+redirect and is the exact miss that left AdminController::sendBroadcast ungated
// at the controller until the API test caught it).
//
// The HTTP API test confirmed non-admins get a hard 403 on admin routes; this pins the invariant so a NEW
// admin route can't ship without a controller gate. It walks the real config/routes.php and reflects each
// admin handler's source — running in CI, no browser needed.
test('admin routes: every /admin/* handler enforces a controller role gate (require_role / adminUpdate / handleUpdate)', function (): void {
    $register = require dirname(__DIR__, 2) . '/config/routes.php';
    $router = new Router();
    $register($router);

    $gatePatterns = ['require_role(', '->adminUpdate(', '->handleUpdate('];

    $adminRoutes = 0;
    $ungated = [];
    foreach ($router->routes() as $route) {
        if (!is_array($route['handler']) || strncmp((string) $route['path'], '/admin', 6) !== 0) {
            continue;
        }
        $adminRoutes++;
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
        if (!$gated) {
            $ungated[] = $route['method'] . ' ' . $route['path'] . ' → ' . $class . '::' . $method . '()';
        }
    }

    assert_true($adminRoutes >= 30, 'the /admin route table is loaded (found ' . $adminRoutes . ' routes)');
    assert_same([], $ungated, 'every /admin route must gate; ungated: ' . implode(' | ', $ungated));
});
