<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\View;
use App\Repositories\ReportRepository;
use DomainException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class ReportService
{
    public function __construct(
        private ReportRepository $reports,
        private ReportExporter $exporter,
    ) {
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
                // gate the MTTR label on the resolved-ticket base: no resolved tickets → '-' (not a fake 0.0)
                'avgResolutionHoursLabel' => (int) ($summary['resolution_base'] ?? 0) > 0
                    ? number_format(round(((float) ($summary['avg_resolution_minutes'] ?? 0)) / 60, 1), 1)
                    : '-',
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
            // /reports overview technician block reads the SAME immutable resolver-based rows as the full
            // /reports/technician-performance page (R13) — so the two pages never disagree and neither restates a
            // past period on reopen/reassign. Previously this used a current-assignee query (getTechnicianPerformance)
            // whose resolved/MTTR mutated + made a reassigned resolver's row vanish.
            'technicianPerformance' => $this->collectTechnicianPerformanceRows($viewer, $normalizedFilters),
            'laborEffort' => $this->buildLaborEffort(
                $this->reports->getLaborByCategory($viewer, $normalizedFilters)
            ),
        ];
    }

    // ===== Asset Reliability Report (หน้าแยกเต็มตัว /reports/asset-reliability) =====

    // แสดงทุก asset ที่มีประวัติแจ้งซ่อมในช่วงกรอง (ไม่จำกัด 20 อันดับแบบ panel ท้าย /reports)
    private const ASSET_REPORT_LIMIT = 500;             // ค่าเริ่มต้นจำนวนแถวที่ตารางแสดง (buyer ปรับผ่าน config/env ได้)
    private const ASSET_SUMMARY_MAX_ROWS = 100000;      // = repo MAX_ROWS — สรุป/จัดอันดับสุขภาพประเมินทรัพย์สินทุกตัวที่เข้าเงื่อนไข

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

        // Score EVERY matching asset → the summary cards and the worst-health ranking are computed over all
        // of them; the table then shows only the top N (buyer-tunable). Previously the summary was built from
        // the capped rows, so it undercounted the fleet size / downtime / labor once past the cap (F1).
        $displayLimit = max(1, (int) config('reports.asset_display_limit', self::ASSET_REPORT_LIMIT));
        $allRows = $this->collectAssetReportRows($viewer, $normalizedFilters, self::ASSET_SUMMARY_MAX_ROWS);
        $displayRows = array_slice($allRows, 0, $displayLimit);

        return [
            'filters' => $this->buildAssetReportFilterData($normalizedFilters, $reference),
            'summary' => $this->buildAssetReportSummary($allRows),
            'rows' => $displayRows,
            'rowsMeta' => [
                'displayed' => count($displayRows),
                'total' => count($allRows),
                'limit' => $displayLimit,
                'capped' => count($allRows) > $displayLimit,
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
            'avg_resolution_hours_label' => (int) ($row['resolved_count'] ?? 0) > 0 ? number_format($avgHours, 1) : '-',
            'downtime_minutes' => $downtimeMinutes,
            // presence = มี incident ที่ปิดจริง (resolved_count) ไม่ใช่ค่าผลรวม > 0 — งานที่ซ่อมเสร็จในนาทีเดียว
            // มี downtime จริง 0.0 ชม. ต้องไม่อ่านเป็น "ไม่มีข้อมูล" (BI-review F4, เกณฑ์เดียวกับ avg_resolution)
            'downtime_hours_label' => (int) ($row['resolved_count'] ?? 0) > 0 ? number_format(round($downtimeMinutes / 60, 1), 1) : '-',
            'labor_minutes' => $laborMinutes,
            'labor_hours_label' => $laborMinutes > 0 ? number_format(round($laborMinutes / 60, 1), 1) : '-',
            'age_label' => $ageYears !== null ? number_format($ageYears, 1) . ' ปี' : '-',
            // raw numerics for Excel export (the label carries a Thai unit → text in Excel; the export header
            // already states the unit, so the sheet gets a bare number the manager can pivot/sum) (BI-review F3)
            'mtbf_days_export' => $mtbfDays !== null ? number_format(round($mtbfDays, 0), 0) : '-',
            'age_years_export' => $ageYears !== null ? number_format($ageYears, 1) : '-',
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
        // reject reversed or future failure dates — a 2030 "failure" would fabricate a huge, healthy-looking
        // MTBF (round-8 F3; belt-and-suspenders alongside the query-level future-incident exclusion).
        if ($first === false || $last === false || $last < $first || $last > time()) {
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
            $row['mtbf_days_export'], $row['avg_resolution_hours_label'], $row['downtime_hours_label'],
            $row['labor_hours_label'], $row['age_years_export'], $row['warranty_label'],
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
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'asset_reliability_report', 'csv', $normalizedFilters);
        $fileName = 'asset-reliability-' . date('Ymd-His') . '.csv';

        try {
            $content = $this->exporter->buildCsvExport(
                $this->assetReportExportHeaders(),
                array_map(fn ($row): array => $this->assetReportExportRow($row), $rows)
            );
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
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'asset_reliability_report', 'xlsx', $normalizedFilters);
        $fileName = 'asset-reliability-' . date('Ymd-His') . '.xlsx';

        try {
            $content = $this->exporter->buildXlsxExport(
                'สุขภาพทรัพย์สิน',
                $this->assetReportExportHeaders(),
                array_map(fn ($row): array => $this->assetReportExportRow($row), $rows)
            );

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
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'asset_reliability_report', 'pdf', $normalizedFilters);
        $fileName = 'asset-reliability-' . date('Ymd-His') . '.pdf';

        try {
            $html = View::capture('reports/asset-reliability-pdf', [
                'generatedAt' => date('d/m/Y H:i'),
                'summary' => $this->buildAssetReportSummary($rows),
                'rows' => $rows,
                'filters' => $this->describeAssetReportFilters($normalizedFilters, $this->reports->getAssetReportReferenceData()),
            ]);

            $content = $this->exporter->renderPdf($html);

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
        // As-reported (Phase 2 + R13): every performance metric is attributed to the resolve-event actor, keyed by
        // resolver id — independent of who the ticket is currently assigned to. The resolver query carries full_name.
        $resolver = [];
        foreach ($this->reports->getTechnicianResolverStats($viewer, $normalizedFilters) as $row) {
            $resolver[(int) $row['id']] = $row;
        }

        $live = [];
        $totalOpenNow = 0;
        foreach ($this->reports->getTechnicianLiveWorkload($viewer) as $row) {
            $live[(int) $row['id']] = $row;
            $totalOpenNow += (int) ($row['open_now'] ?? 0);
        }
        $teamSize = count($live);

        // Row base = the UNION of currently-active technicians (live) AND everyone who RESOLVED in the window
        // (resolver). A technician later deactivated or demoted is no longer in `live`, but their immutable past
        // performance still shows because they remain in `resolver` — otherwise closing an account would erase a
        // person's whole history + shrink team totals (R14-F1). Live-only techs get zeroed performance; resolver-
        // only (departed) techs get zeroed live workload.
        $names = [];
        foreach ($live as $id => $row) {
            $names[$id] = (string) ($row['full_name'] ?? '-');
        }
        foreach ($resolver as $id => $row) {
            $names[$id] ??= (string) ($row['full_name'] ?? '-');
        }

        $rows = [];
        foreach ($names as $id => $name) {
            $rows[] = $this->mapTechnicianPerformanceRow($name, $resolver[$id] ?? [], $live[$id] ?? [], $totalOpenNow, $teamSize);
        }

        usort($rows, static fn (array $a, array $b): int => [$b['open_now'], $b['resolved']] <=> [$a['open_now'], $a['resolved']]);

        return $rows;
    }

    private function mapTechnicianPerformanceRow(string $fullName, array $resolver, array $live, int $totalOpenNow, int $teamSize): array
    {
        $openNow = (int) ($live['open_now'] ?? 0);
        // As-reported (Phase 2 + R13): EVERY performance metric comes from the resolve-event actor
        // (getTechnicianResolverStats) over "the tickets this tech actually closed in the window" — resolved + MTTR
        // + SLA-on-time (vs the FROZEN per-cycle target) + CSAT (per-cycle rating). So a later reopen/reassign never
        // restates a past period, and the /reports overview + full page read the SAME immutable rows (R13). The
        // per-tech completion %, first-response time, labor hours, and the date-filtered "รับ"(assigned) column were
        // removed — none can be a clean immutable/current metric (R12/R13/R14). $live may be empty for a departed
        // resolver (deactivated/demoted) → current-workload columns read 0/'-', but the performance columns still show.
        $resolved = (int) ($resolver['resolved'] ?? 0);
        $mttrMinutes = (float) ($resolver['mttr_minutes'] ?? 0);
        // MTTR presence = tickets with a real resolve event (resolution_base), not the status-resolved count (F1)
        $resolutionBase = (int) ($resolver['resolution_base'] ?? 0);
        $slaBase = (int) ($resolver['sla_base'] ?? 0);
        $slaOnTime = (int) ($resolver['sla_on_time'] ?? 0);
        $ratingCount = (int) ($resolver['rating_count'] ?? 0);
        $avgRating = $ratingCount > 0 ? (float) ($resolver['rating_sum'] ?? 0) / $ratingCount : 0.0;

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
            'full_name' => $fullName,
            // live snapshot (ไม่ขึ้นกับ date filter)
            'open_now' => $openNow,
            'workload_share_label' => $sharePct === null ? '-' : number_format($sharePct, 1) . '%',
            'workload_tone' => $workloadTone,
            'oldest_open_age_label' => $oldestAge === null ? '-' : number_format($oldestAge, 0) . ' วัน',
            'oldest_open_age_export' => $oldestAge === null ? '-' : number_format($oldestAge, 0), // bare number for Excel (F3)
            // performance ในช่วง — ทุกตัว as-reported/immutable จาก resolver cohort (ไม่มี %ปิดงาน/รับ/เวลาตอบรับ/แรงงาน — R12/R13/R14)
            'resolved' => $resolved,
            'sla_on_time_label' => $slaRate === null ? '-' : number_format($slaRate, 1) . '%',
            'sla_on_time_tone' => $this->slaComplianceTone($slaRate),
            'mttr_hours_label' => $resolutionBase > 0 ? number_format(round($mttrMinutes / 60, 1), 1) : '-',
            'avg_rating_label' => $ratingCount > 0 ? number_format($avgRating, 1) : '-',
            'avg_rating_tone' => $ratingCount === 0 ? 'default' : $this->csatTone($avgRating),
            // sample size ต่อ avg/rate — ให้รายงานประเมินคนบอก base ได้ (กัน "5.0 จาก 1 รีวิว" ดูเท่า "5.0 จาก 40")
            'rating_count' => $ratingCount,
            // raw counts (ใช้ทั้ง summary team SLA + แสดง base ต่อแถว)
            'sla_base' => $slaBase,
            'sla_on_time' => $slaOnTime,
            // true = ยังเป็นช่างที่ active (มีแถวใน live roster); false = อดีตช่าง (deactivated/demoted) ที่เก็บไว้เพื่อ
            // ประวัติผลงาน. ใช้แยกนับหัว "ช่างที่ยังใช้งาน" ไม่ให้รวมอดีตช่าง (R14-F1 headcount).
            'is_active_tech' => $live !== [],
        ];
    }

    private function buildTechnicianPerformanceSummary(array $rows): array
    {
        $slaBase = array_sum(array_column($rows, 'sla_base'));
        $slaOnTime = array_sum(array_column($rows, 'sla_on_time'));
        $slaRate = $slaBase > 0 ? round($slaOnTime / $slaBase * 100, 1) : null;

        return [
            // headcount card = "ช่างที่ยังใช้งานในระบบ" → count ONLY active technicians (live roster), not the
            // historical resolvers kept in the detail rows for their immutable past performance (R14-F1).
            'technicians' => count(array_filter($rows, static fn (array $r): bool => (bool) ($r['is_active_tech'] ?? false))),
            'open_now' => (int) array_sum(array_column($rows, 'open_now')),
            'resolved' => (int) array_sum(array_column($rows, 'resolved')),
            'sla_on_time_label' => $slaRate === null ? '-' : number_format($slaRate, 1) . '%',
            'sla_on_time_tone' => $this->slaComplianceTone($slaRate),
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
            'ช่าง', 'งานค้างปัจจุบัน', 'สัดส่วนโหลด', 'ค้างเก่าสุด (วัน)', 'ปิดงาน',
            'SLA ตรงเวลา', 'งาน SLA', 'เวลาซ่อมเฉลี่ย (ชม.)', 'คะแนน', 'จำนวนรีวิว',
        ];
    }

    private function technicianPerformanceExportRow(array $row): array
    {
        // 'งาน SLA' และ 'จำนวนรีวิว' = base ของอัตรา/คะแนน — ให้ export บอก sample size เหมือนหน้าจอ (Finding B)
        return [
            $row['full_name'], $row['open_now'], $row['workload_share_label'], $row['oldest_open_age_export'],
            $row['resolved'], $row['sla_on_time_label'], $row['sla_base'],
            $row['mttr_hours_label'], $row['avg_rating_label'], $row['rating_count'],
        ];
    }

    public function exportTechnicianPerformanceCsv(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeReportFilters($filters);
        $rows = $this->collectTechnicianPerformanceRows($viewer, $normalizedFilters);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'technician_performance_report', 'csv', $normalizedFilters);
        $fileName = 'technician-performance-' . date('Ymd-His') . '.csv';

        try {
            $content = $this->exporter->buildCsvExport(
                $this->technicianPerformanceExportHeaders(),
                array_map(fn ($row): array => $this->technicianPerformanceExportRow($row), $rows)
            );
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
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'technician_performance_report', 'xlsx', $normalizedFilters);
        $fileName = 'technician-performance-' . date('Ymd-His') . '.xlsx';

        try {
            $content = $this->exporter->buildXlsxExport(
                'ผลงานทีมช่าง',
                $this->technicianPerformanceExportHeaders(),
                array_map(fn ($row): array => $this->technicianPerformanceExportRow($row), $rows)
            );

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
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'technician_performance_report', 'pdf', $normalizedFilters);
        $fileName = 'technician-performance-' . date('Ymd-His') . '.pdf';

        try {
            $html = View::capture('reports/technician-performance-pdf', [
                'generatedAt' => date('d/m/Y H:i'),
                'summary' => $this->buildTechnicianPerformanceSummary($rows),
                'rows' => $rows,
                'filters' => $this->describeFilters($normalizedFilters, $this->reports->getFilterReferenceData()),
            ]);

            $content = $this->exporter->renderPdf($html);

            $this->reports->markExportJobCompleted($jobId, $fileName);

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'application/pdf'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ PDF ได้', 0, $exception);
        }
    }

    // ===== Department / Location Problem Hotspot (หน้าแยกเต็มตัว /reports/problem-hotspot) =====

    private const HOTSPOT_DIMENSIONS = ['department', 'location'];
    // เกณฑ์คะแนนพื้นที่ปัญหา (heuristic สัมบูรณ์ ปรับได้)
    private const HOTSPOT_OVERDUE_HIGH = 25;      // %เกิน SLA ≥ นี้ = +2
    private const HOTSPOT_OVERDUE_MED = 10;       // ≥ นี้ = +1
    private const HOTSPOT_VOLUME_HIGH = 20;       // แจ้งซ่อม ≥ นี้ = +1
    private const HOTSPOT_LABOR_HIGH_MIN = 1200;  // แรงงาน ≥ 20 ชม. = +1
    private const HOTSPOT_SLOW_RESOLUTION_HOURS = 8; // ซ่อมเฉลี่ย ≥ นี้ = +1
    private const HOTSPOT_PROBLEM_SCORE = 3;      // ≥ นี้ = พื้นที่ปัญหา
    private const HOTSPOT_WATCH_SCORE = 2;        // ≥ นี้ = เฝ้าระวัง

    public function getProblemHotspotReportPage(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);

        $normalizedFilters = $this->normalizeHotspotFilters($filters);
        $reference = $this->reports->getFilterReferenceData();
        $rows = $this->collectProblemHotspotRows($viewer, $normalizedFilters);

        $filterData = $this->buildFilterData($normalizedFilters, $reference);
        $filterData['selected']['dimension'] = $normalizedFilters['dimension'];
        $filterData['dimensionLabel'] = $this->hotspotDimensionLabel($normalizedFilters['dimension']);
        $filterData['dimensionOptions'] = [
            ['value' => 'department', 'label' => 'แผนก'],
            ['value' => 'location', 'label' => 'สถานที่'],
        ];

        return [
            'filters' => $filterData,
            'summary' => $this->buildProblemHotspotSummary($rows),
            'rows' => $rows,
            'rowsMeta' => ['displayed' => count($rows), 'dimension' => $normalizedFilters['dimension']],
        ];
    }

    /** ดึง + map + เรียงพื้นที่ปัญหาขึ้นบน. ใช้ร่วมทั้งหน้าจอ + export. */
    private function collectProblemHotspotRows(array $viewer, array $normalizedFilters): array
    {
        $rows = array_map(
            fn (array $row): array => $this->mapProblemHotspotRow($row),
            $this->reports->getProblemHotspotByDimension($viewer, $normalizedFilters, $normalizedFilters['dimension'])
        );

        usort($rows, static fn (array $a, array $b): int => [$b['hotspot_score'], $b['ticket_count']] <=> [$a['hotspot_score'], $a['ticket_count']]);

        return $rows;
    }

    private function mapProblemHotspotRow(array $row): array
    {
        $ticketCount = (int) ($row['ticket_count'] ?? 0);
        $openCount = (int) ($row['open_count'] ?? 0);
        $overdueCount = (int) ($row['overdue_count'] ?? 0);
        $laborMinutes = (int) ($row['labor_minutes'] ?? 0);
        $avgMinutes = (float) ($row['avg_resolution_minutes'] ?? 0);
        $avgHours = round($avgMinutes / 60, 1);
        $overdueRate = $ticketCount > 0 ? round($overdueCount / $ticketCount * 100, 1) : 0.0;

        $hotspot = $this->scoreHotspot([
            'overdue_rate' => $overdueRate,
            'ticket_count' => $ticketCount,
            'labor_minutes' => $laborMinutes,
            'avg_resolution_hours' => $avgHours,
        ]);

        return [
            'label' => (string) ($row['dimension_label'] ?? '-'),
            'ticket_count' => $ticketCount,
            'open_count' => $openCount,
            'overdue_count' => $overdueCount,
            'overdue_rate_label' => number_format($overdueRate, 1) . '%',
            'overdue_tone' => $this->breachTone($overdueRate),
            'avg_resolution_hours_label' => (int) ($row['resolved_count'] ?? 0) > 0 ? number_format($avgHours, 1) : '-',
            'labor_minutes' => $laborMinutes,
            'labor_hours_label' => $laborMinutes > 0 ? number_format(round($laborMinutes / 60, 1), 1) : '-',
            'hotspot_score' => $hotspot['score'],
            'hotspot_label' => $hotspot['label'],
            'hotspot_tone' => $hotspot['tone'],
            'hotspot_reason' => $hotspot['reason'],
        ];
    }

    /**
     * คะแนนพื้นที่ปัญหา — รวมสัญญาณ ปริมาณแจ้งซ่อม + %เกิน SLA + แรงงาน + เวลาซ่อม แล้วจัด bucket.
     * โปร่งใส (point-based สัมบูรณ์) + มี reason อธิบายว่าทำไม.
     */
    private function scoreHotspot(array $m): array
    {
        $score = 0;
        $reasons = [];

        $overdueRate = (float) $m['overdue_rate'];
        if ($overdueRate >= self::HOTSPOT_OVERDUE_HIGH) {
            $score += 2;
            $reasons[] = 'เกิน SLA ' . number_format($overdueRate, 1) . '%';
        } elseif ($overdueRate >= self::HOTSPOT_OVERDUE_MED) {
            $score += 1;
            $reasons[] = 'เกิน SLA ' . number_format($overdueRate, 1) . '%';
        }

        $ticketCount = (int) $m['ticket_count'];
        if ($ticketCount >= self::HOTSPOT_VOLUME_HIGH) {
            $score += 1;
            $reasons[] = 'แจ้งซ่อม ' . $ticketCount . ' ครั้ง';
        }

        $laborMinutes = (int) $m['labor_minutes'];
        if ($laborMinutes >= self::HOTSPOT_LABOR_HIGH_MIN) {
            $score += 1;
            $reasons[] = 'แรงงาน ' . number_format(round($laborMinutes / 60, 1), 1) . ' ชม.';
        }

        $avgHours = (float) $m['avg_resolution_hours'];
        if ($avgHours >= self::HOTSPOT_SLOW_RESOLUTION_HOURS) {
            $score += 1;
            $reasons[] = 'ซ่อมนานเฉลี่ย ' . number_format($avgHours, 1) . ' ชม.';
        }

        if ($score >= self::HOTSPOT_PROBLEM_SCORE) {
            $label = 'พื้นที่ปัญหา';
            $tone = 'danger';
        } elseif ($score >= self::HOTSPOT_WATCH_SCORE) {
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

    private function buildProblemHotspotSummary(array $rows): array
    {
        $problemAreas = 0;
        foreach ($rows as $row) {
            $problemAreas += $row['hotspot_tone'] === 'danger' ? 1 : 0;
        }
        $totalLaborMinutes = (int) array_sum(array_column($rows, 'labor_minutes'));

        return [
            'areas' => count($rows),
            'problem_areas' => $problemAreas,
            'total_tickets' => (int) array_sum(array_column($rows, 'ticket_count')),
            'total_overdue' => (int) array_sum(array_column($rows, 'overdue_count')),
            'labor_hours_label' => number_format(round($totalLaborMinutes / 60, 1), 1),
        ];
    }

    private function hotspotDimensionLabel(string $dimension): string
    {
        return $dimension === 'location' ? 'สถานที่' : 'แผนก';
    }

    private function normalizeHotspotFilters(array $filters): array
    {
        $normalized = $this->normalizeReportFilters($filters);
        $dimension = is_string($filters['dimension'] ?? null) ? trim((string) $filters['dimension']) : '';
        $normalized['dimension'] = in_array($dimension, self::HOTSPOT_DIMENSIONS, true) ? $dimension : 'department';
        // hotspot ไม่มี status filter ใน UI — ล้างทิ้ง กัน ?status= ใน URL มาจำกัดผลผิดคาด
        $normalized['status'] = '';

        return $normalized;
    }

    private function hotspotExportHeaders(string $dimension): array
    {
        return [
            $this->hotspotDimensionLabel($dimension), 'คะแนนพื้นที่', 'เหตุผล', 'แจ้งซ่อม', 'งานค้าง',
            'เกิน SLA', '%เกิน SLA', 'เวลาซ่อมเฉลี่ย (ชม.)', 'ชม.แรงงาน',
        ];
    }

    private function hotspotExportRow(array $row): array
    {
        return [
            $row['label'], $row['hotspot_label'], $row['hotspot_reason'], $row['ticket_count'], $row['open_count'],
            $row['overdue_count'], $row['overdue_rate_label'], $row['avg_resolution_hours_label'], $row['labor_hours_label'],
        ];
    }

    public function exportProblemHotspotCsv(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeHotspotFilters($filters);
        $rows = $this->collectProblemHotspotRows($viewer, $normalizedFilters);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'problem_hotspot_report', 'csv', $normalizedFilters);
        $fileName = 'problem-hotspot-' . date('Ymd-His') . '.csv';

        try {
            $content = $this->exporter->buildCsvExport(
                $this->hotspotExportHeaders($normalizedFilters['dimension']),
                array_map(fn ($row): array => $this->hotspotExportRow($row), $rows)
            );
            $this->reports->markExportJobCompleted($jobId, $fileName);

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'text/csv; charset=UTF-8'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ CSV ได้', 0, $exception);
        }
    }

    public function exportProblemHotspotExcel(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeHotspotFilters($filters);
        $rows = $this->collectProblemHotspotRows($viewer, $normalizedFilters);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'problem_hotspot_report', 'xlsx', $normalizedFilters);
        $fileName = 'problem-hotspot-' . date('Ymd-His') . '.xlsx';

        try {
            $content = $this->exporter->buildXlsxExport(
                'พื้นที่ปัญหา',
                $this->hotspotExportHeaders($normalizedFilters['dimension']),
                array_map(fn ($row): array => $this->hotspotExportRow($row), $rows)
            );

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

    public function exportProblemHotspotPdf(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeHotspotFilters($filters);
        $rows = $this->collectProblemHotspotRows($viewer, $normalizedFilters);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'problem_hotspot_report', 'pdf', $normalizedFilters);
        $fileName = 'problem-hotspot-' . date('Ymd-His') . '.pdf';

        try {
            $html = View::capture('reports/problem-hotspot-pdf', [
                'generatedAt' => date('d/m/Y H:i'),
                'dimensionLabel' => $this->hotspotDimensionLabel($normalizedFilters['dimension']),
                'summary' => $this->buildProblemHotspotSummary($rows),
                'rows' => $rows,
                'filters' => $this->describeFilters($normalizedFilters, $this->reports->getFilterReferenceData()),
            ]);

            $content = $this->exporter->renderPdf($html);

            $this->reports->markExportJobCompleted($jobId, $fileName);

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'application/pdf'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ PDF ได้', 0, $exception);
        }
    }

    // ===== Trend แนวโน้มตามเวลา (หน้าแยกเต็มตัว /reports/trend — กราฟ Chart.js) =====

    private const TREND_GRANULARITIES = ['day', 'week', 'month'];
    /** สูงสุดของ "งวด" ในหน้าแนวโน้ม — เกินนี้ปฏิเสธพร้อมข้อความ (ไม่ตัดท้ายช่วงเงียบ ๆ). ตรงกับ guard ใน trendBucketList. */
    private const MAX_TREND_BUCKETS = 400;
    private const TREND_MONTHS_TH = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    public function getTicketTrendReportPage(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);

        $normalizedFilters = $this->normalizeTrendFilters($filters);
        $reference = $this->reports->getFilterReferenceData();
        $granularity = $normalizedFilters['granularity'];

        $buckets = $this->trendBucketList($granularity, $normalizedFilters['from_date'], $normalizedFilters['to_date']);
        $created = $this->indexByBucket($this->reports->getTicketTrendCreated($viewer, $normalizedFilters, $granularity));
        $resolved = $this->indexByBucket($this->reports->getTicketTrendResolved($viewer, $normalizedFilters, $granularity));

        $periods = [];
        foreach ($buckets as $key => $label) {
            $c = (int) ($created[$key]['created'] ?? 0);
            $r = $resolved[$key] ?? [];
            $resolvedCount = (int) ($r['resolved'] ?? 0);
            $mttrMinutes = (float) ($r['mttr_minutes'] ?? 0);
            $slaBase = (int) ($r['sla_base'] ?? 0);
            $slaOnTime = (int) ($r['sla_on_time'] ?? 0);
            $ratingCount = (int) ($r['rating_count'] ?? 0);
            $ratingSum = (float) ($r['rating_sum'] ?? 0);

            // presence from the base (resolved/rating count), not the aggregated value — a genuine 0.0
            // (sub-minute MTTR, all-breached SLA) is real data, distinct from null "no base" (F2/F5)
            $slaPct = $slaBase > 0 ? round($slaOnTime / $slaBase * 100, 1) : null;
            $mttrHours = $resolvedCount > 0 ? round($mttrMinutes / 60, 1) : null;
            $csat = $ratingCount > 0 ? round($ratingSum / $ratingCount, 2) : null;

            $periods[] = [
                'key' => $key,
                'label' => $label,
                'created' => $c,
                'resolved' => $resolvedCount,
                'net' => $c - $resolvedCount,
                'sla_pct' => $slaPct,
                'sla_pct_label' => $slaPct === null ? '-' : number_format($slaPct, 1) . '%',
                'sla_base' => $slaBase,
                'mttr_hours' => $mttrHours,
                'mttr_hours_label' => $mttrHours === null ? '-' : number_format($mttrHours, 1),
                'csat' => $csat,
                'csat_label' => $csat === null ? '-' : number_format($csat, 2),
                'rating_count' => $ratingCount,
            ];
        }

        $filterData = $this->buildFilterData($normalizedFilters, $reference);
        $filterData['selected']['granularity'] = $granularity;
        $filterData['granularityOptions'] = [
            ['value' => 'month', 'label' => 'รายเดือน'],
            ['value' => 'week', 'label' => 'รายสัปดาห์'],
            ['value' => 'day', 'label' => 'รายวัน'],
        ];

        return [
            'filters' => $filterData,
            'summary' => $this->buildTrendSummary($periods),
            'charts' => $this->buildTrendCharts($periods),
            'periods' => $periods,
            'rowsMeta' => ['displayed' => count($periods), 'granularity' => $granularity],
        ];
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, array<string, mixed>> */
    private function indexByBucket(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) ($row['bucket'] ?? '')] = $row;
        }

        return $indexed;
    }

    /** 4 กราฟ payload (shape เดียวกับ dashboard) — Volume ใช้ datasets 2 เส้น, ที่เหลือ single data. */
    private function buildTrendCharts(array $periods): array
    {
        $labels = array_column($periods, 'label');
        $created = array_column($periods, 'created');
        $resolved = array_column($periods, 'resolved');
        $sla = array_map(static fn (array $p) => $p['sla_pct'], $periods);
        $mttr = array_map(static fn (array $p) => $p['mttr_hours'], $periods);
        $csat = array_map(static fn (array $p) => $p['csat'], $periods);
        // volume (counts): 0 across the window = no activity → empty state. rate/avg series: a point is data
        // when it is non-null (a real 0% SLA / 0.0h MTTR must stay charted, not be hidden by sum==0) — F2/F5.
        $hasVolume = static fn (array $data): bool => array_sum(array_map(static fn ($v): float => (float) ($v ?? 0), $data)) > 0;
        $hasSeries = static fn (array $data): bool => array_filter($data, static fn ($v): bool => $v !== null) !== [];

        return [
            'trendVolume' => [
                'label' => 'ปริมาณงาน',
                'labels' => $labels,
                'datasets' => [
                    ['label' => 'แจ้งซ่อม', 'data' => $created],
                    ['label' => 'ปิดงาน', 'data' => $resolved],
                ],
                'has_data' => $hasVolume($created) || $hasVolume($resolved),
            ],
            'trendSla' => ['label' => 'SLA ตรงเวลา %', 'labels' => $labels, 'data' => $sla, 'has_data' => $hasSeries($sla)],
            'trendMttr' => ['label' => 'เวลาซ่อมเฉลี่ย (ชม.)', 'labels' => $labels, 'data' => $mttr, 'has_data' => $hasSeries($mttr)],
            'trendCsat' => ['label' => 'คะแนนเฉลี่ย', 'labels' => $labels, 'data' => $csat, 'has_data' => $hasSeries($csat)],
        ];
    }

    /** งวดล่าสุด + Δ เทียบงวดก่อน (tone ตามทิศที่ "ดี": SLA/CSAT ขึ้น=ดี, MTTR ลง=ดี, created เป็นกลาง). */
    private function buildTrendSummary(array $periods): array
    {
        $n = count($periods);
        $last = $n > 0 ? $periods[$n - 1] : [];
        $prev = $n > 1 ? $periods[$n - 2] : [];

        return [
            'latest_label' => (string) ($last['label'] ?? '-'),
            'created' => $this->trendMetricCard((string) (($last['created'] ?? 0)), $last['created'] ?? null, $prev['created'] ?? null, 'neutral', 0),
            'sla' => $this->trendMetricCard((string) ($last['sla_pct_label'] ?? '-'), $last['sla_pct'] ?? null, $prev['sla_pct'] ?? null, 'up_good', 1, '%'),
            'mttr' => $this->trendMetricCard((string) ($last['mttr_hours_label'] ?? '-'), $last['mttr_hours'] ?? null, $prev['mttr_hours'] ?? null, 'down_good', 1, ' ชม.'),
            'csat' => $this->trendMetricCard((string) ($last['csat_label'] ?? '-'), $last['csat'] ?? null, $prev['csat'] ?? null, 'up_good', 2),
        ];
    }

    private function trendMetricCard(string $valueLabel, mixed $last, mixed $prev, string $goodDir, int $decimals, string $unit = ''): array
    {
        if ($last === null || $prev === null) {
            return ['value' => $valueLabel, 'delta_label' => '—', 'tone' => 'default'];
        }
        $delta = round((float) $last - (float) $prev, $decimals);
        $sign = $delta > 0 ? '+' : '';
        $tone = 'default';
        if ($goodDir === 'up_good') {
            $tone = $delta > 0 ? 'success' : ($delta < 0 ? 'danger' : 'default');
        } elseif ($goodDir === 'down_good') {
            $tone = $delta < 0 ? 'success' : ($delta > 0 ? 'danger' : 'default');
        }

        return [
            'value' => $valueLabel,
            'delta_label' => $delta === 0.0 ? 'เท่าเดิม' : $sign . number_format($delta, $decimals) . $unit,
            'tone' => $tone,
        ];
    }

    private function normalizeTrendFilters(array $filters): array
    {
        $normalized = $this->normalizeReportFilters($filters);
        $granularity = is_string($filters['granularity'] ?? null) ? trim((string) $filters['granularity']) : '';
        $granularity = in_array($granularity, self::TREND_GRANULARITIES, true) ? $granularity : 'month';
        $normalized['granularity'] = $granularity;
        // trend ไม่มี status filter ใน UI — ล้างทิ้ง กัน ?status= ใน URL มาจำกัด volume/throughput (created/resolved) เพี้ยน
        $normalized['status'] = '';

        // default window ถ้าไม่ได้กรองวันที่ — ให้เห็น 12 งวดล่าสุด (day=30 วัน) เสมอ กันกราฟว่าง/ใหญ่เกิน
        $to = $normalized['to_date'] !== '' ? $normalized['to_date'] : date('Y-m-d');
        $from = $normalized['from_date'];
        if ($from === '') {
            $from = match ($granularity) {
                'day' => date('Y-m-d', strtotime('-29 days', strtotime($to))),
                'week' => date('Y-m-d', strtotime('-11 weeks', strtotime($to))),
                default => date('Y-m-01', strtotime('-11 months', strtotime($to))),
            };
        }
        $normalized['from_date'] = $from;
        $normalized['to_date'] = $to;
        $normalized['from_datetime'] = $from . ' 00:00:00';
        $normalized['to_datetime'] = $to . ' 23:59:59';

        // ปฏิเสธช่วงที่ยาวเกิน MAX_TREND_BUCKETS งวด — เดิม trendBucketList ตัดท้ายช่วงทิ้งเงียบ ๆ (วันล่าสุดหาย
        // จากกราฟ/สรุป/export). แจ้งชัดให้ผู้ใช้ลดช่วงหรือใช้ granularity ที่หยาบกว่าแทน (round-2 #1).
        $buckets = $this->trendBucketCount($granularity, $from, $to);
        if ($buckets > self::MAX_TREND_BUCKETS) {
            $granularityTh = ['day' => 'วัน', 'week' => 'สัปดาห์', 'month' => 'เดือน'][$granularity] ?? $granularity;
            throw new DomainException(
                'ช่วงวันที่ยาวเกินไปสำหรับมุมมองราย' . $granularityTh
                . ' (สูงสุด ' . self::MAX_TREND_BUCKETS . ' งวด, ช่วงที่เลือกต้องใช้ ' . number_format($buckets)
                . ' งวด) — กรุณาลดช่วงวันที่ หรือเปลี่ยนเป็นมุมมองที่หยาบกว่า (สัปดาห์/เดือน)'
            );
        }

        return $normalized;
    }

    /** จำนวน "งวด" ที่ช่วง [from, to] จะสร้างตาม granularity — ใช้เช็ก limit ก่อน gap-fill (ไม่วน bucket จริง). */
    private function trendBucketCount(string $granularity, string $fromDate, string $toDate): int
    {
        $fromTs = strtotime($fromDate);
        $toTs = strtotime($toDate);
        if ($fromTs === false || $toTs === false || $toTs < $fromTs) {
            return 0;
        }

        return match ($granularity) {
            'day' => (int) floor(($toTs - $fromTs) / 86400) + 1,
            'week' => (int) floor(($toTs - (int) strtotime('monday this week', $fromTs)) / (7 * 86400)) + 1,
            default => ((int) date('Y', $toTs) - (int) date('Y', $fromTs)) * 12
                + ((int) date('n', $toTs) - (int) date('n', $fromTs)) + 1,
        };
    }

    /** สร้าง bucket ที่คาดหวังทั้งช่วง (key ตรงกับ SQL DATE_FORMAT + label ไทย) — เพื่อ gap-fill งวดว่าง=0. */
    private function trendBucketList(string $granularity, string $fromDate, string $toDate): array
    {
        try {
            $cursor = new \DateTimeImmutable($fromDate);
            $end = new \DateTimeImmutable($toDate);
        } catch (\Throwable) {
            return [];
        }

        if ($granularity === 'month') {
            $cursor = $cursor->modify('first day of this month');
        } elseif ($granularity === 'week') {
            $cursor = $cursor->modify('monday this week');
        }

        $buckets = [];
        $guard = 0;
        while ($cursor <= $end && $guard < 400) {
            [$key, $label] = $this->trendBucketKeyLabel($granularity, $cursor);
            $buckets[$key] = $label;
            $cursor = match ($granularity) {
                'day' => $cursor->modify('+1 day'),
                'week' => $cursor->modify('+1 week'),
                default => $cursor->modify('first day of next month'),
            };
            $guard++;
        }

        return $buckets;
    }

    /** @return array{0: string, 1: string} [bucketKey, thaiLabel] */
    private function trendBucketKeyLabel(string $granularity, \DateTimeInterface $d): array
    {
        if ($granularity === 'day') {
            return [$d->format('Y-m-d'), $d->format('d/m')];
        }
        if ($granularity === 'week') {
            return [$d->format('o-W'), $d->format('d/m')];
        }

        $key = $d->format('Y-m');
        $label = self::TREND_MONTHS_TH[(int) $d->format('n') - 1] . ' ' . substr((string) ((int) $d->format('Y') + 543), -2);

        return [$key, $label];
    }

    private function trendExportHeaders(): array
    {
        return ['ช่วงเวลา', 'แจ้งซ่อม', 'ปิดงาน', 'สุทธิ', 'SLA ตรงเวลา', 'งาน SLA', 'เวลาซ่อมเฉลี่ย (ชม.)', 'คะแนนเฉลี่ย', 'จำนวนรีวิว'];
    }

    private function trendExportRow(array $period): array
    {
        // 'งาน SLA' / 'จำนวนรีวิว' = base ของ % และคะแนน — export บอก sample size เหมือนหน้าจอ (F4)
        return [
            $period['label'], $period['created'], $period['resolved'], $period['net'],
            $period['sla_pct_label'], $period['sla_base'], $period['mttr_hours_label'], $period['csat_label'], $period['rating_count'],
        ];
    }

    public function exportTicketTrendCsv(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeTrendFilters($filters);
        $periods = $this->getTicketTrendReportPage($viewer, $filters)['periods'];
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'ticket_trend_report', 'csv', $normalizedFilters);
        $fileName = 'ticket-trend-' . date('Ymd-His') . '.csv';

        try {
            $content = $this->exporter->buildCsvExport(
                $this->trendExportHeaders(),
                array_map(fn ($period): array => $this->trendExportRow($period), $periods)
            );
            $this->reports->markExportJobCompleted($jobId, $fileName);

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'text/csv; charset=UTF-8'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ CSV ได้', 0, $exception);
        }
    }

    public function exportTicketTrendExcel(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeTrendFilters($filters);
        $periods = $this->getTicketTrendReportPage($viewer, $filters)['periods'];
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'ticket_trend_report', 'xlsx', $normalizedFilters);
        $fileName = 'ticket-trend-' . date('Ymd-His') . '.xlsx';

        try {
            $content = $this->exporter->buildXlsxExport(
                'แนวโน้ม',
                $this->trendExportHeaders(),
                array_map(fn ($period): array => $this->trendExportRow($period), $periods)
            );

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

    public function exportTicketTrendPdf(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeTrendFilters($filters);
        $page = $this->getTicketTrendReportPage($viewer, $filters);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'ticket_trend_report', 'pdf', $normalizedFilters);
        $fileName = 'ticket-trend-' . date('Ymd-His') . '.pdf';

        try {
            $html = View::capture('reports/trend-pdf', [
                'generatedAt' => date('d/m/Y H:i'),
                'granularityLabel' => match ($normalizedFilters['granularity']) {
                    'day' => 'รายวัน',
                    'week' => 'รายสัปดาห์',
                    default => 'รายเดือน',
                },
                'summary' => $page['summary'],
                'periods' => $page['periods'],
                'filters' => $this->describeFilters($normalizedFilters, $this->reports->getFilterReferenceData()),
            ]);

            $content = $this->exporter->renderPdf($html);

            $this->reports->markExportJobCompleted($jobId, $fileName);

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'application/pdf'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ PDF ได้', 0, $exception);
        }
    }

    // ===== Executive KPI Summary (หน้าแยกเต็มตัว /reports/executive — one-pager เทียบงวด) =====

    private const EXEC_PRESETS = ['month', 'quarter', 'year', 'custom'];

    public function getExecutiveSummaryPage(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);

        $normalizedFilters = $this->normalizeExecFilters($filters);
        $reference = $this->reports->getFilterReferenceData();
        $window = $this->computePeriodWindows($normalizedFilters['preset'], $normalizedFilters['from_date'], $normalizedFilters['to_date']);

        $base = [
            'department_id' => $normalizedFilters['department_id'],
            'category_id' => $normalizedFilters['category_id'],
        ];
        $thisSum = $this->reports->getSummary($viewer, $base + [
            'from_datetime' => $window['this']['from'] . ' 00:00:00',
            'to_datetime' => $window['this']['to'] . ' 23:59:59',
        ]);
        $prevSum = $this->reports->getSummary($viewer, $base + [
            'from_datetime' => $window['prev']['from'] . ' 00:00:00',
            'to_datetime' => $window['prev']['to'] . ' 23:59:59',
        ]);

        $tTotal = (int) ($thisSum['total_tickets'] ?? 0);
        $pTotal = (int) ($prevSum['total_tickets'] ?? 0);
        $tResolved = (int) ($thisSum['resolved_tickets'] ?? 0);
        $pResolved = (int) ($prevSum['resolved_tickets'] ?? 0);
        $tComp = $tTotal > 0 ? round($tResolved / $tTotal * 100, 1) : 0.0;
        $pComp = $pTotal > 0 ? round($pResolved / $pTotal * 100, 1) : 0.0;
        $tMttr = round(((float) ($thisSum['avg_resolution_minutes'] ?? 0)) / 60, 1);
        $pMttr = round(((float) ($prevSum['avg_resolution_minutes'] ?? 0)) / 60, 1);
        // MTTR presence = tickets with a real resolved_at (resolution_base), NOT the status-resolved count —
        // a resolved-status ticket with a NULL resolved_at contributes nothing to the average (F1).
        $tMttrBase = (int) ($thisSum['resolution_base'] ?? 0);
        $pMttrBase = (int) ($prevSum['resolution_base'] ?? 0);
        $tRating = (float) ($thisSum['avg_rating'] ?? 0);
        $pRating = (float) ($prevSum['avg_rating'] ?? 0);
        $tRatingCount = (int) ($thisSum['rating_count'] ?? 0);

        $kpis = [
            $this->execKpiCard('แจ้งซ่อมทั้งหมด', $tTotal, $pTotal, 'neutral', 0, '', (string) $tTotal),
            $this->execKpiCard('ปิดงาน', $tResolved, $pResolved, 'neutral', 0, '', (string) $tResolved),
            // completion เป็น rate → base = จำนวนงานทั้งงวด (0% ตอนมีงานคือค่าจริง; ตอนไม่มีงานคือ "ไม่มีข้อมูล")
            $this->execKpiCard('อัตราปิดงาน', $tComp, $pComp, 'up_good', 1, '%', number_format($tComp, 1) . '%', $tTotal > 0, $pTotal > 0),
            // period-scoped breach count (ticket ที่แจ้งในงวดแล้ว breach) — ไม่ใช่ overdue_tickets ที่เป็น
            // snapshot "ค้างเกินตอนนี้" (งวดก่อนปิดไปหมด → baseline ≈ 0 ทำให้เทียบงวดเพี้ยน)
            $this->execKpiCard('เกิน SLA', (int) ($thisSum['breached_tickets'] ?? 0), (int) ($prevSum['breached_tickets'] ?? 0), 'down_good', 0, '', (string) (int) ($thisSum['breached_tickets'] ?? 0)),
            // MTTR/คะแนน เป็น avg → presence = มีงานปิด/มีรีวิวจริงไหม (base count) ไม่ใช่ค่า avg>0 —
            // งานปิด <1 นาที (MTTR 0.0) ยังต้องนับว่ามีข้อมูล ไม่งั้นเดลต้าหาย/โชว์ '-' ทั้งที่มีงาน (F5)
            $this->execKpiCard('เวลาซ่อมเฉลี่ย (ชม.)', $tMttr, $pMttr, 'down_good', 1, '', $tMttrBase > 0 ? number_format($tMttr, 1) : '-', $tMttrBase > 0, $pMttrBase > 0),
            $this->execKpiCard('คะแนนเฉลี่ย', $tRating, $pRating, 'up_good', 1, '', $tRating > 0 ? number_format($tRating, 1) : '-', $tRating > 0, $pRating > 0, $tRatingCount > 0 ? 'จาก ' . number_format($tRatingCount) . ' รีวิว' : null),
        ];

        $filterData = $this->buildFilterData($normalizedFilters, $reference);
        $filterData['selected']['preset'] = $normalizedFilters['preset'];
        $filterData['presetOptions'] = [
            ['value' => 'month', 'label' => 'เดือนนี้'],
            ['value' => 'quarter', 'label' => 'ไตรมาสนี้'],
            ['value' => 'year', 'label' => 'ปีนี้'],
            ['value' => 'custom', 'label' => 'กำหนดเอง'],
        ];

        return [
            'filters' => $filterData,
            'period' => [
                'this' => $window['this']['label'],
                'prev' => $window['prev']['label'],
            ],
            'kpis' => $kpis,
        ];
    }

    private function normalizeExecFilters(array $filters): array
    {
        $preset = is_string($filters['preset'] ?? null) ? trim((string) $filters['preset']) : '';
        $range = normalize_date_range(
            is_string($filters['from_date'] ?? null) ? trim((string) $filters['from_date']) : '',
            is_string($filters['to_date'] ?? null) ? trim((string) $filters['to_date']) : ''
        );

        return [
            'preset' => in_array($preset, self::EXEC_PRESETS, true) ? $preset : 'month',
            'from_date' => $range['from_date'],
            'to_date' => $range['to_date'],
            'department_id' => max(0, (int) ($filters['department_id'] ?? 0)),
            'category_id' => max(0, (int) ($filters['category_id'] ?? 0)),
        ];
    }

    /**
     * คืนช่วง "งวดนี้" + "งวดก่อน" แบบ elapsed-to-date (ยาวเท่ากัน กัน bias งวดปัจจุบันยังไม่จบ):
     * preset → ต้นงวดถึงวันนี้ vs ต้นงวดก่อน + จำนวนวันที่ผ่านไปเท่ากัน ; custom → ช่วงที่เลือก vs ช่วงยาว
     * เท่ากันที่อยู่ติดก่อนหน้า.
     * @return array{this: array{from:string,to:string,label:string}, prev: array{from:string,to:string,label:string}}
     */
    private function computePeriodWindows(string $preset, string $fromDate, string $toDate): array
    {
        $today = new \DateTimeImmutable('today');

        if ($preset === 'custom') {
            $thisFrom = $fromDate !== '' ? new \DateTimeImmutable($fromDate) : $today->setDate((int) $today->format('Y'), (int) $today->format('n'), 1);
            $thisTo = $toDate !== '' ? new \DateTimeImmutable($toDate) : $today;
            if ($thisTo < $thisFrom) {
                [$thisFrom, $thisTo] = [$thisTo, $thisFrom];
            }
            $lengthDays = (int) $thisFrom->diff($thisTo)->days;
            $prevTo = $thisFrom->modify('-1 day');
            $prevFrom = $prevTo->modify('-' . $lengthDays . ' days');
        } else {
            $thisFrom = $this->execPeriodStart($today, $preset);
            $thisTo = $today;
            $elapsed = (int) $thisFrom->diff($thisTo)->days;
            $prevFrom = match ($preset) {
                'year' => $thisFrom->modify('-1 year'),
                'quarter' => $thisFrom->modify('-3 months'),
                default => $thisFrom->modify('-1 month'),
            };
            $prevTo = $prevFrom->modify('+' . $elapsed . ' days');
            // กัน prev เหลื่อมเข้างวดปัจจุบัน: ถ้าเดือน/ไตรมาสก่อนสั้นกว่า elapsed (เช่น 31 มี.ค. → prev ล้ำถึง 3 มี.ค.)
            // ให้ตัด prevTo ไม่เกินวันก่อนงวดนี้เริ่ม (= วันสุดท้ายของงวดก่อน) — ไม่นับวันซ้ำสองงวด
            $prevPeriodEnd = $thisFrom->modify('-1 day');
            if ($prevTo > $prevPeriodEnd) {
                $prevTo = $prevPeriodEnd;
            }
        }

        return [
            'this' => ['from' => $thisFrom->format('Y-m-d'), 'to' => $thisTo->format('Y-m-d'), 'label' => $thisFrom->format('d/m/Y') . ' – ' . $thisTo->format('d/m/Y')],
            'prev' => ['from' => $prevFrom->format('Y-m-d'), 'to' => $prevTo->format('Y-m-d'), 'label' => $prevFrom->format('d/m/Y') . ' – ' . $prevTo->format('d/m/Y')],
        ];
    }

    private function execPeriodStart(\DateTimeImmutable $today, string $preset): \DateTimeImmutable
    {
        $year = (int) $today->format('Y');
        if ($preset === 'year') {
            return $today->setDate($year, 1, 1);
        }
        if ($preset === 'quarter') {
            $quarterStartMonth = intdiv((int) $today->format('n') - 1, 3) * 3 + 1;
            return $today->setDate($year, $quarterStartMonth, 1);
        }

        return $today->setDate($year, (int) $today->format('n'), 1);
    }

    /**
     * การ์ด KPI 1 ตัว: value งวดนี้ + delta/pct เทียบงวดก่อน + tone ตามทิศที่ "ดี" (up_good/down_good/neutral).
     */
    private function execKpiCard(string $label, float $thisVal, float $prevVal, string $goodDir, int $decimals, string $unit, string $valueLabel, bool $thisHasData = true, bool $prevHasData = true, ?string $sampleLabel = null): array
    {
        // งวดก่อนไม่มี base (rate/avg ที่ตัวหารเป็น 0) → prev = "-" ไม่ใช่ 0.0 ปลอม (R11-F2). count ส่ง prevHasData
        // เป็น true เสมอ (0 คือค่าจริง) จึงยังเป็นตัวเลข. single source → หน้าจอ + CSV/XLSX/PDF อ่านค่านี้เหมือนกัน.
        $prevLabel = $prevHasData ? number_format($prevVal, $decimals) . $unit : '-';

        // งวดปัจจุบันไม่มี base (rate/avg ที่ตัวหารเป็น 0) → ห้ามปั้นค่า 0% หรือเดลต้าหลอก (template-review F/Finding A).
        // ต่างจาก count ที่ 0 คือค่าจริง — ตัว count ส่ง hasData=true เสมอ. sample_label = ฐานที่ค่าตั้งอยู่ (F4).
        if (!$thisHasData) {
            return ['label' => $label, 'value_label' => '-', 'prev_value_label' => $prevLabel, 'delta_label' => '—', 'pct_label' => '—', 'tone' => 'default', 'sample_label' => $sampleLabel];
        }

        // งวดก่อนไม่มี base → เทียบไม่ได้: โชว์ค่างวดนี้ แต่ไม่ปั้นเดลต้า/ทิศ (เลียนแบบ trendMetricCard).
        if (!$prevHasData) {
            return ['label' => $label, 'value_label' => $valueLabel, 'prev_value_label' => $prevLabel, 'delta_label' => '—', 'pct_label' => '—', 'tone' => 'default', 'sample_label' => $sampleLabel];
        }

        $delta = round($thisVal - $prevVal, $decimals);
        $pct = $prevVal != 0.0 ? round(($thisVal - $prevVal) / abs($prevVal) * 100, 1) : null;
        $arrow = $delta > 0 ? '↑' : ($delta < 0 ? '↓' : '→');
        $tone = match ($goodDir) {
            'up_good' => $delta > 0 ? 'success' : ($delta < 0 ? 'danger' : 'default'),
            'down_good' => $delta < 0 ? 'success' : ($delta > 0 ? 'danger' : 'default'),
            default => 'default',
        };

        return [
            'label' => $label,
            'value_label' => $valueLabel,
            'prev_value_label' => $prevLabel,
            'delta_label' => $delta === 0.0 ? 'เท่าเดิม' : $arrow . ' ' . ($delta > 0 ? '+' : '') . number_format($delta, $decimals) . $unit,
            'pct_label' => $pct === null ? '—' : ($pct > 0 ? '+' : '') . number_format($pct, 1) . '%',
            'tone' => $tone,
            'sample_label' => $sampleLabel,
        ];
    }

    private function execExportHeaders(): array
    {
        return ['KPI', 'งวดนี้', 'งวดก่อน', 'เปลี่ยนแปลง', '%เปลี่ยน', 'ฐานตัวอย่าง'];
    }

    private function execExportRow(array $kpi): array
    {
        // 'ฐานตัวอย่าง' = sample_label (เช่น "จาก N รีวิว") ให้ export ตรงกับจอ/PDF (F4) ; KPI อื่นเว้นว่าง
        return [$kpi['label'], $kpi['value_label'], $kpi['prev_value_label'], $kpi['delta_label'], $kpi['pct_label'], (string) ($kpi['sample_label'] ?? '')];
    }

    public function exportExecutiveSummaryCsv(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeExecFilters($filters);
        $kpis = $this->getExecutiveSummaryPage($viewer, $filters)['kpis'];
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'executive_summary_report', 'csv', $normalizedFilters);
        $fileName = 'executive-summary-' . date('Ymd-His') . '.csv';

        try {
            $content = $this->exporter->buildCsvExport(
                $this->execExportHeaders(),
                array_map(fn ($kpi): array => $this->execExportRow($kpi), $kpis)
            );
            $this->reports->markExportJobCompleted($jobId, $fileName);

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'text/csv; charset=UTF-8'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ CSV ได้', 0, $exception);
        }
    }

    public function exportExecutiveSummaryExcel(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeExecFilters($filters);
        $kpis = $this->getExecutiveSummaryPage($viewer, $filters)['kpis'];
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'executive_summary_report', 'xlsx', $normalizedFilters);
        $fileName = 'executive-summary-' . date('Ymd-His') . '.xlsx';

        try {
            $content = $this->exporter->buildXlsxExport(
                'สรุปผู้บริหาร',
                $this->execExportHeaders(),
                array_map(fn ($kpi): array => $this->execExportRow($kpi), $kpis)
            );

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

    public function exportExecutiveSummaryPdf(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeExecFilters($filters);
        $page = $this->getExecutiveSummaryPage($viewer, $filters);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'executive_summary_report', 'pdf', $normalizedFilters);
        $fileName = 'executive-summary-' . date('Ymd-His') . '.pdf';

        try {
            $html = View::capture('reports/executive-pdf', [
                'generatedAt' => date('d/m/Y H:i'),
                'period' => $page['period'],
                'kpis' => $page['kpis'],
                'filters' => $this->describeFilters($normalizedFilters, $this->reports->getFilterReferenceData()),
            ]);

            $content = $this->exporter->renderPdf($html);

            $this->reports->markExportJobCompleted($jobId, $fileName);

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'application/pdf'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ PDF ได้', 0, $exception);
        }
    }

    // ===== Backlog & Aging Report (หน้าแยกเต็มตัว /reports/backlog-aging) =====

    private const BACKLOG_DIMENSIONS = ['priority', 'status', 'technician', 'department', 'location'];

    public function getBacklogAgingReportPage(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);

        $normalizedFilters = $this->normalizeBacklogFilters($filters);
        $reference = $this->reports->getFilterReferenceData();
        $rows = $this->collectBacklogAgingRows($viewer, $normalizedFilters);

        $filterData = $this->buildFilterData($normalizedFilters, $reference);
        $filterData['selected']['dimension'] = $normalizedFilters['dimension'];
        $filterData['dimensionLabel'] = $this->backlogDimensionLabel($normalizedFilters['dimension']);
        $filterData['dimensionOptions'] = [
            ['value' => 'priority', 'label' => 'ระดับความสำคัญ'],
            ['value' => 'status', 'label' => 'สถานะ'],
            ['value' => 'technician', 'label' => 'ช่าง'],
            ['value' => 'department', 'label' => 'แผนก'],
            ['value' => 'location', 'label' => 'สถานที่'],
        ];

        return [
            'filters' => $filterData,
            'summary' => $this->buildBacklogSummary($rows),
            'rows' => $rows,
            'rowsMeta' => ['displayed' => count($rows), 'dimension' => $normalizedFilters['dimension']],
        ];
    }

    /** ดึง + map + เรียงงานค้าง >30 วันมากสุดขึ้นบน. ใช้ร่วมทั้งหน้าจอ + export. */
    private function collectBacklogAgingRows(array $viewer, array $normalizedFilters): array
    {
        $rows = array_map(
            fn (array $row): array => $this->mapBacklogAgingRow($row, $normalizedFilters['dimension']),
            $this->reports->getBacklogAgingByDimension($viewer, $normalizedFilters, $normalizedFilters['dimension'])
        );

        usort($rows, static fn (array $a, array $b): int => [$b['bucket_30_plus'], $b['total']] <=> [$a['bucket_30_plus'], $a['total']]);

        return $rows;
    }

    private function mapBacklogAgingRow(array $row, string $dimension): array
    {
        $b03 = (int) ($row['bucket_0_3'] ?? 0);
        $b37 = (int) ($row['bucket_3_7'] ?? 0);
        $b730 = (int) ($row['bucket_7_30'] ?? 0);
        $b30 = (int) ($row['bucket_30_plus'] ?? 0);
        $oldest = (int) ($row['oldest_days'] ?? 0);
        $label = (string) ($row['dimension_label'] ?? '-');
        if ($dimension === 'status') {
            $label = ticket_status_label_th($label);
        }

        return [
            'label' => $label,
            'bucket_0_3' => $b03,
            'bucket_3_7' => $b37,
            'bucket_7_30' => $b730,
            'bucket_30_plus' => $b30,
            'total' => (int) ($row['total'] ?? 0),
            'oldest_days' => $oldest,
            // presence = has backlog (total > 0), NOT oldest > 0 — a real open ticket created today is
            // aged 0 days and must read "0 วัน", not "-" (which means "no backlog"). (round-2 #6)
            'oldest_label' => (int) ($row['total'] ?? 0) > 0 ? $oldest . ' วัน' : '-',
            'warn_tone' => $b730 > 0 ? 'warning' : 'default',
            'over30_tone' => $b30 > 0 ? 'danger' : 'default',
        ];
    }

    private function buildBacklogSummary(array $rows): array
    {
        $oldest = 0;
        foreach ($rows as $row) {
            $oldest = max($oldest, (int) $row['oldest_days']);
        }
        $total = (int) array_sum(array_column($rows, 'total'));

        return [
            'total' => $total,
            'bucket_0_3' => (int) array_sum(array_column($rows, 'bucket_0_3')),
            'bucket_3_7' => (int) array_sum(array_column($rows, 'bucket_3_7')),
            'bucket_7_30' => (int) array_sum(array_column($rows, 'bucket_7_30')),
            'bucket_30_plus' => (int) array_sum(array_column($rows, 'bucket_30_plus')),
            // presence = has backlog (total > 0), so a today-only backlog reads "0 วัน", empty reads "-"
            'oldest_label' => $total > 0 ? $oldest . ' วัน' : '-',
        ];
    }

    private function backlogDimensionLabel(string $dimension): string
    {
        return match ($dimension) {
            'status' => 'สถานะ',
            'technician' => 'ช่าง',
            'department' => 'แผนก',
            'location' => 'สถานที่',
            default => 'ระดับความสำคัญ',
        };
    }

    private function normalizeBacklogFilters(array $filters): array
    {
        $dimension = is_string($filters['dimension'] ?? null) ? trim((string) $filters['dimension']) : '';

        return [
            'dimension' => in_array($dimension, self::BACKLOG_DIMENSIONS, true) ? $dimension : 'priority',
            'department_id' => max(0, (int) ($filters['department_id'] ?? 0)),
            'category_id' => max(0, (int) ($filters['category_id'] ?? 0)),
        ];
    }

    private function backlogExportHeaders(string $dimension): array
    {
        return [$this->backlogDimensionLabel($dimension), '0-3 วัน', '3-7 วัน', '7-30 วัน', '>30 วัน', 'รวม', 'เก่าสุด (วัน)'];
    }

    private function backlogExportRow(array $row): array
    {
        return [
            $row['label'], $row['bucket_0_3'], $row['bucket_3_7'], $row['bucket_7_30'],
            $row['bucket_30_plus'], $row['total'], $row['oldest_days'],
        ];
    }

    public function exportBacklogAgingCsv(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeBacklogFilters($filters);
        $rows = $this->collectBacklogAgingRows($viewer, $normalizedFilters);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'backlog_aging_report', 'csv', $normalizedFilters);
        $fileName = 'backlog-aging-' . date('Ymd-His') . '.csv';

        try {
            $content = $this->exporter->buildCsvExport(
                $this->backlogExportHeaders($normalizedFilters['dimension']),
                array_map(fn ($row): array => $this->backlogExportRow($row), $rows)
            );
            $this->reports->markExportJobCompleted($jobId, $fileName);

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'text/csv; charset=UTF-8'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ CSV ได้', 0, $exception);
        }
    }

    public function exportBacklogAgingExcel(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeBacklogFilters($filters);
        $rows = $this->collectBacklogAgingRows($viewer, $normalizedFilters);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'backlog_aging_report', 'xlsx', $normalizedFilters);
        $fileName = 'backlog-aging-' . date('Ymd-His') . '.xlsx';

        try {
            $content = $this->exporter->buildXlsxExport(
                'งานค้างตามอายุ',
                $this->backlogExportHeaders($normalizedFilters['dimension']),
                array_map(fn ($row): array => $this->backlogExportRow($row), $rows)
            );

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

    public function exportBacklogAgingPdf(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeBacklogFilters($filters);
        $rows = $this->collectBacklogAgingRows($viewer, $normalizedFilters);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'backlog_aging_report', 'pdf', $normalizedFilters);
        $fileName = 'backlog-aging-' . date('Ymd-His') . '.pdf';

        try {
            $html = View::capture('reports/backlog-aging-pdf', [
                'generatedAt' => date('d/m/Y H:i'),
                'dimensionLabel' => $this->backlogDimensionLabel($normalizedFilters['dimension']),
                'summary' => $this->buildBacklogSummary($rows),
                'rows' => $rows,
                'filters' => $this->describeFilters($normalizedFilters, $this->reports->getFilterReferenceData()),
            ]);

            $content = $this->exporter->renderPdf($html);

            $this->reports->markExportJobCompleted($jobId, $fileName);

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'application/pdf'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ PDF ได้', 0, $exception);
        }
    }

    // ===== Reopen / First-Time-Fix Report (หน้าแยกเต็มตัว /reports/reopen-rate) =====

    private const REOPEN_DIMENSIONS = ['technician', 'category', 'priority', 'department', 'location'];

    public function getReopenRateReportPage(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);

        $normalizedFilters = $this->normalizeReopenFilters($filters);
        $reference = $this->reports->getFilterReferenceData();
        $rows = $this->collectReopenRows($viewer, $normalizedFilters);

        $filterData = $this->buildFilterData($normalizedFilters, $reference);
        $filterData['selected']['dimension'] = $normalizedFilters['dimension'];
        $filterData['dimensionLabel'] = $this->reopenDimensionLabel($normalizedFilters['dimension']);
        $filterData['dimensionOptions'] = [
            ['value' => 'technician', 'label' => 'ช่าง'],
            ['value' => 'category', 'label' => 'หมวดหมู่งาน'],
            ['value' => 'priority', 'label' => 'ระดับความสำคัญ'],
            ['value' => 'department', 'label' => 'แผนก'],
            ['value' => 'location', 'label' => 'สถานที่'],
        ];

        return [
            'filters' => $filterData,
            'summary' => $this->buildReopenSummary($rows),
            'rows' => $rows,
            'rowsMeta' => ['displayed' => count($rows), 'dimension' => $normalizedFilters['dimension']],
        ];
    }

    /** ดึง + map + เรียง %เปิดซ้ำมากสุดขึ้นบน (จุดคุณภาพแย่). ใช้ร่วมหน้าจอ + export. */
    private function collectReopenRows(array $viewer, array $normalizedFilters): array
    {
        $rows = array_map(
            fn (array $row): array => $this->mapReopenRow($row),
            $this->reports->getReopenByDimension($viewer, $normalizedFilters, $normalizedFilters['dimension'])
        );

        usort($rows, static fn (array $a, array $b): int => [$b['reopen_rate'], $b['reopened']] <=> [$a['reopen_rate'], $a['reopened']]);

        return $rows;
    }

    private function mapReopenRow(array $row): array
    {
        $resolved = (int) ($row['resolved'] ?? 0);
        $reopened = (int) ($row['reopened'] ?? 0);
        $rate = $resolved > 0 ? round($reopened / $resolved * 100, 1) : 0.0;
        // FTF ต้องเป็นส่วนเติมเต็มของ rate เป๊ะ ๆ (จาก rate ที่ปัดแล้ว) มิฉะนั้น rate+ftf อาจได้ 100.1% ที่ขอบ .x5
        $ftf = $resolved > 0 ? round(100 - $rate, 1) : 0.0;

        return [
            'label' => (string) ($row['dimension_label'] ?? '-'),
            'resolved' => $resolved,
            'reopened' => $reopened,
            'reopen_rate' => $rate,
            'reopen_rate_label' => $resolved > 0 ? number_format($rate, 1) . '%' : '-',
            'ftf_label' => $resolved > 0 ? number_format($ftf, 1) . '%' : '-',
            'reopen_tone' => $this->reopenTone($rate),
        ];
    }

    /** tone มุมคุณภาพ: เปิดซ้ำยิ่งเยอะยิ่งแย่. */
    private function reopenTone(float $rate): string
    {
        return $rate >= 20 ? 'danger' : ($rate >= 10 ? 'warning' : 'success');
    }

    private function buildReopenSummary(array $rows): array
    {
        $resolved = (int) array_sum(array_column($rows, 'resolved'));
        $reopened = (int) array_sum(array_column($rows, 'reopened'));
        $rate = $resolved > 0 ? round($reopened / $resolved * 100, 1) : null;
        $ftf = $rate === null ? null : round(100 - $rate, 1); // ส่วนเติมเต็มของ rate ที่ปัดแล้ว → รวมได้ 100.0% เสมอ

        return [
            'resolved' => $resolved,
            'reopened' => $reopened,
            'reopen_rate_label' => $rate === null ? '-' : number_format($rate, 1) . '%',
            'ftf_label' => $ftf === null ? '-' : number_format($ftf, 1) . '%',
            'reopen_tone' => $rate === null ? 'default' : $this->reopenTone($rate),
        ];
    }

    private function reopenDimensionLabel(string $dimension): string
    {
        return match ($dimension) {
            'category' => 'หมวดหมู่งาน',
            'priority' => 'ระดับความสำคัญ',
            'department' => 'แผนก',
            'location' => 'สถานที่',
            default => 'ช่าง',
        };
    }

    private function normalizeReopenFilters(array $filters): array
    {
        $dimension = is_string($filters['dimension'] ?? null) ? trim((string) $filters['dimension']) : '';
        $range = normalize_date_range(
            is_string($filters['from_date'] ?? null) ? trim((string) $filters['from_date']) : '',
            is_string($filters['to_date'] ?? null) ? trim((string) $filters['to_date']) : ''
        );

        return $range + [
            'dimension' => in_array($dimension, self::REOPEN_DIMENSIONS, true) ? $dimension : 'technician',
            'department_id' => max(0, (int) ($filters['department_id'] ?? 0)),
            'category_id' => max(0, (int) ($filters['category_id'] ?? 0)),
        ];
    }

    private function reopenExportHeaders(string $dimension): array
    {
        return [$this->reopenDimensionLabel($dimension), 'งานที่ปิด', 'เปิดซ้ำ', '%เปิดซ้ำ', '%ปิดจบรอบเดียว'];
    }

    private function reopenExportRow(array $row): array
    {
        return [$row['label'], $row['resolved'], $row['reopened'], $row['reopen_rate_label'], $row['ftf_label']];
    }

    public function exportReopenRateCsv(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeReopenFilters($filters);
        $rows = $this->collectReopenRows($viewer, $normalizedFilters);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'reopen_rate_report', 'csv', $normalizedFilters);
        $fileName = 'reopen-rate-' . date('Ymd-His') . '.csv';

        try {
            $content = $this->exporter->buildCsvExport(
                $this->reopenExportHeaders($normalizedFilters['dimension']),
                array_map(fn ($row): array => $this->reopenExportRow($row), $rows)
            );
            $this->reports->markExportJobCompleted($jobId, $fileName);

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'text/csv; charset=UTF-8'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ CSV ได้', 0, $exception);
        }
    }

    public function exportReopenRateExcel(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeReopenFilters($filters);
        $rows = $this->collectReopenRows($viewer, $normalizedFilters);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'reopen_rate_report', 'xlsx', $normalizedFilters);
        $fileName = 'reopen-rate-' . date('Ymd-His') . '.xlsx';

        try {
            $content = $this->exporter->buildXlsxExport(
                'งานเปิดซ้ำ',
                $this->reopenExportHeaders($normalizedFilters['dimension']),
                array_map(fn ($row): array => $this->reopenExportRow($row), $rows)
            );

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

    public function exportReopenRatePdf(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeReopenFilters($filters);
        $rows = $this->collectReopenRows($viewer, $normalizedFilters);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'reopen_rate_report', 'pdf', $normalizedFilters);
        $fileName = 'reopen-rate-' . date('Ymd-His') . '.pdf';

        try {
            $html = View::capture('reports/reopen-rate-pdf', [
                'generatedAt' => date('d/m/Y H:i'),
                'dimensionLabel' => $this->reopenDimensionLabel($normalizedFilters['dimension']),
                'summary' => $this->buildReopenSummary($rows),
                'rows' => $rows,
                'filters' => $this->describeFilters($normalizedFilters, $this->reports->getFilterReferenceData()),
            ]);

            $content = $this->exporter->renderPdf($html);

            $this->reports->markExportJobCompleted($jobId, $fileName);

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'application/pdf'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ PDF ได้', 0, $exception);
        }
    }

    // ===== CSAT / ความพึงพอใจ Report (หน้าแยกเต็มตัว /reports/csat) =====

    private const CSAT_DIMENSIONS = ['technician', 'category', 'priority', 'department', 'location'];
    private const CSAT_FEEDBACK_PAGE_LIMIT = 100;   // แสดงบนหน้าจอ
    private const CSAT_FEEDBACK_EXPORT_LIMIT = 500;  // Excel sheet 2 = ดึงได้มากกว่า (เพดาน repo = 500)

    public function getCsatReportPage(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);

        $normalizedFilters = $this->normalizeCsatFilters($filters);
        $reference = $this->reports->getFilterReferenceData();
        $rows = $this->collectCsatRows($viewer, $normalizedFilters);
        $summary = $this->buildCsatSummary($rows);

        $filterData = $this->buildFilterData($normalizedFilters, $reference);
        $filterData['selected']['dimension'] = $normalizedFilters['dimension'];
        $filterData['dimensionLabel'] = $this->csatDimensionLabel($normalizedFilters['dimension']);
        $filterData['dimensionOptions'] = [
            ['value' => 'technician', 'label' => 'ช่าง'],
            ['value' => 'category', 'label' => 'หมวดหมู่งาน'],
            ['value' => 'priority', 'label' => 'ระดับความสำคัญ'],
            ['value' => 'department', 'label' => 'แผนก'],
            ['value' => 'location', 'label' => 'สถานที่'],
        ];

        return [
            'filters' => $filterData,
            'summary' => $summary,
            'distribution' => $this->buildCsatDistribution($viewer, $normalizedFilters, $summary['rating_count']),
            'rows' => $rows,
            'feedback' => $this->collectCsatFeedback($viewer, $normalizedFilters),
            'rowsMeta' => ['displayed' => count($rows), 'dimension' => $normalizedFilters['dimension']],
        ];
    }

    /** ดึง + map + เรียงคะแนนแย่สุดขึ้นบน (avg น้อยก่อน, tie-break รีวิวเยอะก่อน). ใช้ร่วมหน้าจอ + export. */
    private function collectCsatRows(array $viewer, array $normalizedFilters): array
    {
        $rows = array_map(
            fn (array $row): array => $this->mapCsatRow($row),
            $this->reports->getRatingByDimension($viewer, $normalizedFilters, $normalizedFilters['dimension'])
        );

        usort($rows, static fn (array $a, array $b): int => [$a['avg_score'], -$a['rating_count']] <=> [$b['avg_score'], -$b['rating_count']]);

        return $rows;
    }

    private function mapCsatRow(array $row): array
    {
        $count = (int) ($row['rating_count'] ?? 0);
        $scoreSum = (int) ($row['score_sum'] ?? 0);
        $satisfied = (int) ($row['satisfied'] ?? 0);
        $dissatisfied = (int) ($row['dissatisfied'] ?? 0);
        // คิด avg จาก Σscore/Σcount เอง (pipeline เดียว) เพื่อให้ตรงกับ summary เป๊ะ — ไม่พึ่ง AVG() ที่ปัดแยก
        $avg = $count > 0 ? round($scoreSum / $count, 2) : 0.0;

        return [
            'label' => (string) ($row['dimension_label'] ?? '-'),
            'avg_score' => $avg,
            'avg_label' => $count > 0 ? number_format($avg, 2) : '-',
            'rating_count' => $count,
            'score_sum' => $scoreSum,
            'satisfied' => $satisfied,
            'dissatisfied' => $dissatisfied,
            'satisfied_pct_label' => $count > 0 ? number_format($satisfied / $count * 100, 1) . '%' : '-',
            'dissatisfied_pct_label' => $count > 0 ? number_format($dissatisfied / $count * 100, 1) . '%' : '-',
            'csat_tone' => $count > 0 ? $this->csatTone($avg) : 'default',
        ];
    }

    /** tone ความพึงพอใจ: คะแนนยิ่งต่ำยิ่งแย่. */
    private function csatTone(float $avg): string
    {
        return $avg >= 4.0 ? 'success' : ($avg >= 3.0 ? 'warning' : 'danger');
    }

    /**
     * tone SLA/ตรงเวลา: ≥90% เขียว · ≥75% เหลือง · ต่ำกว่าแดง (ยิ่งสูงยิ่งดี). null = ยังไม่มีข้อมูล → default.
     * Single source สำหรับทุก mapper + guide drift-lock (คู่มืออ่านรายงาน) — ห้าม inline ซ้ำ.
     */
    private function slaComplianceTone(?float $pct): string
    {
        if ($pct === null) {
            return 'default';
        }

        return $pct >= 90 ? 'success' : ($pct >= 75 ? 'warning' : 'danger');
    }

    private function buildCsatSummary(array $rows): array
    {
        $count = (int) array_sum(array_column($rows, 'rating_count'));
        $scoreSum = (int) array_sum(array_column($rows, 'score_sum'));
        $satisfied = (int) array_sum(array_column($rows, 'satisfied'));
        $dissatisfied = (int) array_sum(array_column($rows, 'dissatisfied'));
        $avg = $count > 0 ? round($scoreSum / $count, 2) : null; // Σscore/Σcount — ไม่ใช่ avg-of-avg

        return [
            'avg_score' => $avg,
            'avg_label' => $avg === null ? '-' : number_format($avg, 2),
            'rating_count' => $count,
            'satisfied' => $satisfied,
            'dissatisfied' => $dissatisfied,
            'satisfied_pct_label' => $count > 0 ? number_format($satisfied / $count * 100, 1) . '%' : '-',
            'dissatisfied_pct_label' => $count > 0 ? number_format($dissatisfied / $count * 100, 1) . '%' : '-',
            'csat_tone' => $avg === null ? 'default' : $this->csatTone($avg),
        ];
    }

    /** กระจายคะแนน 5→1 พร้อม % (เทียบ total รีวิวทั้งหมด) — เติม bucket ที่ไม่มีเป็น 0. */
    private function buildCsatDistribution(array $viewer, array $normalizedFilters, int $total): array
    {
        // ไม่มีรีวิวเลย (base=0) → ไม่มี "การกระจายคะแนน" จริง — คืน [] แทน 5 bucket ที่โชว์ 0.0% ทั้งหมด
        // (มิฉะนั้น PDF/หน้าจอช่วงว่างจะดูเหมือนมี distribution จริง). สอดคล้อง empty≠zero. (BI-review round-2 #5)
        if ($total <= 0) {
            return [];
        }

        $counts = [];
        foreach ($this->reports->getRatingDistribution($viewer, $normalizedFilters) as $row) {
            $counts[(int) ($row['score'] ?? 0)] = (int) ($row['rating_count'] ?? 0);
        }

        $buckets = [];
        for ($score = 5; $score >= 1; $score--) {
            $count = $counts[$score] ?? 0;
            $buckets[] = [
                'score' => $score,
                'count' => $count,
                'pct' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                'pct_label' => $total > 0 ? number_format($count / $total * 100, 1) . '%' : '0.0%',
            ];
        }

        return $buckets;
    }

    /** รายการ feedback ดิบ (คะแนนแย่ก่อน) — text ยังไม่ escape ที่นี่ (escape ด้วย e() ในชั้น view). */
    private function collectCsatFeedback(array $viewer, array $normalizedFilters, int $limit = self::CSAT_FEEDBACK_PAGE_LIMIT): array
    {
        return array_map(
            static function (array $row): array {
                $score = (int) ($row['score'] ?? 0);
                $technician = trim((string) ($row['technician_name'] ?? ''));
                $category = trim((string) ($row['category_name'] ?? ''));
                $createdAt = (string) ($row['created_at'] ?? '');
                // รูปแบบ d/m/Y H:i ตรงกับที่ตัว sort ของ data-table (type="date") parse ได้ (ต่างจาก human_date ไทย)
                $createdTs = $createdAt !== '' ? strtotime($createdAt) : false;

                return [
                    'score' => $score,
                    'feedback' => (string) ($row['feedback'] ?? ''),
                    'technician_name' => $technician !== '' ? $technician : 'ไม่ระบุช่าง',
                    'category_name' => $category !== '' ? $category : '-',
                    'ticket_id' => (int) ($row['ticket_id'] ?? 0),
                    'ticket_no' => (string) ($row['ticket_no'] ?? ''),
                    'created_at' => $createdAt,
                    'date_label' => $createdTs !== false ? date('d/m/Y H:i', $createdTs) : '-',
                    'tone' => $score <= 2 ? 'danger' : ($score === 3 ? 'warning' : 'success'),
                ];
            },
            $this->reports->getRatingFeedback($viewer, $normalizedFilters, $limit)
        );
    }

    private function csatDimensionLabel(string $dimension): string
    {
        return match ($dimension) {
            'category' => 'หมวดหมู่งาน',
            'priority' => 'ระดับความสำคัญ',
            'department' => 'แผนก',
            'location' => 'สถานที่',
            default => 'ช่าง',
        };
    }

    private function normalizeCsatFilters(array $filters): array
    {
        $dimension = is_string($filters['dimension'] ?? null) ? trim((string) $filters['dimension']) : '';
        $range = normalize_date_range(
            is_string($filters['from_date'] ?? null) ? trim((string) $filters['from_date']) : '',
            is_string($filters['to_date'] ?? null) ? trim((string) $filters['to_date']) : ''
        );

        return $range + [
            'dimension' => in_array($dimension, self::CSAT_DIMENSIONS, true) ? $dimension : 'technician',
            'department_id' => max(0, (int) ($filters['department_id'] ?? 0)),
            'category_id' => max(0, (int) ($filters['category_id'] ?? 0)),
            'status' => '', // กัน status leak เข้าตัวกรอง (บทเรียนจาก reopen code-review)
        ];
    }

    private function csatExportHeaders(string $dimension): array
    {
        return [$this->csatDimensionLabel($dimension), 'คะแนนเฉลี่ย', 'จำนวนรีวิว', '%พอใจ(≥4★)', '%ไม่พอใจ(≤2★)'];
    }

    private function csatExportRow(array $row): array
    {
        return [$row['label'], $row['avg_label'], $row['rating_count'], $row['satisfied_pct_label'], $row['dissatisfied_pct_label']];
    }

    private function csatFeedbackExportHeaders(): array
    {
        return ['เลขที่ Ticket', 'คะแนน', 'ความคิดเห็น', 'ช่าง', 'หมวดหมู่', 'วันที่'];
    }

    private function csatFeedbackExportRow(array $row): array
    {
        return [$row['ticket_no'], $row['score'], $row['feedback'], $row['technician_name'], $row['category_name'], $row['created_at']];
    }

    /**
     * The 4 CSAT sections (summary / distribution / breakdown / feedback) as title+headers+rows — SINGLE SOURCE
     * so every export format carries the same sections the screen shows (BI-review F4 parity: CSV was breakdown-
     * only, Excel had no distribution, PDF had no feedback). Order matches the screen.
     */
    private function csatExportSections(array $viewer, array $normalizedFilters, array $rows, int $feedbackLimit): array
    {
        $summary = $this->buildCsatSummary($rows);
        $distribution = $this->buildCsatDistribution($viewer, $normalizedFilters, (int) ($summary['rating_count'] ?? 0));
        $feedback = $this->collectCsatFeedback($viewer, $normalizedFilters, $feedbackLimit);

        return [
            ['title' => 'สรุปความพึงพอใจ', 'headers' => ['ตัวชี้วัด', 'ค่า'], 'rows' => [
                ['คะแนนเฉลี่ย', $summary['avg_label']],
                ['จำนวนรีวิว', $summary['rating_count']],
                ['พอใจ (4–5 ดาว)', $summary['satisfied']],
                ['สัดส่วนพอใจ', $summary['satisfied_pct_label']],
                ['ไม่พอใจ (1–2 ดาว)', $summary['dissatisfied']],
                ['สัดส่วนไม่พอใจ', $summary['dissatisfied_pct_label']],
            ]],
            ['title' => 'การกระจายคะแนน', 'headers' => ['คะแนน', 'จำนวน', 'สัดส่วน'], 'rows' => array_map(
                static fn (array $b): array => [$b['score'], $b['count'], $b['pct_label']],
                $distribution
            )],
            ['title' => 'ความพึงพอใจ', 'headers' => $this->csatExportHeaders($normalizedFilters['dimension']), 'rows' => array_map(fn (array $r): array => $this->csatExportRow($r), $rows)],
            ['title' => 'ความคิดเห็น', 'headers' => $this->csatFeedbackExportHeaders(), 'rows' => array_map(fn (array $r): array => $this->csatFeedbackExportRow($r), $feedback)],
        ];
    }

    public function exportCsatCsv(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeCsatFilters($filters);
        $rows = $this->collectCsatRows($viewer, $normalizedFilters);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'csat_report', 'csv', $normalizedFilters);
        $fileName = 'csat-' . date('Ymd-His') . '.csv';

        try {
            // all 4 sections (summary + distribution + breakdown + feedback), same as Excel / screen
            $content = $this->exporter->buildCsvSections(
                $this->csatExportSections($viewer, $normalizedFilters, $rows, self::CSAT_FEEDBACK_EXPORT_LIMIT)
            );
            $this->reports->markExportJobCompleted($jobId, $fileName);

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'text/csv; charset=UTF-8'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ CSV ได้', 0, $exception);
        }
    }

    public function exportCsatExcel(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeCsatFilters($filters);
        $rows = $this->collectCsatRows($viewer, $normalizedFilters);
        // Excel ดึง feedback ได้มากกว่าหน้าจอ (ตามที่ UI แจ้งว่า "ดูใน Excel") — เพดาน repo = 500
        $sections = $this->csatExportSections($viewer, $normalizedFilters, $rows, self::CSAT_FEEDBACK_EXPORT_LIMIT);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'csat_report', 'xlsx', $normalizedFilters);
        $fileName = 'csat-' . date('Ymd-His') . '.xlsx';

        try {
            $spreadsheet = new Spreadsheet();

            // All 4 sections as sheets (parity with CSV / screen). summary + distribution + breakdown go through
            // the numeric writer (%พอใจ / %กระจาย เป็นตัวเลขจริง pivot/sum ได้); feedback is text.
            $this->exporter->fillSheet($spreadsheet->getActiveSheet(), $sections[0]['title'], $sections[0]['headers'], $sections[0]['rows']);
            $this->exporter->fillSheet($spreadsheet->createSheet(), $sections[1]['title'], $sections[1]['headers'], $sections[1]['rows']);
            $this->exporter->fillSheet($spreadsheet->createSheet(), $sections[2]['title'], $sections[2]['headers'], $sections[2]['rows']);
            $this->exporter->addExcelSheet($spreadsheet, $sections[3]['title'], $sections[3]['headers'], $sections[3]['rows']);

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

    public function exportCsatPdf(array $viewer, array $filters = []): array
    {
        $this->ensureCanViewReports($viewer);
        $normalizedFilters = $this->normalizeCsatFilters($filters);
        $rows = $this->collectCsatRows($viewer, $normalizedFilters);
        $summary = $this->buildCsatSummary($rows);
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'csat_report', 'pdf', $normalizedFilters);
        $fileName = 'csat-' . date('Ymd-His') . '.pdf';

        try {
            $html = View::capture('reports/csat-pdf', [
                'generatedAt' => date('d/m/Y H:i'),
                'dimensionLabel' => $this->csatDimensionLabel($normalizedFilters['dimension']),
                'summary' => $summary,
                'distribution' => $this->buildCsatDistribution($viewer, $normalizedFilters, $summary['rating_count']),
                'rows' => $rows,
                // feedback comments — the screen shows them, so the PDF should too (export parity)
                'feedback' => $this->collectCsatFeedback($viewer, $normalizedFilters, self::CSAT_FEEDBACK_EXPORT_LIMIT),
                'filters' => $this->describeFilters($normalizedFilters, $this->reports->getFilterReferenceData()),
            ]);

            $content = $this->exporter->renderPdf($html);

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
            'tone' => $this->slaComplianceTone($pct),
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
            // pivot by dimension_id, not label — two entities with the same name (schema allows duplicate
            // location/category names) must stay distinct rows (template-review F3). null id → '' bucket.
            $key = (string) ($row['dimension_id'] ?? '');
            if (!isset($byDim[$key])) {
                $byDim[$key] = ['label' => (string) ($row['dimension_label'] ?? '-'), 'response' => ['met' => 0, 'breached' => 0], 'resolution' => ['met' => 0, 'breached' => 0]];
            }
            $byDim[$key][$metric]['met'] += (int) ($row['met'] ?? 0);
            $byDim[$key][$metric]['breached'] += (int) ($row['breached'] ?? 0);
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
        return [$this->slaBreachDimensionLabel($dimension), 'ตอบรับ เกิน', 'แก้ไข เกิน', 'เกินรวม', 'ทันกำหนด', '%เกิน'];
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
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'sla_breach_report', 'csv', $normalizedFilters);
        $fileName = 'sla-breach-' . date('Ymd-His') . '.csv';

        try {
            $content = $this->exporter->buildCsvExport(
                $this->slaBreachExportHeaders($normalizedFilters['dimension']),
                array_map(fn ($row): array => $this->slaBreachExportRow($row), $rows)
            );
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
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'sla_breach_report', 'xlsx', $normalizedFilters);
        $fileName = 'sla-breach-' . date('Ymd-His') . '.xlsx';

        try {
            $content = $this->exporter->buildXlsxExport(
                'SLA เกินกำหนด',
                $this->slaBreachExportHeaders($normalizedFilters['dimension']),
                array_map(fn ($row): array => $this->slaBreachExportRow($row), $rows)
            );

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
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'sla_breach_report', 'pdf', $normalizedFilters);
        $fileName = 'sla-breach-' . date('Ymd-His') . '.pdf';

        try {
            $html = View::capture('reports/sla-breach-pdf', [
                'generatedAt' => date('d/m/Y H:i'),
                'dimensionLabel' => $this->slaBreachDimensionLabel($normalizedFilters['dimension']),
                'summary' => $this->buildSlaBreachSummary($rows),
                'rows' => $rows,
                'filters' => $this->describeSlaBreachFilters($normalizedFilters, $this->reports->getSlaBreachReferenceData()),
            ]);

            $content = $this->exporter->renderPdf($html);

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
            'avg_resolution_hours_label' => (int) ($row['resolved_count'] ?? 0) > 0 ? number_format(round($avgMinutes / 60, 1), 1) : '-',
            'labor_hours_label' => (int) ($row['labor_minutes'] ?? 0) > 0 ? number_format(round((int) $row['labor_minutes'] / 60, 1), 1) : '-',
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
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'ticket_report', 'xlsx', $normalizedFilters);
        $fileName = 'ticket-report-' . date('Ymd-His') . '.xlsx';

        try {
            $spreadsheet = new Spreadsheet();

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

            // route the main sheet through the shared writer so numeric cells (e.g. "เวลาแก้ไข" > 999h with a
            // thousands comma) are real numbers, not text — same path as the CSAT/analytics sheets (round-9)
            $this->exporter->fillSheet(
                $spreadsheet->getActiveSheet(),
                'รายงาน Ticket',
                $headers,
                array_map(fn (array $row): array => [
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
                ], $rows)
            );

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
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'ticket_report', 'pdf', $normalizedFilters);
        $fileName = 'ticket-report-' . date('Ymd-His') . '.pdf';

        try {
            $html = View::capture('reports/pdf', [
                'generatedAt' => date('d/m/Y H:i'),
                'summary' => [
                    'total' => (int) ($summary['total_tickets'] ?? 0),
                    'resolved' => (int) ($summary['resolved_tickets'] ?? 0),
                    'overdue' => (int) ($summary['overdue_tickets'] ?? 0),
                    'avgResolutionHours' => round(((float) ($summary['avg_resolution_minutes'] ?? 0)) / 60, 1),
                    'avgResolutionHoursLabel' => (int) ($summary['resolution_base'] ?? 0) > 0
                        ? number_format(round(((float) ($summary['avg_resolution_minutes'] ?? 0)) / 60, 1), 1)
                        : '-',
                    'avgRatingLabel' => (float) ($summary['avg_rating'] ?? 0) > 0 ? number_format((float) ($summary['avg_rating'] ?? 0), 1) : '-',
                ],
                'rows' => $rows,
                'filters' => $this->describeFilters($normalizedFilters, $this->reports->getFilterReferenceData()),
                'analytics' => $this->collectAnalytics($viewer, $normalizedFilters),
            ]);

            $content = $this->exporter->renderPdf($html);

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
        $jobId = $this->createExportJob((int) ($viewer['id'] ?? 0), 'ticket_report', 'csv', $normalizedFilters);
        $fileName = 'ticket-report-' . date('Ymd-His') . '.csv';

        try {
            // The CSV carries the ticket table PLUS the same 4 analytics blocks as the Excel sheets / screen, so
            // a manager who exports CSV gets the same conclusions as the other formats (BI-review F4). The ticket
            // table stays the first block (a clean pivotable table); the analytics follow as titled blocks.
            $ticketSection = [
                'headers' => ['เลขที่', 'หัวข้อ', 'ผู้แจ้ง', 'แผนก', 'หมวดหมู่', 'ช่างเทคนิค', 'ความสำคัญ', 'สถานะ', 'วันที่แจ้ง', 'วันที่แก้ไข', 'เวลาแก้ไข (ชม.)', 'เกิน SLA', 'สถานะ SLA', 'คะแนน', 'สถานที่'],
                'rows' => array_map(fn ($row): array => [
                    $row['ticket_no'], $row['title'], $row['requester_name'], $row['department_name'],
                    $row['category_name'], $row['technician_name'], $row['priority_label'], $row['status_label'],
                    $row['requested_at'], $row['resolved_at'], $row['resolution_hours_label'],
                    $row['sla_overdue_label'], $row['sla_label'], $row['rating_label'], $row['location_name'],
                ], $rows),
            ];
            $content = $this->exporter->buildCsvSections(array_merge(
                [$ticketSection],
                $this->reportAnalyticsSections($this->collectAnalytics($viewer, $normalizedFilters))
            ));
            $this->reports->markExportJobCompleted($jobId, $fileName);

            return ['content' => $content, 'file_name' => $fileName, 'content_type' => 'text/csv; charset=UTF-8'];
        } catch (\Throwable $exception) {
            $this->recordExportFailure($jobId, $exception);
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ CSV ได้', 0, $exception);
        }
    }

    /**
     * The 4 analytics sections (title / headers / rows) — SINGLE SOURCE so the Excel sheets, the CSV blocks and
     * the on-screen panels all carry the same data (export parity by construction). Each = ['title','headers','rows'].
     */
    private function reportAnalyticsSections(array $analytics): array
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

        return [
            ['title' => 'SLA ตรงตามกำหนด', 'headers' => ['ระดับความสำคัญ', 'ตอบรับ ตรง', 'ตอบรับ เกิน', 'ตอบรับ %', 'แก้ไข ตรง', 'แก้ไข เกิน', 'แก้ไข %'], 'rows' => $slaRows],
            ['title' => 'ผลงานช่างเทคนิค', 'headers' => ['ช่าง', 'ปิดงาน', 'ค้าง', 'เวลาซ่อมเฉลี่ย (ชม.)', 'คะแนนเฉลี่ย'], 'rows' => array_map(static fn (array $t): array => [$t['full_name'], $t['resolved'], $t['open_now'], $t['mttr_hours_label'], $t['avg_rating_label']], $analytics['technicianPerformance'] ?? [])],
            ['title' => 'ชั่วโมงแรงงาน', 'headers' => ['หมวดหมู่งาน', 'จำนวนงาน', 'งานที่บันทึกแรงงาน', 'รวมชั่วโมง', 'เฉลี่ย/งาน (ชม.)'], 'rows' => array_map(static fn (array $c): array => [$c['category_name'], $c['tickets'], $c['labored_tickets'], $c['labor_hours_label'], $c['avg_hours_label']], $analytics['laborEffort']['byCategory'] ?? [])],
            ['title' => 'ทรัพย์สินเสียบ่อย', 'headers' => ['รหัส', 'ชื่อ', 'หมวดหมู่', 'สถานที่', 'สถานะ', 'จำนวนครั้ง', 'ครั้งล่าสุด', 'เวลาซ่อมเฉลี่ย (ชม.)', 'ชม.แรงงาน'], 'rows' => array_map(static fn (array $a): array => [$a['asset_code'], $a['name'], $a['category_name'], $a['location_name'], $a['status_label'], $a['failure_count'], $a['last_failure'], $a['avg_resolution_hours_label'], $a['labor_hours_label']], $analytics['assetReliability'] ?? [])],
        ];
    }

    /** เพิ่ม sheet analytics 4 ตัวเข้า workbook (ต่อจาก sheet ticket) ให้ export ตรงกับหน้าจอ. */
    private function appendAnalyticsSheets(Spreadsheet $spreadsheet, array $analytics): void
    {
        foreach ($this->reportAnalyticsSections($analytics) as $section) {
            $this->exporter->addExcelSheet($spreadsheet, $section['title'], $section['headers'], $section['rows']);
        }
    }

    private function recordExportFailure(int $jobId, \Throwable $exception): void
    {
        try {
            $this->reports->markExportJobFailed($jobId, $exception->getMessage());
        } catch (\Throwable $auditFailure) {
            // The caller re-throws the original export error; just make sure the audit-write failure itself is
            // not silent — a systematically-failing export_jobs write would otherwise leave no trace.
            log_caught_exception('report.export.failure', $auditFailure, ['job' => $jobId]);
        }
    }

    /**
     * When true, export-job tracking is suppressed (createExportJob returns 0 → the later
     * markExportJobCompleted/Failed become no-op UPDATEs on id 0). Used only by the sample-pack preview.
     */
    private bool $suppressExportJobTracking = false;

    /**
     * Single entry point for creating an export_jobs audit row. Every deliberate export (POST /reports/export/*)
     * records one; the sample-pack GET preview suppresses it so refreshes/bot-preloads don't inflate the audit.
     */
    private function createExportJob(int $requestedBy, string $type, string $format, array $filters): int
    {
        if ($this->suppressExportJobTracking) {
            return 0;
        }

        return $this->reports->createExportJob($requestedBy, $type, $format, $filters);
    }

    /**
     * Sample Pack — รวม PDF+Excel ของ 4 รายงานเด่น + README เป็น ZIP เดียว (ช่วยขาย/พรีเซนต์).
     * ใช้ default filter ของแต่ละรายงาน (ไม่ว่าง) ; bundle ในหน่วยความจำผ่าน addFromString (temp file แค่ zip เดียว).
     */
    public function generateSamplePack(array $viewer): array
    {
        $this->ensureCanViewReports($viewer);

        // [ชื่อไฟล์ไทย, เมธอด PDF, เมธอด Excel, filter]. executive ใช้ 'year' เพราะเป็น period-to-date
        // (เดือน/ไตรมาสต้นงวดจะโล่ง) — ปีให้เห็นภาพเต็ม ; ที่เหลือ default (ทั้งช่วง) พอ.
        $catalog = [
            ['1-สรุปผู้บริหาร', 'exportExecutiveSummaryPdf', 'exportExecutiveSummaryExcel', ['preset' => 'year']],
            ['2-วิเคราะห์ SLA เกิน', 'exportSlaBreachPdf', 'exportSlaBreachExcel', []],
            ['3-ความพึงพอใจลูกค้า', 'exportCsatPdf', 'exportCsatExcel', []],
            ['4-แนวโน้มงานซ่อม', 'exportTicketTrendPdf', 'exportTicketTrendExcel', []],
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'pack') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('ไม่สามารถเตรียมไฟล์ ZIP ได้');
        }

        // The sample pack is a GET preview bundle — it must NOT create export_jobs audit rows (a refresh or a
        // link-preview bot would inflate the trail). Only deliberate POST exports are audited.
        $this->suppressExportJobTracking = true;
        try {
            $lines = ['ชุดตัวอย่างรายงาน — ระบบแจ้งซ่อมและบำรุงรักษา', 'สร้างเมื่อ ' . date('d/m/Y H:i'), '', 'ไฟล์ในชุดนี้:'];
            foreach ($catalog as [$name, $pdfMethod, $excelMethod, $filters]) {
                $pdf = $this->{$pdfMethod}($viewer, $filters);
                $excel = $this->{$excelMethod}($viewer, $filters);
                $zip->addFromString($name . '.pdf', (string) ($pdf['content'] ?? ''));
                $zip->addFromString($name . '.xlsx', (string) ($excel['content'] ?? ''));
                $lines[] = "  • {$name}.pdf / {$name}.xlsx";
            }
            $lines[] = '';
            $lines[] = '* ตัวเลขสร้างจากข้อมูลในระบบขณะดาวน์โหลด — โหลด "ข้อมูลตัวอย่าง" ที่หน้าผู้ดูแลระบบเพื่อให้รายงานดูเต็ม';
            $zip->addFromString('README.txt', implode("\n", $lines));
            $zip->close();

            $content = (string) file_get_contents($tmp);
            @unlink($tmp);

            return [
                'content' => $content,
                'file_name' => 'report-sample-pack-' . date('Ymd') . '.zip',
                'content_type' => 'application/zip',
            ];
        } catch (\Throwable $exception) {
            @$zip->close();
            @unlink($tmp);
            throw new RuntimeException('ไม่สามารถสร้างชุดตัวอย่างรายงานได้', 0, $exception);
        } finally {
            $this->suppressExportJobTracking = false;
        }
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
        // resolution_minutes is NULL only when the ticket was never resolved (same-minute resolve = 0, not
        // NULL). Gate on presence, not > 0, so a same-minute resolution shows 0.0 instead of '-'.
        $hasResolution = isset($row['resolution_minutes']);
        $resolutionMinutes = $hasResolution ? (int) $row['resolution_minutes'] : 0;
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
            'resolution_hours' => $hasResolution ? round($resolutionMinutes / 60, 1) : 0,
            'resolution_hours_label' => $hasResolution ? number_format(round($resolutionMinutes / 60, 1), 1) : '-',
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

        $requestedAt = $ticket['requested_at'] ?? null;
        $response = $this->buildSlaMetricState($ticket['response_due_at'] ?? null, $ticket['first_response_at'] ?? null, $requestedAt);
        $resolution = $this->buildSlaMetricState($ticket['resolution_due_at'] ?? null, $ticket['resolved_at'] ?? null, $requestedAt);
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

    private function buildSlaMetricState(mixed $targetAt, mixed $achievedAt, mixed $requestedAt = null): array
    {
        $target = is_string($targetAt) ? trim($targetAt) : '';
        $achieved = is_string($achievedAt) ? trim($achievedAt) : '';
        $requested = is_string($requestedAt) ? trim($requestedAt) : '';
        $targetTimestamp = $target !== '' ? strtotime($target) : false;
        $achievedTimestamp = $achieved !== '' ? strtotime($achieved) : false;
        $requestedTimestamp = $requested !== '' ? strtotime($requested) : false;

        if ($targetTimestamp === false) {
            return ['status' => 'unavailable'];
        }

        if ($achievedTimestamp !== false) {
            // an achievement BEFORE the ticket was requested is impossible data (bad seed/import) — it cannot
            // be judged met/breached, so treat SLA as unavailable rather than a false "met". (F1-residual)
            if ($requestedTimestamp !== false && $achievedTimestamp < $requestedTimestamp) {
                return ['status' => 'unavailable'];
            }

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
