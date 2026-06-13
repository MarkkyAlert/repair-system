<?php
declare(strict_types=1);

namespace App\Core;

class Response
{
    public static function view(string $view, array $data = [], string $layout = 'app', int $status = 200): never
    {
        http_response_code($status);
        View::render($view, $data, $layout);
        exit;
    }

    public static function redirect(string $path, int $status = 302): never
    {
        $location = preg_match('#^https?://#i', $path) ? $path : url($path);

        header('Location: ' . $location, true, $status);
        exit;
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function download(string $content, string $fileName, string $contentType, string $disposition = 'attachment', int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($content));
        header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo $content;
        exit;
    }

    public static function abort(int $status = 404, string $message = ''): never
    {
        $view = View::exists('errors/' . $status) ? 'errors/' . $status : 'errors/500';
        http_response_code($status);
        View::render($view, ['title' => (string) $status, 'message' => $message], 'guest');
        exit;
    }
}
