<?php

declare(strict_types=1);

use App\Services\ReportExporter;
use App\Support\ExportText;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

// R18: a USER-DEFINED text column (asset_code, dimension label, name, feedback) must never be number-inferred by
// the XLSX writer, whatever its shape — leading-zero decimal, thousands-comma, or percent-looking. Wrapping it in
// ExportText forces verbatim text (byte-equal to screen/CSV). A typed metric next to it stays numeric. This locks
// the mechanism directly at the exporter, independent of any one report's columns.
test('exporter: an ExportText identifier stays text in XLSX for every numeric-looking shape (R18)', function (): void {
    $exporter = tvm_container()->get(ReportExporter::class);

    $codes = ['00970705.25', '1,234', '50.0%', '0028712749', '007'];
    $rows = array_map(static fn (string $c): array => [new ExportText($c), 5], $codes);

    $tmp = tempnam(sys_get_temp_dir(), 'et_') . '.xlsx';
    file_put_contents($tmp, $exporter->buildXlsxExport('T', ['code', 'metric'], $rows));
    $sheet = IOFactory::createReader('Xlsx')->load($tmp)->getActiveSheet();
    @unlink($tmp);

    foreach ($codes as $i => $code) {
        $cell = $sheet->getCell('A' . ($i + 2));
        assert_same(DataType::TYPE_STRING, $cell->getDataType(), "identifier '$code' stays text, not number-inferred");
        assert_same($code, (string) $cell->getValue(), "identifier '$code' is byte-equal");
    }
    assert_same(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType(), 'a typed metric next to identifiers stays numeric');
});
