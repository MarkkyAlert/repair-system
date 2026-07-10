<?php
declare(strict_types=1);

use App\Core\Router;

// Every route's [Controller::class, 'method'] handler must point at a method that actually exists.
// A typo in config/routes.php — or a controller-method rename that misses a route (exactly the risk
// of the export-method rename in consistency #4) — would otherwise only surface as a runtime 404.
// This loads the real routes and asserts each array-handler is resolvable at the class level, so a
// broken mapping fails the suite instead of a user's request.
test('routes: every [Controller, method] handler points at a method that exists', function (): void {
    $register = require dirname(__DIR__, 2) . '/config/routes.php';
    $router = new Router();
    $register($router);

    $routes = $router->routes();
    assert_true(count($routes) > 100, 'the full route table is registered');

    $checked = 0;
    foreach ($routes as $route) {
        $handler = $route['handler'];
        if (!is_array($handler)) {
            continue; // closure handlers are inherently callable
        }
        [$class, $method] = $handler;
        assert_true(
            method_exists($class, $method),
            $route['method'] . ' ' . $route['path'] . ' → ' . $class . '::' . $method . '() is missing'
        );
        $checked++;
    }

    assert_true($checked > 100, 'most routes use [Controller, method] handlers and were checked');
});
