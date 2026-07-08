<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\ReportService;
use DomainException;
use RuntimeException;

class ReportsController
{
    public function __construct(private ReportService $reports)
    {
    }

    public function index(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        // Gate at the controller too (consistent with guide()/samplePack()); ReportService::ensureCanViewReports
        // stays as the defense-in-depth boundary that also covers the export endpoints.
        if (!is_manager_or_admin((string) ($viewer['role'] ?? 'guest'))) {
            flash('error', 'หน้านี้สงวนสำหรับผู้จัดการและผู้ดูแลระบบเท่านั้น');
            Response::redirect('/dashboard');
        }

        try {
            $data = $this->reports->getReportPageData($viewer, request()?->query ?? []);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/dashboard');
        }

        Response::view('reports/index', [
            'title' => 'รายงาน',
            'pageHeading' => 'รายงานและ Export',
            'currentUser' => $viewer,
            'filters' => $data['filters'],
            'summary' => $data['summary'],
            'rows' => $data['rows'],
            'rowsMeta' => $data['rowsMeta'],
            'assetReliability' => $data['assetReliability'],
            'slaCompliance' => $data['slaCompliance'],
            'technicianPerformance' => $data['technicianPerformance'],
            'laborEffort' => $data['laborEffort'],
        ]);
    }

    /** คู่มืออ่านรายงาน — เนื้อหา static (ไม่มี query) gate manager/admin ให้ตรงกับเมนู+รายงานอื่น. */
    public function guide(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        if (!is_manager_or_admin((string) ($viewer['role'] ?? 'guest'))) {
            flash('error', 'หน้านี้สงวนสำหรับผู้จัดการและผู้ดูแลระบบเท่านั้น');
            Response::redirect('/dashboard');
        }

        Response::view('reports/guide', [
            'title' => 'คู่มืออ่านรายงาน',
            'pageHeading' => 'คู่มืออ่านรายงาน',
            'currentUser' => $viewer,
        ]);
    }

    /** ดาวน์โหลดชุดตัวอย่างรายงาน (ZIP: PDF+Excel 4 รายงานเด่น) — GET download, gate manager/admin. */
    public function samplePack(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        if (!is_manager_or_admin((string) ($viewer['role'] ?? 'guest'))) {
            flash('error', 'หน้านี้สงวนสำหรับผู้จัดการและผู้ดูแลระบบเท่านั้น');
            Response::redirect('/dashboard');
        }

        try {
            $pack = $this->reports->generateSamplePack($viewer);
        } catch (DomainException | RuntimeException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/reports/guide');
        }

        Response::download((string) $pack['content'], (string) $pack['file_name'], 'application/zip');
    }

