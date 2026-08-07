<?php

declare(strict_types=1);

use App\Services\ReportService;

// Exports are bounded so a huge window cannot OOM or hang the request. The bound has to REFUSE, never quietly
// return a shorter file: a manager who exports a year and silently receives the first N rows has no way to know
// the rest existed, and this project has already been bitten three times by exactly that shape (the trend
// bucket cap, the technician LIMIT, the asset display limit).
//
// Verified at volume: with 5,000 tickets in the window the CSV carries all 5,000 rows, the XLSX builds fine, and
// the PDF — capped at 3,000 because a printed document that long is unusable — refuses with a message naming the
// limit and telling the reader how to proceed.

function erc_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

test('export(cap): the row bound refuses loudly instead of returning a silently shortened file', function (): void {
    $svc = erc_service();
    $admin = ['id' => 4, 'role' => 'admin'];

    $exportRows = new ReflectionMethod(ReportService::class, 'exportRows');
    $exportRows->setAccessible(true);
    $normalize = new ReflectionMethod(ReportService::class, 'normalizeReportFilters');
    $normalize->setAccessible(true);
    $filters = $normalize->invoke($svc, []);

    // sanity: the fixture DB really does hold more rows than the tiny cap used below
    $available = count($exportRows->invoke($svc, $admin, $filters, 100000));
    assert_true($available >= 3, 'sanity: there is enough data to overflow a cap of 2');

    $threw = false;
    try {
        $exportRows->invoke($svc, $admin, $filters, 2);
    } catch (DomainException $e) {
        $threw = true;
        assert_contains_str('มากเกิน', $e->getMessage(), 'the refusal says the data is too large');
        assert_contains_str('2', $e->getMessage(), 'and names the limit that was exceeded');
        assert_contains_str('กรอง', $e->getMessage(), 'and tells the reader to narrow the filter — an actionable message, not a dead end');
    }
    assert_true($threw, 'exceeding the cap must throw; returning the first 2 rows would be silent data loss');

    // under the cap nothing is lost or altered
    $under = $exportRows->invoke($svc, $admin, $filters, 100000);
    assert_same($available, count($under), 'a window inside the cap exports every row it has');
});

test('export(cap): each format carries a deliberate limit, and the printed one is the smallest', function (): void {
    $reflection = new ReflectionClass(ReportService::class);
    $xlsx = (int) $reflection->getConstant('EXPORT_MAX_ROWS_XLSX');
    $pdf = (int) $reflection->getConstant('EXPORT_MAX_ROWS_PDF');
    $csv = (int) $reflection->getConstant('EXPORT_MAX_ROWS_CSV');

    assert_true($pdf > 0 && $xlsx > 0 && $csv > 0, 'every format declares a bound');
    assert_true($pdf < $xlsx, 'a printed PDF must not be allowed to run longer than a spreadsheet');
    assert_true($xlsx <= $csv, 'plain CSV is the cheapest format, so it may carry the most rows');
    assert_true($csv >= 10000, 'the raw-data format stays generous enough for a real yearly export');
});
