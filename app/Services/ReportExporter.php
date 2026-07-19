<?php
declare(strict_types=1);

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * ชั้นผลิตไฟล์กลางสำหรับ export รายงาน: แปลงแถวที่ map แล้วเป็นไฟล์ไบนารี .xlsx / .csv / .pdf.
 * ไม่มี state (ไม่แตะ DB, ไม่แตะ request) เลย unit-test ง่ายมากและ exporter ตัวไหนก็ใช้ซ้ำได้.
 *
 * ทุกเส้นทางส่งแต่ละแถวผ่าน sanitizeExportRow() — guard กัน CSV/formula-injection เลยไม่มีทางถูก
 * เมธอด export ตัวไหนลืม ป้อนแถวเข้าไปได้ไฟล์ปลอดภัยออกมา. ส่วนการติดตาม job (audit ใน
 * export_jobs) เป็นคนละเรื่อง อยู่กับตัวเรียก.
 */
class ReportExporter
{
    /** ทำให้ทุกเซลล์ในแถวปลอดภัยจาก CSV/spreadsheet formula injection (เติม `'` นำหน้าเมื่อขึ้นต้นด้วย = + - @) */
    public function sanitizeExportRow(array $values): array
    {
        return array_map(static fn (mixed $value): string => sanitize_export_cell($value), $values);
    }

    /**
     * เขียน Excel ทีละเซลล์: ป้ายที่เป็นเปอร์เซ็นต์ล้วน ("50.0%", "+25.0%") เก็บเป็นตัวเลขจริง
     * (0.5) พร้อม format แสดงผลเป็นเปอร์เซ็นต์ ให้ผู้บริหาร/ผู้จัดการ pivot/sum ได้ — text "50.0%" เอาไป
     * aggregate ไม่ได้. ที่เหลือยัง guard กัน formula-injection อยู่; เซลล์ที่เป็น string ตัวเลขอย่าง
     * "4.50"/"2" ก็ยังกลายเป็นตัวเลขผ่าน default value binder.
     */
    private function writeDataRow(Worksheet $sheet, int $rowNumber, array $row): void
    {
        $colIndex = 1;
        foreach (array_values($row) as $value) {
            $coord = Coordinate::stringFromColumnIndex($colIndex) . $rowNumber;
            $string = (string) $value;
            if ($value instanceof \App\Support\ExportText) {
                // ตัวระบุที่เป็น text ชัดเจน (asset_code, ticket_no) → เก็บเป็น text ตามตัวอักษร ไม่เดาเป็นตัวเลข
                $sheet->setCellValueExplicit($coord, sanitize_export_cell($string), DataType::TYPE_STRING);
            } elseif (is_int($value) || is_float($value)) {
                // ตัวเลขที่กำหนด type แล้ว (count, delta, net ติดลบ, metric ตัวเลขเปล่า) → numeric; พิสูจน์ได้ว่าเป็นค่า
                // ไม่ใช่ formula หรือ identifier แน่นอน. ตัวเรียกส่ง metric แบบ typed และส่ง identifier เป็น string (ด้านล่าง).
                $sheet->setCellValueExplicit($coord, $value, DataType::TYPE_NUMERIC);
            } elseif (preg_match('/^[+-]?\d+(\.\d+)?%$/', $string) === 1) {
                $sheet->setCellValueExplicit($coord, (float) rtrim($string, '%') / 100, DataType::TYPE_NUMERIC);
                $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('0.0%');
            } elseif (preg_match('/^[+-]?\d{1,3}(,\d{3})+(\.\d+)?$/', $string) === 1) {
                // ตัวเลขที่ format หลักพันแล้ว ("1,234.0" จาก number_format ≥ 1000) → เก็บเป็นตัวเลขจริง
                // แล้วแสดงผลแบบคั่นหลักพัน ให้ Excel sum/pivot ได้ ไม่ปล่อยเป็น text.
                $dotPos = strpos($string, '.');
                $decimals = $dotPos === false ? 0 : strlen($string) - $dotPos - 1;
                $sheet->setCellValueExplicit($coord, (float) str_replace(',', '', $string), DataType::TYPE_NUMERIC);
                $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('#,##0' . ($decimals > 0 ? '.' . str_repeat('0', $decimals) : ''));
            } elseif (preg_match('/^-?\d+\.\d+$/', $string) === 1) {
                // ป้าย metric ที่เป็นทศนิยม format แล้ว ("5.0", "9.0") — string ทศนิยมไม่มีทางเป็น identifier ที่นำหน้า
                // ด้วยเลขศูนย์ เก็บเป็น numeric ไว้ pivot/sum ได้ปลอดภัย.
                $sheet->setCellValueExplicit($coord, (float) $string, DataType::TYPE_NUMERIC);
            } else {
                // ที่เหลือเป็น text — รวมถึง string identifier ที่เป็นตัวเลขล้วน (asset_code "0028712749",
                // ticket_no): เก็บเป็น text ชัด ๆ เลขศูนย์นำหน้าจะได้ไม่หายและ code ยาว ๆ คงความแม่นยำ.
                // อย่าปล่อยให้ default value binder ของ PhpSpreadsheet แปลง string identifier เป็นตัวเลข.
                // guard กัน formula-injection ยังมีผลอยู่. (metric ที่เป็น integer ล้วนต้องส่งแบบ typed ตามด้านบน.)
                $sheet->setCellValueExplicit($coord, sanitize_export_cell($value), DataType::TYPE_STRING);
            }
            $colIndex++;
        }
    }

