<?php
declare(strict_types=1);

namespace App\Core;

class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $input,
        public readonly array $server,
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $basePath = self::detectBasePath();
        $path = self::stripBasePath($requestPath, $basePath);

        return new self($method, $path, $_GET, $_POST, $_SERVER);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $this->query[$key] ?? $default;
    }

    private static function detectBasePath(): string
    {
        $configuredBasePath = '';
        $configFile = dirname(__DIR__, 2) . '/config/config.php';

        if (function_exists('config')) {
            $configuredBasePath = (string) config('app.base_path', '');
        } elseif (is_file($configFile)) {
            $config = require $configFile;
            $configuredBasePath = (string) ($config['app']['base_path'] ?? '');
        }

        return $configuredBasePath !== '' ? rtrim($configuredBasePath, '/') : '';
    }

    private static function stripBasePath(string $path, string $basePath): string
    {
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
        }

        $path = '/' . ltrim($path, '/');

        if ($path === '//') {
            return '/';
        }

        $trimmedPath = rtrim($path, '/');

        return $trimmedPath !== '' ? $trimmedPath : '/';
    }
}
