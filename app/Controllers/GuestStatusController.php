<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\GuestTicketService;
use DomainException;
use RuntimeException;

/** หน้า public ให้ guest ติดตามสถานะคำขอด้วย request_no + เบอร์/อีเมล (ไม่ต้องล็อกอิน, layout guest). */
class GuestStatusController
{
    public function __construct(private GuestTicketService $guests)
    {
    }

    public function form(): void
    {
        $this->render(trim((string) (request()?->query['ref'] ?? '')), null, null);
    }

    public function lookup(): void
    {
        $requestNo = trim((string) ($_POST['request_no'] ?? ''));
        $contact = trim((string) ($_POST['contact'] ?? ''));
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $result = null;
        $error = null;
        try {
            // csrf_validate() throws RuntimeException — keep it INSIDE the try (and catch RuntimeException)
            // so a missing/expired token on this public guest form renders a friendly error, not a 500.
            csrf_validate();
            $result = $this->guests->lookupGuestStatus($requestNo, $contact, $ip);
            if ($result === null) {
                // error กลาง ๆ — ไม่บอกว่าเลขมีจริงไหม (กัน enumeration)
                $error = 'ไม่พบข้อมูล — กรุณาตรวจสอบเลขอ้างอิงและเบอร์โทร/อีเมลที่แจ้งไว้อีกครั้ง';
            }
        } catch (DomainException|RuntimeException $exception) {
            $error = $exception->getMessage();
        }

        $this->render($requestNo, $result, $error);
    }

    protected function render(string $ref, ?array $result, ?string $error): void
    {
        Response::view('guest/track', [
            'title' => 'ติดตามสถานะคำขอ',
            'pageHeading' => 'ติดตามสถานะคำขอ',
            'currentUser' => auth()->user() ?? [],
            'ref' => $ref,
            'result' => $result,
            'error' => $error,
        ], 'guest');
    }
}
