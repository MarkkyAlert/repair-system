<?php
declare(strict_types=1);

use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Security-lock: the shared report export builders MUST sanitise every row (CSV/formula-injection guard) so
// no *Excel/*Csv export can forget it. Feeds a formula cell straight into the builder and confirms the built
// file neutralises it (leading quote → the spreadsheet renders it as text, not a formula).

test('export-builder(xlsx): buildXlsxExport neutralises a formula cell (guard built into the shared builder)', function (): void {
    $content = call_private(tvm_container()->get(ReportService::class), 'buildXlsxExport', ['รายงาน', ['หัว'], [['=cmd()']]]);

    $tmp = tempnam(sys_get_temp_dir(), 'xlsxguard_') . '.xlsx';
    try {
        file_put_contents($tmp, $content);
        $a2 = (string) IOFactory::createReader('Xlsx')->load($tmp)->getActiveSheet()->getCell('A2')->getValue();
        assert_same("'=cmd()", $a2, 'the formula cell is neutralised with a leading quote by the builder');
    } finally {
        @unlink($tmp);
    }
});
