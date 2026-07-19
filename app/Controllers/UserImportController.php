<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Services\UserImportService;
use DomainException;
use RuntimeException;

class UserImportController
{
    public function __construct(private UserImportService $userImporter)
    {
    }

    public function showForm(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['admin'], 'หน้านี้สงวนสำหรับผู้ดูแลระบบเท่านั้น');

        Response::view('admin/import-users', [
            'title' => 'นำเข้าผู้ใช้จาก CSV',
            'pageHeading' => 'นำเข้าผู้ใช้จาก CSV',
            'currentUser' => $viewer,
            'preview' => null,
            'errorMessage' => flash_message('error'),
        ]);
    }

    public function preview(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['admin'], 'หน้านี้สงวนสำหรับผู้ดูแลระบบเท่านั้น');

        try {
            csrf_validate();

            $rows = $this->userImporter->parseUploadedFile($_FILES['csv'] ?? []);
            $preview = $this->userImporter->validateRows($rows);

            // ผูก batch ที่ preview นี้ไว้กับ token ใช้ครั้งเดียวที่ฝังอยู่ในฟอร์มยืนยัน เพื่อไม่ให้การเปิด preview ครั้งที่สอง
            // ในอีกแท็บ ทำให้การ "ยืนยัน" ของแท็บแรกไปนำเข้าข้อมูลแถวของแท็บที่สองแทน.
            $token = bin2hex(random_bytes(16));
            Session::put('user_import_batch', ['token' => $token, 'rows' => $preview['valid']]);

            Response::view('admin/import-users', [
                'title' => 'ตรวจสอบก่อนนำเข้าผู้ใช้',
                'pageHeading' => 'ตรวจสอบก่อนนำเข้า',
                'currentUser' => $viewer,
                'preview' => $preview,
                'importToken' => $token,
                'errorMessage' => null,
            ]);
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra (โครงสร้างพื้นฐาน) → ตัวจัดการ error ส่วนกลางจะ log แล้วส่ง 500 แบบทั่วไป ไม่หลุด SQL ออกไป
        } catch (DomainException|RuntimeException $exception) {
            if ($exception instanceof RuntimeException) {
                log_caught_exception('controller.operational', $exception, ['path' => (string) (request()?->path ?? '')]);
            }
            flash('error', $exception->getMessage());
            Response::redirect('/admin/users/import');
        }
    }

    public function execute(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['admin'], 'หน้านี้สงวนสำหรับผู้ดูแลระบบเท่านั้น');

        try {
            csrf_validate();

            $batch = Session::get('user_import_batch', []);
            try {
                // token ต้องตรงกับ batch ที่ preview ไว้ — ถ้าไม่ตรงแปลว่ามี preview ใหม่กว่า (จากอีกแท็บ)
                // มาแทนที่ session แล้ว; ให้ปฏิเสธแทนที่จะนำเข้าข้อมูลแถวที่ผิด.
                $validRows = verified_import_rows($batch, (string) ($_POST['import_token'] ?? ''));
            } catch (DomainException $exception) {
                Session::forget('user_import_batch');
                throw $exception;
            }

            $result = $this->userImporter->executeImport($validRows);
            Session::forget('user_import_batch');

            $skipped = count($result['skipped'] ?? []);
            $summary = 'นำเข้า ' . (int) $result['imported'] . ' ผู้ใช้';
            if ((int) $result['reset_emails_queued'] > 0) {
                $summary .= ' · ส่งอีเมลตั้งรหัสผ่าน ' . (int) $result['reset_emails_queued'] . ' ฉบับ';
            }
            if ($skipped > 0) {
                $summary .= ' · ข้าม ' . $skipped . ' รายการ';
            }
            flash('success', $summary);

            $resetFailures = $result['reset_failures'] ?? [];
            if ($resetFailures !== []) {
                $names = implode(', ', array_map(static fn (array $f): string => (string) ($f['username'] ?? ''), $resetFailures));
                flash('error', 'ส่งอีเมลตั้งรหัสผ่านไม่สำเร็จ ' . count($resetFailures) . ' ผู้ใช้ (' . $names . ') — ผู้ใช้ถูกสร้างแล้วแต่ยังไม่มีรหัสผ่าน กรุณารีเซ็ตรหัสผ่านให้เอง');
            }
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra (โครงสร้างพื้นฐาน) → ตัวจัดการ error ส่วนกลางจะ log แล้วส่ง 500 แบบทั่วไป ไม่หลุด SQL ออกไป
        } catch (DomainException|RuntimeException $exception) {
            if ($exception instanceof RuntimeException) {
                log_caught_exception('controller.operational', $exception, ['path' => (string) (request()?->path ?? '')]);
            }
            flash('error', $exception->getMessage());
            Response::redirect('/admin/users/import');
        }

        Response::redirect('/admin');
    }

    public function template(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['admin'], 'หน้านี้สงวนสำหรับผู้ดูแลระบบเท่านั้น');

        $columns = UserImportService::CSV_COLUMNS;
        $sample = ['somchai', 'somchai@example.com', 'สมชาย ใจดี', 'requester', 'IT', '0812345678', ''];

        $csv = implode(',', $columns) . "\r\n" . implode(',', array_map(static fn ($v): string => '"' . str_replace('"', '""', (string) $v) . '"', $sample)) . "\r\n";

        Response::download("\xEF\xBB\xBF" . $csv, 'user-import-template.csv', 'text/csv; charset=utf-8');
    }
}
