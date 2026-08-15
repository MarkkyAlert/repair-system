<?php
declare(strict_types=1);

use App\Services\ReportExporter;
use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Smalot\PdfParser\Parser;

// ไฟล์ที่ส่งออกมักถูกส่งต่อให้คนที่ไม่เคยเปิดระบบเอง (ผู้บริหาร เจ้าของอาคาร ผู้รับเหมา) — เอกสารกดปุ่มดูคำอธิบาย
// ไม่ได้ คำว่า SLA ในหัวคอลัมน์จึงต้องอธิบายตัวเองอยู่ในไฟล์
//
// เชิงอรรถถูกเติมจาก ReportExporter ที่เดียว โดยดูจากหัวคอลัมน์เอง ไม่ใช่ให้ทุกจุดที่เรียก export จำใส่เอง
// (จุดเรียกมีหลายสิบที่ ลืมที่เดียวก็ได้ไฟล์ที่อธิบายตัวเองไม่ได้)

function esf_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('export(sla): แผ่นงานที่มีคอลัมน์ SLA ได้เชิงอรรถต่อท้าย และหัวคอลัมน์ยังอยู่แถวแรก', function (): void {
    $exporter = tvm_container()->get(ReportExporter::class);
    $book = new Spreadsheet();

    $exporter->fillSheet($book->getActiveSheet(), 'มี SLA', ['ช่วงเวลา', 'SLA ตรงเวลา'], [['ม.ค.', '90.0%'], ['ก.พ.', '80.0%']]);
    $sheet = $book->getActiveSheet();

    // แถวแรกต้องยังเป็นหัวคอลัมน์เสมอ — ทั้งคนอ่านและเทสต์ export อื่น ๆ อ่าน A1 เป็นหัว
    assert_same('ช่วงเวลา', (string) $sheet->getCell('A1')->getValue(), 'หัวคอลัมน์ต้องอยู่แถวแรก');
    assert_same('ม.ค.', (string) $sheet->getCell('A2')->getValue(), 'ข้อมูลแถวแรกต่อจากหัวทันที');
    assert_same('', (string) $sheet->getCell('A4')->getValue(), 'เว้นหนึ่งบรรทัดคั่นก่อนเชิงอรรถ');
    assert_same(ReportExporter::SLA_FOOTNOTE, (string) $sheet->getCell('A5')->getValue(), 'เชิงอรรถอยู่ท้ายตาราง');

    // ตัวกัน formula-injection จะเติม ' นำหน้าถ้าข้อความขึ้นต้นด้วย - หรือ = ผู้ใช้จะเห็นอักขระประหลาดใน Excel
    assert_true(str_starts_with(ReportExporter::SLA_FOOTNOTE, 'หมายเหตุ'), 'เชิงอรรถต้องขึ้นต้นด้วยคำว่าหมายเหตุ');

    $book->disconnectWorksheets();
});

test('export(sla): แผ่นงานที่ไม่ได้พูดถึง SLA เลย ไม่ต้องมีเชิงอรรถ', function (): void {
    // เชิงอรรถต้องไปเฉพาะที่ที่มีศัพท์ให้อธิบายจริง ไม่ใช่แปะทุกแผ่นจนกลายเป็นเสียงรบกวน
    // (ตัดสินจากทั้งชื่อแผ่นและหัวคอลัมน์ — แผ่น "SLA ตรงตามกำหนด" ไม่มีคำว่า SLA ในหัวคอลัมน์เลยสักช่อง)
    $exporter = tvm_container()->get(ReportExporter::class);
    $book = new Spreadsheet();

    $exporter->fillSheet($book->getActiveSheet(), 'ชั่วโมงแรงงาน', ['ช่าง', 'ชั่วโมงแรงงาน'], [['สมชาย', '8.0']]);
    $flat = implode(' ', array_map(
        static fn (array $row): string => implode(' ', array_map(static fn ($cell): string => (string) $cell, $row)),
        $book->getActiveSheet()->toArray()
    ));

    assert_false(str_contains($flat, 'หมายเหตุ: SLA'), 'แผ่นที่ไม่ได้พูดถึง SLA ต้องไม่มีเชิงอรรถ');
    $book->disconnectWorksheets();
});

test('export(sla): ไฟล์ Excel และ CSV ของรายงานรวมมีเชิงอรรถจริง', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) esf_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();
    $tmp = tempnam(sys_get_temp_dir(), 'esf_') . '.xlsx';

    try {
        $csv = tvm_container()->get(ReportService::class)->exportCsv($admin, []);
        assert_true(str_contains((string) $csv['content'], ReportExporter::SLA_FOOTNOTE), 'CSV ต้องมีเชิงอรรถท้ายไฟล์');

        file_put_contents($tmp, (string) tvm_container()->get(ReportService::class)->exportExcel($admin, [])['content']);
        $book = IOFactory::createReader('Xlsx')->load($tmp);
        $sla = $book->getSheetByName('SLA ตรงตามกำหนด');
        assert_same('ระดับความสำคัญ', (string) $sla->getCell('A1')->getValue(), 'หัวคอลัมน์ยังอยู่แถวแรกหลังเพิ่มเชิงอรรถ');

        $columnA = array_map(static fn (array $row): string => (string) ($row[0] ?? ''), $sla->toArray());
        assert_true(in_array(ReportExporter::SLA_FOOTNOTE, $columnA, true), 'แผ่น SLA ต้องมีเชิงอรรถ');
        $book->disconnectWorksheets();
    } finally {
        @unlink($tmp);
        esf_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});

test('export(sla): เชิงอรรถใน PDF อ่านออกเป็นตัวอักษรไทยจริง ไม่ใช่กล่องสี่เหลี่ยม', function (): void {
    // เคยมีเคสที่ดาว ★ ใน PDF กลายเป็นกล่องเพราะฟอนต์ไม่มี glyph — เช็คแค่ว่าไฟล์เป็น %PDF- จึงไม่พอ
    // ต้องอ่านตัวอักษรออกมาจากไฟล์จริง
    $admin = ['id' => 4, 'role' => 'admin'];
    $baselineJobId = (int) esf_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        $pdf = (string) tvm_container()->get(ReportService::class)->exportPdf($admin, [])['content'];
        assert_true(str_starts_with($pdf, '%PDF-'), 'ไฟล์ต้องเป็น PDF');

        $text = (new Parser())->parseContent($pdf)->getText();
        assert_true(str_contains($text, 'ข้อตกลงระดับการให้บริการ'), 'ต้องอ่านคำอธิบาย SLA ออกจาก PDF ได้');
        assert_true(str_contains($text, 'หมายเหตุ'), 'ต้องอ่านคำว่าหมายเหตุออกจาก PDF ได้');
    } finally {
        esf_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});

test('export(sla): เอกสาร PDF ทุกใบที่พูดถึง SLA ต้องแนบเชิงอรรถ', function (): void {
    $root = dirname(__DIR__, 2);
    $missing = [];
    foreach (glob($root . '/app/Views/**/*pdf*.php') ?: [] as $view) {
        $html = (string) file_get_contents($view);
        if (str_contains($html, 'SLA') && !str_contains($html, 'export-footnote')) {
            $missing[] = str_replace($root . '/', '', $view);
        }
    }

    assert_same([], $missing, 'PDF ที่มีคำว่า SLA แต่ไม่มีเชิงอรรถ: ' . implode(', ', $missing));
});
