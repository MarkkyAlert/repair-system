<?php
declare(strict_types=1);

use App\Services\ReportExporter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Security-lock: the shared report export builders MUST sanitise every row (CSV/formula-injection guard) so
// no *Excel/*Csv export can forget it. Feeds a formula cell straight into the builder and confirms the built
// file neutralises it (leading quote → the spreadsheet renders it as text, not a formula). ReportExporter is a
// stateless standalone service, so this tests its public methods directly (no call_private needed).

function ebs_exporter(): ReportExporter
{
    return tvm_container()->get(ReportExporter::class);
}

test('export-builder(xlsx): buildXlsxExport neutralises a formula cell (guard built into the shared builder)', function (): void {
    $content = ebs_exporter()->buildXlsxExport('รายงาน', ['หัว'], [['=cmd()']]);

    $tmp = tempnam(sys_get_temp_dir(), 'xlsxguard_') . '.xlsx';
    try {
        file_put_contents($tmp, $content);
        $a2 = (string) IOFactory::createReader('Xlsx')->load($tmp)->getActiveSheet()->getCell('A2')->getValue();
        assert_same("'=cmd()", $a2, 'the formula cell is neutralised with a leading quote by the builder');
    } finally {
        @unlink($tmp);
    }
});

test('export-builder(xlsx): percentage cells are numeric so users can pivot/sum, not stored as text (Finding #2)', function (): void {
    $content = ebs_exporter()->buildXlsxExport('รายงาน', ['อัตรา', 'เฉลี่ย', 'จำนวน'], [['50.0%', '4.50', '2']]);

    $tmp = tempnam(sys_get_temp_dir(), 'xlsxpct_') . '.xlsx';
    try {
        file_put_contents($tmp, $content);
        $sheet = IOFactory::createReader('Xlsx')->load($tmp)->getActiveSheet();
        // "50.0%" → a real number 0.5 with a percentage display format (so Excel pivot/sum works)
        assert_same(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType(), 'percentage cell is numeric, not text');
        assert_same(0.5, $sheet->getCell('A2')->getValue(), '"50.0%" is stored as 0.5');
        assert_same('0.0%', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode(), 'and displays as a percentage');
        // plain decimal + count stay numeric
        assert_same(4.5, $sheet->getCell('B2')->getValue(), '"4.50" stays a number');
        assert_same(2, (int) $sheet->getCell('C2')->getValue(), '"2" stays a number');
    } finally {
        @unlink($tmp);
    }
});

test('export-builder(csv): buildCsvExport neutralises a formula cell (guard built into the shared builder)', function (): void {
    $content = ebs_exporter()->buildCsvExport(['หัว'], [['=cmd()']]);

    assert_contains_str("'=cmd()", $content, 'the formula cell is neutralised with a leading quote in the CSV');
});

test('export-builder(csv): output starts with the 3-byte UTF-8 BOM so Excel reads Thai (all reports route through here)', function (): void {
    // Every report CSV goes through buildCsvExport, so this one guard covers them all. Without the BOM,
    // Excel on Windows guesses the encoding and renders Thai headers/labels as mojibake (à¸...). (BI-review #4.)
    $content = ebs_exporter()->buildCsvExport(['หัวข้อไทย'], [['ค่าไทย']]);

    assert_same("\xEF\xBB\xBF", substr($content, 0, 3), 'CSV begins with the UTF-8 BOM (EF BB BF)');
    $firstLine = rtrim(explode("\n", substr($content, 3))[0], "\r");
    assert_same('หัวข้อไทย', $firstLine, 'the Thai header follows immediately after the BOM (not corrupted)');
});
