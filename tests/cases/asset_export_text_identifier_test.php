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
