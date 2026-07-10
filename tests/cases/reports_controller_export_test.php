<?php
declare(strict_types=1);

use App\Controllers\ReportsController;
use App\Services\ReportService;

// The ticket-report export triplet (POST /reports/export/{excel,pdf,csv}) delegates to the shared
// downloadReport() helper — the same auth + csrf + Response::download flow every other report export
// already uses. This locks the wiring so the dedup can't silently drift: each route must hand
// downloadReport the correct service method, fallback filename, content-type, and error-redirect path.
//
// A spy subclass overrides the (protected) downloadReport to record its arguments instead of running
// the real one (which calls AuthMiddleware::handle / csrf_validate / Response::download → exit, none of
// which are drivable inside the in-process harness). The controller method body itself still runs for
// real, so a wrong delegated argument reddens this test.
test('reports export triplet delegates to downloadReport with the right service method, fallbacks, and redirect', function (): void {
    $spy = new class (tvm_container()->get(ReportService::class)) extends ReportsController {
        /** @var array<int, array{0:string,1:string,2:string,3:string}> */
        public array $calls = [];

        protected function downloadReport(string $serviceMethod, string $fallbackName, string $fallbackType, string $redirectPath): void
        {
            $this->calls[] = [$serviceMethod, $fallbackName, $fallbackType, $redirectPath];
        }
    };

    $spy->exportExcel();
    $spy->exportPdf();
    $spy->exportCsv();

    assert_count(3, $spy->calls);
    assert_same(['exportExcel', 'report.xlsx', 'application/octet-stream', '/reports'], $spy->calls[0], 'excel wiring');
    assert_same(['exportPdf', 'report.pdf', 'application/pdf', '/reports'], $spy->calls[1], 'pdf wiring');
    assert_same(['exportCsv', 'report.csv', 'text/csv; charset=UTF-8', '/reports'], $spy->calls[2], 'csv wiring');
});
