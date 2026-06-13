<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class View
{
    public static function render(string $view, array $data = [], string $layout = 'app'): void
    {
        $viewFile = self::resolve($view);
        $title = $data['title'] ?? config('app.name', 'Repair System');

        extract($data, EXTR_SKIP);

        ob_start();
        include $viewFile;
        $content = (string) ob_get_clean();

        if ($layout === '') {
            echo $content;
            return;
        }

        $layoutFile = self::resolve('layouts/' . $layout);
        include $layoutFile;
    }

    public static function capture(string $view, array $data = []): string
    {
        $viewFile = self::resolve($view);

        extract($data, EXTR_SKIP);

        ob_start();
        include $viewFile;

        return (string) ob_get_clean();
    }

    public static function exists(string $view): bool
    {
        $viewsPath = rtrim((string) config('paths.views'), '/');
        return is_file($viewsPath . '/' . str_replace('.', '/', $view) . '.php');
    }

    private static function resolve(string $view): string
    {
        $viewsPath = rtrim((string) config('paths.views'), '/');
        $file = $viewsPath . '/' . str_replace('.', '/', $view) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("View [$view] not found.");
        }

        return $file;
    }
}
