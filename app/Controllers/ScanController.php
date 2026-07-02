<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
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
            'title' => 'Scan Asset QR',
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

        Response::view('scan/report', [
            'title' => 'แจ้งปัญหาจาก QR',
            'pageHeading' => 'แจ้งปัญหา',
            'currentUser' => auth()->user() ?? [],
            'asset' => $data['asset'],
            'token' => $token,
            'errorMessage' => flash_message('error'),
            'oldInput' => pull_old_input(),
        ], $layout);
    }

    public function submitReport(string $token): void
    {
        try {
            csrf_validate();
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
