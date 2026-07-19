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
     * รูปแบบ JSON มาตรฐานสำหรับ endpoint ที่เปลี่ยนข้อมูล (สร้าง/แก้/ลบ): {"success":true,"message":...,...$data}
     * ใช้คู่กับ jsonError() กับทุก action ที่ฟอร์ม/AJAX ส่งมา ส่วน endpoint แบบดึงสถานะเป็นระยะ (polling)
     * อย่างสถานะ ticket, ฟีดคอมเมนต์, {max_id}, ฟีดแจ้งเตือน จงใจใช้รูปแบบเฉพาะของตัวเอง
     * เพราะ JS ฝั่งนั้นอ่านค่าตามชื่อฟิลด์ ไม่ได้อ่าน envelope นี้
     */
    public static function jsonSuccess(array $data = [], string $message = '', int $status = 200): never
    {
        self::json(['success' => true, 'message' => $message] + $data, $status);
    }

    /** รูปแบบ JSON มาตรฐานสำหรับคำสั่งที่ล้มเหลว: {"success":false,"message":...,...$data}. */
    public static function jsonError(string $message, int $status = 422, array $data = []): never
    {
        self::json(['success' => false, 'message' => $message] + $data, $status);
    }

    public static function download(string $content, string $fileName, string $contentType, string $disposition = 'attachment', int $status = 200): never
    {
        http_response_code($status);
        // คุกกี้บอกสัญญาณ "เริ่มดาวน์โหลด": ส่ง token ของ client กลับไปให้ JS หน้า export รู้ว่าดาวน์โหลดเริ่มแล้ว
        // จะได้ซ่อนวงล้อหมุนรอ การตอบกลับแบบไฟล์แนบไม่พาออกจากหน้า แล้วก็ไม่ยิง event blur/unload
        // ที่เชื่อถือได้ คุกกี้นี้เลยเป็นสัญญาณว่าดาวน์โหลดเริ่มแล้วที่แน่นอนกว่า
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
        // แนบรหัสอ้างอิงของ request ไว้ ให้หน้า error โชว์รหัสที่ตรงกับ log ฝั่งเซิร์ฟเวอร์
        // เรนเดอร์ผ่าน layout `error` ที่ไม่แตะฐานข้อมูล ไม่ใช่ `guest` ที่เรียก setting() เพราะแบบนี้ 500 ที่เกิด
        // เพราะฐานข้อมูลล่มยังแสดงหน้าเต็มมีสไตล์ได้ ถ้าตัวนั้นยังพังอีก ค่อยถอยไปใช้ HTML สำเร็จรูป
        // ในตัว ผู้ใช้จะได้ไม่เห็นหน้าว่างหรือหน้าดิบ ๆ
        try {
            View::render($view, ['title' => (string) $status, 'message' => $message, 'reference' => $reference], 'error');
        } catch (\Throwable $renderFailure) {
            log_uncaught_exception($renderFailure);
            echo self::minimalErrorHtml($status, $message, $reference);
        }
        exit;
    }

    /**
     * หน้า error ทางเลือกสุดท้าย: HTML/CSS แบบ inline ล้วน ไม่มี include ไม่แตะฐานข้อมูล ไม่เรียก helper ที่อาจพังได้
     * ใช้เฉพาะกรณีที่การเรนเดอร์หน้า error ปกติ (ซึ่งไม่แตะฐานข้อมูลอยู่แล้ว) ดันโยน exception ซ้ำอีก
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
