<?php
declare(strict_types=1);

namespace App\Services;

use DomainException;

/**
 * Shared CSV-upload parser for import services (AssetImportService / UserImportService):
 * validates the upload, enforces the .csv extension + size cap, reads and normalizes the
 * header, ensures every required column is present, then returns one assoc row per data
 * line keyed by column name (plus a 1-based `_line` for error reporting).
 */
trait ParsesCsvUpload
{
    /**
     * @param array<string, mixed> $file  a single $_FILES entry
     * @param list<string>         $columns  required lowercase column names
     * @return list<array<string, string|int>>
     */
    protected function parseCsvUpload(array $file, array $columns, int $maxBytes, int $maxRows): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new DomainException('อัปโหลดไฟล์ไม่สำเร็จ กรุณาเลือกไฟล์ CSV และลองอีกครั้ง');
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            throw new DomainException('รองรับเฉพาะไฟล์ .csv เท่านั้น');
        }

        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            throw new DomainException('ไฟล์มีขนาดเกิน ' . (int) ($maxBytes / 1048576) . 'MB');
        }

        $handle = fopen((string) $file['tmp_name'], 'r');
        if ($handle === false) {
            throw new DomainException('ไม่สามารถเปิดไฟล์ที่อัปโหลดได้');
        }

        $rows = [];
        try {
            $header = fgetcsv($handle);
            if ($header === false || $header === null) {
                throw new DomainException('ไฟล์ CSV ว่างเปล่า');
            }

            $header = array_map(static fn ($h): string => strtolower(trim((string) $h)), $header);
            $missing = array_diff($columns, $header);
            if ($missing !== []) {
                throw new DomainException('ไฟล์ CSV ไม่ครบ column: ' . implode(', ', $missing));
            }

            $lineNo = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $lineNo++;
                if (count(array_filter($row, static fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }

                $assoc = ['_line' => $lineNo];
                foreach ($header as $idx => $colName) {
                    if (in_array($colName, $columns, true)) {
                        $assoc[$colName] = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
                    }
                }
                $rows[] = $assoc;
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw new DomainException('ไม่พบข้อมูลใน CSV');
        }

        if (count($rows) > $maxRows) {
            throw new DomainException('นำเข้าได้ครั้งละไม่เกิน ' . $maxRows . ' รายการ');
        }

        return $rows;
    }
}
