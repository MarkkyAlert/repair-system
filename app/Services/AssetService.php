<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AssetRepository;
use DomainException;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\Writer\PngWriter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class AssetService
{
    public function __construct(
        private AssetRepository $assets,
        private TicketService $tickets,
    ) {
    }

    public function getAssetIndexData(array $viewer, array $filters = []): array
    {
        $this->assertManageable($viewer);

        $reference = $this->assets->getAssetFormReferenceData();
        $normalizedFilters = $this->normalizeAssetIndexFilters($filters);
        $result = $this->assets->getAssetListPage(max(1, (int) ($filters['page'] ?? 1)), 18, $normalizedFilters);
        $filterOptions = $this->buildAssetFilterOptions($reference);

        return [
            'roleLabel' => role_label_th((string) ($viewer['role'] ?? 'guest')),
            'canManage' => $this->canManageAssets($viewer),
            'assets' => array_map(fn (array $asset): array => $this->mapAssetSummary($asset), $result['items']),
            'pagination' => $result,
            'filters' => $normalizedFilters,
            'filterOptions' => $filterOptions,
            'activeFilters' => $this->buildAssetActiveFilterChips($normalizedFilters, $filterOptions),
        ];
    }

    public function getCreateFormData(array $viewer, array $oldInput = []): array
    {
        // gate ให้ตรงกับ getEditFormData/createAsset — กัน technician/requester เห็นฟอร์ม
        // + reference data (categories/locations/departments/custodian users) ทั้งที่จัดการ asset ไม่ได้
        $this->assertManageable($viewer);

        return $this->buildAssetFormData($viewer, $this->assets->getAssetFormReferenceData(), $oldInput);
    }

    public function getEditFormData(int $assetId, array $viewer, array $oldInput = []): ?array
    {
        $this->assertManageable($viewer);

        $asset = $this->assets->findAssetById($assetId);
        if ($asset === null) {
            return null;
        }

        return $this->buildAssetFormData($viewer, $this->assets->getAssetFormReferenceData(), $oldInput, $asset);
    }

    public function getAssetDetailData(int $assetId, array $viewer): ?array
    {
        $this->assertManageable($viewer);

        $asset = $this->assets->findAssetById($assetId);
        if ($asset === null) {
            return null;
        }

        $history = $this->tickets->getRecentTicketsForAsset($assetId, 10);

        return [
            'asset' => $this->mapAssetDetail($asset),
            'canManage' => $this->canManageAssets($viewer),
            'recentTickets' => $history['tickets'],
            'recentTicketsTotal' => $history['total'],
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

        $originalVersion = (int) ($input['original_version'] ?? 0);
        if ($originalVersion <= 0) {
            throw new DomainException('ข้อมูล Asset ไม่ครบถ้วน กรุณารีเฟรชหน้าแล้วลองอีกครั้ง');
        }

        $payload = $this->validateAssetInput($input);
        $payload['original_version'] = $originalVersion;

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

    private const PRINT_ASSETS_CAP = 500;

    // Export size guard — กัน OOM/timeout เมื่อ asset เยอะ (บังคับให้กรองก่อน)
    private const EXPORT_MAX_ROWS = 10000;

    public function getPrintableAssetsData(array $viewer, array $filters = []): array
    {
        $this->assertManageable($viewer);

        // สืบทอด filter จากหน้า list (ผ่าน query string) — normalize ชุดเดียวกับ getAssetIndexData
        $normalizedFilters = $this->normalizeAssetIndexFilters($filters);

        // ดึง cap+1 เพื่อรู้ว่ามีเกิน cap ไหม แล้ว slice + แจ้งเตือนในหน้าพิมพ์ให้กรองแคบลง
        $rows = $this->assets->getPrintableAssets(self::PRINT_ASSETS_CAP + 1, $normalizedFilters);
        $capped = count($rows) > self::PRINT_ASSETS_CAP;
        if ($capped) {
            $rows = array_slice($rows, 0, self::PRINT_ASSETS_CAP);
        }

        $filterOptions = $this->buildAssetFilterOptions($this->assets->getAssetFormReferenceData());

        return [
            'brandName' => (string) setting('app_name', config('app.name', 'Repair System')),
            'brandLogoUrl' => branding_logo_url(),
            'capped' => $capped,
            'printLimit' => self::PRINT_ASSETS_CAP,
            'activeFilters' => $this->buildAssetActiveFilterChips($normalizedFilters, $filterOptions),
            'assets' => array_map(fn (array $asset): array => [
                'id' => (int) ($asset['id'] ?? 0),
                'asset_code' => (string) ($asset['asset_code'] ?? ''),
                'name' => (string) ($asset['name'] ?? ''),
                'location_name' => (string) ($asset['location_name'] ?? '-'),
                'scan_url' => url('/scan/' . (string) ($asset['token'] ?? '')),
                'qr_png_url' => url('/asset-registry/' . (int) ($asset['id'] ?? 0) . '/qr.png'),
            ], $rows),
        ];
    }

    public function exportCsv(array $viewer, array $filters = []): array
    {
        [$headers, $rows] = $this->prepareAssetExport($viewer, $filters);

        return [
            'content' => $this->buildAssetCsv($headers, $rows),
            'file_name' => 'asset-registry-' . date('Ymd-His') . '.csv',
            'content_type' => 'text/csv; charset=UTF-8',
        ];
    }

    public function exportExcel(array $viewer, array $filters = []): array
    {
        [$headers, $rows] = $this->prepareAssetExport($viewer, $filters);

        return [
            'content' => $this->buildAssetXlsx($headers, $rows),
            'file_name' => 'asset-registry-' . date('Ymd-His') . '.xlsx',
            'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /**
     * เตรียมข้อมูล export: gate + normalize filter (ชุดเดียวกับ list) + size guard, แล้ว map แต่ละ asset
     * เป็นค่าเรียงตาม AssetImportService::CSV_COLUMNS (codes/username) เพื่อ round-trip กับ import.
     *
     * @return array{0: string[], 1: array<int, array<int, string>>}
     */
    private function prepareAssetExport(array $viewer, array $filters): array
    {
        $this->assertManageable($viewer);

        $normalizedFilters = $this->normalizeAssetIndexFilters($filters);
        $rows = $this->assets->getAssetsForExport($normalizedFilters, self::EXPORT_MAX_ROWS + 1);
        if (count($rows) > self::EXPORT_MAX_ROWS) {
            throw new DomainException('ข้อมูลสำหรับ export มีมากเกิน ' . number_format(self::EXPORT_MAX_ROWS) . ' แถว กรุณากรอง (หมวด/สถานที่/สถานะ) ให้แคบลงก่อน export');
        }

        $headers = AssetImportService::CSV_COLUMNS;
        $dataRows = array_map(static fn (array $asset): array => array_map(
            static fn (string $column): string => (string) ($asset[$column] ?? ''),
            $headers
        ), $rows);

        return [$headers, $dataRows];
    }

    private function buildAssetCsv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('ไม่สามารถเตรียมไฟล์ CSV ได้');
        }
        fwrite($stream, "\xEF\xBB\xBF"); // BOM ให้ Excel อ่านภาษาไทยถูก
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, $this->sanitizeExportRow($row));
        }
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    private function buildAssetXlsx(array $headers, array $rows): string
    {
        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('ทะเบียนทรัพย์สิน');

            $column = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($column . '1', $header);
                $sheet->getColumnDimension($column)->setAutoSize(true);
                $column++;
            }

            $rowNumber = 2;
            foreach ($rows as $row) {
                $sheet->fromArray($this->sanitizeExportRow($row), null, 'A' . $rowNumber);
                $rowNumber++;
            }

            $writer = new Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            $content = (string) ob_get_clean();
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return $content;
        } catch (\Throwable $exception) {
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ Excel ได้', 0, $exception);
        }
    }

    private function sanitizeExportRow(array $values): array
    {
        // CSV/spreadsheet injection guard lives in the shared sanitize_export_cell() helper (single source of truth)
        return array_map(static fn (mixed $value): string => sanitize_export_cell($value), $values);
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
                'status' => asset_status_label_th((string) ($asset['status'] ?? 'active')),
                'notes' => (string) ($asset['notes'] ?? ''),
                'last_scanned_at' => $this->formatDateTime($asset['last_scanned_at'] ?? null),
            ],
            'ticket_create_path' => '/tickets/create?asset_id=' . (int) ($asset['id'] ?? 0),
            'login_path' => '/login?return=' . rawurlencode('/tickets/create?asset_id=' . (int) ($asset['id'] ?? 0)),
        ];
    }

    public function generateQrPng(int $assetId, array $viewer): string
    {
        $this->assertManageable($viewer);

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
                'label' => (string) ($custodian['full_name'] ?? '') . ' · ' . role_label_th((string) ($custodian['role'] ?? 'user')),
            ], $reference['custodians'] ?? []),
            'statusOptions' => array_map(static fn (string $status): array => [
                'value' => $status,
                'label' => asset_status_label_th($status),
            ], asset_status_values()),
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
                'version' => (int) ($oldInput['original_version'] ?? ($asset['version'] ?? 1)),
            ],
        ];
    }

    private function validateAssetInput(array $input): array
    {
        $reference = $this->assets->getAssetFormReferenceData();

        $assetCode = strtoupper(trim((string) ($input['asset_code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $serialNumber = trim((string) ($input['serial_number'] ?? ''));
        // strict_int so a malformed "1junk" reference is rejected, not coerced to its prefix (round F1)
        $categoryId = strict_int($input['asset_category_id'] ?? null, 'หมวดหมู่ Asset ');
        $departmentId = strict_int($input['department_id'] ?? null, 'แผนก ');
        $locationId = strict_int($input['location_id'] ?? null, 'สถานที่ ');
        $custodianUserId = strict_int($input['custodian_user_id'] ?? null, 'ผู้ดูแล ');
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

        // Optional text fields — bound to their columns so an over-long value is a friendly message, not a
        // raw DB error under strict mode (serial/brand/model VARCHAR(100), vendor VARCHAR(150)). (F6)
        require_max_length($serialNumber, 100, 'Serial Number');
        require_max_length($brand, 100, 'ยี่ห้อ');
        require_max_length($model, 100, 'รุ่น');
        require_max_length($vendor, 150, 'ผู้จำหน่าย');

        if (!in_array($status, asset_status_values(), true)) {
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
            'status_label' => asset_status_label_th((string) ($asset['status'] ?? 'active')),
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

    private function normalizeAssetIndexFilters(array $filters): array
    {
        $statusOptions = array_keys($this->assetStatusOptions());
        $status = trim((string) ($filters['status'] ?? ''));

        return [
            'q' => function_exists('mb_substr')
                ? mb_substr(trim((string) ($filters['q'] ?? '')), 0, 120)
                : substr(trim((string) ($filters['q'] ?? '')), 0, 120),
            'category_id' => max(0, (int) ($filters['category_id'] ?? 0)),
            'location_id' => max(0, (int) ($filters['location_id'] ?? 0)),
            'status' => in_array($status, $statusOptions, true) ? $status : '',
        ];
    }

    private function buildAssetFilterOptions(array $reference): array
    {
        return [
            'categories' => array_map(fn (array $category): array => [
                'id' => (int) ($category['id'] ?? 0),
                'label' => (string) ($category['name'] ?? '') . ' (' . (string) ($category['code'] ?? '') . ')',
            ], $reference['categories'] ?? []),
            'locations' => array_map(fn (array $location): array => [
                'id' => (int) ($location['id'] ?? 0),
                'label' => $this->buildLabel([
                    (string) ($location['name'] ?? ''),
                    (string) ($location['building'] ?? ''),
                    (string) ($location['room'] ?? ''),
                ]),
            ], $reference['locations'] ?? []),
            'statuses' => $this->assetStatusOptions(),
        ];
    }

    /** Active-filter chips (view-model): label + dismiss URL per applied asset filter. */
    private function buildAssetActiveFilterChips(array $filters, array $options): array
    {
        $dismissUrl = static function (string $removeKey) use ($filters): string {
            $query = [
                'q' => (string) ($filters['q'] ?? ''),
                'category_id' => (int) ($filters['category_id'] ?? 0) > 0 ? (string) $filters['category_id'] : '',
                'location_id' => (int) ($filters['location_id'] ?? 0) > 0 ? (string) $filters['location_id'] : '',
                'status' => (string) ($filters['status'] ?? ''),
            ];
            unset($query[$removeKey]);
            $query = array_filter($query, static fn ($v): bool => (string) $v !== '');

            return url('/asset-registry' . ($query !== [] ? '?' . http_build_query($query) : ''));
        };

        $chips = [];
        if ((string) ($filters['q'] ?? '') !== '') {
            $chips[] = ['label' => 'คำค้น: ' . (string) $filters['q'], 'dismiss' => $dismissUrl('q')];
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $label = $this->findOptionLabel($options['categories'] ?? [], $categoryId);
            $chips[] = ['label' => 'หมวดหมู่: ' . ($label !== '' ? $label : (string) $categoryId), 'dismiss' => $dismissUrl('category_id')];
        }

        $locationId = (int) ($filters['location_id'] ?? 0);
        if ($locationId > 0) {
            $label = $this->findOptionLabel($options['locations'] ?? [], $locationId);
            $chips[] = ['label' => 'สถานที่: ' . ($label !== '' ? $label : (string) $locationId), 'dismiss' => $dismissUrl('location_id')];
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $chips[] = ['label' => 'สถานะ: ' . asset_status_label_th($status), 'dismiss' => $dismissUrl('status')];
        }

        return $chips;
    }

    private function findOptionLabel(array $options, int $id): string
    {
        foreach ($options as $option) {
            if ((int) ($option['id'] ?? 0) === $id) {
                return (string) ($option['label'] ?? '');
            }
        }

        return '';
    }

    private function assetStatusOptions(): array
    {
        $keys = asset_status_values();

        return array_combine($keys, array_map('asset_status_label_th', $keys));
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
        return is_manager_or_admin((string) ($viewer['role'] ?? 'guest'));
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
        // Drop empty/placeholder parts and collapse repeated segments
        // (e.g. location_name == room -> "Server Room / Head Office / Server Room"
        //  becomes "Server Room / Head Office"), keeping first occurrence.
        $segments = [];
        $seen = [];
        foreach ($parts as $value) {
            $value = trim($value);
            if ($value === '' || $value === '-') {
                continue;
            }
            $key = mb_strtolower($value);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $segments[] = $value;
        }

        return $segments !== [] ? implode(' / ', $segments) : '-';
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
