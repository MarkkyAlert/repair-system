<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Services\AssetImportService;
use App\Services\AssetService;
use DomainException;
use RuntimeException;

class AssetsController
{
    use HandlesFormSubmission;

    public function __construct(
        private AssetService $assets,
        private AssetImportService $importer,
    ) {
    }

    public function index(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $data = $this->assets->getAssetIndexData($viewer, request()?->query ?? []);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/dashboard');
        }

        Response::view('assets/index', [
            'title' => 'ทรัพย์สินและ QR',
            'pageHeading' => 'ทรัพย์สินและ QR',
            'currentUser' => $viewer,
            'assets' => $data['assets'],
            'roleLabel' => $data['roleLabel'],
            'canManage' => $data['canManage'],
            'pagination' => $data['pagination'],
            'filters' => $data['filters'],
            'filterOptions' => $data['filterOptions'],
            'activeFilters' => $data['activeFilters'],
        ]);
    }

    public function create(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $form = $this->assets->getCreateFormData($viewer, pull_old_input());
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/asset-registry');
        }

        Response::view('assets/create', [
            'title' => 'เพิ่มทรัพย์สิน',
            'pageHeading' => 'เพิ่มทรัพย์สินใหม่',
            'currentUser' => $viewer,
            'form' => $form,
            'errorMessage' => flash_message('error'),
        ]);
    }

    // ไม่ใช้ handleUpdate(): พอสำเร็จต้อง redirect ไปหน้ารายละเอียดของ asset ที่เพิ่งสร้าง (ต้องใช้ id ที่เพิ่งได้),
    // ส่วนตอน error redirect กลับไปหน้าฟอร์มสร้างพร้อมค่าเดิม — ปลายทางคนละที่ handleUpdate เลยจัดการไม่ได้.
    /**
     * รับฟอร์มเพิ่มทรัพย์สินใหม่ (POST, ต้องล็อกอิน + CSRF; service กันสิทธิ์ manager/admin ซ้ำ) ผ่าน AssetService::createAsset.
     * ผลข้างเคียง: สร้างแถว asset ใหม่พร้อม QR token.
     * สำเร็จ → redirect ไปหน้ารายละเอียด asset ที่เพิ่งสร้าง; error → เก็บค่าเดิมไว้แล้ว redirect กลับ /asset-registry/create.
     */
    public function store(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            clear_old_input();

            $assetId = $this->assets->createAsset($viewer, $_POST);
            flash('success', 'สร้าง Asset และ QR token เรียบร้อยแล้ว');
            Response::redirect('/asset-registry/' . $assetId);
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
        } catch (DomainException|RuntimeException $exception) {
            if ($exception instanceof RuntimeException) {
                log_caught_exception('controller.operational', $exception, ['path' => (string) (request()?->path ?? '')]);
            }
            with_old_input($this->assetOldInput($_POST));
            flash('error', $exception->getMessage());
            Response::redirect('/asset-registry/create');
        }
    }

    public function show(string $assetId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $detail = $this->assets->getAssetDetailData((int) $assetId, $viewer);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/dashboard');
        }

        if ($detail === null) {
            Response::abort(404, 'ไม่พบ Asset ที่ต้องการเปิดดู');
        }

        Response::view('assets/show', [
            'title' => 'รายละเอียดทรัพย์สิน',
            'pageHeading' => 'รายละเอียดทรัพย์สิน',
            'currentUser' => $viewer,
            'asset' => $detail['asset'],
            'canManage' => $detail['canManage'],
            'recentTickets' => $detail['recentTickets'] ?? [],
            'recentTicketsTotal' => $detail['recentTicketsTotal'] ?? 0,
        ]);
    }

    public function edit(string $assetId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $form = $this->assets->getEditFormData((int) $assetId, $viewer, pull_old_input());
            if ($form === null) {
                Response::abort(404, 'ไม่พบทรัพย์สินที่ต้องการแก้ไข');
            }
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/asset-registry/' . (int) $assetId);
        }

        Response::view('assets/edit', [
            'title' => 'แก้ไขทรัพย์สิน',
            'pageHeading' => 'แก้ไขทรัพย์สิน',
            'currentUser' => $viewer,
            'assetId' => (int) $assetId,
            'form' => $form,
            'errorMessage' => flash_message('error'),
        ]);
    }

    // ไม่ใช้ handleUpdate(): พอสำเร็จ redirect ไปหน้ารายละเอียด asset ส่วนตอน error กลับไปหน้าฟอร์มแก้ไข
    // พร้อมค่าเดิม — ปลายทางสำเร็จกับ error คนละที่ redirect เดียวของ handleUpdate เลยรองรับไม่ได้.
    /**
     * อัปเดตทรัพย์สิน (POST, ต้องล็อกอิน + CSRF; service กันสิทธิ์ manager/admin ซ้ำ) ผ่าน AssetService::updateAsset.
     * ผลข้างเคียง: เขียนแถว asset ด้วย optimistic lock (hidden original_version) กันเขียนทับข้อมูลที่ถูกแก้ไปแล้ว.
     * สำเร็จ → redirect ไปหน้ารายละเอียด asset; error → เก็บค่าเดิมไว้แล้ว redirect กลับหน้าแก้ไข.
     */
    public function update(string $assetId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            clear_old_input();

            $this->assets->updateAsset((int) $assetId, $viewer, $_POST);
            flash('success', 'อัปเดต Asset เรียบร้อยแล้ว');
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
        } catch (DomainException|RuntimeException $exception) {
            if ($exception instanceof RuntimeException) {
                log_caught_exception('controller.operational', $exception, ['path' => (string) (request()?->path ?? '')]);
            }
            with_old_input($this->assetOldInput($_POST));
            flash('error', $exception->getMessage());
            Response::redirect('/asset-registry/' . (int) $assetId . '/edit');
        }

        Response::redirect('/asset-registry/' . (int) $assetId);
    }

    /**
     * สร้าง QR token ใหม่ให้ทรัพย์สิน (POST, เฉพาะ manager/admin + CSRF) ผ่าน AssetService::regenerateQrToken.
     * ผลข้างเคียง: เขียน token ใหม่ทับของเดิม + ล้าง cache PNG — QR ที่พิมพ์/ติดไว้เดิมจะสแกนไม่ได้อีก.
     * redirect กลับหน้ารายละเอียด asset (flash).
     */
    public function regenerateQr(string $assetId): void
    {
        $this->handleUpdate(
            fn (array $viewer) => $this->assets->regenerateQrToken((int) $assetId, $viewer),
            'สร้าง QR token ใหม่เรียบร้อยแล้ว',
            '/asset-registry/' . (int) $assetId,
            ['manager', 'admin'],
            'คุณไม่มีสิทธิ์จัดการข้อมูล Asset และ QR'
        );
    }

    /**
     * ส่ง QR ของทรัพย์สินเป็น PNG แบบ inline (GET, ต้องล็อกอิน) ผ่าน AssetService::generateQrPng.
     * ผลข้างเคียง: ไม่เขียน DB — render/อ่าน PNG แล้ว stream ออก (ปิดด้วย exit → return never); ไม่พบ/ไม่มีสิทธิ์ → 404.
     */
    public function qrPng(string $assetId): never
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $png = $this->assets->generateQrPng((int) $assetId, $viewer);
            Response::download($png, 'asset-qr-' . (int) $assetId . '.png', 'image/png', 'inline');
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
        } catch (DomainException|RuntimeException $exception) {
            if ($exception instanceof RuntimeException) {
                log_caught_exception('controller.operational', $exception, ['path' => (string) (request()?->path ?? '')]);
            }
            Response::abort(404, $exception->getMessage());
        }
    }

    public function printSheet(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $data = $this->assets->getPrintableAssetsData($viewer, request()?->query ?? []);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/asset-registry');
        }

        Response::view('assets/print', [
            'title' => 'พิมพ์แผ่น QR',
            'pageHeading' => 'พิมพ์แผ่น QR',
            'currentUser' => $viewer,
            'assets' => $data['assets'],
            'brandName' => $data['brandName'],
            'brandLogoUrl' => $data['brandLogoUrl'],
            'capped' => $data['capped'] ?? false,
            'printLimit' => $data['printLimit'] ?? 0,
            'activeFilters' => $data['activeFilters'] ?? [],
        ]);
    }

    /**
     * ดาวน์โหลดทะเบียนทรัพย์สินเป็น CSV (POST + CSRF, ต้องล็อกอิน) ผ่าน AssetService::exportCsv (กรองตาม $_POST).
     * ผลข้างเคียง: ไม่เขียน DB — stream ไฟล์ดาวน์โหลด (Response::download exit); error → redirect กลับ /asset-registry.
     */
    public function exportCsv(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            $export = $this->assets->exportCsv($viewer, $_POST);
            Response::download(
                (string) ($export['content'] ?? ''),
                (string) ($export['file_name'] ?? 'asset-registry.csv'),
                (string) ($export['content_type'] ?? 'text/csv; charset=UTF-8')
            );
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
        } catch (DomainException|RuntimeException $exception) {
            if ($exception instanceof RuntimeException) {
                log_caught_exception('controller.operational', $exception, ['path' => (string) (request()?->path ?? '')]);
            }
            flash('error', $exception->getMessage());
            Response::redirect('/asset-registry');
        }
    }

    /**
     * ดาวน์โหลดทะเบียนทรัพย์สินเป็น Excel (.xlsx) (POST + CSRF, ต้องล็อกอิน) ผ่าน AssetService::exportExcel (กรองตาม $_POST).
     * ผลข้างเคียง: ไม่เขียน DB — stream ไฟล์ดาวน์โหลด (Response::download exit); error → redirect กลับ /asset-registry.
     */
    public function exportExcel(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            $export = $this->assets->exportExcel($viewer, $_POST);
            Response::download(
                (string) ($export['content'] ?? ''),
                (string) ($export['file_name'] ?? 'asset-registry.xlsx'),
                (string) ($export['content_type'] ?? 'application/octet-stream')
            );
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
        } catch (DomainException|RuntimeException $exception) {
            if ($exception instanceof RuntimeException) {
                log_caught_exception('controller.operational', $exception, ['path' => (string) (request()?->path ?? '')]);
            }
            flash('error', $exception->getMessage());
            Response::redirect('/asset-registry');
        }
    }

    public function importForm(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['manager', 'admin'], 'คุณไม่มีสิทธิ์จัดการข้อมูล Asset และ QR');

        Response::view('assets/import', [
            'title' => 'นำเข้าทรัพย์สินจาก CSV',
            'pageHeading' => 'นำเข้าทรัพย์สินจาก CSV',
            'currentUser' => $viewer,
            'preview' => null,
            'errorMessage' => flash_message('error'),
        ]);
    }

    /**
     * ตรวจไฟล์ CSV ก่อนนำเข้าทรัพย์สิน (POST + CSRF, เฉพาะ manager/admin) — parse + validate ไม่เขียน asset ลง DB.
     * ผลข้างเคียง: เก็บชุดแถวที่ผ่านไว้ใน session ('asset_import_batch') ผูกกับ one-time token กัน confirm ข้ามแท็บ; render หน้า preview.
     * error → redirect กลับ /asset-registry/import.
     */
    public function importPreview(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['manager', 'admin'], 'คุณไม่มีสิทธิ์จัดการข้อมูล Asset และ QR');

        try {
            csrf_validate();
            $rows = $this->importer->parseUploadedFile($_FILES['csv'] ?? []);
            $preview = $this->importer->validateRows($rows);

            // ผูก batch นี้ไว้กับ token กันไม่ให้ preview อีกครั้งในแท็บอื่นมาแย่ง confirm ของแท็บนี้.
            $token = bin2hex(random_bytes(16));
            Session::put('asset_import_batch', ['token' => $token, 'rows' => $preview['valid']]);

            Response::view('assets/import', [
                'title' => 'ตรวจสอบก่อนนำเข้าทรัพย์สิน',
                'pageHeading' => 'ตรวจสอบก่อนนำเข้า',
                'currentUser' => $viewer,
                'preview' => $preview,
                'importToken' => $token,
                'errorMessage' => null,
            ]);
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
        } catch (DomainException|RuntimeException $exception) {
            if ($exception instanceof RuntimeException) {
                log_caught_exception('controller.operational', $exception, ['path' => (string) (request()?->path ?? '')]);
            }
            flash('error', $exception->getMessage());
            Response::redirect('/asset-registry/import');
        }
    }

    /**
     * ยืนยันนำเข้าทรัพย์สินจากชุดที่ preview ไว้ (POST + CSRF, เฉพาะ manager/admin) ผ่าน AssetImportService::executeImport.
     * ผลข้างเคียง: ตรวจ import_token ให้ตรงกับ batch ใน session แล้ว bulk-insert asset ทีละแถว (ข้ามแถวซ้ำ/ผิดพลาด), จากนั้นล้าง batch ออกจาก session.
     * สำเร็จ → flash สรุปจำนวนนำเข้า/ข้าม แล้ว redirect ไป /asset-registry; error → redirect กลับ /asset-registry/import.
     */
    public function importExecute(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['manager', 'admin'], 'คุณไม่มีสิทธิ์จัดการข้อมูล Asset และ QR');

        try {
            csrf_validate();
            $batch = Session::get('asset_import_batch', []);
            try {
                $validRows = verified_import_rows($batch, (string) ($_POST['import_token'] ?? ''));
            } catch (DomainException $exception) {
                Session::forget('asset_import_batch');
                throw $exception;
            }

            $result = $this->importer->executeImport($validRows, $viewer);
            Session::forget('asset_import_batch');

            $skipped = count($result['skipped'] ?? []);
            $summary = 'นำเข้า ' . (int) $result['imported'] . ' รายการ';
            if ($skipped > 0) {
                $summary .= ' · ข้าม ' . $skipped . ' รายการ (อาจซ้ำหรือผิดพลาด)';
            }
            flash('success', $summary);
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
        } catch (DomainException|RuntimeException $exception) {
            if ($exception instanceof RuntimeException) {
                log_caught_exception('controller.operational', $exception, ['path' => (string) (request()?->path ?? '')]);
            }
            flash('error', $exception->getMessage());
            Response::redirect('/asset-registry/import');
        }

        Response::redirect('/asset-registry');
    }

    /**
     * ดาวน์โหลดไฟล์ CSV ตัวอย่างสำหรับนำเข้าทรัพย์สิน (GET, เฉพาะ manager/admin) — เนื้อหา static (หัวคอลัมน์ + 1 แถวตัวอย่าง).
     * ผลข้างเคียง: ไม่เขียน DB — stream ไฟล์ดาวน์โหลด (มี BOM ให้ Excel อ่านภาษาไทย).
     */
    public function importTemplate(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['manager', 'admin'], 'คุณไม่มีสิทธิ์จัดการข้อมูล Asset และ QR');
        $columns = AssetImportService::CSV_COLUMNS;
        $sample = [
            'ASSET-001', 'Notebook Dell Latitude 5420', 'SN-12345', 'IT_HW', 'HQ-FL2',
            'IT', 'admin', 'Dell', 'Latitude 5420', 'Dell Thailand',
            '2024-01-15', '2027-01-15', 'active', 'ใช้งานในแผนก IT',
        ];

        $csv = implode(',', $columns) . "\r\n" . implode(',', array_map(static fn ($v): string => '"' . str_replace('"', '""', (string) $v) . '"', $sample)) . "\r\n";

        Response::download("\xEF\xBB\xBF" . $csv, 'asset-import-template.csv', 'text/csv; charset=utf-8');
    }

    private function assetOldInput(array $input): array
    {
        return [
            'asset_code' => (string) ($input['asset_code'] ?? ''),
            'name' => (string) ($input['name'] ?? ''),
            'serial_number' => (string) ($input['serial_number'] ?? ''),
            'asset_category_id' => (string) ($input['asset_category_id'] ?? ''),
            'department_id' => (string) ($input['department_id'] ?? ''),
            'location_id' => (string) ($input['location_id'] ?? ''),
            'custodian_user_id' => (string) ($input['custodian_user_id'] ?? ''),
            'brand' => (string) ($input['brand'] ?? ''),
            'model' => (string) ($input['model'] ?? ''),
            'vendor' => (string) ($input['vendor'] ?? ''),
            'purchase_date' => (string) ($input['purchase_date'] ?? ''),
            'warranty_expires_at' => (string) ($input['warranty_expires_at'] ?? ''),
            'status' => (string) ($input['status'] ?? 'active'),
            'notes' => (string) ($input['notes'] ?? ''),
        ];
    }
}
