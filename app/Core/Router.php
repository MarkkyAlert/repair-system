<?php
declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, callable|array $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $this->normalizePath($path),
            'handler' => $handler,
        ];
    }

    /**
     * The registered route table, for introspection/tests (e.g. asserting every controller handler
     * method exists). Read-only snapshot — mutating the result does not affect routing.
     *
     * @return array<int, array{method: string, path: string, handler: callable|array}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function dispatch(Request $request, Container $container): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }

            $matches = $this->match($route['path'], $request->path);
            if ($matches === null) {
                continue;
            }

            $handler = $route['handler'];

            if (is_array($handler)) {
                [$class, $method] = $handler;
                $controller = $container->get($class);
                $controller->{$method}(...array_values($matches));
                return;
            }

            $handler(...array_values($matches));
            return;
        }

        Response::abort(404, 'ไม่พบหน้าที่คุณกำลังค้นหา');
    }

    private function match(string $routePath, string $requestPath): ?array
    {
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_-]*)\}#', '(?P<$1>[^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';
        $requestPath = $this->normalizePath($requestPath);

        if (!preg_match($pattern, $requestPath, $matches)) {
            return null;
        }

        return array_filter($matches, static fn ($key): bool => !is_int($key), ARRAY_FILTER_USE_KEY);
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        $normalized = '/' . trim($path, '/');

        if ($normalized === '//') {
            return '/';
        }

        $trimmed = rtrim($normalized, '/');

        return $trimmed !== '' ? $trimmed : '/';
    }
}
