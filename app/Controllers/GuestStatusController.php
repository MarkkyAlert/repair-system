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

    /**
     * ค้นสถานะคำขอ guest ด้วย request_no + เบอร์/อีเมล (POST, public endpoint + CSRF) ผ่าน GuestTicketService::lookupGuestStatus.
     * เป็นการอ่านอย่างเดียว (ไม่เขียน DB) แต่จำกัดอัตราต่อ IP; ไม่พบ → error กลาง ๆ ที่ไม่บอกว่าเลขมีจริงไหม (กัน enumeration).
     * render หน้า track เดิมพร้อมผลลัพธ์หรือข้อความ error.
     */
    public function lookup(): void
    {
        $requestNo = trim((string) ($_POST['request_no'] ?? ''));
        $contact = trim((string) ($_POST['contact'] ?? ''));
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $result = null;
        $error = null;
        try {
            // csrf_validate() จะ throw DomainException เมื่อ token ผิด/หมดอายุ — ต้องวางไว้ข้างใน try เพื่อให้
            // token ที่หาย/เก่าบนฟอร์ม guest แบบ public นี้แสดง error แบบอ่านง่าย ไม่ใช่ 500.
            csrf_validate();
            $result = $this->guests->lookupGuestStatus($requestNo, $contact, $ip);
            if ($result === null) {
                // error กลาง ๆ — ไม่บอกว่าเลขมีจริงไหม (กัน enumeration)
                $error = 'ไม่พบข้อมูล — กรุณาตรวจสอบเลขอ้างอิงและเบอร์โทร/อีเมลที่แจ้งไว้อีกครั้ง';
            }
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
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
