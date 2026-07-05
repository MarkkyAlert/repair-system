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
