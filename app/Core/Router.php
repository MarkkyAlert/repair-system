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
     * รายการ route ที่ลงทะเบียนไว้ เผื่อให้เทสต์หรือเครื่องมือส่องดู เช่น เช็คว่าทุก handler method
     * ของ controller มีอยู่จริง เป็นสำเนาอ่านอย่างเดียว แก้ค่าที่คืนไปก็ไม่กระทบการ routing
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
        // placeholder ที่เป็น id ({ticketId}, {userId}, {commentId}, …) จะ match เฉพาะตัวเลขเท่านั้น
        // path ผิดรูปอย่าง "/tickets/12junk/approve" เลยได้ 404 ไม่ใช่ส่ง "12junk" ให้ controller ไป (int)-cast เป็น ticket 12
        // ส่วน placeholder ที่ไม่ใช่ id ({token}, {templateKey}) ยังใช้ [^/]+ ตามเดิม ขณะที่ส่วน path ปกติ
        // ต้อง quote เป็น literal ไม่ให้ "." ใน template.csv/qr.png กลายเป็น regex wildcard
        $parts = preg_split(
            '#(\{[a-zA-Z_][a-zA-Z0-9_-]*\})#',
            $routePath,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        ) ?: [$routePath];
        $pattern = '';
        foreach ($parts as $part) {
            if (preg_match('#^\{([a-zA-Z_][a-zA-Z0-9_-]*)\}$#', $part, $placeholder) === 1) {
                $name = $placeholder[1];
                $charClass = str_ends_with($name, 'Id') ? '\d+' : '[^/]+';
                $pattern .= '(?P<' . $name . '>' . $charClass . ')';
                continue;
            }
            $pattern .= preg_quote($part, '#');
        }
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
