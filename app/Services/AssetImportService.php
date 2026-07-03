<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AssetRepository;
use App\Repositories\UserRepository;
use DomainException;
use PDO;
use Throwable;

class AssetImportService
{
    use ParsesCsvUpload;

    public const CSV_COLUMNS = [
        'asset_code', 'name', 'serial_number', 'category_code', 'location_code',
        'department_code', 'custodian_username', 'brand', 'model', 'vendor',
        'purchase_date', 'warranty_expires_at', 'status', 'notes',
    ];

    public const ALLOWED_STATUSES = ['active', 'maintenance', 'retired', 'disposed'];

    public function __construct(
        private AssetRepository $assets,
        private UserRepository $users,
        private PDO $db,
    ) {
    }

    public function parseUploadedFile(array $file): array
    {
        return $this->parseCsvUpload($file, self::CSV_COLUMNS, 2 * 1024 * 1024, 500);
    }

    public function validateRows(array $rows): array
    {
        $reference = $this->assets->getAssetFormReferenceData();
        $categoriesByCode = $this->indexByCode($reference['categories'] ?? []);
        $locationsByCode = $this->indexByCode($reference['locations'] ?? []);
        $departmentsByCode = $this->indexByCode($reference['departments'] ?? []);

        $valid = [];
        $invalid = [];
        $seenCodes = [];

        foreach ($rows as $row) {
            $errors = [];
            $assetCode = strtoupper(trim((string) ($row['asset_code'] ?? '')));
            $name = trim((string) ($row['name'] ?? ''));
            $status = strtolower(trim((string) ($row['status'] ?? 'active'))) ?: 'active';

            if ($assetCode === '' || $name === '') {
                $errors[] = 'asset_code และ name จำเป็นต้องมี';
            }
            if (strlen($assetCode) > 60) {
                $errors[] = 'asset_code ยาวเกิน 60 ตัวอักษร';
            }
            if (strlen($name) > 200) {
                $errors[] = 'name ยาวเกิน 200 ตัวอักษร';
            }
            if ($assetCode !== '' && isset($seenCodes[$assetCode])) {
                $errors[] = 'asset_code ซ้ำกับแถวอื่นในไฟล์';
            }
            if (!in_array($status, self::ALLOWED_STATUSES, true)) {
                $errors[] = 'status ต้องเป็นหนึ่งใน: ' . implode(', ', self::ALLOWED_STATUSES);
            }

            $categoryCode = strtoupper(trim((string) ($row['category_code'] ?? '')));
            $locationCode = strtoupper(trim((string) ($row['location_code'] ?? '')));
            $departmentCode = strtoupper(trim((string) ($row['department_code'] ?? '')));
            $custodianUsername = strtolower(trim((string) ($row['custodian_username'] ?? '')));

            $categoryId = $categoryCode !== '' ? ($categoriesByCode[$categoryCode] ?? null) : null;
            $locationId = $locationCode !== '' ? ($locationsByCode[$locationCode] ?? null) : null;
            $departmentId = $departmentCode !== '' ? ($departmentsByCode[$departmentCode] ?? null) : null;
            $custodianUserId = null;
            if ($custodianUsername !== '') {
                $custodian = $this->users->findByLogin($custodianUsername);
                if ($custodian !== null) {
                    $custodianUserId = (int) ($custodian['id'] ?? 0);
                }
            }

            if ($categoryCode === '' || $categoryId === null) {
                $errors[] = 'category_code "' . $categoryCode . '" ไม่พบในระบบ';
            }
            if ($locationCode === '' || $locationId === null) {
                $errors[] = 'location_code "' . $locationCode . '" ไม่พบในระบบ';
            }
            if ($departmentCode !== '' && $departmentId === null) {
                $errors[] = 'department_code "' . $departmentCode . '" ไม่พบในระบบ';
            }
            if ($custodianUsername !== '' && $custodianUserId === null) {
                $errors[] = 'custodian_username "' . $custodianUsername . '" ไม่พบในระบบ';
            }

            $purchaseDate = trim((string) ($row['purchase_date'] ?? ''));
            $warrantyExpiresAt = trim((string) ($row['warranty_expires_at'] ?? ''));
            if ($purchaseDate !== '' && !$this->isValidDate($purchaseDate)) {
                $errors[] = 'purchase_date ต้องเป็นรูปแบบ YYYY-MM-DD';
            }
            if ($warrantyExpiresAt !== '' && !$this->isValidDate($warrantyExpiresAt)) {
                $errors[] = 'warranty_expires_at ต้องเป็นรูปแบบ YYYY-MM-DD';
            }
            if ($purchaseDate !== '' && $warrantyExpiresAt !== '' && strtotime($warrantyExpiresAt) < strtotime($purchaseDate)) {
                $errors[] = 'warranty_expires_at ต้องไม่น้อยกว่า purchase_date';
            }

            $entry = [
                'line' => (int) ($row['_line'] ?? 0),
                'asset_code' => $assetCode,
                'name' => $name,
                'errors' => $errors,
            ];

            if ($errors !== []) {
                $invalid[] = $entry;
                continue;
            }

            $seenCodes[$assetCode] = true;
            $valid[] = [
                'line' => (int) ($row['_line'] ?? 0),
                'asset_code' => $assetCode,
                'name' => $name,
                'serial_number' => trim((string) ($row['serial_number'] ?? '')),
                'asset_category_id' => (int) $categoryId,
                'department_id' => $departmentId !== null ? (int) $departmentId : null,
                'location_id' => (int) $locationId,
                'custodian_user_id' => $custodianUserId,
                'brand' => trim((string) ($row['brand'] ?? '')),
                'model' => trim((string) ($row['model'] ?? '')),
                'vendor' => trim((string) ($row['vendor'] ?? '')),
                'purchase_date' => $purchaseDate,
                'warranty_expires_at' => $warrantyExpiresAt,
                'status' => $status,
                'notes' => trim((string) ($row['notes'] ?? '')),
            ];
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
            'total' => count($rows),
        ];
    }

    public function executeImport(array $validRows, array $viewer): array
    {
        $imported = 0;
        $skipped = [];
        $generatedBy = (int) ($viewer['id'] ?? 0) > 0 ? (int) $viewer['id'] : null;

        foreach ($validRows as $row) {
            try {
                $payload = $row;
                unset($payload['line']);
                $payload['generated_by'] = $generatedBy;
                $this->assets->createAsset($payload);
                $imported++;
            } catch (Throwable $exception) {
                $skipped[] = [
                    'line' => (int) ($row['line'] ?? 0),
                    'asset_code' => (string) ($row['asset_code'] ?? ''),
                    'reason' => is_duplicate_key_error($exception)
                        ? 'asset_code หรือ serial_number ซ้ำกับข้อมูลที่มีอยู่'
                        : 'เกิดข้อผิดพลาดในการบันทึก',
                ];
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    private function indexByCode(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            if ($code !== '') {
                $index[$code] = (int) ($row['id'] ?? 0);
            }
        }
        return $index;
    }

    private function isValidDate(string $value): bool
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
    }
}
