<?php
declare(strict_types=1);

use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Tests that the report export carries the same 4 analytics as the on-screen panels:
//  - Excel workbook gets 5 sheets (ticket + SLA / technician / labor / asset) with the right titles.
//  - The SLA sheet always carries the "รวมทั้งหมด" overall row (present even with no priority breakdown).
//  - PDF still renders (starts with %PDF-) once analytics sections are injected into the view.
//  - CSV stays raw ticket rows (single-table, analytics deliberately excluded).
// Structural assertions only (no seeded-value coupling) so it holds regardless of test-DB contents.

function ea_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

function ea_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('export analytics: xlsx has 5 sheets (ticket + 4 analytics) with correct titles + SLA overall row', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) ea_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'ea_') . '.xlsx';

    try {
        $export = ea_service()->exportExcel($admin, []);
        file_put_contents($tmp, (string) $export['content']);

        $book = IOFactory::createReader('Xlsx')->load($tmp);
        assert_same(5, $book->getSheetCount(), 'workbook has ticket + 4 analytics sheets');
        assert_same(
            ['รายงาน Ticket', 'SLA ตรงตามกำหนด', 'ผลงานช่างเทคนิค', 'ชั่วโมงแรงงาน', 'ทรัพย์สินเสียบ่อย'],
            $book->getSheetNames(),
            'sheet titles in order'
        );

        $sla = $book->getSheetByName('SLA ตรงตามกำหนด');
        assert_same('ระดับความสำคัญ', (string) $sla->getCell('A1')->getValue(), 'SLA header cell');
        assert_same('รวมทั้งหมด', (string) $sla->getCell('A2')->getValue(), 'SLA overall row is always present');

        assert_same('ช่าง', (string) $book->getSheetByName('ผลงานช่างเทคนิค')->getCell('A1')->getValue(), 'technician header');
        assert_same('หมวดหมู่งาน', (string) $book->getSheetByName('ชั่วโมงแรงงาน')->getCell('A1')->getValue(), 'labor header');
        assert_same('รหัส', (string) $book->getSheetByName('ทรัพย์สินเสียบ่อย')->getCell('A1')->getValue(), 'asset header');

        $book->disconnectWorksheets();
    } finally {
        @unlink($tmp);
        ea_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});

test('export analytics: pdf still renders (%PDF-) and csv stays raw ticket rows', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) ea_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        $pdf = ea_service()->exportPdf($admin, []);
        assert_same('%PDF-', substr((string) $pdf['content'], 0, 5), 'pdf magic bytes (analytics render without throwing)');

        $csv = ea_service()->exportCsv($admin, []);
        $csvBody = (string) $csv['content'];
        // BOM + ticket header row; analytics section titles must NOT leak into the single-table CSV.
        assert_true(str_contains($csvBody, 'เลขที่'), 'csv keeps ticket header');
        assert_false(str_contains($csvBody, 'SLA ตรงตามกำหนด'), 'csv stays raw ticket rows (no analytics section)');
    } finally {
        ea_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});
