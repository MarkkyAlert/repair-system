<?php
declare(strict_types=1);

use App\Repositories\ReportRepository;
use App\Services\ReportService;

// Regression for the silent export truncation bug: ReportRepository::getRows() used to clamp LIMIT to
// 1000, so exports (which ask for maxRows+1, up to 50001) were silently capped at 1000 rows with NO
// overflow warning. These insert >1000 tickets and assert: getRows honors the big export limit, the
// CSV export is complete, the on-screen table stays capped at 250, and the LIMIT overflow-probe works.

function rel_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function rel_repo(): ReportRepository
{
    return tvm_container()->get(ReportRepository::class);
}

function rel_reports(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

test('report export: getRows honors export limit (>1000, no silent 1000 clamp) + CSV complete + screen stays 250', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $runId = bin2hex(random_bytes(4));
    $exportJobsBefore = (int) rel_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        // Bulk-insert 1,002 tickets (one statement) so the total crosses the old 1000 clamp.
        $values = [];
        for ($i = 1; $i <= 1002; $i++) {
            $values[] = "('RLIMIT-$runId-$i', 'export limit test', 'x', 1, 1, 1, 1)";
        }
        rel_pdo()->exec(
            'INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id) VALUES '
            . implode(',', $values)
        );

        $total = (int) rel_pdo()->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
        assert_true($total > 1000, 'setup: total tickets > 1000 (got ' . $total . ')');

        // 1) repo honors a big limit (previously clamped to 1000 → silent truncation)
        $rows = rel_repo()->getRows($admin, [], 2000);
        assert_true(count($rows) > 1000, 'getRows(2000) returns >1000 rows (no 1000 clamp)');
        assert_same($total, count($rows), 'getRows returns every ticket up to the requested limit');

        // 3) overflow probe: LIMIT is honored exactly when data exceeds it (this is what powers the
        //    service overflow throw — getRows(maxRows+1) must actually return maxRows+1 when over cap)
        assert_same(5, count(rel_repo()->getRows($admin, [], 5)), 'getRows(5) returns exactly 5 rows');

        // 1b) end-to-end: CSV export must contain all rows, not 1000. (Test data has no embedded
        //     newlines, so one row == one line; assert > 1000 which holds regardless of seed rows.)
        $csv = rel_reports()->exportCsv($admin, []);
        $content = str_replace("\xEF\xBB\xBF", '', $csv['content']);
        $dataRows = count(explode("\n", trim($content))) - 1; // minus header
        assert_true($dataRows > 1000, 'CSV export contains >1000 data rows (not truncated) — got ' . $dataRows);

        // 2) on-screen report table still capped at 250 with an honest capped flag/total
        $page = rel_reports()->getReportPageData($admin, []);
        assert_same(250, count($page['rows']), 'on-screen table stays capped at 250');
        assert_true((bool) $page['rowsMeta']['capped'], 'rowsMeta flags the table as capped');
        assert_true($page['rowsMeta']['total'] > 1000, 'rowsMeta.total reflects the real total');
    } finally {
        rel_pdo()->exec("DELETE FROM tickets WHERE ticket_no LIKE 'RLIMIT-$runId-%'");
        rel_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$exportJobsBefore]);
    }
});
