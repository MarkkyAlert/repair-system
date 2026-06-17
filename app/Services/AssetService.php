<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AssetRepository;
use DomainException;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\Writer\PngWriter;

class AssetService
{
    public function __construct(private AssetRepository $assets)
    {
    }

    public function getAssetIndexData(array $viewer, array $filters = []): array
    {
        $result = $this->assets->getAssetListPage(max(1, (int) ($filters['page'] ?? 1)), 18);

        return [
            'roleLabel' => $this->labelize((string) ($viewer['role'] ?? 'guest')),
            'canManage' => $this->canManageAssets($viewer),
            'assets' => array_map(fn (array $asset): array => $this->mapAssetSummary($asset), $result['items']),
            'pagination' => $result,
        ];
    }

    public function getCreateFormData(array $viewer, array $oldInput = []): array
    {
        return $this->buildAssetFormData($viewer, $this->assets->getAssetFormReferenceData(), $oldInput);
    }

    public function getEditFormData(int $assetId, array $viewer, array $oldInput = []): ?array
    {
        $asset = $this->assets->findAssetById($assetId);
        if ($asset === null) {
            return null;
        }

        return $this->buildAssetFormData($viewer, $this->assets->getAssetFormReferenceData(), $oldInput, $asset);
    }

    public function getAssetDetailData(int $assetId, array $viewer): ?array
    {
        $asset = $this->assets->findAssetById($assetId);
        if ($asset === null) {
            return null;
        }

        return [
            'asset' => $this->mapAssetDetail($asset),
            'canManage' => $this->canManageAssets($viewer),
        ];
    }

    public function createAsset(array $viewer, array $input): int
    {
        $this->assertManageable($viewer);
        $payload = $this->validateAssetInput($input);
        $payload['generated_by'] = (int) ($viewer['id'] ?? 0) > 0 ? (int) $viewer['id'] : null;

        return $this->assets->createAsset($payload);
    }

    public function updateAsset(int $assetId, array $viewer, array $input): void
    {
        $this->assertManageable($viewer);

        if ($this->assets->findAssetById($assetId) === null) {
            throw new DomainException('ไม่พบ asset ที่ต้องการแก้ไข');
        }

        $originalUpdatedAt = trim((string) ($input['updated_at'] ?? ''));
        if ($originalUpdatedAt === '') {
            throw new DomainException('ข้อมูล Asset ไม่ครบถ้วน กรุณารีเฟรชหน้าแล้วลองอีกครั้ง');
        }

        $payload = $this->validateAssetInput($input);
        $payload['original_updated_at'] = $originalUpdatedAt;

        $this->assets->updateAsset($assetId, $payload);
    }

    public function regenerateQrToken(int $assetId, array $viewer): void
    {
        $this->assertManageable($viewer);

        if ($this->assets->findAssetById($assetId) === null) {
            throw new DomainException('ไม่พบ asset ที่ต้องการสร้าง QR ใหม่');
        }

        $this->assets->regenerateQrToken($assetId, (int) ($viewer['id'] ?? 0) > 0 ? (int) $viewer['id'] : null);
    }

    public function getPrintableAssetsData(array $viewer): array
    {
        $this->assertManageable($viewer);

        return [
            'assets' => array_map(fn (array $asset): array => [
                'id' => (int) ($asset['id'] ?? 0),
                'asset_code' => (string) ($asset['asset_code'] ?? ''),
                'name' => (string) ($asset['name'] ?? ''),
                'location_name' => (string) ($asset['location_name'] ?? '-'),
                'scan_url' => url('/scan/' . (string) ($asset['token'] ?? '')),
                'qr_png_url' => url('/asset-registry/' . (int) ($asset['id'] ?? 0) . '/qr.png'),
            ], $this->assets->getPrintableAssets()),
        ];
    }

