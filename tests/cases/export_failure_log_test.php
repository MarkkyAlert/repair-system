<?php
declare(strict_types=1);

use App\Repositories\ReportRepository;
use App\Services\ReportExporter;
use App\Services\ReportService;

// Locks the export-failure audit fallback: when an export fails, ReportService records it in export_jobs; if
// that audit write ALSO fails, the secondary failure must not be swallowed silently (a systematically-failing
// export_jobs write would otherwise be invisible). Drives recordExportFailure with a stub ReportRepository
// whose markExportJobFailed throws, and asserts the failure is logged. The original export error is still
// preserved by the caller (out of scope here).

test('export(failure-log): a failing export-failure audit write is logged, not swallowed', function (): void {
    $fakeReports = new class () extends ReportRepository {
        public function __construct()
        {
            // skip the PDO dependency — only markExportJobFailed is exercised
        }

        public function markExportJobFailed(int $jobId, string $errorMessage): void
        {
            throw new RuntimeException('export_jobs write failed');
        }
    };

    $service = new ReportService($fakeReports, new ReportExporter());

    $tmp = tempnam(sys_get_temp_dir(), 'exportfail_') . '.log';
    $originalLog = (string) ini_get('error_log');
    ini_set('error_log', $tmp);

    try {
        // recordExportFailure catches the audit failure internally (it must not throw over the original error)
        call_private($service, 'recordExportFailure', [123, new RuntimeException('the export itself boomed')]);

        $logged = (string) @file_get_contents($tmp);
        assert_contains_str('[report.export.failure]', $logged, 'the audit-write failure is logged with its marker');
        assert_contains_str('job=123', $logged, 'the affected export job id is recorded');
        assert_contains_str('export_jobs write failed', $logged, 'the underlying audit error is recorded');
    } finally {
        ini_set('error_log', $originalLog);
        @unlink($tmp);
    }
});
