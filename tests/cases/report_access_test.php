<?php
declare(strict_types=1);

use App\Services\ReportService;

// Cross-report guards (BI-review G3): (1) every report entry point is manager/admin-only — a non-manager
// is blocked at the service, so report data (which is org-wide, visibilityClause = 1=1 for those roles)
// never reaches a role that shouldn't see it; (2) exports do not error when the filter matches no data.

function ras_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function ras_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

test('reports: every report page is blocked for a non-manager (require manager/admin)', function (): void {
    $svc = ras_service();
    // covers all 11 report entry points — each must call ensureCanViewReports
    $pages = [
        'getReportPageData' => static fn (array $v) => $svc->getReportPageData($v, []),
        'getAssetReliabilityReportPage' => static fn (array $v) => $svc->getAssetReliabilityReportPage($v, []),
        'getTechnicianPerformanceReportPage' => static fn (array $v) => $svc->getTechnicianPerformanceReportPage($v, []),
        'getProblemHotspotReportPage' => static fn (array $v) => $svc->getProblemHotspotReportPage($v, []),
        'getTicketTrendReportPage' => static fn (array $v) => $svc->getTicketTrendReportPage($v, []),
        'getExecutiveSummaryPage' => static fn (array $v) => $svc->getExecutiveSummaryPage($v, []),
        'getBacklogAgingReportPage' => static fn (array $v) => $svc->getBacklogAgingReportPage($v, []),
        'getReopenRateReportPage' => static fn (array $v) => $svc->getReopenRateReportPage($v, []),
        'getCsatReportPage' => static fn (array $v) => $svc->getCsatReportPage($v, []),
        'getSlaBreachReportPage' => static fn (array $v) => $svc->getSlaBreachReportPage($v, []),
    ];

    foreach (['technician', 'requester', 'guest'] as $role) {
        $viewer = ['id' => 1, 'role' => $role];
        foreach ($pages as $name => $call) {
            $blocked = false;
            try {
                $call($viewer);
            } catch (DomainException $e) {
                $blocked = str_contains($e->getMessage(), 'ไม่มีสิทธิ์เข้าถึงรายงาน');
            }
            assert_true($blocked, "$name blocks a $role viewer");
        }
    }
});

test('reports: exports do not error when the filter matches no data', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $emptyWindow = ['from_date' => '2099-01-01', 'to_date' => '2099-01-31']; // no tickets/logs in 2099
    $baselineJobId = (int) ras_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        $csv = (string) ras_service()->exportReopenRateCsv($admin, $emptyWindow)['content'];
        assert_true(str_contains($csv, 'เปิดซ้ำ'), 'empty CSV still carries the header row (no error)');

        $xlsx = (string) ras_service()->exportReopenRateExcel($admin, $emptyWindow)['content'];
        assert_same('PK', substr($xlsx, 0, 2), 'empty XLSX is a valid workbook (no error)');

        $pdf = (string) ras_service()->exportReopenRatePdf($admin, $emptyWindow)['content'];
        assert_same('%PDF-', substr($pdf, 0, 5), 'empty PDF renders (no error, no fake numbers)');
    } finally {
        ras_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});