    public function getScanData(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $asset = $this->assets->findActiveAssetByToken($token);
        if ($asset === null) {
            return null;
        }

        $this->assets->touchScanToken($token);
        $asset = $this->assets->findActiveAssetByToken($token);
        if ($asset === null) {
            return null;
        }

        return [
            'asset' => [
                'id' => (int) ($asset['id'] ?? 0),
                'asset_code' => (string) ($asset['asset_code'] ?? ''),
                'name' => (string) ($asset['name'] ?? ''),
                'serial_number' => (string) ($asset['serial_number'] ?? '-'),
                'category_name' => (string) ($asset['category_name'] ?? '-'),
                'location_label' => $this->buildLabel([
                    (string) ($asset['location_name'] ?? ''),
                    (string) ($asset['building'] ?? ''),
                    (string) ($asset['room'] ?? ''),
                ]),
                'status' => $this->labelize((string) ($asset['status'] ?? 'active')),
                'notes' => (string) ($asset['notes'] ?? ''),
                'last_scanned_at' => $this->formatDateTime($asset['last_scanned_at'] ?? null),
            ],
            'ticket_create_path' => '/tickets/create?asset_id=' . (int) ($asset['id'] ?? 0),
            'login_path' => '/login?return=' . rawurlencode('/tickets/create?asset_id=' . (int) ($asset['id'] ?? 0)),
        ];
    }

    public function generateQrPng(int $assetId, array $viewer): string
    {
        $asset = $this->assets->findAssetById($assetId);
        if ($asset === null || (string) ($asset['qr_token'] ?? '') === '') {
            throw new DomainException('ไม่พบ QR token สำหรับ asset นี้');
        }

        $result = Builder::create()
            ->writer(new PngWriter())
            ->data(url('/scan/' . (string) $asset['qr_token']))
            ->encoding(new Encoding('UTF-8'))
            ->size(300)
            ->margin(12)
            ->build();

        return $result->getString();
    }

    private function buildAssetFormData(array $viewer, array $reference, array $oldInput = [], ?array $asset = null): array
    {
        return [
            'canManage' => $this->canManageAssets($viewer),
            'categories' => array_map(fn (array $category): array => [
                'id' => (int) ($category['id'] ?? 0),
                'label' => (string) ($category['name'] ?? '') . ' (' . (string) ($category['code'] ?? '') . ')',
            ], $reference['categories'] ?? []),
            'departments' => array_map(fn (array $department): array => [
                'id' => (int) ($department['id'] ?? 0),
                'label' => (string) ($department['name'] ?? '') . ' (' . (string) ($department['code'] ?? '') . ')',
            ], $reference['departments'] ?? []),
            'locations' => array_map(fn (array $location): array => [
                'id' => (int) ($location['id'] ?? 0),
                'label' => $this->buildLabel([
                    (string) ($location['name'] ?? ''),
                    (string) ($location['building'] ?? ''),
                    (string) ($location['room'] ?? ''),
                ]),
            ], $reference['locations'] ?? []),
            'custodians' => array_map(fn (array $custodian): array => [
                'id' => (int) ($custodian['id'] ?? 0),
                'label' => (string) ($custodian['full_name'] ?? '') . ' · ' . $this->labelize((string) ($custodian['role'] ?? 'user')),
            ], $reference['custodians'] ?? []),
            'statusOptions' => array_map(fn (string $status): array => [
                'value' => $status,
                'label' => $this->labelize($status),
            ], ['active', 'maintenance', 'retired', 'disposed']),
            'defaults' => [
                'asset_code' => (string) ($oldInput['asset_code'] ?? ($asset['asset_code'] ?? '')),
                'name' => (string) ($oldInput['name'] ?? ($asset['name'] ?? '')),
                'serial_number' => (string) ($oldInput['serial_number'] ?? ($asset['serial_number'] ?? '')),
                'asset_category_id' => (string) ($oldInput['asset_category_id'] ?? (string) ($asset['asset_category_id'] ?? '')),
                'department_id' => (string) ($oldInput['department_id'] ?? (string) ($asset['department_id'] ?? '')),
                'location_id' => (string) ($oldInput['location_id'] ?? (string) ($asset['location_id'] ?? '')),
                'custodian_user_id' => (string) ($oldInput['custodian_user_id'] ?? (string) ($asset['custodian_user_id'] ?? '')),
                'brand' => (string) ($oldInput['brand'] ?? ($asset['brand'] ?? '')),
                'model' => (string) ($oldInput['model'] ?? ($asset['model'] ?? '')),
                'vendor' => (string) ($oldInput['vendor'] ?? ($asset['vendor'] ?? '')),
                'purchase_date' => (string) ($oldInput['purchase_date'] ?? ($asset['purchase_date'] ?? '')),
                'warranty_expires_at' => (string) ($oldInput['warranty_expires_at'] ?? ($asset['warranty_expires_at'] ?? '')),
                'status' => (string) ($oldInput['status'] ?? ($asset['status'] ?? 'active')),
                'notes' => (string) ($oldInput['notes'] ?? ($asset['notes'] ?? '')),
                'updated_at' => (string) ($oldInput['updated_at'] ?? ($asset['updated_at'] ?? '')),
            ],
        ];
    }

