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

    /**
     * หน้าปลายทางเมื่อสแกน QR ทรัพย์สิน (GET /scan/{token}) — public endpoint ไม่ต้องล็อกอิน (layout สลับ guest/app ตามสถานะล็อกอิน).
     * อ่านข้อมูล asset จาก token ผ่าน AssetService::getScanData (ไม่เขียน DB); ไม่พบ token/asset → 404. render ปุ่มไปทางแจ้งซ่อม (ล็อกอิน) หรือแจ้งปัญหาแบบ guest.
     */
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

    /**
     * ฟอร์มแจ้งปัญหาแบบ guest จากการสแกน QR (GET) — public endpoint ไม่ต้องล็อกอิน; ผู้ที่ล็อกอินอยู่จะถูก redirect ไปทางสร้าง ticket แบบปกติ.
     * ผลข้างเคียง: ออก one-time form token ใหม่แล้วเก็บใน session ('guest_report_token') กัน double-submit/refresh สร้างคำขอซ้ำ.
     * ไม่พบ token/asset → 404.
     */
    public function showReport(string $token): void
    {
        $data = $this->assets->getScanData($token);
        if ($data === null) {
            Response::abort(404, 'ไม่พบ QR token หรือ Asset ที่เกี่ยวข้อง');
        }

        // เจ้าหน้าที่ที่ล็อกอินอยู่ควรไปทางสร้าง ticket แบบล็อกอิน (เติมข้อมูล asset ให้ล่วงหน้า),
        // ไม่ใช่ฟอร์มแจ้งปัญหาแบบ guest ที่ "ไม่ต้องล็อกอิน".
        if (auth()->check()) {
            Response::redirect((string) $data['ticket_create_path']);
        }

        $layout = 'guest';

        // One-time form token → กัน double-submit/refresh สร้าง guest request ซ้ำ.
        // ออกใหม่ทุกครั้งที่เปิดฟอร์ม แล้ว consume ตอน submit สำเร็จ.
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

    /**
     * รับฟอร์มแจ้งปัญหาแบบ guest (POST /scan/{token}/report) — public endpoint ไม่ต้องล็อกอิน แต่มี CSRF + idempotency ด้วย one-time form token (ต้องตรงกับ session แล้ว consume ทิ้ง).
     * ผลข้างเคียง: ผ่าน GuestTicketService::submitGuestRequest สร้างแถวคำขอ guest (จำกัดอัตราต่อ IP + honeypot + unique submission_token กันซ้ำ) และแจ้ง manager/admin.
     * สำเร็จ → render หน้ายืนยันพร้อมเลขอ้างอิง; error → เก็บค่าเดิมไว้แล้ว redirect กลับหน้าฟอร์ม (จะออก token ใหม่ให้).
     */
    public function submitReport(string $token): void
    {
        try {
            csrf_validate();

            // Idempotency: one-time form token ต้องตรงกับที่เก็บใน session และยังไม่ถูก consume.
            // double-submit/refresh → token ถูก consume ไปแล้ว → ไม่ตรง → ไม่สร้างคำขอซ้ำ.
            // ทาง error redirect กลับ showReport ที่จะออก token ใหม่ให้ ลองส่งใหม่ได้ตามปกติ.
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
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
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
