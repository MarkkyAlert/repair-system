<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\AssetService;
use DomainException;
use RuntimeException;

class AssetsController
{
    public function __construct(private AssetService $assets)
    {
    }

    public function index(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $data = $this->assets->getAssetIndexData($viewer);

        Response::view('assets/index', [
            'title' => 'Assets & QR',
            'pageHeading' => 'ทรัพย์สินและ QR',
            'currentUser' => $viewer,
            'assets' => $data['assets'],
            'roleLabel' => $data['roleLabel'],
            'canManage' => $data['canManage'],
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
            'title' => 'Create Asset',
            'pageHeading' => 'เพิ่ม Asset ใหม่',
            'currentUser' => $viewer,
            'form' => $form,
            'errorMessage' => flash_message('error'),
        ]);
    }

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
        $detail = $this->assets->getAssetDetailData((int) $assetId, $viewer);

        if ($detail === null) {
            Response::abort(404, 'ไม่พบ Asset ที่ต้องการเปิดดู');
        }

        Response::view('assets/show', [
            'title' => 'Asset Detail',
            'pageHeading' => 'รายละเอียดทรัพย์สิน',
            'currentUser' => $viewer,
            'asset' => $detail['asset'],
            'canManage' => $detail['canManage'],
        ]);
    }

    public function edit(string $assetId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $form = $this->assets->getEditFormData((int) $assetId, $viewer, pull_old_input());
            if ($form === null) {
                Response::abort(404, 'ไม่พบ Asset ที่ต้องการแก้ไข');
            }
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/asset-registry/' . (int) $assetId);
        }

        Response::view('assets/edit', [
            'title' => 'Edit Asset',
            'pageHeading' => 'แก้ไข Asset',
            'currentUser' => $viewer,
            'assetId' => (int) $assetId,
            'form' => $form,
            'errorMessage' => flash_message('error'),
        ]);
    }

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
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            $this->assets->regenerateQrToken((int) $assetId, $viewer);
            flash('success', 'สร้าง QR token ใหม่เรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect('/asset-registry/' . (int) $assetId);
    }

    public function qrPng(string $assetId): never
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $png = $this->assets->generateQrPng((int) $assetId, $viewer);
            http_response_code(200);
            header('Content-Type: image/png');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo $png;
            exit;
        } catch (DomainException|RuntimeException $exception) {
            Response::abort(404, $exception->getMessage());
        }
    }

    public function printSheet(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $data = $this->assets->getPrintableAssetsData($viewer);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/asset-registry');
        }

        Response::view('assets/print', [
            'title' => 'QR Print Sheet',
            'pageHeading' => 'พิมพ์ QR Sheet',
            'currentUser' => $viewer,
            'assets' => $data['assets'],
        ]);
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
