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
        $rows = array_map(fn (array $row): array => $this->mapReportRow($row), $this->reports->getRows($viewer, $normalizedFilters, 250));

        return [
            'filters' => $this->buildFilterData($normalizedFilters, $reference),
            'summary' => [
                'total' => (int) ($summary['total_tickets'] ?? 0),
                'resolved' => (int) ($summary['resolved_tickets'] ?? 0),
                'overdue' => (int) ($summary['overdue_tickets'] ?? 0),
                'avgResolutionHours' => round(((float) ($summary['avg_resolution_minutes'] ?? 0)) / 60, 1),
                'avgRating' => (float) ($summary['avg_rating'] ?? 0),
                'avgRatingLabel' => (float) ($summary['avg_rating'] ?? 0) > 0 ? number_format((float) ($summary['avg_rating'] ?? 0), 1) : '-',
            ],
            'rows' => $rows,
        ];
    }

    public function exportExcel(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);

        $normalizedFilters = $this->normalizeReportFilters($filters);
        $rows = array_map(fn (array $row): array => $this->mapReportRow($row), $this->reports->getRows($viewer, $normalizedFilters, null));
        $jobId = $this->reports->createExportJob((int) ($viewer['id'] ?? 0), 'ticket_report', 'xlsx', $normalizedFilters);
        $fileName = 'ticket-report-' . date('Ymd-His') . '.xlsx';

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Ticket Report');

            $headers = [
                'Ticket No',
                'Title',
                'Requester',
                'Department',
                'Category',
                'Technician',
                'Priority',
                'Status',
                'Requested At',
                'Resolved At',
                'Resolution Hours',
                'เกิน SLA',
                'SLA Status',
                'Rating',
                'Location',
            ];

            $column = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($column . '1', $header);
                $sheet->getColumnDimension($column)->setAutoSize(true);
                $column++;
            }

            $rowNumber = 2;
            foreach ($rows as $row) {
                $sheet->fromArray([
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
                ], null, 'A' . $rowNumber);
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
        $rows = array_map(fn (array $row): array => $this->mapReportRow($row), $this->reports->getRows($viewer, $normalizedFilters, null));
        $summary = $this->reports->getSummary($viewer, $normalizedFilters);
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
            $options->setTempDir('/private/tmp');
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
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

    private function recordExportFailure(int $jobId, \Throwable $exception): void
    {
        try {
            $this->reports->markExportJobFailed($jobId, $exception->getMessage());
        } catch (\Throwable) {
            // Preserve the original export error if failure logging also fails.
        }
    }

    private function ensureCanViewReports(array $viewer): void
    {
        if (!in_array((string) ($viewer['role'] ?? 'guest'), ['manager', 'admin'], true)) {
            throw new DomainException('คุณไม่มีสิทธิ์เข้าถึงรายงาน');
        }
    }

    private function normalizeReportFilters(array $filters): array
    {
        $fromDate = is_string($filters['from_date'] ?? null) ? trim((string) $filters['from_date']) : '';
        $toDate = is_string($filters['to_date'] ?? null) ? trim((string) $filters['to_date']) : '';
        $status = is_string($filters['status'] ?? null) ? trim((string) $filters['status']) : '';
        $allowedStatuses = [
            'submitted',
            'pending_approval',
            'approved',
            'assigned',
            'accepted',
            'in_progress',
            'on_hold',
            'resolved',
            'completed',
            'rejected',
            'cancelled',
            'closed',
        ];

        $normalized = [
            'from_date' => $this->normalizeDateInput($fromDate),
            'to_date' => $this->normalizeDateInput($toDate),
            'from_datetime' => '',
            'to_datetime' => '',
            'department_id' => max(0, (int) ($filters['department_id'] ?? 0)),
            'category_id' => max(0, (int) ($filters['category_id'] ?? 0)),
            'status' => in_array($status, $allowedStatuses, true) ? $status : '',
        ];

        if ($normalized['from_date'] !== '') {
            $normalized['from_datetime'] = $normalized['from_date'] . ' 00:00:00';
        }

        if ($normalized['to_date'] !== '') {
            $normalized['to_datetime'] = $normalized['to_date'] . ' 23:59:59';
        }

        if ($normalized['from_date'] !== '' && $normalized['to_date'] !== '' && strcmp($normalized['from_date'], $normalized['to_date']) > 0) {
            [$normalized['from_date'], $normalized['to_date']] = [$normalized['to_date'], $normalized['from_date']];
            [$normalized['from_datetime'], $normalized['to_datetime']] = [$normalized['to_date'] . ' 00:00:00', $normalized['from_date'] . ' 23:59:59'];
        }

        return $normalized;
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
            'statusOptions' => array_merge([
                ['value' => '', 'label' => 'ทุกสถานะ'],
            ], $this->enumOptions([
                'submitted',
                'pending_approval',
                'approved',
                'assigned',
                'accepted',
                'in_progress',
                'on_hold',
                'resolved',
                'completed',
                'rejected',
                'cancelled',
                'closed',
            ])),
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
            'status' => (string) ($filters['status'] ?? '') !== '' ? $this->labelize((string) ($filters['status'] ?? '')) : 'ทุกสถานะ',
        ];
    }

    private function mapReportRow(array $row): array
    {
        $sla = $this->buildSlaSummary($row);
        $resolutionMinutes = isset($row['resolution_minutes']) ? (int) $row['resolution_minutes'] : 0;
        $ratingScore = (int) ($row['rating_score'] ?? 0);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'ticket_no' => (string) ($row['ticket_no'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'requester_name' => (string) ($row['requester_name'] ?? '-'),
            'department_name' => (string) ($row['department_name'] ?? '-'),
            'category_name' => (string) ($row['category_name'] ?? '-'),
            'technician_name' => (string) ($row['technician_name'] ?? '-'),
            'priority_label' => (string) ($row['priority_name'] ?? strtoupper((string) ($row['priority_code'] ?? 'MEDIUM'))),
            'status' => (string) ($row['status'] ?? 'submitted'),
            'status_label' => $this->labelize((string) ($row['status'] ?? 'submitted')),
            'requested_at' => $this->formatDateTime($row['requested_at'] ?? null),
            'resolved_at' => $this->formatDateTime($row['resolved_at'] ?? null),
            'resolution_hours' => $resolutionMinutes > 0 ? round($resolutionMinutes / 60, 1) : 0,
            'resolution_hours_label' => $resolutionMinutes > 0 ? number_format(round($resolutionMinutes / 60, 1), 1) : '-',
            'sla_label' => (string) ($sla['label'] ?? '-'),
            'sla_overdue' => (bool) ($sla['is_overdue'] ?? false),
            'sla_overdue_label' => !empty($sla['is_overdue']) ? 'Yes' : 'No',
            'rating_score' => $ratingScore,
            'rating_label' => $ratingScore > 0 ? (string) $ratingScore : '-',
            'location_name' => (string) ($row['location_name'] ?? '-'),
            'detail_url' => '/tickets/' . (int) ($row['id'] ?? 0),
        ];
    }

    private function buildSlaSummary(array $ticket): array
    {
        $response = $this->buildSlaMetricState($ticket['response_due_at'] ?? null, $ticket['first_response_at'] ?? null);
        $resolution = $this->buildSlaMetricState($ticket['resolution_due_at'] ?? null, $ticket['resolved_at'] ?? null);
        $isOverdue = ($response['status'] ?? '') === 'breached' || ($resolution['status'] ?? '') === 'breached' || (bool) ($ticket['is_overdue'] ?? false);

        if (($resolution['status'] ?? '') === 'breached') {
            $label = 'Resolution overdue';
        } elseif (($response['status'] ?? '') === 'breached') {
            $label = 'Response overdue';
        } elseif (($response['status'] ?? '') === 'met' && ($resolution['status'] ?? '') === 'met') {
            $label = 'Within SLA';
        } elseif (($response['status'] ?? '') === 'pending') {
            $label = 'Response pending';
        } elseif (($resolution['status'] ?? '') === 'pending') {
            $label = 'Resolution pending';
        } else {
            $label = 'SLA tracked';
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

    private function normalizeDateInput(string $value): string
    {
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
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

    private function enumOptions(array $values): array
    {
        return array_map(fn (string $value): array => [
            'value' => $value,
            'label' => $this->labelize($value),
        ], $values);
    }

    private function labelize(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '-';
        }

        return ucwords(str_replace('_', ' ', $normalized));
    }
}