    public function exportExcel(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            $export = $this->reports->exportExcel($viewer, $_POST);
            Response::download(
                (string) ($export['content'] ?? ''),
                (string) ($export['file_name'] ?? 'report.xlsx'),
                (string) ($export['content_type'] ?? 'application/octet-stream')
            );
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/reports');
        }
    }

    public function exportPdf(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            $export = $this->reports->exportPdf($viewer, $_POST);
            Response::download(
                (string) ($export['content'] ?? ''),
                (string) ($export['file_name'] ?? 'report.pdf'),
                (string) ($export['content_type'] ?? 'application/pdf')
            );
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/reports');
        }
    }

    public function exportCsv(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            $export = $this->reports->exportCsv($viewer, $_POST);
            Response::download(
                (string) ($export['content'] ?? ''),
                (string) ($export['file_name'] ?? 'report.csv'),
                (string) ($export['content_type'] ?? 'text/csv; charset=UTF-8')
            );
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/reports');
        }
    }

    public function assetReliability(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $data = $this->reports->getAssetReliabilityReportPage($viewer, request()?->query ?? []);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/dashboard');
        }

        Response::view('reports/asset-reliability', [
            'title' => 'สุขภาพทรัพย์สิน',
            'pageHeading' => 'รายงานสุขภาพทรัพย์สิน',
            'currentUser' => $viewer,
            'filters' => $data['filters'],
            'summary' => $data['summary'],
            'rows' => $data['rows'],
            'rowsMeta' => $data['rowsMeta'],
        ]);
    }

    public function assetReliabilityExportCsv(): void
    {
        $this->downloadReport('exportAssetReliabilityCsv', 'asset-reliability.csv', 'text/csv; charset=UTF-8', '/reports/asset-reliability');
    }

    public function assetReliabilityExportExcel(): void
    {
        $this->downloadReport(
            'exportAssetReliabilityExcel',
            'asset-reliability.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            '/reports/asset-reliability'
        );
    }

    public function assetReliabilityExportPdf(): void
    {
        $this->downloadReport('exportAssetReliabilityPdf', 'asset-reliability.pdf', 'application/pdf', '/reports/asset-reliability');
    }

    public function slaBreach(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $data = $this->reports->getSlaBreachReportPage($viewer, request()?->query ?? []);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/dashboard');
        }

        Response::view('reports/sla-breach', [
            'title' => 'วิเคราะห์ SLA เกินกำหนด',
            'pageHeading' => 'วิเคราะห์ SLA เกินกำหนด',
            'currentUser' => $viewer,
            'filters' => $data['filters'],
            'summary' => $data['summary'],
            'rows' => $data['rows'],
            'rowsMeta' => $data['rowsMeta'],
        ]);
    }

    public function slaBreachExportCsv(): void
    {
        $this->downloadReport('exportSlaBreachCsv', 'sla-breach.csv', 'text/csv; charset=UTF-8', '/reports/sla-breach');
    }

    public function slaBreachExportExcel(): void
    {
        $this->downloadReport(
            'exportSlaBreachExcel',
            'sla-breach.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            '/reports/sla-breach'
        );
    }

    public function slaBreachExportPdf(): void
    {
        $this->downloadReport('exportSlaBreachPdf', 'sla-breach.pdf', 'application/pdf', '/reports/sla-breach');
    }

    public function technicianPerformance(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $data = $this->reports->getTechnicianPerformanceReportPage($viewer, request()?->query ?? []);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/dashboard');
        }

        Response::view('reports/technician-performance', [
            'title' => 'ผลงานทีมช่าง',
            'pageHeading' => 'ผลงานและภาระงานทีมช่าง',
            'currentUser' => $viewer,
            'filters' => $data['filters'],
            'summary' => $data['summary'],
            'rows' => $data['rows'],
            'rowsMeta' => $data['rowsMeta'],
        ]);
    }

    public function technicianPerformanceExportCsv(): void
    {
        $this->downloadReport('exportTechnicianPerformanceCsv', 'technician-performance.csv', 'text/csv; charset=UTF-8', '/reports/technician-performance');
    }

    public function technicianPerformanceExportExcel(): void
    {
        $this->downloadReport(
            'exportTechnicianPerformanceExcel',
            'technician-performance.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            '/reports/technician-performance'
        );
    }

    public function technicianPerformanceExportPdf(): void
    {
        $this->downloadReport('exportTechnicianPerformancePdf', 'technician-performance.pdf', 'application/pdf', '/reports/technician-performance');
    }

    public function problemHotspot(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $data = $this->reports->getProblemHotspotReportPage($viewer, request()?->query ?? []);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/dashboard');
        }

        Response::view('reports/problem-hotspot', [
            'title' => 'พื้นที่ปัญหา',
            'pageHeading' => 'พื้นที่ปัญหา (แผนก / สถานที่)',
            'currentUser' => $viewer,
            'filters' => $data['filters'],
            'summary' => $data['summary'],
            'rows' => $data['rows'],
            'rowsMeta' => $data['rowsMeta'],
        ]);
    }

    public function problemHotspotExportCsv(): void
    {
        $this->downloadReport('exportProblemHotspotCsv', 'problem-hotspot.csv', 'text/csv; charset=UTF-8', '/reports/problem-hotspot');
    }

    public function problemHotspotExportExcel(): void
    {
        $this->downloadReport(
            'exportProblemHotspotExcel',
            'problem-hotspot.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            '/reports/problem-hotspot'
        );
    }

    public function problemHotspotExportPdf(): void
    {
        $this->downloadReport('exportProblemHotspotPdf', 'problem-hotspot.pdf', 'application/pdf', '/reports/problem-hotspot');
    }

    public function trend(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $data = $this->reports->getTicketTrendReportPage($viewer, request()?->query ?? []);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/dashboard');
        }

        Response::view('reports/trend', [
            'title' => 'แนวโน้ม',
            'pageHeading' => 'แนวโน้มงานซ่อมตามเวลา',
            'currentUser' => $viewer,
            'filters' => $data['filters'],
            'summary' => $data['summary'],
            'charts' => $data['charts'],
            'periods' => $data['periods'],
            'rowsMeta' => $data['rowsMeta'],
        ]);
    }

    public function trendExportCsv(): void
    {
        $this->downloadReport('exportTicketTrendCsv', 'ticket-trend.csv', 'text/csv; charset=UTF-8', '/reports/trend');
    }

    public function trendExportExcel(): void
    {
        $this->downloadReport(
            'exportTicketTrendExcel',
            'ticket-trend.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            '/reports/trend'
        );
    }

    public function trendExportPdf(): void
    {
        $this->downloadReport('exportTicketTrendPdf', 'ticket-trend.pdf', 'application/pdf', '/reports/trend');
    }

    public function executiveSummary(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $data = $this->reports->getExecutiveSummaryPage($viewer, request()?->query ?? []);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/dashboard');
        }

        Response::view('reports/executive', [
            'title' => 'สรุปผู้บริหาร',
            'pageHeading' => 'สรุปผู้บริหาร (เทียบงวด)',
            'currentUser' => $viewer,
            'filters' => $data['filters'],
            'period' => $data['period'],
            'kpis' => $data['kpis'],
        ]);
    }

    public function executiveSummaryExportCsv(): void
    {
        $this->downloadReport('exportExecutiveSummaryCsv', 'executive-summary.csv', 'text/csv; charset=UTF-8', '/reports/executive');
    }

    public function executiveSummaryExportExcel(): void
    {
        $this->downloadReport(
            'exportExecutiveSummaryExcel',
            'executive-summary.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            '/reports/executive'
        );
    }

    public function executiveSummaryExportPdf(): void
    {
        $this->downloadReport('exportExecutiveSummaryPdf', 'executive-summary.pdf', 'application/pdf', '/reports/executive');
    }

    public function backlogAging(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $data = $this->reports->getBacklogAgingReportPage($viewer, request()?->query ?? []);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/dashboard');
        }

        Response::view('reports/backlog-aging', [
            'title' => 'งานค้างตามอายุ',
            'pageHeading' => 'งานค้างตามอายุ (Backlog Aging)',
            'currentUser' => $viewer,
            'filters' => $data['filters'],
            'summary' => $data['summary'],
            'rows' => $data['rows'],
            'rowsMeta' => $data['rowsMeta'],
        ]);
    }

    public function backlogAgingExportCsv(): void
    {
        $this->downloadReport('exportBacklogAgingCsv', 'backlog-aging.csv', 'text/csv; charset=UTF-8', '/reports/backlog-aging');
    }

    public function backlogAgingExportExcel(): void
    {
        $this->downloadReport(
            'exportBacklogAgingExcel',
            'backlog-aging.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            '/reports/backlog-aging'
        );
    }

    public function backlogAgingExportPdf(): void
    {
        $this->downloadReport('exportBacklogAgingPdf', 'backlog-aging.pdf', 'application/pdf', '/reports/backlog-aging');
    }

    public function reopenRate(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $data = $this->reports->getReopenRateReportPage($viewer, request()?->query ?? []);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/dashboard');
        }

        Response::view('reports/reopen-rate', [
            'title' => 'งานเปิดซ้ำ',
            'pageHeading' => 'งานเปิดซ้ำ / ปิดจบรอบเดียว (First-Time-Fix)',
            'currentUser' => $viewer,
            'filters' => $data['filters'],
            'summary' => $data['summary'],
            'rows' => $data['rows'],
            'rowsMeta' => $data['rowsMeta'],
        ]);
    }

    public function reopenRateExportCsv(): void
    {
        $this->downloadReport('exportReopenRateCsv', 'reopen-rate.csv', 'text/csv; charset=UTF-8', '/reports/reopen-rate');
    }

    public function reopenRateExportExcel(): void
    {
        $this->downloadReport(
            'exportReopenRateExcel',
            'reopen-rate.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            '/reports/reopen-rate'
        );
    }

    public function reopenRateExportPdf(): void
    {
        $this->downloadReport('exportReopenRatePdf', 'reopen-rate.pdf', 'application/pdf', '/reports/reopen-rate');
    }

    public function csat(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            $data = $this->reports->getCsatReportPage($viewer, request()?->query ?? []);
        } catch (DomainException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/dashboard');
        }

        Response::view('reports/csat', [
            'title' => 'ความพึงพอใจลูกค้า (CSAT)',
            'pageHeading' => 'ความพึงพอใจลูกค้า (CSAT)',
            'currentUser' => $viewer,
            'filters' => $data['filters'],
            'summary' => $data['summary'],
            'distribution' => $data['distribution'],
            'rows' => $data['rows'],
            'feedback' => $data['feedback'],
            'rowsMeta' => $data['rowsMeta'],
        ]);
    }

    public function csatExportCsv(): void
    {
        $this->downloadReport('exportCsatCsv', 'csat.csv', 'text/csv; charset=UTF-8', '/reports/csat');
    }

    public function csatExportExcel(): void
    {
        $this->downloadReport(
            'exportCsatExcel',
            'csat.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            '/reports/csat'
        );
    }

    public function csatExportPdf(): void
    {
        $this->downloadReport('exportCsatPdf', 'csat.pdf', 'application/pdf', '/reports/csat');
    }

    /** ตัวช่วยรวม flow export ของรายงานย่อย (csrf + download + redirect กลับหน้าเดิมเมื่อ error). */
    private function downloadReport(string $serviceMethod, string $fallbackName, string $fallbackType, string $redirectPath): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            $export = $this->reports->{$serviceMethod}($viewer, $_POST);
            Response::download(
                (string) ($export['content'] ?? ''),
                (string) ($export['file_name'] ?? $fallbackName),
                (string) ($export['content_type'] ?? $fallbackType)
            );
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect($redirectPath);
        }
    }
}