    /**
     * สร้างไฟล์ .xlsx แบบ sheet เดียวจากแถวที่ map แล้ว. sanitise ทุกแถวเสมอ export แบบ *Excel ตัวไหน
     * เลยไม่มีทางลืม guard กัน formula-injection.
     *
     * @param array<int, string>            $headers
     * @param array<int, array<int, mixed>> $rows
     */
    public function buildXlsxExport(string $sheetTitle, array $headers, array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $this->fillSheet($spreadsheet->getActiveSheet(), $sheetTitle, $headers, $rows);

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = (string) ob_get_clean();
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $content;
    }

    /**
     * เพิ่ม sheet ที่ sanitise แล้วอีกหนึ่งใบเข้า Spreadsheet ที่มีอยู่ (สำหรับ export หลาย sheet อย่าง CSAT /
     * รายงาน ticket). ตัวเรียกที่ต้องการ sheet เดียวให้ใช้ buildXlsxExport แทน.
     *
     * @param array<int, string>            $headers
     * @param array<int, array<int, mixed>> $rows
     */
    public function addExcelSheet(Spreadsheet $spreadsheet, string $title, array $headers, array $rows): void
    {
        $this->fillSheet($spreadsheet->createSheet(), $title, $headers, $rows);
    }

    /**
     * เขียน title + แถว header + แถวข้อมูล ลง worksheet ผ่าน writer ทีละเซลล์ตัวกลาง
     * (writeDataRow) ทุก sheet จะได้จัดการ numeric-percentage + formula-injection เหมือนกันหมด —
     * ทั้ง export sheet เดียว, sheet เสริม, หรือ active sheet ของ exporter หลาย sheet. เป็น public ให้ตัวเรียก
     * ที่ถือ Spreadsheet ของตัวเอง (เช่น export 2 sheet ของ CSAT) เติม active sheet ของตัวเองผ่าน writer
     * ตัวเดียวนี้ แทนการเขียน fromArray() เองซึ่งจะทำให้เปอร์เซ็นต์กลายเป็น text.
     *
     * @param array<int, string>            $headers
     * @param array<int, array<int, mixed>> $rows
     */
    public function fillSheet(Worksheet $sheet, string $title, array $headers, array $rows): void
    {
        $sheet->setTitle($title);
        $colIndex = 1;
        foreach ($headers as $header) {
            $column = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue($column . '1', $header);
            $sheet->getColumnDimension($column)->setAutoSize(true);
            $colIndex++;
        }
        $rowNumber = 2;
        foreach ($rows as $row) {
            $this->writeDataRow($sheet, $rowNumber, $row);
            $rowNumber++;
        }
    }

