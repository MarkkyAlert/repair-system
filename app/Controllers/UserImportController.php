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
            'title' => 'Import Users (CSV)',
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

        try {
            csrf_validate();
            if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
                throw new DomainException('เฉพาะผู้ดูแลระบบเท่านั้น');
            }

            $rows = $this->userImporter->parseUploadedFile($_FILES['csv'] ?? []);
            $preview = $this->userImporter->validateRows($rows);

            Session::put('user_import_valid_rows', $preview['valid']);

            Response::view('admin/import-users', [
                'title' => 'Preview Import Users',
                'pageHeading' => 'ตรวจสอบก่อนนำเข้า',
                'currentUser' => $viewer,
                'preview' => $preview,
                'errorMessage' => null,
            ]);
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/admin/users/import');
        }
    }

    public function execute(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
                throw new DomainException('เฉพาะผู้ดูแลระบบเท่านั้น');
            }

            $validRows = Session::get('user_import_valid_rows', []);
            if (!is_array($validRows) || $validRows === []) {
                throw new DomainException('ไม่พบข้อมูลที่ผ่านการตรวจสอบ กรุณาเริ่มกระบวนการนำเข้าใหม่');
            }

            $result = $this->userImporter->executeImport($validRows);
            Session::forget('user_import_valid_rows');

            $skipped = count($result['skipped'] ?? []);
            $summary = 'นำเข้า ' . (int) $result['imported'] . ' ผู้ใช้';
            if ((int) $result['reset_emails_queued'] > 0) {
                $summary .= ' · ส่งอีเมลตั้งรหัสผ่าน ' . (int) $result['reset_emails_queued'] . ' ฉบับ';
            }
            if ($skipped > 0) {
                $summary .= ' · ข้าม ' . $skipped . ' รายการ';
            }
            flash('success', $summary);
        } catch (DomainException|RuntimeException $exception) {
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

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="user-import-template.csv"');
        echo "\xEF\xBB\xBF" . $csv;
        exit;
    }
}
