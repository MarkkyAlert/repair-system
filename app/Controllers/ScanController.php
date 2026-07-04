<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Services\AssetService;
use App\Services\GuestTicketService;
use App\Services\TicketService;
use DomainException;
use RuntimeException;

class ScanController
{
    public function __construct(
        private AssetService $assets,
        private GuestTicketService $guests,
        private TicketService $tickets,
    ) {
    }

    public function show(string $token): void
    {
        $data = $this->assets->getScanData($token);
        if ($data === null) {
            Response::abort(404, 'ไม่พบ QR token หรือ Asset ที่เกี่ยวข้อง');
        }

        $layout = auth()->check() ? 'app' : 'guest';

        Response::view('scan/show', [
            'title' => 'สแกน QR ทรัพย์สิน',
            'pageHeading' => 'สแกน QR ทรัพย์สิน',
            'currentUser' => auth()->user() ?? [],
            'asset' => $data['asset'],
            'ticketCreatePath' => $data['ticket_create_path'],
            'loginPath' => $data['login_path'],
            'guestReportPath' => '/scan/' . rawurlencode($token) . '/report',
            'isAuthenticated' => auth()->check(),
        ], $layout);
    }

    public function showReport(string $token): void
    {
        $data = $this->assets->getScanData($token);
        if ($data === null) {
            Response::abort(404, 'ไม่พบ QR token หรือ Asset ที่เกี่ยวข้อง');
        }

        // Staff who are logged in belong in the authenticated ticket flow (asset prefilled),
        // not the "no login needed" guest report form.
        if (auth()->check()) {
            Response::redirect((string) $data['ticket_create_path']);
        }

        $layout = 'guest';

        // One-time form token → กัน double-submit/refresh สร้าง guest request ซ้ำ (idempotency).
        // ออกใหม่ทุกครั้งที่เปิดฟอร์ม, consume ตอน submit สำเร็จ.
        $formToken = bin2hex(random_bytes(16));
        Session::put('guest_report_token', $formToken);

        Response::view('scan/report', [
            'title' => 'แจ้งปัญหาจาก QR',
            'pageHeading' => 'แจ้งปัญหา',
            'currentUser' => auth()->user() ?? [],
            'asset' => $data['asset'],
            'token' => $token,
            'formToken' => $formToken,
            'errorMessage' => flash_message('error'),
            'oldInput' => pull_old_input(),
        ], $layout);
    }

    public function submitReport(string $token): void
    {
        try {
            csrf_validate();

            // Idempotency: one-time form token ต้องตรงกับที่เก็บใน session และยังไม่ถูก consume.
            // double-submit/refresh → token ถูก consume ไปแล้ว → mismatch → ไม่สร้างคำขอซ้ำ.
            // error path redirect กลับ showReport ซึ่งออก token ใหม่ให้ retry ปกติได้.
            $sessionToken = (string) Session::get('guest_report_token', '');
            if ($sessionToken === '' || !hash_equals($sessionToken, (string) ($_POST['form_token'] ?? ''))) {
                throw new DomainException('คำขอนี้ถูกส่งไปแล้ว หรือฟอร์มหมดอายุ กรุณาสแกน QR ใหม่อีกครั้งหากต้องการแจ้ง');
            }
            Session::forget('guest_report_token');

            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $result = $this->guests->submitGuestRequest($token, $_POST, $ip);

            $layout = auth()->check() ? 'app' : 'guest';
            Response::view('scan/report-success', [
                'title' => 'รับเรื่องแล้ว',
                'pageHeading' => 'รับเรื่องแล้ว',
                'currentUser' => auth()->user() ?? [],
                'requestNo' => (string) ($result['request_no'] ?? ''),
            ], $layout);
        } catch (DomainException|RuntimeException $exception) {
            with_old_input([
                'guest_name' => (string) ($_POST['guest_name'] ?? ''),
                'guest_email' => (string) ($_POST['guest_email'] ?? ''),
                'guest_phone' => (string) ($_POST['guest_phone'] ?? ''),
                'title' => (string) ($_POST['title'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
            ]);
            flash('error', $exception->getMessage());
            Response::redirect('/scan/' . rawurlencode($token) . '/report');
        }
    }
}
