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

// A .xlsx cell is neutralised by its TYPE, not by a quote character glued onto the value. The builder writes every
// text cell with an explicit string type, so the saved workbook contains no formula element at all — Excel cannot
// evaluate it no matter what the text starts with. Prepending "'" (the CSV convention) would make that apostrophe a
// real character IN the cell, so every "no data" cell would read '- instead of - on the reader's screen. The CSV
// exporter still prepends it, because a CSV cell carries no type and the file format has no other defence.
test('export-builder(xlsx): a formula-looking cell is inert by type, with the value left readable', function (): void {
    $content = ebs_exporter()->buildXlsxExport('รายงาน', ['หัว'], [['=cmd()'], ['-'], ['@SUM(A1)']]);

    $tmp = tempnam(sys_get_temp_dir(), 'xlsxguard_') . '.xlsx';
    try {
        file_put_contents($tmp, $content);

        // the real guard: the workbook carries no <f> (formula) element for these cells
        $zip = new ZipArchive();
        assert_true($zip->open($tmp) === true, 'the workbook opens as a zip');
        $sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        assert_true(!str_contains($sheetXml, '<f>'), 'no cell was written as a formula, so Excel has nothing to evaluate');

        $sheet = IOFactory::createReader('Xlsx')->load($tmp)->getActiveSheet();
        assert_same(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType(), 'the formula-looking cell is stored as text');
        assert_same('=cmd()', (string) $sheet->getCell('A2')->getValue(), 'and its text is preserved byte-for-byte, not rewritten');
        // the placeholder every report uses for "no data" must read as a plain dash on screen
        assert_same('-', (string) $sheet->getCell('A3')->getValue(), 'a "no data" dash is not disfigured into \'-');
        assert_same('@SUM(A1)', (string) $sheet->getCell('A4')->getValue(), 'other formula openers are equally inert and equally readable');
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

test('export-builder(xlsx): thousands-formatted numbers ("1,234.0") are numeric, not text (round-8 F2)', function (): void {
    // number_format adds a thousands comma at >= 1000 (asset downtime/labor labels), which the default binder
    // treats as text → not sum/pivot-able. The shared writer must coerce it to a real number.
    $content = ebs_exporter()->buildXlsxExport('รายงาน', ['downtime', 'labor', 'count'], [['1,234.0', '12,000', '2']]);

    $tmp = tempnam(sys_get_temp_dir(), 'xlsxk_') . '.xlsx';
    try {
        file_put_contents($tmp, $content);
        $sheet = IOFactory::createReader('Xlsx')->load($tmp)->getActiveSheet();
        assert_same(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType(), '"1,234.0" is numeric, not text');
        assert_same(1234.0, $sheet->getCell('A2')->getValue(), '"1,234.0" stored as 1234.0');
        assert_same('#,##0.0', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode(), 'grouped 1-decimal display preserved');
        assert_same(12000.0, $sheet->getCell('B2')->getValue(), '"12,000" stored as 12000');
        assert_same('#,##0', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode(), 'grouped integer display');
        assert_same(2, (int) $sheet->getCell('C2')->getValue(), 'plain "2" stays numeric');
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
