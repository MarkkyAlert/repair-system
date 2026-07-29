<?php

declare(strict_types=1);

use App\Services\AssetService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

// bug-hunt MED#7: the asset-registry XLSX export hand-rolled $sheet->fromArray, which lets PhpSpreadsheet
// number-infer each cell. The export columns are AssetImportService::CSV_COLUMNS and the file is meant to
// round-trip back through import (see prepareAssetExport), so a leading-zero asset_code, a "1E5" serial, or a
// numeric department_code got mangled (0028712749 → 28712749, 1E5 → 100000) and re-imported wrong. The CSV
// export keeps them verbatim; the XLSX must match by writing every cell as explicit text.
test('asset export (MED#7): every XLSX cell is text — leading zeros / scientific-looking codes survive (round-trips with import)', function (): void {
    $svc = tvm_container()->get(AssetService::class);
    $build = new ReflectionMethod($svc, 'buildAssetXlsx');
    $build->setAccessible(true);

    $headers = ['asset_code', 'serial_number', 'department_code'];
    $identifiers = ['0028712749', '1E5', '007']; // number-inference would break each of these

    $xlsx = (string) $build->invoke($svc, $headers, [$identifiers]);

    $tmp = tempnam(sys_get_temp_dir(), 'assetxlsx_') . '.xlsx';
    file_put_contents($tmp, $xlsx);
    $sheet = IOFactory::createReader('Xlsx')->load($tmp)->getActiveSheet();
    @unlink($tmp);

    foreach ($identifiers as $i => $value) {
        $cell = $sheet->getCell(chr(ord('A') + $i) . '2');
        assert_same(DataType::TYPE_STRING, $cell->getDataType(), "identifier '{$value}' stays text, not number-inferred");
        assert_same($value, (string) $cell->getValue(), "identifier '{$value}' is byte-equal after export (must re-import unchanged)");
    }
});

// ── M-11: การสร้าง XLSX ต้องไม่พ่นอะไรออก output เลย ──
// เดิม header loop เดินคอลัมน์ด้วย $column++ บนสตริง ('A'→'B') ซึ่ง PHP 8.5 ประกาศเป็น deprecated. ถ้าเครื่อง
// ที่ติดตั้งเปิด display_errors ไว้ notice จะถูกพ่นออกไปก่อน Response::download() จะทันตั้ง header ด้วยซ้ำ
// (headers_sent = true) ผลคือผู้ใช้ดาวน์โหลดไฟล์ที่มีข้อความ notice ปนอยู่หัวไฟล์ แล้ว Excel เปิดไม่ขึ้น
// composer.json รองรับ ^8.1 โดยไม่มีเพดานบน = PHP 8.5 เป็นเป้าหมายที่ต้องรองรับของ template ที่ขายไป
test('asset export M-11: building the XLSX emits nothing — a stray notice would corrupt the download', function (): void {
    $service = tvm_container()->get(App\Services\AssetService::class);
    $method = new ReflectionMethod(App\Services\AssetService::class, 'buildAssetXlsx');
    $method->setAccessible(true);

    $displayErrors = ini_get('display_errors');
    ini_set('display_errors', '1');
    ob_start();

    try {
        $binary = (string) $method->invoke($service, ['รหัส', 'ชื่อ'], [['A-001', 'เครื่องพิมพ์']]);
        $leaked = (string) ob_get_clean();
        assert_same('', $leaked, 'nothing may be echoed while the workbook is built — it would land in the downloaded file');
        assert_same("PK\x03\x04", substr($binary, 0, 4), 'and the result is a real xlsx (zip) container');
    } finally {
        ini_set('display_errors', (string) $displayErrors);
    }
});

test('asset export M-11: a header row wider than Z still maps to real columns (AA, AB, …)', function (): void {
    $service = tvm_container()->get(App\Services\AssetService::class);
    $method = new ReflectionMethod(App\Services\AssetService::class, 'buildAssetXlsx');
    $method->setAccessible(true);

    $headers = [];
    for ($i = 1; $i <= 30; $i++) {
        $headers[] = 'h' . $i;
    }

    $binary = (string) $method->invoke($service, $headers, [array_fill(0, 30, 'x')]);
    $tmp = tempnam(sys_get_temp_dir(), 'm11') . '.xlsx';

    try {
        file_put_contents($tmp, $binary);
        $sheet = PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
        assert_same('h1', (string) $sheet->getCell('A1')->getValue(), 'the first header lands in A1');
        assert_same('h26', (string) $sheet->getCell('Z1')->getValue(), 'the 26th lands in Z1');
        assert_same('h30', (string) $sheet->getCell('AD1')->getValue(), 'and the 30th continues into AD1 rather than breaking past Z');
    } finally {
        @unlink($tmp);
    }
});
