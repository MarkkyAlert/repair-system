<?php
declare(strict_types=1);

namespace App\Services;

use DomainException;

/**
 * ตัว parse ไฟล์ CSV ที่อัปโหลด ใช้ร่วมกันสำหรับ import service (AssetImportService / UserImportService):
 * ตรวจสอบไฟล์ที่อัปโหลด, บังคับนามสกุล .csv + จำกัดขนาด, อ่านและ normalize (ปรับให้เป็นมาตรฐาน)
 * header, ตรวจว่ามีทุก column ที่จำเป็นครบ แล้วคืน row แบบ assoc หนึ่งแถวต่อบรรทัดข้อมูลหนึ่งบรรทัด
 * โดย key ด้วยชื่อ column (บวก `_line` ที่เริ่มนับจาก 1 ไว้สำหรับรายงาน error).
 */
trait ParsesCsvUpload
{
    /**
     * @param array<string, mixed> $file  entry เดียวจาก $_FILES
     * @param list<string>         $columns  ชื่อ column ที่จำเป็น (ตัวพิมพ์เล็ก)
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
