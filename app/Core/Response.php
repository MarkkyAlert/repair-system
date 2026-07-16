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

    /**
     * JSON envelope for command (mutation) endpoints: {"success":true,"message":...,...$data}.
     * Use this + jsonError() for anything a form/AJAX action submits. Polling/read endpoints
     * (ticket state, comment feed, {max_id}, notification feed) keep their own field-specific
     * shapes by design — their JS clients read named fields, not this envelope.
     */
    public static function jsonSuccess(array $data = [], string $message = '', int $status = 200): never
    {
        self::json(['success' => true, 'message' => $message] + $data, $status);
    }

    /** JSON envelope for a failed command: {"success":false,"message":...,...$data}. */
    public static function jsonError(string $message, int $status = 422, array $data = []): never
    {
        self::json(['success' => false, 'message' => $message] + $data, $status);
    }

    public static function download(string $content, string $fileName, string $contentType, string $disposition = 'attachment', int $status = 200): never
    {
        http_response_code($status);
        // Download-signal cookie: echo the client's token back so the export overlay JS can detect
        // the download has started and hide its spinner. Attachment responses don't navigate away or
        // fire blur/unload reliably, so this cookie is the robust "download started" signal.
        $downloadToken = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($_POST['_download_token'] ?? $_GET['_download_token'] ?? ''));
        if ($downloadToken !== '' && !headers_sent()) {
            setcookie('fileDownload', substr($downloadToken, 0, 64), ['expires' => 0, 'path' => '/', 'samesite' => 'Lax']);
        }
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
        // carry the request correlation id so the error page can show a reference that matches the server log (error-review F8)
        View::render($view, ['title' => (string) $status, 'message' => $message, 'reference' => request_id()], 'guest');
        exit;
    }
}
