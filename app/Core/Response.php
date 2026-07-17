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
        $reference = request_id();
        http_response_code($status);
        // carry the request correlation id so the error page can show a reference that matches the server log (error-review F8)
        // Render through the DB-free `error` layout (not `guest`, which calls setting()) so a 500 raised BY a
        // database outage still produces a full styled page. If even that fails, fall back to a self-contained
        // static shell so the user never sees a blank/raw page. (ux-review-3 F1)
        try {
            View::render($view, ['title' => (string) $status, 'message' => $message, 'reference' => $reference], 'error');
        } catch (\Throwable $renderFailure) {
            log_uncaught_exception($renderFailure);
            echo self::minimalErrorHtml($status, $message, $reference);
        }
        exit;
    }

    /**
     * Last-resort error shell: pure inline HTML/CSS, no includes, no DB, no helpers that can fail. Only used if
     * the normal (already DB-free) error render itself throws. (ux-review-3 F1)
     */
    private static function minimalErrorHtml(int $status, string $message, string $reference): string
    {
        $authed = AuthManager::checkSession();
        $ctaHref = $authed ? '/dashboard' : '/login';
        $ctaLabel = $authed ? 'กลับแดชบอร์ด' : 'กลับหน้าเข้าสู่ระบบ';
        $safeMessage = htmlspecialchars($message !== '' ? $message : 'ระบบเกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง', ENT_QUOTES, 'UTF-8');
        $safeRef = htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');
        $refLine = $reference !== '' ? '<p style="opacity:.6;font-size:.85rem">รหัสอ้างอิง: <code>' . $safeRef . '</code></p>' : '';

        return '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . $status . '</title>'
            . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'font-family:system-ui,"IBM Plex Sans Thai",sans-serif;background:#0f0a2e;color:#ecebff;padding:1.5rem}'
            . '.box{max-width:26rem;text-align:center}h1{font-size:3rem;margin:0}'
            . 'a{display:inline-block;margin-top:1.25rem;padding:.65rem 1.25rem;border-radius:.75rem;'
            . 'background:#6366f1;color:#fff;text-decoration:none;font-weight:600}</style></head>'
            . '<body><div class="box"><h1>' . $status . '</h1><p>' . $safeMessage . '</p>'
            . $refLine . '<a href="' . $ctaHref . '">' . $ctaLabel . '</a></div></body></html>';
    }
}
