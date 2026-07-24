<?php
declare(strict_types=1);

namespace App\Services;

use DomainException;

/**
 * ตัว parse ไฟล์ CSV ที่อัปโหลด ใช้ร่วมกันในงาน import (AssetImportService / UserImportService):
 * เช็คไฟล์ที่อัปโหลด, บังคับนามสกุล .csv กับจำกัดขนาด, อ่าน header แล้ว normalize ให้เป็นมาตรฐาน,
 * ดูว่ามี column ที่จำเป็นครบไหม แล้วคืน row แบบ assoc หนึ่งแถวต่อข้อมูลหนึ่งบรรทัด
 * โดย key ด้วยชื่อ column (แถม `_line` นับจาก 1 ไว้ใช้ตอนรายงาน error).
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
        // is_uploaded_file: ต้องเป็นไฟล์ที่แนบมากับ request นี้จริง ไม่ใช่ path ที่ถูกยัดค่ามาให้ชี้ไปไฟล์อื่นในเครื่อง (เช่น config) แล้วดูดออกมาเป็นข้อมูล import
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

            // ตัด UTF-8 BOM (\xEF\xBB\xBF) ออกจาก header ตัวแรกก่อน normalize — Excel/โปรแกรมตารางใส่ BOM ต้นไฟล์
            // เสมอ (รวมทั้ง template ที่แอปแจกเอง + ไฟล์ export) BOM ไม่ใช่ whitespace จึงรอด trim() ทำให้ column
            // แรกไม่ match ทั้งที่ไฟล์ถูก → import ปฏิเสธไฟล์ที่ผู้ใช้สร้างจริง
            if (isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
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
                        // ถอดเครื่องหมายกันสูตรที่ export เติมไว้ (unsanitize_import_cell) เพื่อให้ไฟล์ที่ export
                        // จากระบบเอง import กลับได้ค่าตรงเดิม (round-trip) ไม่เพี้ยนที่ตัวขึ้นต้น - + @ =
                        $assoc[$colName] = isset($row[$idx]) ? unsanitize_import_cell(trim((string) $row[$idx])) : '';
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