    private function validateAssetInput(array $input): array
    {
        $reference = $this->assets->getAssetFormReferenceData();

        $assetCode = strtoupper(trim((string) ($input['asset_code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $serialNumber = trim((string) ($input['serial_number'] ?? ''));
        $categoryId = (int) ($input['asset_category_id'] ?? 0);
        $departmentId = (int) ($input['department_id'] ?? 0);
        $locationId = (int) ($input['location_id'] ?? 0);
        $custodianUserId = (int) ($input['custodian_user_id'] ?? 0);
        $brand = trim((string) ($input['brand'] ?? ''));
        $model = trim((string) ($input['model'] ?? ''));
        $vendor = trim((string) ($input['vendor'] ?? ''));
        $purchaseDate = trim((string) ($input['purchase_date'] ?? ''));
        $warrantyExpiresAt = trim((string) ($input['warranty_expires_at'] ?? ''));
        $status = strtolower(trim((string) ($input['status'] ?? 'active')));
        $notes = trim((string) ($input['notes'] ?? ''));

        if ($assetCode === '' || $name === '') {
            throw new DomainException('กรุณากรอกรหัส Asset และชื่อ Asset ให้ครบถ้วน');
        }

        if (strlen($assetCode) > 60 || strlen($name) > 200) {
            throw new DomainException('รหัสหรือชื่อ Asset ยาวเกินกว่าที่ระบบรองรับ');
        }

        if (!in_array($status, ['active', 'maintenance', 'retired', 'disposed'], true)) {
            throw new DomainException('สถานะของ Asset ไม่ถูกต้อง');
        }

        if ($this->findReferenceById($reference['categories'] ?? [], $categoryId) === null || $this->findReferenceById($reference['locations'] ?? [], $locationId) === null) {
            throw new DomainException('กรุณาเลือก Category และ Location ให้ถูกต้อง');
        }

        if ($departmentId > 0 && $this->findReferenceById($reference['departments'] ?? [], $departmentId) === null) {
            throw new DomainException('Department ที่เลือกไม่ถูกต้อง');
        }

        if ($custodianUserId > 0 && $this->findReferenceById($reference['custodians'] ?? [], $custodianUserId) === null) {
            throw new DomainException('Custodian ที่เลือกไม่ถูกต้อง');
        }

        if ($purchaseDate !== '' && !$this->isValidDate($purchaseDate)) {
            throw new DomainException('รูปแบบ Purchase Date ไม่ถูกต้อง');
        }

        if ($warrantyExpiresAt !== '' && !$this->isValidDate($warrantyExpiresAt)) {
            throw new DomainException('รูปแบบ Warranty Expiry ไม่ถูกต้อง');
        }

        if ($purchaseDate !== '' && $warrantyExpiresAt !== '' && strtotime($warrantyExpiresAt) < strtotime($purchaseDate)) {
            throw new DomainException('วันหมดประกันต้องไม่น้อยกว่าวันที่จัดซื้อ');
        }

        return [
            'asset_code' => $assetCode,
            'name' => $name,
            'serial_number' => $serialNumber,
            'asset_category_id' => $categoryId,
            'department_id' => $departmentId > 0 ? $departmentId : null,
            'location_id' => $locationId,
            'custodian_user_id' => $custodianUserId > 0 ? $custodianUserId : null,
            'brand' => $brand,
            'model' => $model,
            'vendor' => $vendor,
            'purchase_date' => $purchaseDate,
            'warranty_expires_at' => $warrantyExpiresAt,
            'status' => $status,
            'notes' => $notes,
        ];
    }

    private function mapAssetSummary(array $asset): array
    {
        return [
            'id' => (int) ($asset['id'] ?? 0),
            'asset_code' => (string) ($asset['asset_code'] ?? ''),
            'name' => (string) ($asset['name'] ?? ''),
            'serial_number' => (string) ($asset['serial_number'] ?? '-'),
            'brand_model' => $this->buildLabel([
                (string) ($asset['brand'] ?? ''),
                (string) ($asset['model'] ?? ''),
            ]),
            'status' => (string) ($asset['status'] ?? 'active'),
            'status_label' => $this->labelize((string) ($asset['status'] ?? 'active')),
            'status_tone' => $this->statusTone((string) ($asset['status'] ?? 'active')),
            'category_name' => (string) ($asset['category_name'] ?? '-'),
            'location_label' => $this->buildLabel([
                (string) ($asset['location_name'] ?? ''),
                (string) ($asset['building'] ?? ''),
                (string) ($asset['room'] ?? ''),
            ]),
            'department_name' => (string) ($asset['department_name'] ?? '-'),
            'custodian_name' => (string) ($asset['custodian_name'] ?? '-'),
            'warranty_expires_at' => $this->formatDate($asset['warranty_expires_at'] ?? null),
            'last_scanned_at' => $this->formatDateTime($asset['last_scanned_at'] ?? null),
            'qr_png_url' => url('/asset-registry/' . (int) ($asset['id'] ?? 0) . '/qr.png'),
            'prefill_ticket_url' => '/tickets/create?asset_id=' . (int) ($asset['id'] ?? 0),
        ];
    }

    private function mapAssetDetail(array $asset): array
    {
        return $this->mapAssetSummary($asset) + [
            'department_id' => (int) ($asset['department_id'] ?? 0),
            'location_id' => (int) ($asset['location_id'] ?? 0),
            'custodian_user_id' => (int) ($asset['custodian_user_id'] ?? 0),
            'brand' => (string) ($asset['brand'] ?? ''),
            'model' => (string) ($asset['model'] ?? ''),
            'vendor' => (string) ($asset['vendor'] ?? ''),
            'notes' => (string) ($asset['notes'] ?? ''),
            'category_name' => (string) ($asset['category_name'] ?? '-'),
            'department_name' => (string) ($asset['department_name'] ?? '-'),
            'location_name' => $this->buildLabel([
                (string) ($asset['location_name'] ?? ''),
                (string) ($asset['building'] ?? ''),
                (string) ($asset['floor'] ?? ''),
                (string) ($asset['room'] ?? ''),
            ]),
            'custodian_name' => (string) ($asset['custodian_name'] ?? '-'),
            'purchase_date' => $this->formatDate($asset['purchase_date'] ?? null),
            'qr_token' => (string) ($asset['qr_token'] ?? ''),
            'qr_created_at' => $this->formatDateTime($asset['qr_created_at'] ?? null),
            'scan_url' => (string) ($asset['qr_token'] ?? '') !== '' ? url('/scan/' . (string) $asset['qr_token']) : '',
            'qr_png_url' => url('/asset-registry/' . (int) ($asset['id'] ?? 0) . '/qr.png'),
            'prefill_ticket_url' => '/tickets/create?asset_id=' . (int) ($asset['id'] ?? 0),
        ];
    }

    private function assertManageable(array $viewer): void
    {
        if (!$this->canManageAssets($viewer)) {
            throw new DomainException('คุณไม่มีสิทธิ์จัดการข้อมูล Asset และ QR');
        }
    }

    private function canManageAssets(array $viewer): bool
    {
        return in_array((string) ($viewer['role'] ?? 'guest'), ['manager', 'admin'], true);
    }

    private function findReferenceById(array $items, int $id): ?array
    {
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return $item;
            }
        }

        return null;
    }

    private function isValidDate(string $value): bool
    {
        $parts = date_parse($value);

        return $parts['error_count'] === 0 && checkdate((int) ($parts['month'] ?? 0), (int) ($parts['day'] ?? 0), (int) ($parts['year'] ?? 0));
    }

    private function formatDateTime(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '-';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return (string) $value;
        }

        return date('d/m/Y H:i', $timestamp);
    }

    private function formatDate(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '-';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return (string) $value;
        }

        return date('d/m/Y', $timestamp);
    }

    private function buildLabel(array $parts): string
    {
        $segments = array_values(array_filter($parts, static fn (string $value): bool => trim($value) !== '' && $value !== '-'));

        return $segments !== [] ? implode(' / ', $segments) : '-';
    }

    private function labelize(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '-';
        }

        return ucwords(str_replace('_', ' ', $normalized));
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'maintenance' => 'warning',
            'retired', 'disposed' => 'danger',
            default => 'default',
        };
    }
}
