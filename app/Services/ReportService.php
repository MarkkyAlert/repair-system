<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\View;
use App\Repositories\ReportRepository;
use DomainException;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class ReportService
{
    public function __construct(private ReportRepository $reports)
    {
    }

    public function getReportPageData(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);

        $normalizedFilters = $this->normalizeReportFilters($filters);
        $reference = $this->reports->getFilterReferenceData();
        $summary = $this->reports->getSummary($viewer, $normalizedFilters);
        $rowLimit = 250;
        $rows = array_map(fn (array $row): array => $this->mapReportRow($row), $this->reports->getRows($viewer, $normalizedFilters, $rowLimit));

        $totalTickets = (int) ($summary['total_tickets'] ?? 0);
        $displayedCount = count($rows);

        return [
            'filters' => $this->buildFilterData($normalizedFilters, $reference),
            'summary' => [
                'total' => $totalTickets,
                'resolved' => (int) ($summary['resolved_tickets'] ?? 0),
                'overdue' => (int) ($summary['overdue_tickets'] ?? 0),
                'avgResolutionHours' => round(((float) ($summary['avg_resolution_minutes'] ?? 0)) / 60, 1),
                'avgRating' => (float) ($summary['avg_rating'] ?? 0),
                'avgRatingLabel' => (float) ($summary['avg_rating'] ?? 0) > 0 ? number_format((float) ($summary['avg_rating'] ?? 0), 1) : '-',
            ],
            'rows' => $rows,
            // The on-screen table is capped; this meta lets the view show an honest count
            // and warn when data is truncated (full data is available via export).
            'rowsMeta' => [
                'displayed' => $displayedCount,
                'total' => max($totalTickets, $displayedCount),
                'limit' => $rowLimit,
                'capped' => $totalTickets > $displayedCount,
            ],
            // ทรัพย์สินที่แจ้งซ่อมบ่อย (ใช้ filter ชุดเดียวกับ report)
            'assetReliability' => array_map(
                fn (array $row): array => $this->mapAssetReliabilityRow($row),
                $this->reports->getAssetReliabilityRows($viewer, $normalizedFilters, self::ASSET_RELIABILITY_LIMIT)
            ),
        ];
    }

    private function mapAssetReliabilityRow(array $row): array
    {
        $avgMinutes = (int) ($row['avg_resolution_minutes'] ?? 0);
        $status = (string) ($row['status'] ?? 'active');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'asset_code' => (string) ($row['asset_code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'category_name' => (string) ($row['category_name'] ?? '-'),
            'location_name' => (string) ($row['location_name'] ?? '-'),
            'status' => $status,
            'status_label' => asset_status_label_th($status),
            'status_tone' => match ($status) {
                'active' => 'success',
                'maintenance' => 'warning',
                'retired', 'disposed' => 'danger',
                default => 'default',
            },
            'failure_count' => (int) ($row['failure_count'] ?? 0),
            'last_failure' => $this->formatDateTime($row['last_failure_at'] ?? null),
            'avg_resolution_hours_label' => $avgMinutes > 0 ? number_format(round($avgMinutes / 60, 1), 1) : '-',
            'detail_url' => '/asset-registry/' . (int) ($row['id'] ?? 0),
        ];
    }

    // Asset reliability panel — จำนวน asset สูงสุดที่จัดอันดับบนหน้า report
    private const ASSET_RELIABILITY_LIMIT = 20;

    // Export size guards — กัน OOM/request timeout เมื่อ ticket เยอะ (PDF หนักสุด, CSV เบาสุด)
    private const EXPORT_MAX_ROWS_XLSX = 10000;
    private const EXPORT_MAX_ROWS_PDF = 3000;
    private const EXPORT_MAX_ROWS_CSV = 50000;

    /**
     * ดึงแถวสำหรับ export แบบมี bound — fetch maxRows+1 เพื่อตรวจ overflow แล้ว throw
     * ให้ผู้ใช้กรองช่วงวันที่/เงื่อนไขให้แคบลง แทนที่จะโหลดทั้งหมดจน OOM/timeout.
     *
     * @return array<int, array<string, mixed>>
     */
    private function exportRows(array $viewer, array $normalizedFilters, int $maxRows): array
    {
        $rows = $this->reports->getRows($viewer, $normalizedFilters, $maxRows + 1);
        if (count($rows) > $maxRows) {
            throw new DomainException('ข้อมูลสำหรับ export มีมากเกิน ' . number_format($maxRows) . ' แถว กรุณากรองช่วงวันที่หรือเงื่อนไขให้แคบลงก่อน export');
        }

        return array_map(fn (array $row): array => $this->mapReportRow($row), $rows);
    }

    public function exportExcel(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);

        $normalizedFilters = $this->normalizeReportFilters($filters);
        $rows = $this->exportRows($viewer, $normalizedFilters, self::EXPORT_MAX_ROWS_XLSX);
        // RISK MAP: Export is triggered by GET but creates an export job; add throttling/idempotency if exports become heavy.
        $jobId = $this->reports->createExportJob((int) ($viewer['id'] ?? 0), 'ticket_report', 'xlsx', $normalizedFilters);
        $fileName = 'ticket-report-' . date('Ymd-His') . '.xlsx';

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('รายงาน Ticket');

            $headers = [
                'เลขที่',
                'หัวข้อ',
                'ผู้แจ้ง',
                'แผนก',
                'หมวดหมู่',
                'ช่างเทคนิค',
                'ความสำคัญ',
                'สถานะ',
                'วันที่แจ้ง',
                'วันที่แก้ไข',
                'เวลาแก้ไข (ชม.)',
                'เกิน SLA',
                'สถานะ SLA',
                'คะแนน',
                'สถานที่',
            ];

            $column = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($column . '1', $header);
                $sheet->getColumnDimension($column)->setAutoSize(true);
                $column++;
            }

            $rowNumber = 2;
            foreach ($rows as $row) {
                $sheet->fromArray($this->sanitizeExportRow([
                    $row['ticket_no'],
                    $row['title'],
                    $row['requester_name'],
                    $row['department_name'],
                    $row['category_name'],
                    $row['technician_name'],
                    $row['priority_label'],
                    $row['status_label'],
                    $row['requested_at'],
                    $row['resolved_at'],
                    $row['resolution_hours_label'],
                    $row['sla_overdue_label'],
                    $row['sla_label'],
                    $row['rating_label'],
                    $row['location_name'],
                ]), null, 'A' . $rowNumber);
                $rowNumber++;
            }

            $writer = new Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            $content = (string) ob_get_clean();
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            $this->reports->markExportJobCompleted($jobId, $fileName);

            return [
                'content' => $content,
                'file_name' => $fileName,
                'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ Excel ได้', 0, $exception);
        }
    }

    public function exportPdf(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);

        $normalizedFilters = $this->normalizeReportFilters($filters);
        $rows = $this->exportRows($viewer, $normalizedFilters, self::EXPORT_MAX_ROWS_PDF);
        $summary = $this->reports->getSummary($viewer, $normalizedFilters);
        // RISK MAP: Export is triggered by GET but creates an export job; add throttling/idempotency if exports become heavy.
        $jobId = $this->reports->createExportJob((int) ($viewer['id'] ?? 0), 'ticket_report', 'pdf', $normalizedFilters);
        $fileName = 'ticket-report-' . date('Ymd-His') . '.pdf';

        try {
            $html = View::capture('reports/pdf', [
                'generatedAt' => date('d/m/Y H:i'),
                'summary' => [
                    'total' => (int) ($summary['total_tickets'] ?? 0),
                    'resolved' => (int) ($summary['resolved_tickets'] ?? 0),
                    'overdue' => (int) ($summary['overdue_tickets'] ?? 0),
                    'avgResolutionHours' => round(((float) ($summary['avg_resolution_minutes'] ?? 0)) / 60, 1),
                    'avgRatingLabel' => (float) ($summary['avg_rating'] ?? 0) > 0 ? number_format((float) ($summary['avg_rating'] ?? 0), 1) : '-',
                ],
                'rows' => $rows,
                'filters' => $this->describeFilters($normalizedFilters, $this->reports->getFilterReferenceData()),
            ]);

            $options = new Options();
            // Writable, portable temp dir for Dompdf. sys_get_temp_dir() can be empty/non-writable under
            // macOS Apache; /tmp is world-writable on Linux + macOS. Fall back to an app-writable dir last.
            $dompdfTmp = sys_get_temp_dir();
            if ($dompdfTmp === '' || !@is_writable($dompdfTmp)) {
                $dompdfTmp = is_dir('/tmp') ? '/tmp' : BASE_PATH . '/storage/uploads';
            }
            $options->setTempDir($dompdfTmp);
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'sarabun');
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            $content = $dompdf->output();

            $this->reports->markExportJobCompleted($jobId, $fileName);

            return [
                'content' => $content,
                'file_name' => $fileName,
                'content_type' => 'application/pdf',
            ];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ PDF ได้', 0, $exception);
        }
    }

    public function exportCsv(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeReportFilters($filters);
        $rows = $this->exportRows($viewer, $normalizedFilters, self::EXPORT_MAX_ROWS_CSV);
        // RISK MAP: Export is triggered by GET but creates an export job; add throttling/idempotency if exports become heavy.
        $jobId = $this->reports->createExportJob((int) ($viewer['id'] ?? 0), 'ticket_report', 'csv', $normalizedFilters);
        $fileName = 'ticket-report-' . date('Ymd-His') . '.csv';

        try {
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new RuntimeException('ไม่สามารถเตรียมไฟล์ CSV ได้');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['เลขที่', 'หัวข้อ', 'ผู้แจ้ง', 'แผนก', 'หมวดหมู่', 'ช่างเทคนิค', 'ความสำคัญ', 'สถานะ', 'วันที่แจ้ง', 'วันที่แก้ไข', 'เวลาแก้ไข (ชม.)', 'เกิน SLA', 'สถานะ SLA', 'คะแนน', 'สถานที่']);
            foreach ($rows as $row) {
                fputcsv($stream, $this->sanitizeExportRow([
                    $row['ticket_no'], $row['title'], $row['requester_name'], $row['department_name'],
                    $row['category_name'], $row['technician_name'], $row['priority_label'], $row['status_label'],
                    $row['requested_at'], $row['resolved_at'], $row['resolution_hours_label'],
                    $row['sla_overdue_label'], $row['sla_label'], $row['rating_label'], $row['location_name'],
                ]));
            }
            rewind($stream);
            $content = (string) stream_get_contents($stream);
            fclose($stream);
            $this->reports->markExportJobCompleted($jobId, $fileName);

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'text/csv; charset=UTF-8'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ CSV ได้', 0, $exception);
        }
    }

    private function recordExportFailure(int $jobId, \Throwable $exception): void
    {
        try {
            $this->reports->markExportJobFailed($jobId, $exception->getMessage());
        } catch (\Throwable) {
            // Preserve the original export error if failure logging also fails.
        }
    }

    private function sanitizeExportRow(array $values): array
    {
        return array_map(fn (mixed $value): string => $this->sanitizeExportCell($value), $values);
    }

    private function sanitizeExportCell(mixed $value): string
    {
        $cell = (string) $value;
        $trimmed = ltrim($cell);

        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'" . $cell;
        }

        return $cell;
    }

    private function ensureCanViewReports(array $viewer): void
    {
        if (!is_manager_or_admin((string) ($viewer['role'] ?? 'guest'))) {
            throw new DomainException('คุณไม่มีสิทธิ์เข้าถึงรายงาน');
        }
    }

    private function normalizeReportFilters(array $filters): array
    {
        $fromDate = is_string($filters['from_date'] ?? null) ? trim((string) $filters['from_date']) : '';
        $toDate = is_string($filters['to_date'] ?? null) ? trim((string) $filters['to_date']) : '';
        $status = is_string($filters['status'] ?? null) ? trim((string) $filters['status']) : '';
        $allowedStatuses = ticket_status_values();

        return normalize_date_range($fromDate, $toDate) + [
            'department_id' => max(0, (int) ($filters['department_id'] ?? 0)),
            'category_id' => max(0, (int) ($filters['category_id'] ?? 0)),
            'status' => in_array($status, $allowedStatuses, true) ? $status : '',
        ];
    }

    private function buildFilterData(array $filters, array $reference): array
    {
        $queryFilters = array_filter([
            'from_date' => (string) ($filters['from_date'] ?? ''),
            'to_date' => (string) ($filters['to_date'] ?? ''),
            'department_id' => (int) ($filters['department_id'] ?? 0) > 0 ? (string) ($filters['department_id'] ?? '') : '',
            'category_id' => (int) ($filters['category_id'] ?? 0) > 0 ? (string) ($filters['category_id'] ?? '') : '',
            'status' => (string) ($filters['status'] ?? ''),
        ], static fn (string $value): bool => $value !== '');

        return [
            'selected' => [
                'from_date' => (string) ($filters['from_date'] ?? ''),
                'to_date' => (string) ($filters['to_date'] ?? ''),
                'department_id' => (string) ($filters['department_id'] ?? ''),
                'category_id' => (string) ($filters['category_id'] ?? ''),
                'status' => (string) ($filters['status'] ?? ''),
            ],
            'departmentOptions' => array_merge([
                ['value' => '', 'label' => 'ทุกแผนก'],
            ], array_map(fn (array $row): array => [
                'value' => (string) ($row['id'] ?? 0),
                'label' => (string) ($row['name'] ?? '-'),
            ], $reference['departments'] ?? [])),
            'categoryOptions' => array_merge([
                ['value' => '', 'label' => 'ทุกหมวด'],
            ], array_map(fn (array $row): array => [
                'value' => (string) ($row['id'] ?? 0),
                'label' => (string) ($row['name'] ?? '-'),
            ], $reference['categories'] ?? [])),
            'statusOptions' => ticket_status_options(true),
            'active_count' => $this->countActiveFilters($filters),
            'query_string' => http_build_query($queryFilters),
        ];
    }

    private function describeFilters(array $filters, array $reference): array
    {
        $departmentName = 'ทุกแผนก';
        foreach (($reference['departments'] ?? []) as $department) {
            if ((int) ($department['id'] ?? 0) === (int) ($filters['department_id'] ?? 0)) {
                $departmentName = (string) ($department['name'] ?? $departmentName);
                break;
            }
        }

        $categoryName = 'ทุกหมวด';
        foreach (($reference['categories'] ?? []) as $category) {
            if ((int) ($category['id'] ?? 0) === (int) ($filters['category_id'] ?? 0)) {
                $categoryName = (string) ($category['name'] ?? $categoryName);
                break;
            }
        }

        return [
            'from_date' => (string) ($filters['from_date'] ?? '') !== '' ? (string) ($filters['from_date'] ?? '') : 'ไม่ระบุ',
            'to_date' => (string) ($filters['to_date'] ?? '') !== '' ? (string) ($filters['to_date'] ?? '') : 'ไม่ระบุ',
            'department' => $departmentName,
            'category' => $categoryName,
            'status' => (string) ($filters['status'] ?? '') !== '' ? ticket_status_label_th((string) ($filters['status'] ?? '')) : 'ทุกสถานะ',
        ];
    }

    private function mapReportRow(array $row): array
    {
        $sla = $this->buildSlaSummary($row);
        $resolutionMinutes = isset($row['resolution_minutes']) ? (int) $row['resolution_minutes'] : 0;
        $ratingScore = (int) ($row['rating_score'] ?? 0);
        $status = (string) ($row['status'] ?? 'submitted');
        $priorityCode = strtoupper((string) ($row['priority_code'] ?? 'MEDIUM'));
        $isOverdue = (bool) ($sla['is_overdue'] ?? false);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'ticket_no' => (string) ($row['ticket_no'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'requester_name' => (string) ($row['requester_name'] ?? '-'),
            'department_name' => (string) ($row['department_name'] ?? '-'),
            'category_name' => (string) ($row['category_name'] ?? '-'),
            'technician_name' => (string) ($row['technician_name'] ?? '-'),
            // Thai labels — shared by both the on-screen table and the Excel/PDF/CSV exporters.
            'priority_label' => in_array($priorityCode, ['LOW', 'MEDIUM', 'HIGH', 'URGENT'], true)
                ? priority_label_th($priorityCode)
                : (string) ($row['priority_name'] ?? $priorityCode),
            'status' => $status,
            'status_label' => ticket_status_label_th($status),
            'requested_at' => $this->formatDateTime($row['requested_at'] ?? null),
            'resolved_at' => $this->formatDateTime($row['resolved_at'] ?? null),
            'resolution_hours' => $resolutionMinutes > 0 ? round($resolutionMinutes / 60, 1) : 0,
            'resolution_hours_label' => $resolutionMinutes > 0 ? number_format(round($resolutionMinutes / 60, 1), 1) : '-',
            'sla_label' => (string) ($sla['label'] ?? '-'),
            'sla_overdue' => $isOverdue,
            'sla_overdue_label' => $isOverdue ? 'ใช่' : 'ไม่ใช่',
            'rating_score' => $ratingScore,
            'rating_label' => $ratingScore > 0 ? (string) $ratingScore : '-',
            'location_name' => (string) ($row['location_name'] ?? '-'),
            'detail_url' => '/tickets/' . (int) ($row['id'] ?? 0),
        ];
    }

    private function buildSlaSummary(array $ticket): array
    {
        if ((string) ($ticket['status'] ?? '') === 'cancelled') {
            return [
                'label' => 'ไม่คิด SLA',
                'is_overdue' => false,
            ];
        }

        $response = $this->buildSlaMetricState($ticket['response_due_at'] ?? null, $ticket['first_response_at'] ?? null);
        $resolution = $this->buildSlaMetricState($ticket['resolution_due_at'] ?? null, $ticket['resolved_at'] ?? null);
        $isOverdue = ($response['status'] ?? '') === 'breached' || ($resolution['status'] ?? '') === 'breached' || (bool) ($ticket['is_overdue'] ?? false);

        if (($resolution['status'] ?? '') === 'breached') {
            $label = 'แก้ไขเกินกำหนด';
        } elseif (($response['status'] ?? '') === 'breached') {
            $label = 'ตอบรับเกินกำหนด';
        } elseif (($response['status'] ?? '') === 'met' && ($resolution['status'] ?? '') === 'met') {
            $label = 'อยู่ใน SLA';
        } elseif (($response['status'] ?? '') === 'pending') {
            $label = 'รอตอบรับ';
        } elseif (($resolution['status'] ?? '') === 'pending') {
            $label = 'รอแก้ไข';
        } else {
            $label = 'กำลังติดตาม SLA';
        }

        return [
            'label' => $label,
            'is_overdue' => $isOverdue,
        ];
    }

    private function buildSlaMetricState(mixed $targetAt, mixed $achievedAt): array
    {
        $target = is_string($targetAt) ? trim($targetAt) : '';
        $achieved = is_string($achievedAt) ? trim($achievedAt) : '';
        $targetTimestamp = $target !== '' ? strtotime($target) : false;
        $achievedTimestamp = $achieved !== '' ? strtotime($achieved) : false;

        if ($targetTimestamp === false) {
            return ['status' => 'unavailable'];
        }

        if ($achievedTimestamp !== false) {
            return ['status' => $achievedTimestamp > $targetTimestamp ? 'breached' : 'met'];
        }

        return ['status' => $targetTimestamp < time() ? 'breached' : 'pending'];
    }

    private function countActiveFilters(array $filters): int
    {
        $count = 0;

        foreach (['from_date', 'to_date', 'department_id', 'category_id', 'status'] as $key) {
            $value = $filters[$key] ?? '';
            if (is_string($value) && trim($value) !== '') {
                $count++;
                continue;
            }

            if (is_int($value) && $value > 0) {
                $count++;
            }
        }

        return $count;
    }

    private function formatDateTime(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '-';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return (string) $value;
        }

        return date('d/m/Y H:i', $timestamp);
    }
}
