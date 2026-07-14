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
 * Shared file-production layer for report exports: turns already-mapped rows into an .xlsx / .csv / .pdf
 * binary. Stateless (no DB, no request state) so it is trivially unit-testable and reusable by any exporter.
 *
 * Every path runs each row through sanitizeExportRow(), so the CSV/formula-injection guard cannot be
 * forgotten by an individual export method — feed rows in, get a safe file out. Job-tracking (the
 * export_jobs audit) is a separate concern and stays with the caller.
 */
class ReportExporter
{
    /** Neutralise every cell in a row against CSV/spreadsheet formula injection (leading `'` on = + - @). */
    public function sanitizeExportRow(array $values): array
    {
        return array_map(static fn (mixed $value): string => sanitize_export_cell($value), $values);
    }

    /**
     * Write one Excel row, per cell: a pure percentage label ("50.0%", "+25.0%") is stored as a real number
     * (0.5) with a percentage display format so the exec/manager can pivot/sum it — text "50.0%" cannot be
     * aggregated (BI-review). Everything else keeps the formula-injection guard; numeric-string cells like
     * "4.50"/"2" still become numbers via the default value binder.
     */
    private function writeDataRow(Worksheet $sheet, int $rowNumber, array $row): void
    {
        $colIndex = 1;
        foreach (array_values($row) as $value) {
            $coord = Coordinate::stringFromColumnIndex($colIndex) . $rowNumber;
            $string = (string) $value;
            if (preg_match('/^[+-]?\d+(\.\d+)?%$/', $string) === 1) {
                $sheet->setCellValueExplicit($coord, (float) rtrim($string, '%') / 100, DataType::TYPE_NUMERIC);
                $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('0.0%');
            } elseif (preg_match('/^[+-]?\d{1,3}(,\d{3})+(\.\d+)?$/', $string) === 1) {
                // thousands-formatted number ("1,234.0" from number_format ≥ 1000) → a real number with a
                // grouped display, so Excel can sum/pivot it instead of leaving it as text (round-8 F2). It is
                // provably numeric so the formula-injection guard is not needed (a numeric cell can't be a formula).
                $dotPos = strpos($string, '.');
                $decimals = $dotPos === false ? 0 : strlen($string) - $dotPos - 1;
                $sheet->setCellValueExplicit($coord, (float) str_replace(',', '', $string), DataType::TYPE_NUMERIC);
                $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('#,##0' . ($decimals > 0 ? '.' . str_repeat('0', $decimals) : ''));
            } elseif (preg_match('/^-?\d+$/', $string) === 1) {
                // a plain (optionally negative) integer is a value, not a formula → store numeric so Excel can
                // sum/pivot and it stays byte-equal to the screen; a leading-minus number must not get the
                // injection quote (audit F2: a negative trend net was becoming text "'-1").
                $sheet->setCellValueExplicit($coord, (int) $string, DataType::TYPE_NUMERIC);
            } elseif (preg_match('/^-?\d+\.\d+$/', $string) === 1) {
                $sheet->setCellValueExplicit($coord, (float) $string, DataType::TYPE_NUMERIC);
            } else {
                $sheet->setCellValue($coord, sanitize_export_cell($value));
            }
            $colIndex++;
        }
    }

    /**
     * Build a single-sheet .xlsx from already-mapped rows. Always sanitises each row, so no *Excel export
     * can forget the formula-injection guard.
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
     * Append an extra sanitised sheet to an existing Spreadsheet (for multi-sheet exports like CSAT /
     * the ticket report). Callers that need a single sheet use buildXlsxExport instead.
     *
     * @param array<int, string>            $headers
     * @param array<int, array<int, mixed>> $rows
     */
    public function addExcelSheet(Spreadsheet $spreadsheet, string $title, array $headers, array $rows): void
    {
        $this->fillSheet($spreadsheet->createSheet(), $title, $headers, $rows);
    }

    /**
     * Write a title + header row + data rows into a worksheet through the shared per-cell writer
     * (writeDataRow), so EVERY sheet gets the same numeric-percentage + formula-injection handling —
     * single-sheet exports, extra sheets, or a multi-sheet exporter's own active sheet. Public so callers
     * that manage their own Spreadsheet (e.g. CSAT's 2-sheet export) fill their active sheet through the one
     * writer instead of a hand-rolled fromArray() that would drop percentages to text.
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
     * Build a UTF-8 CSV (with BOM) from already-mapped rows. Always sanitises each row, so no *Csv export
     * can forget the formula-injection guard.
     *
     * @param array<int, string>            $headers
     * @param array<int, array<int, mixed>> $rows
     */
    public function buildCsvExport(array $headers, array $rows): string
    {
        return $this->buildCsvSections([['headers' => $headers, 'rows' => $rows]]);
    }

    /**
     * Multi-section CSV: one UTF-8 BOM (so Excel reads Thai), then each section as an optional title row + header
     * row + sanitised data rows, separated by a blank line. Lets the /reports CSV carry the ticket table AND the
     * analytics blocks (parity with the Excel sheets / screen) while each block stays a clean pivotable table.
     * Each section = ['headers' => string[], 'rows' => array<array>, 'title' => ?string].
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
                fputcsv($stream, []); // blank line between sections
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
     * Render an HTML string to a PDF binary via Dompdf with the shared report defaults: A4 landscape,
     * Thai 'sarabun' font (so Thai text renders instead of tofu boxes), remote assets disabled, and a
     * writable temp dir (falls back to /tmp then storage/uploads when the system temp dir is unusable).
     * Single source of truth for every report PDF exporter — no export path can drift on font/paper/temp.
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
        // โหลดฟอนต์ไทย sarabun จากไฟล์ที่ commit ใน repo (resources/fonts) ไม่พึ่ง vendor ที่ gitignored —
        // มิฉะนั้น fresh deploy (composer install) ไม่มีฟอนต์ → ไทยเป็น tofu. metrics cache ลง temp ที่ write ได้.
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
