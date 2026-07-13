<?php

declare(strict_types=1);

use App\Services\ReportService;

// R12 — every standalone report must export a VALID artifact even when the window has zero rows: a manager who
// exports a quiet period should get a headed file, not a 0-byte/corrupt download or an error. Previously only
// reopen-rate had this (report_access_test.php); this sweeps all reports × CSV/XLSX/PDF on an empty window.

function ree_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

function ree_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('report exports: every report produces a valid file on an empty window (R12)', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    // far-future window → no tickets/logs; superset of filter keys so every report's normaliser is satisfied
    $empty = ['from_date' => '2099-01-01', 'to_date' => '2099-01-31', 'preset' => 'custom', 'granularity' => 'month'];
    $baselineJobId = (int) ree_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    // report → [csvMethod, excelMethod, pdfMethod]
    $reports = [
        'SLA breach' => ['exportSlaBreachCsv', 'exportSlaBreachExcel', 'exportSlaBreachPdf'],
        'technician' => ['exportTechnicianPerformanceCsv', 'exportTechnicianPerformanceExcel', 'exportTechnicianPerformancePdf'],
        'problem hotspot' => ['exportProblemHotspotCsv', 'exportProblemHotspotExcel', 'exportProblemHotspotPdf'],
        'trend' => ['exportTicketTrendCsv', 'exportTicketTrendExcel', 'exportTicketTrendPdf'],
        'executive' => ['exportExecutiveSummaryCsv', 'exportExecutiveSummaryExcel', 'exportExecutiveSummaryPdf'],
        'backlog aging' => ['exportBacklogAgingCsv', 'exportBacklogAgingExcel', 'exportBacklogAgingPdf'],
        'reopen rate' => ['exportReopenRateCsv', 'exportReopenRateExcel', 'exportReopenRatePdf'],
        'CSAT' => ['exportCsatCsv', 'exportCsatExcel', 'exportCsatPdf'],
        'asset reliability' => ['exportAssetReliabilityCsv', 'exportAssetReliabilityExcel', 'exportAssetReliabilityPdf'],
    ];

    try {
        foreach ($reports as $name => [$csv, $xlsx, $pdf]) {
            $c = (string) ree_service()->{$csv}($admin, $empty)['content'];
            assert_same("\xEF\xBB\xBF", substr($c, 0, 3), "$name CSV keeps the UTF-8 BOM even when empty");
            assert_true(strlen($c) > 3, "$name CSV still carries a header row (not a 0-byte file)");

            $x = (string) ree_service()->{$xlsx}($admin, $empty)['content'];
            assert_same('PK', substr($x, 0, 2), "$name XLSX is a valid workbook when empty");

            $p = (string) ree_service()->{$pdf}($admin, $empty)['content'];
            assert_same('%PDF-', substr($p, 0, 5), "$name PDF renders when empty (no error, no fake numbers)");
        }
    } finally {
        ree_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});
