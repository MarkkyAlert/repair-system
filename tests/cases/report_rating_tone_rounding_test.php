<?php

declare(strict_types=1);

use App\Services\ReportService;

// BI-review (2026-07-26): the technician page rounded the average rating for the LABEL but toned the badge from
// the UNROUNDED value. csatTone cuts at >= 4.0, so an average of 3.96 rendered the string "4.0" wearing a yellow
// warning badge while /reports/guide promises เขียว at >= 4.0. Every sibling (mapCsatRow, buildCsatSummary)
// rounds first and tones the rounded value. On a personnel-evaluation table a cell that reads 4.0 but is not
// green reads as a broken report, so label and colour must be derived from the same number.

test('technician rating: the badge colour is derived from the SAME rounded number the cell shows', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $map = new ReflectionMethod(ReportService::class, 'mapTechnicianPerformanceRow');
    $map->setAccessible(true);

    // 25 reviews summing to 99 => 3.96 => displays as "4.0"
    $row = $map->invoke($svc, 'Tone Probe', [
        'rating_count' => 25,
        'rating_sum' => 99,
    ], [], 0, 1);

    assert_same('4.0', (string) $row['avg_rating_label'], 'the cell displays 4.0 (3.96 rounded to one decimal)');
    assert_same(
        'success',
        (string) $row['avg_rating_tone'],
        'a cell reading 4.0 must be green — the guide documents เขียว at >= 4.0, so label and colour cannot disagree'
    );

    // and the boundary still discriminates: a genuine 3.9 stays yellow
    $lower = $map->invoke($svc, 'Tone Probe Low', [
        'rating_count' => 10,
        'rating_sum' => 39,
    ], [], 0, 1);
    assert_same('3.9', (string) $lower['avg_rating_label'], 'a genuine 3.9 average');
    assert_same('warning', (string) $lower['avg_rating_tone'], 'still yellow — the fix did not just paint everything green');
});