    /**
     * สร้าง CSV แบบ UTF-8 (มี BOM) จากแถวที่ map แล้ว. sanitise ทุกแถวเสมอ export แบบ *Csv ตัวไหน
     * เลยไม่มีทางลืม guard กัน formula-injection.
     *
     * @param array<int, string>            $headers
     * @param array<int, array<int, mixed>> $rows
     */
    public function buildCsvExport(array $headers, array $rows): string
    {
        return $this->buildCsvSections([['headers' => $headers, 'rows' => $rows]]);
    }

    /**
     * CSV แบบหลาย section: มี UTF-8 BOM ตัวเดียว (ให้ Excel อ่านไทยได้) แล้วแต่ละ section เป็นแถว title (จะมีหรือไม่ก็ได้) + แถว
     * header + แถวข้อมูลที่ sanitise แล้ว คั่นด้วยบรรทัดว่าง. CSV ของ /reports เลยพกทั้งตาราง ticket และ
     * บล็อก analytics (ตรงกับ sheet ของ Excel / หน้าจอ) โดยแต่ละบล็อกยังเป็นตาราง pivot สะอาด ๆ ได้.
     * แต่ละ section = ['headers' => string[], 'rows' => array<array>, 'title' => ?string].
     */
    public function buildCsvSections(array $sections): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('ไม่สามารถเตรียมไฟล์ CSV ได้');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        $first = true;
        foreach ($sections as $section) {
            if (!$first) {
                fputcsv($stream, []); // บรรทัดว่างคั่นระหว่าง section
            }
            $first = false;
            $title = (string) ($section['title'] ?? '');
            if ($title !== '') {
                fputcsv($stream, [$title]);
            }
            fputcsv($stream, (array) ($section['headers'] ?? []));
            foreach ((array) ($section['rows'] ?? []) as $row) {
                fputcsv($stream, $this->sanitizeExportRow((array) $row));
            }
        }
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    /**
     * เรนเดอร์ HTML string เป็นไฟล์ไบนารี PDF ผ่าน Dompdf ด้วยค่า default กลางของรายงาน: A4 แนวนอน,
     * ฟอนต์ไทย 'sarabun' (ให้ข้อความไทยแสดงผลได้ ไม่เป็นกล่อง tofu), ปิด remote assets และใช้
     * temp dir ที่เขียนได้ (ถ้า temp dir ของระบบใช้ไม่ได้ก็ fallback ไป /tmp แล้วต่อด้วย storage/uploads).
     * เป็นแหล่งเดียวของ exporter PDF ทุกรายงาน — ไม่มีเส้นทาง export ไหนหลุดเรื่อง font/paper/temp ได้.
     */
    public function renderPdf(string $html): string
    {
        $options = new Options();
        $dompdfTmp = sys_get_temp_dir();
        if ($dompdfTmp === '' || !@is_writable($dompdfTmp)) {
            $dompdfTmp = is_dir('/tmp') ? '/tmp' : BASE_PATH . '/storage/uploads';
        }
        $options->setTempDir($dompdfTmp);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'sarabun');
        // โหลดฟอนต์ไทย sarabun จากไฟล์ที่ commit ไว้ใน repo (resources/fonts) ไม่พึ่ง vendor ที่ gitignored —
        // ไม่งั้น fresh deploy (composer install) จะไม่มีฟอนต์ → ไทยเป็น tofu. metrics cache ลง temp ที่ write ได้.
        $options->setChroot([BASE_PATH]);
        $options->set('fontCache', $dompdfTmp);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->withThaiFontFace($html), 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    /** ฝัง @font-face ของ sarabun (ชี้ไฟล์ .ttf ที่ commit) เข้า <head> ของ HTML ทุก report PDF. */
    private function withThaiFontFace(string $html): string
    {
        $dir = BASE_PATH . '/resources/fonts';
        $face = '<style>'
            . "@font-face{font-family:'sarabun';font-weight:normal;font-style:normal;src:url('" . $dir . "/sarabun-regular.ttf') format('truetype');}"
            . "@font-face{font-family:'sarabun';font-weight:bold;font-style:normal;src:url('" . $dir . "/sarabun-bold.ttf') format('truetype');}"
            . '</style>';

        return str_contains($html, '<head>')
            ? str_replace('<head>', '<head>' . $face, $html)
            : $face . $html;
    }
}
