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

    // Not handleUpdate(): success redirects to the new asset's detail page (needs the created id),
    // while errors redirect back to the create form with old input — a split target handleUpdate can't express.
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
        } catch (DomainException|RuntimeException $exception) {
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

    // Not handleUpdate(): success redirects to the asset detail page, errors back to the edit form
    // with old input — split success/error targets that handleUpdate's single redirect can't express.
    public function update(string $assetId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            clear_old_input();

            $this->assets->updateAsset((int) $assetId, $viewer, $_POST);
            flash('success', 'อัปเดต Asset เรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            with_old_input($this->assetOldInput($_POST));
            flash('error', $exception->getMessage());
            Response::redirect('/asset-registry/' . (int) $assetId . '/edit');
        }

        Response::redirect('/asset-registry/' . (int) $assetId);
    }

    public function regenerateQr(string $assetId): void
    {
        $this->handleUpdate(
            fn (array $viewer) => $this->assets->regenerateQrToken((int) $assetId, $viewer),
            'สร้าง QR token ใหม่เรียบร้อยแล้ว',
            '/asset-registry/' . (int) $assetId
        );
    }

    public function qrPng(string $assetId): never
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $png = $this->assets->generateQrPng((int) $assetId, $viewer);
            Response::download($png, 'asset-qr-' . (int) $assetId . '.png', 'image/png', 'inline');
        } catch (DomainException|RuntimeException $exception) {
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

    public function importPreview(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['manager', 'admin'], 'คุณไม่มีสิทธิ์จัดการข้อมูล Asset และ QR');

        try {
            csrf_validate();
            $rows = $this->importer->parseUploadedFile($_FILES['csv'] ?? []);
            $preview = $this->importer->validateRows($rows);

            Session::put('asset_import_valid_rows', $preview['valid']);

            Response::view('assets/import', [
                'title' => 'ตรวจสอบก่อนนำเข้าทรัพย์สิน',
                'pageHeading' => 'ตรวจสอบก่อนนำเข้า',
                'currentUser' => $viewer,
                'preview' => $preview,
                'errorMessage' => null,
            ]);
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/asset-registry/import');
        }
    }

    public function importExecute(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['manager', 'admin'], 'คุณไม่มีสิทธิ์จัดการข้อมูล Asset และ QR');

        try {
            csrf_validate();
            $validRows = Session::get('asset_import_valid_rows', []);
            if (!is_array($validRows) || $validRows === []) {
                throw new DomainException('ไม่พบข้อมูลที่ผ่านการตรวจสอบ กรุณาเริ่มกระบวนการนำเข้าใหม่');
            }

            $result = $this->importer->executeImport($validRows, $viewer);
            Session::forget('asset_import_valid_rows');

            $skipped = count($result['skipped'] ?? []);
            $summary = 'นำเข้า ' . (int) $result['imported'] . ' รายการ';
            if ($skipped > 0) {
                $summary .= ' · ข้าม ' . $skipped . ' รายการ (อาจซ้ำหรือผิดพลาด)';
            }
            flash('success', $summary);
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/asset-registry/import');
        }

        Response::redirect('/asset-registry');
    }

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
