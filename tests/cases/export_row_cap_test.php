<?php

declare(strict_types=1);

use App\Repositories\ReportRepository;
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

// The caps come in a pair, and only one half is visible from the export code. ReportService bounds each format,
// hands that bound to the repository, and the repository clamps it again against its own MAX_ROWS before the
// query runs. The overflow probe above works by asking for maxRows+1 and checking whether the extra row comes
// back — so the moment a service cap exceeds the repository's, the probe's extra row is eaten by the clamp,
// nothing looks like an overflow, and the export returns a shortened file with no warning.
//
// That is the exact failure the first test in this file exists to prevent, and it is reachable through a change
// that looks entirely reasonable: a buyer wants a bigger yearly CSV, someone raises EXPORT_MAX_ROWS_CSV past
// 100k, and every other assertion here still passes. The comment on ReportRepository::MAX_ROWS stated this
// pairing; nothing enforced it.
//
// Derived by reflection rather than listed by hand, so a cap added later is covered without anyone remembering.
test('export(cap): no service row cap may exceed the repository cap that clamps it', function (): void {
    $repoCap = (int) (new ReflectionClass(ReportRepository::class))->getConstant('MAX_ROWS');
    assert_true($repoCap > 0, 'the repository still declares the cap every row query is clamped with');

    $caps = [];
    foreach ((new ReflectionClass(ReportService::class))->getConstants() as $name => $value) {
        if (is_int($value) && str_contains($name, 'MAX_ROWS')) {
            $caps[$name] = $value;
        }
    }
    assert_true(
        count($caps) >= 4,
        'the service still declares its per-format and summary row caps (found: ' . implode(', ', array_keys($caps)) . ')'
    );

    foreach ($caps as $name => $value) {
        assert_true(
            $value <= $repoCap,
            $name . ' is ' . number_format($value) . ', above ReportRepository::MAX_ROWS (' . number_format($repoCap)
            . '), so the overflow probe gets clamped away and this export truncates silently instead of refusing'
        );
    }
});
