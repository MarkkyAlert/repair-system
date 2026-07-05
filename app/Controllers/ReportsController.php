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
        $this->downloadAssetReliability('exportAssetReliabilityCsv', 'asset-reliability.csv', 'text/csv; charset=UTF-8');
    }

    public function assetReliabilityExportExcel(): void
    {
        $this->downloadAssetReliability(
            'exportAssetReliabilityExcel',
            'asset-reliability.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    public function assetReliabilityExportPdf(): void
    {
        $this->downloadAssetReliability('exportAssetReliabilityPdf', 'asset-reliability.pdf', 'application/pdf');
    }

    /** ตัวช่วยรวม flow export ของ Asset Reliability Report ทั้ง 3 format (csrf + download + redirect กลับ). */
    private function downloadAssetReliability(string $serviceMethod, string $fallbackName, string $fallbackType): void
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
            Response::redirect('/reports/asset-reliability');
        }
    }
}
