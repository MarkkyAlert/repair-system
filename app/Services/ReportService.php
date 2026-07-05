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
        ] + $this->collectAnalytics($viewer, $normalizedFilters);
    }

    /**
     * รวม analytics ทั้ง 4 (asset reliability / SLA compliance / ผลงานช่าง / ชั่วโมงแรงงาน) จาก filter
     * ชุดเดียว — ใช้ร่วมทั้งหน้าจอ (getReportPageData) และ export (PDF/Excel) เพื่อให้ตรงกันเสมอ.
     */
    private function collectAnalytics(array $viewer, array $normalizedFilters): array
    {
        return [
            'assetReliability' => array_map(
                fn (array $row): array => $this->mapAssetReliabilityRow($row),
                $this->reports->getAssetReliabilityRows($viewer, $normalizedFilters, self::ASSET_RELIABILITY_LIMIT)
            ),
            'slaCompliance' => $this->buildSlaCompliance(
                $this->reports->getSlaComplianceByPriority($viewer, $normalizedFilters)
            ),
            'technicianPerformance' => array_map(
                fn (array $row): array => $this->mapTechnicianRow($row),
                $this->reports->getTechnicianPerformance($viewer, $normalizedFilters, self::TECHNICIAN_LIMIT)
            ),
            'laborEffort' => $this->buildLaborEffort(
                $this->reports->getLaborByCategory($viewer, $normalizedFilters)
            ),
        ];
    }

    // ===== Asset Reliability Report (หน้าแยกเต็มตัว /reports/asset-reliability) =====

    // แสดงทุก asset ที่มีประวัติแจ้งซ่อมในช่วงกรอง (ไม่จำกัด 20 อันดับแบบ panel ท้าย /reports)
    private const ASSET_REPORT_LIMIT = 500;

    // เกณฑ์ให้คะแนนสุขภาพ (heuristic, ปรับได้) — รวมคะแนนแล้วจัด bucket ควรเปลี่ยน/เฝ้าระวัง/ปกติ
    private const HEALTH_FAILURE_HIGH = 5;   // เสีย ≥ ครั้งนี้ = +2
    private const HEALTH_FAILURE_MED = 3;    // เสีย ≥ ครั้งนี้ = +1
    private const HEALTH_AGE_HIGH = 8;       // อายุ ≥ ปีนี้ = +2
    private const HEALTH_AGE_MED = 5;        // อายุ ≥ ปีนี้ = +1
    private const HEALTH_MTBF_SHORT_DAYS = 30;   // MTBF < นี้ (≥2 ครั้ง) = +1
    private const HEALTH_SLOW_RESOLUTION_HOURS = 8; // ซ่อมเฉลี่ย ≥ นี้ = +1
    private const HEALTH_REPLACE_SCORE = 4;  // ≥ นี้ = ควรเปลี่ยน
    private const HEALTH_WATCH_SCORE = 2;    // ≥ นี้ = เฝ้าระวัง

    public function getAssetReliabilityReportPage(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);

        $normalizedFilters = $this->normalizeAssetReportFilters($filters);
        $reference = $this->reports->getAssetReportReferenceData();
        $rows = $this->collectAssetReportRows($viewer, $normalizedFilters, self::ASSET_REPORT_LIMIT);

        return [
            'filters' => $this->buildAssetReportFilterData($normalizedFilters, $reference),
            'summary' => $this->buildAssetReportSummary($rows),
            'rows' => $rows,
            'rowsMeta' => [
                'displayed' => count($rows),
                'limit' => self::ASSET_REPORT_LIMIT,
                'capped' => count($rows) >= self::ASSET_REPORT_LIMIT,
            ],
        ];
    }

    /** ดึง + map + เรียง (สุขภาพแย่สุดขึ้นบน = จุดขาย). ใช้ร่วมทั้งหน้าจอและ export ให้ตรงกันเสมอ. */
    private function collectAssetReportRows(array $viewer, array $normalizedFilters, int $limit): array
    {
        $rows = array_map(
            fn (array $row): array => $this->mapAssetReliabilityReportRow($row),
            $this->reports->getAssetReliabilityReport($viewer, $normalizedFilters, $limit)
        );

        usort($rows, static fn (array $a, array $b): int => [$b['health_score'], $b['failure_count']] <=> [$a['health_score'], $a['failure_count']]);

        return $rows;
    }

    private function buildAssetReportSummary(array $rows): array
    {
        $replace = 0;
        $watch = 0;
        $downtimeMinutes = 0;
        $laborMinutes = 0;
        foreach ($rows as $row) {
            $replace += $row['health_tone'] === 'danger' ? 1 : 0;
            $watch += $row['health_tone'] === 'warning' ? 1 : 0;
            $downtimeMinutes += (int) $row['downtime_minutes'];
            $laborMinutes += (int) $row['labor_minutes'];
        }

        return [
            'assets' => count($rows),
            'replace' => $replace,
            'watch' => $watch,
            'downtimeHoursLabel' => number_format(round($downtimeMinutes / 60, 1), 1),
            'laborHoursLabel' => number_format(round($laborMinutes / 60, 1), 1),
        ];
    }

    private function mapAssetReliabilityReportRow(array $row): array
    {
        $status = (string) ($row['status'] ?? 'active');
        $failureCount = (int) ($row['failure_count'] ?? 0);
        // single-rounding pipeline (float) เหมือน summary/MTTR — [[report-avg-time-rounding]]
        $avgMinutes = (float) ($row['avg_resolution_minutes'] ?? 0);
        $avgHours = round($avgMinutes / 60, 1);
        $downtimeMinutes = (int) ($row['downtime_minutes'] ?? 0);
        $laborMinutes = (int) ($row['labor_minutes'] ?? 0);

        $ageYears = $this->yearsSince($row['purchase_date'] ?? null);
        $warranty = $this->warrantyState($row['warranty_expires_at'] ?? null);
        $mtbfDays = $this->meanTimeBetweenFailures($row['first_failure_at'] ?? null, $row['last_failure_at'] ?? null, $failureCount);

        $health = $this->scoreAssetHealth([
            'failure_count' => $failureCount,
            'warranty_status' => $warranty['status'],
            'age_years' => $ageYears,
            'mtbf_days' => $mtbfDays,
            'avg_resolution_hours' => $avgHours,
            'status' => $status,
        ]);

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
            'failure_count' => $failureCount,
            'last_failure' => $this->formatDateTime($row['last_failure_at'] ?? null),
            'mtbf_days_label' => $mtbfDays !== null ? number_format(round($mtbfDays, 0), 0) . ' วัน' : '-',
            'avg_resolution_hours_label' => $avgMinutes > 0 ? number_format($avgHours, 1) : '-',
            'downtime_minutes' => $downtimeMinutes,
            'downtime_hours_label' => $downtimeMinutes > 0 ? number_format(round($downtimeMinutes / 60, 1), 1) : '-',
            'labor_minutes' => $laborMinutes,
            'labor_hours_label' => $laborMinutes > 0 ? number_format(round($laborMinutes / 60, 1), 1) : '-',
            'age_label' => $ageYears !== null ? number_format($ageYears, 1) . ' ปี' : '-',
            'warranty_label' => $warranty['label'],
            'warranty_tone' => $warranty['tone'],
            'health_score' => $health['score'],
            'health_label' => $health['label'],
            'health_tone' => $health['tone'],
            'health_reason' => $health['reason'],
            'detail_url' => '/asset-registry/' . (int) ($row['id'] ?? 0),
        ];
    }

    /**
     * คะแนนสุขภาพแบบ heuristic — ระบบไม่มีข้อมูลต้นทุนซ่อม จึงใช้สัญญาณเชิงพฤติกรรม (ความถี่เสีย + อายุ +
     * ประกัน + MTBF + เวลาซ่อม + สถานะ) แล้วจัด bucket. คืน reason อธิบายว่าทำไมให้ผู้ใช้ตัดสินใจต่อได้.
     */
    private function scoreAssetHealth(array $m): array
    {
        $score = 0;
        $reasons = [];

        $failures = (int) $m['failure_count'];
        if ($failures >= self::HEALTH_FAILURE_HIGH) {
            $score += 2;
        } elseif ($failures >= self::HEALTH_FAILURE_MED) {
            $score += 1;
        }
        if ($failures >= self::HEALTH_FAILURE_MED) {
            $reasons[] = 'เสีย ' . $failures . ' ครั้ง';
        }

        if (($m['warranty_status'] ?? '') === 'expired') {
            $score += 1;
            $reasons[] = 'หมดประกัน';
        }

        $age = $m['age_years'];
        if ($age !== null && $age >= self::HEALTH_AGE_HIGH) {
            $score += 2;
            $reasons[] = 'อายุ ' . number_format($age, 0) . ' ปี';
        } elseif ($age !== null && $age >= self::HEALTH_AGE_MED) {
            $score += 1;
            $reasons[] = 'อายุ ' . number_format($age, 0) . ' ปี';
        }

        $mtbf = $m['mtbf_days'];
        if ($mtbf !== null && $failures >= 2 && $mtbf < self::HEALTH_MTBF_SHORT_DAYS) {
            $score += 1;
            $reasons[] = 'เสียถี่ (ทุก ~' . number_format(round($mtbf, 0), 0) . ' วัน)';
        }

        if ((float) $m['avg_resolution_hours'] >= self::HEALTH_SLOW_RESOLUTION_HOURS) {
            $score += 1;
            $reasons[] = 'ซ่อมนานเฉลี่ย ' . number_format((float) $m['avg_resolution_hours'], 1) . ' ชม.';
        }

        if (($m['status'] ?? '') === 'maintenance') {
            $score += 1;
            $reasons[] = 'กำลังซ่อมอยู่';
        }

        if ($score >= self::HEALTH_REPLACE_SCORE) {
            $label = 'ควรเปลี่ยน';
            $tone = 'danger';
        } elseif ($score >= self::HEALTH_WATCH_SCORE) {
            $label = 'เฝ้าระวัง';
            $tone = 'warning';
        } else {
            $label = 'ปกติ';
            $tone = 'success';
        }

        return [
            'score' => $score,
            'label' => $label,
            'tone' => $tone,
            'reason' => $reasons === [] ? 'ไม่มีสัญญาณเสี่ยง' : implode(', ', $reasons),
        ];
    }

    /** อายุเป็นปี (ทศนิยม) จากวันซื้อ — คืน null ถ้าไม่มี/รูปแบบผิด/อยู่ในอนาคต. */
    private function yearsSince(mixed $date): ?float
    {
        if (!is_string($date) || trim($date) === '') {
            return null;
        }
        $timestamp = strtotime((string) $date);
        if ($timestamp === false || $timestamp > time()) {
            return null;
        }

        return round((time() - $timestamp) / (365.25 * 86400), 1);
    }

    /** สถานะประกัน: expired (เลยวันหมด) / valid (ยังไม่หมด) / unknown (ไม่ระบุ). */
    private function warrantyState(mixed $expiresAt): array
    {
        if (!is_string($expiresAt) || trim($expiresAt) === '') {
            return ['status' => 'unknown', 'label' => 'ไม่ระบุ', 'tone' => 'default'];
        }
        $timestamp = strtotime((string) $expiresAt);
        if ($timestamp === false) {
            return ['status' => 'unknown', 'label' => 'ไม่ระบุ', 'tone' => 'default'];
        }

        return $timestamp < time()
            ? ['status' => 'expired', 'label' => 'หมดประกัน', 'tone' => 'warning']
            : ['status' => 'valid', 'label' => 'ในประกัน', 'tone' => 'success'];
    }

    /** MTBF = ช่วงเวลาระหว่างครั้งแรกกับครั้งล่าสุด หารด้วย (จำนวนครั้ง−1). null ถ้าเสีย < 2 ครั้ง. */
    private function meanTimeBetweenFailures(mixed $firstAt, mixed $lastAt, int $failureCount): ?float
    {
        if ($failureCount < 2 || !is_string($firstAt) || !is_string($lastAt)) {
            return null;
        }
        $first = strtotime((string) $firstAt);
        $last = strtotime((string) $lastAt);
        if ($first === false || $last === false || $last < $first) {
            return null;
        }

        return (($last - $first) / 86400) / ($failureCount - 1);
    }

    private function normalizeAssetReportFilters(array $filters): array
    {
        $fromDate = is_string($filters['from_date'] ?? null) ? trim((string) $filters['from_date']) : '';
        $toDate = is_string($filters['to_date'] ?? null) ? trim((string) $filters['to_date']) : '';
        $status = is_string($filters['asset_status'] ?? null) ? trim((string) $filters['asset_status']) : '';
        $allowedStatuses = ['active', 'maintenance', 'retired', 'disposed'];

        return normalize_date_range($fromDate, $toDate) + [
            'asset_category_id' => max(0, (int) ($filters['asset_category_id'] ?? 0)),
            'location_id' => max(0, (int) ($filters['location_id'] ?? 0)),
            'asset_status' => in_array($status, $allowedStatuses, true) ? $status : '',
        ];
    }

    private function buildAssetReportFilterData(array $filters, array $reference): array
    {
        $queryFilters = array_filter([
            'from_date' => (string) ($filters['from_date'] ?? ''),
            'to_date' => (string) ($filters['to_date'] ?? ''),
            'asset_category_id' => (int) ($filters['asset_category_id'] ?? 0) > 0 ? (string) ($filters['asset_category_id'] ?? '') : '',
            'location_id' => (int) ($filters['location_id'] ?? 0) > 0 ? (string) ($filters['location_id'] ?? '') : '',
            'asset_status' => (string) ($filters['asset_status'] ?? ''),
        ], static fn (string $value): bool => $value !== '');

        return [
            'selected' => [
                'from_date' => (string) ($filters['from_date'] ?? ''),
                'to_date' => (string) ($filters['to_date'] ?? ''),
                'asset_category_id' => (string) ($filters['asset_category_id'] ?? ''),
                'location_id' => (string) ($filters['location_id'] ?? ''),
                'asset_status' => (string) ($filters['asset_status'] ?? ''),
            ],
            'categoryOptions' => array_merge([
                ['value' => '', 'label' => 'ทุกหมวดหมู่'],
            ], array_map(static fn (array $row): array => [
                'value' => (string) ($row['id'] ?? 0),
                'label' => (string) ($row['name'] ?? '-'),
            ], $reference['categories'] ?? [])),
            'locationOptions' => array_merge([
                ['value' => '', 'label' => 'ทุกสถานที่'],
            ], array_map(static fn (array $row): array => [
                'value' => (string) ($row['id'] ?? 0),
                'label' => (string) ($row['name'] ?? '-'),
            ], $reference['locations'] ?? [])),
            'statusOptions' => array_merge([
                ['value' => '', 'label' => 'ทุกสถานะ'],
            ], array_map(static fn (string $value): array => [
                'value' => $value,
                'label' => asset_status_label_th($value),
            ], ['active', 'maintenance', 'retired', 'disposed'])),
            'active_count' => ((int) ($filters['asset_category_id'] ?? 0) > 0 ? 1 : 0)
                + ((int) ($filters['location_id'] ?? 0) > 0 ? 1 : 0)
                + ((string) ($filters['asset_status'] ?? '') !== '' ? 1 : 0)
                + ((string) ($filters['from_date'] ?? '') !== '' ? 1 : 0)
                + ((string) ($filters['to_date'] ?? '') !== '' ? 1 : 0),
            'query_string' => http_build_query($queryFilters),
        ];
    }

    private function describeAssetReportFilters(array $filters, array $reference): array
    {
        $categoryName = 'ทุกหมวดหมู่';
        foreach (($reference['categories'] ?? []) as $category) {
            if ((int) ($category['id'] ?? 0) === (int) ($filters['asset_category_id'] ?? 0)) {
                $categoryName = (string) ($category['name'] ?? $categoryName);
                break;
            }
        }

        $locationName = 'ทุกสถานที่';
        foreach (($reference['locations'] ?? []) as $location) {
            if ((int) ($location['id'] ?? 0) === (int) ($filters['location_id'] ?? 0)) {
                $locationName = (string) ($location['name'] ?? $locationName);
                break;
            }
        }

        return [
            'from_date' => (string) ($filters['from_date'] ?? '') !== '' ? (string) ($filters['from_date'] ?? '') : 'ไม่ระบุ',
            'to_date' => (string) ($filters['to_date'] ?? '') !== '' ? (string) ($filters['to_date'] ?? '') : 'ไม่ระบุ',
            'category' => $categoryName,
            'location' => $locationName,
            'status' => (string) ($filters['asset_status'] ?? '') !== '' ? asset_status_label_th((string) ($filters['asset_status'] ?? '')) : 'ทุกสถานะ',
        ];
    }

    /** header ของ export (ใช้ร่วม CSV/Excel/PDF ให้คอลัมน์ตรงกัน). */
    private function assetReportExportHeaders(): array
    {
        return [
            'รหัส', 'ชื่อ', 'หมวดหมู่', 'สถานที่', 'สถานะ', 'สุขภาพ', 'เหตุผล', 'จำนวนครั้ง',
            'ครั้งล่าสุด', 'MTBF (วัน)', 'เวลาซ่อมเฉลี่ย (ชม.)', 'Downtime (ชม.)', 'ชม.แรงงาน', 'อายุ (ปี)', 'ประกัน',
        ];
    }

    private function assetReportExportRow(array $row): array
    {
        return [
            $row['asset_code'], $row['name'], $row['category_name'], $row['location_name'], $row['status_label'],
            $row['health_label'], $row['health_reason'], $row['failure_count'], $row['last_failure'],
            $row['mtbf_days_label'], $row['avg_resolution_hours_label'], $row['downtime_hours_label'],
            $row['labor_hours_label'], $row['age_label'], $row['warranty_label'],
        ];
    }

    private function assetReportExportRows(array $viewer, array $normalizedFilters, int $maxRows): array
    {
        $rows = $this->collectAssetReportRows($viewer, $normalizedFilters, $maxRows + 1);
        if (count($rows) > $maxRows) {
            throw new DomainException('ข้อมูลสำหรับ export มีมากเกิน ' . number_format($maxRows) . ' แถว กรุณากรองเงื่อนไขให้แคบลงก่อน export');
        }

        return $rows;
    }

    public function exportAssetReliabilityCsv(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeAssetReportFilters($filters);
        $rows = $this->assetReportExportRows($viewer, $normalizedFilters, self::EXPORT_MAX_ROWS_CSV);
        $jobId = $this->reports->createExportJob((int) ($viewer['id'] ?? 0), 'asset_reliability_report', 'csv', $normalizedFilters);
        $fileName = 'asset-reliability-' . date('Ymd-His') . '.csv';

        try {
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new RuntimeException('ไม่สามารถเตรียมไฟล์ CSV ได้');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $this->assetReportExportHeaders());
            foreach ($rows as $row) {
                fputcsv($stream, $this->sanitizeExportRow($this->assetReportExportRow($row)));
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

    public function exportAssetReliabilityExcel(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeAssetReportFilters($filters);
        $rows = $this->assetReportExportRows($viewer, $normalizedFilters, self::EXPORT_MAX_ROWS_XLSX);
        $jobId = $this->reports->createExportJob((int) ($viewer['id'] ?? 0), 'asset_reliability_report', 'xlsx', $normalizedFilters);
        $fileName = 'asset-reliability-' . date('Ymd-His') . '.xlsx';

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('สุขภาพทรัพย์สิน');

            $column = 'A';
            foreach ($this->assetReportExportHeaders() as $header) {
                $sheet->setCellValue($column . '1', $header);
                $sheet->getColumnDimension($column)->setAutoSize(true);
                $column++;
            }

            $rowNumber = 2;
            foreach ($rows as $row) {
                $sheet->fromArray($this->sanitizeExportRow($this->assetReportExportRow($row)), null, 'A' . $rowNumber);
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

    public function exportAssetReliabilityPdf(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeAssetReportFilters($filters);
        $rows = $this->assetReportExportRows($viewer, $normalizedFilters, self::EXPORT_MAX_ROWS_PDF);
        $jobId = $this->reports->createExportJob((int) ($viewer['id'] ?? 0), 'asset_reliability_report', 'pdf', $normalizedFilters);
        $fileName = 'asset-reliability-' . date('Ymd-His') . '.pdf';

        try {
            $html = View::capture('reports/asset-reliability-pdf', [
                'generatedAt' => date('d/m/Y H:i'),
                'summary' => $this->buildAssetReportSummary($rows),
                'rows' => $rows,
                'filters' => $this->describeAssetReportFilters($normalizedFilters, $this->reports->getAssetReportReferenceData()),
            ]);

            $options = new Options();
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

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'application/pdf'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ PDF ได้', 0, $exception);
        }
    }

    private function buildLaborEffort(array $rows): array
    {
        $totalMinutes = 0;
        $totalLabored = 0;
        $byCategory = [];

        foreach ($rows as $row) {
            $minutes = (int) ($row['labor_minutes'] ?? 0);
            $labored = (int) ($row['labored_tickets'] ?? 0);
            $totalMinutes += $minutes;
            $totalLabored += $labored;

            $byCategory[] = [
                'category_name' => (string) ($row['category_name'] ?? '-'),
                'tickets' => (int) ($row['tickets'] ?? 0),
                'labored_tickets' => $labored,
                'labor_hours_label' => number_format(round($minutes / 60, 1), 1),
                'avg_hours_label' => $labored > 0 ? number_format(round($minutes / $labored / 60, 1), 1) : '-',
            ];
        }

        return [
            'total_hours_label' => $totalMinutes > 0 ? number_format(round($totalMinutes / 60, 1), 1) : '-',
            'avg_hours_label' => $totalLabored > 0 ? number_format(round($totalMinutes / $totalLabored / 60, 1), 1) : '-',
            'labored_tickets' => $totalLabored,
            'byCategory' => $byCategory,
            'hasData' => $rows !== [],
        ];
    }

    private function mapTechnicianRow(array $row): array
    {
        $assigned = (int) ($row['assigned'] ?? 0);
        $resolved = (int) ($row['resolved'] ?? 0);
        $ratingCount = (int) ($row['rating_count'] ?? 0);
        $avgRating = (float) ($row['avg_rating'] ?? 0);
        // float (not int): keep the .5-minute precision so MTTR rounds to hours the same way the
        // summary card's avg_resolution does — otherwise pre-truncating minutes tips the 1-decimal
        // hours across a boundary (68.5min→69 gave 1.2h vs the summary's correct 1.1h).
        $mttrMinutes = (float) ($row['mttr_minutes'] ?? 0);
        $laborMinutes = (int) ($row['labor_minutes'] ?? 0);
        $completionPct = $assigned > 0 ? round($resolved / $assigned * 100, 1) : null;

        return [
            'full_name' => (string) ($row['full_name'] ?? '-'),
            'assigned' => $assigned,
            'resolved' => $resolved,
            'open' => (int) ($row['open_count'] ?? 0),
            'completion_label' => $completionPct === null ? '-' : number_format($completionPct, 1) . '%',
            'completion_tone' => $completionPct === null
                ? 'default'
                : ($completionPct >= 80 ? 'success' : ($completionPct >= 60 ? 'warning' : 'danger')),
            'mttr_hours_label' => $mttrMinutes > 0 ? number_format(round($mttrMinutes / 60, 1), 1) : '-',
            'avg_rating_label' => $ratingCount > 0 ? number_format($avgRating, 1) : '-',
            'avg_rating_tone' => $ratingCount === 0
                ? 'default'
                : ($avgRating >= 4 ? 'success' : ($avgRating >= 3 ? 'warning' : 'danger')),
            'labor_hours_label' => $laborMinutes > 0 ? number_format(round($laborMinutes / 60, 1), 1) : '-',
        ];
    }

    // ===== Technician Workload & Performance (หน้าแยกเต็มตัว /reports/technician-performance) =====

    public function getTechnicianPerformanceReportPage(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);

        $normalizedFilters = $this->normalizeReportFilters($filters);
        $reference = $this->reports->getFilterReferenceData();
        $rows = $this->collectTechnicianPerformanceRows($viewer, $normalizedFilters);

        return [
            'filters' => $this->buildFilterData($normalizedFilters, $reference),
            'summary' => $this->buildTechnicianPerformanceSummary($rows),
            'rows' => $rows,
            'rowsMeta' => ['displayed' => count($rows)],
        ];
    }

    /**
     * Merge period stats (date-filtered) + live workload (snapshot ทุกช่าง) ต่อ tech id → เรียงคนโหลดหนัก
     * ขึ้นบน. base = live (ช่างทุกคน), overlay period 0 ถ้าไม่มีงานในช่วง. ใช้ร่วมทั้งหน้าจอ + export.
     */
    private function collectTechnicianPerformanceRows(array $viewer, array $normalizedFilters): array
    {
        $period = [];
        foreach ($this->reports->getTechnicianPeriodStats($viewer, $normalizedFilters) as $row) {
            $period[(int) $row['id']] = $row;
        }

        $live = $this->reports->getTechnicianLiveWorkload($viewer);
        $totalOpenNow = 0;
        foreach ($live as $row) {
            $totalOpenNow += (int) ($row['open_now'] ?? 0);
        }
        $teamSize = count($live);

        $rows = array_map(
            fn (array $l): array => $this->mapTechnicianPerformanceRow($period[(int) $l['id']] ?? [], $l, $totalOpenNow, $teamSize),
            $live
        );

        usort($rows, static fn (array $a, array $b): int => [$b['open_now'], $b['resolved']] <=> [$a['open_now'], $a['resolved']]);

        return $rows;
    }

    private function mapTechnicianPerformanceRow(array $period, array $live, int $totalOpenNow, int $teamSize): array
    {
        $openNow = (int) ($live['open_now'] ?? 0);
        $assigned = (int) ($period['assigned'] ?? 0);
        $resolved = (int) ($period['resolved'] ?? 0);
        $ratingCount = (int) ($period['rating_count'] ?? 0);
        $avgRating = (float) ($period['avg_rating'] ?? 0);
        $mttrMinutes = (float) ($period['mttr_minutes'] ?? 0);
        $firstRespMinutes = (float) ($period['first_response_minutes'] ?? 0);
        $laborMinutes = (int) ($period['labor_minutes'] ?? 0);
        $slaBase = (int) ($period['sla_base'] ?? 0);
        $slaOnTime = (int) ($period['sla_on_time'] ?? 0);

        $completionPct = $assigned > 0 ? round($resolved / $assigned * 100, 1) : null;
        $slaRate = $slaBase > 0 ? round($slaOnTime / $slaBase * 100, 1) : null;
        $sharePct = $totalOpenNow > 0 ? round($openNow / $totalOpenNow * 100, 1) : null;
        $oldestAge = $this->daysSince($live['oldest_open_at'] ?? null);

        // workload tone เทียบ even split (โหลดเฉลี่ยต่อคน) — เกิน 2 เท่า = โหลดหนัก(danger), 1.5 เท่า = warning
        $workloadTone = 'default';
        $even = $teamSize > 0 ? $totalOpenNow / $teamSize : 0.0;
        if ($openNow > 0 && $even > 0) {
            $ratio = $openNow / $even;
            $workloadTone = $ratio >= 2 ? 'danger' : ($ratio >= 1.5 ? 'warning' : 'success');
        }

        return [
            'full_name' => (string) ($live['full_name'] ?? '-'),
            // live snapshot (ไม่ขึ้นกับ date filter)
            'open_now' => $openNow,
            'workload_share_label' => $sharePct === null ? '-' : number_format($sharePct, 1) . '%',
            'workload_tone' => $workloadTone,
            'oldest_open_age_label' => $oldestAge === null ? '-' : number_format($oldestAge, 0) . ' วัน',
            // performance ในช่วง
            'assigned' => $assigned,
            'resolved' => $resolved,
            'completion_label' => $completionPct === null ? '-' : number_format($completionPct, 1) . '%',
            'completion_tone' => $completionPct === null
                ? 'default'
                : ($completionPct >= 80 ? 'success' : ($completionPct >= 60 ? 'warning' : 'danger')),
            'sla_on_time_label' => $slaRate === null ? '-' : number_format($slaRate, 1) . '%',
            'sla_on_time_tone' => $slaRate === null
                ? 'default'
                : ($slaRate >= 90 ? 'success' : ($slaRate >= 75 ? 'warning' : 'danger')),
            'first_response_hours_label' => $firstRespMinutes > 0 ? number_format(round($firstRespMinutes / 60, 1), 1) : '-',
            'mttr_hours_label' => $mttrMinutes > 0 ? number_format(round($mttrMinutes / 60, 1), 1) : '-',
            'avg_rating_label' => $ratingCount > 0 ? number_format($avgRating, 1) : '-',
            'avg_rating_tone' => $ratingCount === 0
                ? 'default'
                : ($avgRating >= 4 ? 'success' : ($avgRating >= 3 ? 'warning' : 'danger')),
            'labor_hours_label' => $laborMinutes > 0 ? number_format(round($laborMinutes / 60, 1), 1) : '-',
            // raw counts (ซ่อน) สำหรับ summary team SLA
            'sla_base' => $slaBase,
            'sla_on_time' => $slaOnTime,
        ];
    }

    private function buildTechnicianPerformanceSummary(array $rows): array
    {
        $slaBase = array_sum(array_column($rows, 'sla_base'));
        $slaOnTime = array_sum(array_column($rows, 'sla_on_time'));
        $slaRate = $slaBase > 0 ? round($slaOnTime / $slaBase * 100, 1) : null;

        return [
            'technicians' => count($rows),
            'open_now' => (int) array_sum(array_column($rows, 'open_now')),
            'resolved' => (int) array_sum(array_column($rows, 'resolved')),
            'sla_on_time_label' => $slaRate === null ? '-' : number_format($slaRate, 1) . '%',
            'sla_on_time_tone' => $slaRate === null
                ? 'default'
                : ($slaRate >= 90 ? 'success' : ($slaRate >= 75 ? 'warning' : 'danger')),
        ];
    }

    /** จำนวนวัน (ทศนิยม) นับจากวันที่ถึงตอนนี้ — คืน null ถ้าไม่มี/รูปแบบผิด. */
    private function daysSince(mixed $date): ?float
    {
        if (!is_string($date) || trim($date) === '') {
            return null;
        }
        $timestamp = strtotime((string) $date);
        if ($timestamp === false) {
            return null;
        }

        return max(0.0, (time() - $timestamp) / 86400);
    }

    private function technicianPerformanceExportHeaders(): array
    {
        return [
            'ช่าง', 'งานค้างปัจจุบัน', 'สัดส่วนโหลด', 'ค้างเก่าสุด', 'รับ', 'ปิด', 'อัตราปิด',
            'SLA ตรงเวลา', 'เวลาตอบรับ (ชม.)', 'MTTR (ชม.)', 'คะแนน', 'ชม.แรงงาน',
        ];
    }

    private function technicianPerformanceExportRow(array $row): array
    {
        return [
            $row['full_name'], $row['open_now'], $row['workload_share_label'], $row['oldest_open_age_label'],
            $row['assigned'], $row['resolved'], $row['completion_label'], $row['sla_on_time_label'],
            $row['first_response_hours_label'], $row['mttr_hours_label'], $row['avg_rating_label'], $row['labor_hours_label'],
        ];
    }

    public function exportTechnicianPerformanceCsv(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeReportFilters($filters);
        $rows = $this->collectTechnicianPerformanceRows($viewer, $normalizedFilters);
        $jobId = $this->reports->createExportJob((int) ($viewer['id'] ?? 0), 'technician_performance_report', 'csv', $normalizedFilters);
        $fileName = 'technician-performance-' . date('Ymd-His') . '.csv';

        try {
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new RuntimeException('ไม่สามารถเตรียมไฟล์ CSV ได้');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $this->technicianPerformanceExportHeaders());
            foreach ($rows as $row) {
                fputcsv($stream, $this->sanitizeExportRow($this->technicianPerformanceExportRow($row)));
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

    public function exportTechnicianPerformanceExcel(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeReportFilters($filters);
        $rows = $this->collectTechnicianPerformanceRows($viewer, $normalizedFilters);
        $jobId = $this->reports->createExportJob((int) ($viewer['id'] ?? 0), 'technician_performance_report', 'xlsx', $normalizedFilters);
        $fileName = 'technician-performance-' . date('Ymd-His') . '.xlsx';

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('ผลงานทีมช่าง');

            $column = 'A';
            foreach ($this->technicianPerformanceExportHeaders() as $header) {
                $sheet->setCellValue($column . '1', $header);
                $sheet->getColumnDimension($column)->setAutoSize(true);
                $column++;
            }

            $rowNumber = 2;
            foreach ($rows as $row) {
                $sheet->fromArray($this->sanitizeExportRow($this->technicianPerformanceExportRow($row)), null, 'A' . $rowNumber);
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

    public function exportTechnicianPerformancePdf(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeReportFilters($filters);
        $rows = $this->collectTechnicianPerformanceRows($viewer, $normalizedFilters);
        $jobId = $this->reports->createExportJob((int) ($viewer['id'] ?? 0), 'technician_performance_report', 'pdf', $normalizedFilters);
        $fileName = 'technician-performance-' . date('Ymd-His') . '.pdf';

        try {
            $html = View::capture('reports/technician-performance-pdf', [
                'generatedAt' => date('d/m/Y H:i'),
                'summary' => $this->buildTechnicianPerformanceSummary($rows),
                'rows' => $rows,
                'filters' => $this->describeFilters($normalizedFilters, $this->reports->getFilterReferenceData()),
            ]);

            $options = new Options();
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

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'application/pdf'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ PDF ได้', 0, $exception);
        }
    }

    /**
     * Pivot flat rows (priority × metric) → overall + byPriority พร้อม %compliance + tone.
     * @return array{overall: array<string, array>, byPriority: array<int, array>, hasData: bool}
     */
    private function buildSlaCompliance(array $rows): array
    {
        $overall = ['response' => ['met' => 0, 'breached' => 0], 'resolution' => ['met' => 0, 'breached' => 0]];
        $byLevel = [];

        foreach ($rows as $row) {
            $metric = (string) ($row['metric_type'] ?? '');
            if (!isset($overall[$metric])) {
                continue;
            }
            $met = (int) ($row['met'] ?? 0);
            $breached = (int) ($row['breached'] ?? 0);
            $level = (int) ($row['priority_level'] ?? 0);

            $overall[$metric]['met'] += $met;
            $overall[$metric]['breached'] += $breached;

            if (!isset($byLevel[$level])) {
                $byLevel[$level] = [
                    'priority_name' => (string) ($row['priority_name'] ?? '-'),
                    'response' => ['met' => 0, 'breached' => 0],
                    'resolution' => ['met' => 0, 'breached' => 0],
                ];
            }
            $byLevel[$level][$metric]['met'] += $met;
            $byLevel[$level][$metric]['breached'] += $breached;
        }

        krsort($byLevel); // Urgent (level สูง) → Low

        return [
            'overall' => [
                'response' => $this->slaMetricCell($overall['response']),
                'resolution' => $this->slaMetricCell($overall['resolution']),
            ],
            'byPriority' => array_map(fn (array $p): array => [
                'priority_name' => $p['priority_name'],
                'response' => $this->slaMetricCell($p['response']),
                'resolution' => $this->slaMetricCell($p['resolution']),
            ], array_values($byLevel)),
            'hasData' => $rows !== [],
        ];
    }

    private function slaMetricCell(array $counts): array
    {
        $met = (int) ($counts['met'] ?? 0);
        $breached = (int) ($counts['breached'] ?? 0);
        $concluded = $met + $breached;
        $pct = $concluded > 0 ? round($met / $concluded * 100, 1) : null;

        return [
            'met' => $met,
            'breached' => $breached,
            'pct' => $pct,
            'pct_label' => $pct === null ? '-' : number_format($pct, 1) . '%',
            'tone' => $pct === null ? 'default' : ($pct >= 90 ? 'success' : ($pct >= 75 ? 'warning' : 'danger')),
        ];
    }

    // ===== SLA Breach Analysis (หน้าแยกเต็มตัว /reports/sla-breach) =====

    private const SLA_BREACH_DIMENSIONS = ['priority', 'category', 'department', 'location'];

    public function getSlaBreachReportPage(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);

        $normalizedFilters = $this->normalizeSlaBreachFilters($filters);
        $reference = $this->reports->getSlaBreachReferenceData();
        $rows = $this->collectSlaBreachRows($viewer, $normalizedFilters);

        return [
            'filters' => $this->buildSlaBreachFilterData($normalizedFilters, $reference),
            'summary' => $this->buildSlaBreachSummary($rows),
            'rows' => $rows,
            'rowsMeta' => ['displayed' => count($rows), 'dimension' => $normalizedFilters['dimension']],
        ];
    }

    /** ดึง breach ตามมิติที่เลือก → pivot → เรียง breach มากสุดขึ้นบน (bottleneck). ใช้ร่วมหน้าจอ + export. */
    private function collectSlaBreachRows(array $viewer, array $normalizedFilters): array
    {
        $raw = $this->reports->getSlaBreachByDimension($viewer, $normalizedFilters, $normalizedFilters['dimension']);

        return $this->buildSlaBreach($raw);
    }

    /** pivot (dimension_label × metric_type) → แถวต่อค่ามิติ พร้อม cell response/resolution + รวม breach/rate. */
    private function buildSlaBreach(array $rows): array
    {
        $byDim = [];
        foreach ($rows as $row) {
            $metric = (string) ($row['metric_type'] ?? '');
            if (!in_array($metric, ['response', 'resolution'], true)) {
                continue;
            }
            $label = (string) ($row['dimension_label'] ?? '-');
            if (!isset($byDim[$label])) {
                $byDim[$label] = ['label' => $label, 'response' => ['met' => 0, 'breached' => 0], 'resolution' => ['met' => 0, 'breached' => 0]];
            }
            $byDim[$label][$metric]['met'] += (int) ($row['met'] ?? 0);
            $byDim[$label][$metric]['breached'] += (int) ($row['breached'] ?? 0);
        }

        $result = array_map(function (array $d): array {
            $response = $this->slaBreachCell($d['response']);
            $resolution = $this->slaBreachCell($d['resolution']);
            $totalBreached = $response['breached'] + $resolution['breached'];
            $totalMet = $response['met'] + $resolution['met'];
            $totalConcluded = $totalBreached + $totalMet;
            $rate = $totalConcluded > 0 ? round($totalBreached / $totalConcluded * 100, 1) : null;

            return [
                'label' => $d['label'],
                'response' => $response,
                'resolution' => $resolution,
                'total_breached' => $totalBreached,
                'total_met' => $totalMet,
                'total_concluded' => $totalConcluded,
                'breach_rate' => $rate,
                'breach_rate_label' => $rate === null ? '-' : number_format($rate, 1) . '%',
                'breach_tone' => $this->breachTone($rate),
            ];
        }, array_values($byDim));

        usort($result, static fn (array $a, array $b): int => [$b['total_breached'], $b['total_concluded']] <=> [$a['total_breached'], $a['total_concluded']]);

        return $result;
    }

    private function slaBreachCell(array $counts): array
    {
        $met = (int) ($counts['met'] ?? 0);
        $breached = (int) ($counts['breached'] ?? 0);
        $concluded = $met + $breached;
        $rate = $concluded > 0 ? round($breached / $concluded * 100, 1) : null;

        return [
            'met' => $met,
            'breached' => $breached,
            'concluded' => $concluded,
            'breach_rate' => $rate,
            'breach_rate_label' => $rate === null ? '-' : number_format($rate, 1) . '%',
            'tone' => $this->breachTone($rate),
        ];
    }

    /** tone มุม breach: เกินยิ่งเยอะยิ่งแย่ (คู่ตรงข้ามของ slaMetricCell ที่เป็นมุม compliance). */
    private function breachTone(?float $rate): string
    {
        if ($rate === null) {
            return 'default';
        }

        return $rate >= 25 ? 'danger' : ($rate >= 10 ? 'warning' : 'success');
    }

    private function buildSlaBreachSummary(array $rows): array
    {
        $responseBreached = 0;
        $resolutionBreached = 0;
        $totalBreached = 0;
        $totalConcluded = 0;
        foreach ($rows as $row) {
            $responseBreached += (int) $row['response']['breached'];
            $resolutionBreached += (int) $row['resolution']['breached'];
            $totalBreached += (int) $row['total_breached'];
            $totalConcluded += (int) $row['total_concluded'];
        }
        $rate = $totalConcluded > 0 ? round($totalBreached / $totalConcluded * 100, 1) : null;

        return [
            'total_breached' => $totalBreached,
            'response_breached' => $responseBreached,
            'resolution_breached' => $resolutionBreached,
            'breach_rate_label' => $rate === null ? '-' : number_format($rate, 1) . '%',
            'breach_tone' => $this->breachTone($rate),
        ];
    }

    /** ป้ายไทยของหัวคอลัมน์มิติ (ใช้ทั้งตาราง/หัว export/PDF). */
    private function slaBreachDimensionLabel(string $dimension): string
    {
        return match ($dimension) {
            'category' => 'หมวดหมู่งาน',
            'department' => 'แผนก',
            'location' => 'สถานที่',
            default => 'ระดับความสำคัญ',
        };
    }

    private function normalizeSlaBreachFilters(array $filters): array
    {
        $fromDate = is_string($filters['from_date'] ?? null) ? trim((string) $filters['from_date']) : '';
        $toDate = is_string($filters['to_date'] ?? null) ? trim((string) $filters['to_date']) : '';
        $dimension = is_string($filters['dimension'] ?? null) ? trim((string) $filters['dimension']) : '';

        return normalize_date_range($fromDate, $toDate) + [
            'department_id' => max(0, (int) ($filters['department_id'] ?? 0)),
            'category_id' => max(0, (int) ($filters['category_id'] ?? 0)),
            'priority_id' => max(0, (int) ($filters['priority_id'] ?? 0)),
            'location_id' => max(0, (int) ($filters['location_id'] ?? 0)),
            'dimension' => in_array($dimension, self::SLA_BREACH_DIMENSIONS, true) ? $dimension : 'priority',
        ];
    }

    private function buildSlaBreachFilterData(array $filters, array $reference): array
    {
        $option = static fn (array $rows, string $allLabel): array => array_merge(
            [['value' => '', 'label' => $allLabel]],
            array_map(static fn (array $row): array => [
                'value' => (string) ($row['id'] ?? 0),
                'label' => (string) ($row['name'] ?? '-'),
            ], $rows)
        );

        $queryFilters = array_filter([
            'from_date' => (string) ($filters['from_date'] ?? ''),
            'to_date' => (string) ($filters['to_date'] ?? ''),
            'department_id' => (int) ($filters['department_id'] ?? 0) > 0 ? (string) ($filters['department_id'] ?? '') : '',
            'category_id' => (int) ($filters['category_id'] ?? 0) > 0 ? (string) ($filters['category_id'] ?? '') : '',
            'priority_id' => (int) ($filters['priority_id'] ?? 0) > 0 ? (string) ($filters['priority_id'] ?? '') : '',
            'location_id' => (int) ($filters['location_id'] ?? 0) > 0 ? (string) ($filters['location_id'] ?? '') : '',
            'dimension' => (string) ($filters['dimension'] ?? 'priority'),
        ], static fn (string $value): bool => $value !== '');

        return [
            'selected' => [
                'from_date' => (string) ($filters['from_date'] ?? ''),
                'to_date' => (string) ($filters['to_date'] ?? ''),
                'department_id' => (string) ($filters['department_id'] ?? ''),
                'category_id' => (string) ($filters['category_id'] ?? ''),
                'priority_id' => (string) ($filters['priority_id'] ?? ''),
                'location_id' => (string) ($filters['location_id'] ?? ''),
                'dimension' => (string) ($filters['dimension'] ?? 'priority'),
            ],
            'dimensionLabel' => $this->slaBreachDimensionLabel((string) ($filters['dimension'] ?? 'priority')),
            'dimensionOptions' => [
                ['value' => 'priority', 'label' => 'ระดับความสำคัญ'],
                ['value' => 'category', 'label' => 'หมวดหมู่งาน'],
                ['value' => 'department', 'label' => 'แผนก'],
                ['value' => 'location', 'label' => 'สถานที่'],
            ],
            'departmentOptions' => $option($reference['departments'] ?? [], 'ทุกแผนก'),
            'categoryOptions' => $option($reference['categories'] ?? [], 'ทุกหมวดหมู่'),
            'priorityOptions' => $option($reference['priorities'] ?? [], 'ทุกระดับ'),
            'locationOptions' => $option($reference['locations'] ?? [], 'ทุกสถานที่'),
            'active_count' => ((int) ($filters['department_id'] ?? 0) > 0 ? 1 : 0)
                + ((int) ($filters['category_id'] ?? 0) > 0 ? 1 : 0)
                + ((int) ($filters['priority_id'] ?? 0) > 0 ? 1 : 0)
                + ((int) ($filters['location_id'] ?? 0) > 0 ? 1 : 0)
                + ((string) ($filters['from_date'] ?? '') !== '' ? 1 : 0)
                + ((string) ($filters['to_date'] ?? '') !== '' ? 1 : 0),
            'query_string' => http_build_query($queryFilters),
        ];
    }

    private function describeSlaBreachFilters(array $filters, array $reference): array
    {
        $name = static function (array $rows, int $id, string $fallback): string {
            foreach ($rows as $row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    return (string) ($row['name'] ?? $fallback);
                }
            }
            return $fallback;
        };

        return [
            'dimension' => $this->slaBreachDimensionLabel((string) ($filters['dimension'] ?? 'priority')),
            'from_date' => (string) ($filters['from_date'] ?? '') !== '' ? (string) ($filters['from_date'] ?? '') : 'ไม่ระบุ',
            'to_date' => (string) ($filters['to_date'] ?? '') !== '' ? (string) ($filters['to_date'] ?? '') : 'ไม่ระบุ',
            'department' => $name($reference['departments'] ?? [], (int) ($filters['department_id'] ?? 0), 'ทุกแผนก'),
            'category' => $name($reference['categories'] ?? [], (int) ($filters['category_id'] ?? 0), 'ทุกหมวดหมู่'),
            'priority' => $name($reference['priorities'] ?? [], (int) ($filters['priority_id'] ?? 0), 'ทุกระดับ'),
            'location' => $name($reference['locations'] ?? [], (int) ($filters['location_id'] ?? 0), 'ทุกสถานที่'),
        ];
    }

    private function slaBreachExportHeaders(string $dimension): array
    {
        return [$this->slaBreachDimensionLabel($dimension), 'ตอบรับ เกิน', 'แก้ไข เกิน', 'breach รวม', 'ทันกำหนด', '%เกิน'];
    }

    private function slaBreachExportRow(array $row): array
    {
        return [
            $row['label'],
            $row['response']['breached'],
            $row['resolution']['breached'],
            $row['total_breached'],
            $row['total_met'],
            $row['breach_rate_label'],
        ];
    }

    public function exportSlaBreachCsv(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeSlaBreachFilters($filters);
        $rows = $this->collectSlaBreachRows($viewer, $normalizedFilters);
        $jobId = $this->reports->createExportJob((int) ($viewer['id'] ?? 0), 'sla_breach_report', 'csv', $normalizedFilters);
        $fileName = 'sla-breach-' . date('Ymd-His') . '.csv';

        try {
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new RuntimeException('ไม่สามารถเตรียมไฟล์ CSV ได้');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $this->slaBreachExportHeaders($normalizedFilters['dimension']));
            foreach ($rows as $row) {
                fputcsv($stream, $this->sanitizeExportRow($this->slaBreachExportRow($row)));
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

    public function exportSlaBreachExcel(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeSlaBreachFilters($filters);
        $rows = $this->collectSlaBreachRows($viewer, $normalizedFilters);
        $jobId = $this->reports->createExportJob((int) ($viewer['id'] ?? 0), 'sla_breach_report', 'xlsx', $normalizedFilters);
        $fileName = 'sla-breach-' . date('Ymd-His') . '.xlsx';

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('SLA เกินกำหนด');

            $column = 'A';
            foreach ($this->slaBreachExportHeaders($normalizedFilters['dimension']) as $header) {
                $sheet->setCellValue($column . '1', $header);
                $sheet->getColumnDimension($column)->setAutoSize(true);
                $column++;
            }

            $rowNumber = 2;
            foreach ($rows as $row) {
                $sheet->fromArray($this->sanitizeExportRow($this->slaBreachExportRow($row)), null, 'A' . $rowNumber);
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

    public function exportSlaBreachPdf(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeSlaBreachFilters($filters);
        $rows = $this->collectSlaBreachRows($viewer, $normalizedFilters);
        $jobId = $this->reports->createExportJob((int) ($viewer['id'] ?? 0), 'sla_breach_report', 'pdf', $normalizedFilters);
        $fileName = 'sla-breach-' . date('Ymd-His') . '.pdf';

        try {
            $html = View::capture('reports/sla-breach-pdf', [
                'generatedAt' => date('d/m/Y H:i'),
                'dimensionLabel' => $this->slaBreachDimensionLabel($normalizedFilters['dimension']),
                'summary' => $this->buildSlaBreachSummary($rows),
                'rows' => $rows,
                'filters' => $this->describeSlaBreachFilters($normalizedFilters, $this->reports->getSlaBreachReferenceData()),
            ]);

            $options = new Options();
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

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'application/pdf'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ PDF ได้', 0, $exception);
        }
    }

    private function mapAssetReliabilityRow(array $row): array
    {
        // float (not int): same single-rounding pipeline as summary + technician MTTR — keep the
        // .5-minute precision so avg-resolution rounds to hours consistently across the whole report.
        $avgMinutes = (float) ($row['avg_resolution_minutes'] ?? 0);
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
            'labor_hours_label' => (int) ($row['labor_minutes'] ?? 0) > 0 ? number_format(round((int) $row['labor_minutes'] / 60, 1), 1) : '-',
            'detail_url' => '/asset-registry/' . (int) ($row['id'] ?? 0),
        ];
    }

    // Asset reliability panel — จำนวน asset สูงสุดที่จัดอันดับบนหน้า report
    private const ASSET_RELIABILITY_LIMIT = 20;

    // Technician performance panel — จำนวนช่างสูงสุดในตาราง
    private const TECHNICIAN_LIMIT = 50;

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

            $this->appendAnalyticsSheets($spreadsheet, $this->collectAnalytics($viewer, $normalizedFilters));
            $spreadsheet->setActiveSheetIndex(0);

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
                'analytics' => $this->collectAnalytics($viewer, $normalizedFilters),
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

    /** เพิ่ม sheet analytics 4 ตัวเข้า workbook (ต่อจาก sheet ticket) ให้ export ตรงกับหน้าจอ. */
    private function appendAnalyticsSheets(Spreadsheet $spreadsheet, array $analytics): void
    {
        $overall = $analytics['slaCompliance']['overall'] ?? [];
        $slaRows = [[
            'รวมทั้งหมด',
            $overall['response']['met'] ?? 0, $overall['response']['breached'] ?? 0, $overall['response']['pct_label'] ?? '-',
            $overall['resolution']['met'] ?? 0, $overall['resolution']['breached'] ?? 0, $overall['resolution']['pct_label'] ?? '-',
        ]];
        foreach (($analytics['slaCompliance']['byPriority'] ?? []) as $p) {
            $slaRows[] = [
                $p['priority_name'], $p['response']['met'], $p['response']['breached'], $p['response']['pct_label'],
                $p['resolution']['met'], $p['resolution']['breached'], $p['resolution']['pct_label'],
            ];
        }
        $this->addExcelSheet($spreadsheet, 'SLA ตรงตามกำหนด', ['ระดับความสำคัญ', 'ตอบรับ ตรง', 'ตอบรับ เกิน', 'ตอบรับ %', 'แก้ไข ตรง', 'แก้ไข เกิน', 'แก้ไข %'], $slaRows);

        $this->addExcelSheet($spreadsheet, 'ผลงานช่างเทคนิค',
            ['ช่าง', 'มอบหมาย', 'เสร็จ', 'ค้าง', 'อัตราปิดงาน', 'เวลาซ่อมเฉลี่ย (ชม.)', 'คะแนนเฉลี่ย', 'ชม.แรงงาน'],
            array_map(static fn (array $t): array => [$t['full_name'], $t['assigned'], $t['resolved'], $t['open'], $t['completion_label'], $t['mttr_hours_label'], $t['avg_rating_label'], $t['labor_hours_label']], $analytics['technicianPerformance'] ?? []));

        $this->addExcelSheet($spreadsheet, 'ชั่วโมงแรงงาน',
            ['หมวดหมู่งาน', 'จำนวนงาน', 'งานที่บันทึกแรงงาน', 'รวมชั่วโมง', 'เฉลี่ย/งาน (ชม.)'],
            array_map(static fn (array $c): array => [$c['category_name'], $c['tickets'], $c['labored_tickets'], $c['labor_hours_label'], $c['avg_hours_label']], $analytics['laborEffort']['byCategory'] ?? []));

        $this->addExcelSheet($spreadsheet, 'ทรัพย์สินเสียบ่อย',
            ['รหัส', 'ชื่อ', 'หมวดหมู่', 'สถานที่', 'สถานะ', 'จำนวนครั้ง', 'ครั้งล่าสุด', 'เวลาซ่อมเฉลี่ย (ชม.)', 'ชม.แรงงาน'],
            array_map(static fn (array $a): array => [$a['asset_code'], $a['name'], $a['category_name'], $a['location_name'], $a['status_label'], $a['failure_count'], $a['last_failure'], $a['avg_resolution_hours_label'], $a['labor_hours_label']], $analytics['assetReliability'] ?? []));
    }

    private function addExcelSheet(Spreadsheet $spreadsheet, string $title, array $headers, array $rows): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $column = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($column . '1', $header);
            $sheet->getColumnDimension($column)->setAutoSize(true);
            $column++;
        }
        $rowNumber = 2;
        foreach ($rows as $row) {
            $sheet->fromArray($this->sanitizeExportRow($row), null, 'A' . $rowNumber);
            $rowNumber++;
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
