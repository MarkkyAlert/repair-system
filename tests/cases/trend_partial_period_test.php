<?php

declare(strict_types=1);

use App\Services\ReportService;

// BI-review (2026-07-26, owner decision "เทียบเท่าจำนวนวันที่ผ่านไป"): the trend page's "งวดล่าสุด" card compared the
// CURRENT, still-running period against a COMPLETE previous one. On the 10th of a month a manager saw
// "แจ้งซ่อม 12, เทียบงวดก่อน −38" — a fabricated collapse that healed itself by month end, so the same report
// contradicted itself week to week. The executive page already equalises elapsed days in computePeriodWindows;
// the trend page never got that treatment.
//
// The previous period is now truncated to the same number of elapsed days before the delta is taken.
// The window below sits entirely in the past so "elapsed days" is fixed (10) whatever day the suite runs.

function tpp_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function tpp_ticket(string $sfx, string $requestedAt, int $n, int $locId, int $catId, int $priId): void
{
    tpp_pdo()->prepare(
        'INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id,
            priority_id, status, approval_status, requested_at, created_at, updated_at)
         VALUES (?, ?, "x", 1, ?, ?, ?, "in_progress", "approved", ?, NOW(), NOW())'
    )->execute(["TPP-$sfx-$n", "tpp $n", $locId, $catId, $priId, $requestedAt]);
}

test('trend(partial): a half-finished latest period is compared against the SAME number of days, not a full one', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $sfx = bin2hex(random_bytes(4));

    $pdo = tpp_pdo();
    $pdo->prepare('INSERT INTO locations (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["TPPL-$sfx", "TppLoc-$sfx"]);
    $locId = (int) $pdo->lastInsertId();
    $catId = (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn();
    $priId = (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn();

    // two whole months in the past: prev = -3 months (complete), last = -2 months (cut off at day 10).
    // Anchor the month subtraction to the 1st of this month: subtracting months from a 31st (e.g. Jul 31)
    // overflows shorter months (Apr/Jun 31 → next month), which collapsed -2 and -3 into the same month.
    $monthAnchor = new DateTimeImmutable('first day of this month');
    $lastStart = $monthAnchor->modify('-2 months');
    $prevStart = $monthAnchor->modify('-3 months');
    $observedTo = $lastStart->modify('+9 days'); // 10 elapsed days in the latest period

    try {
        // latest period: 2 tickets inside the first 10 days
        tpp_ticket($sfx, $lastStart->format('Y-m-d') . ' 09:00:00', 1, $locId, $catId, $priId);
        tpp_ticket($sfx, $lastStart->modify('+2 days')->format('Y-m-d') . ' 09:00:00', 2, $locId, $catId, $priId);
        // previous period: 2 tickets in its first 10 days …
        tpp_ticket($sfx, $prevStart->format('Y-m-d') . ' 09:00:00', 3, $locId, $catId, $priId);
        tpp_ticket($sfx, $prevStart->modify('+2 days')->format('Y-m-d') . ' 09:00:00', 4, $locId, $catId, $priId);
        // … plus 5 more LATE in that month, which a like-for-like comparison must not count
        foreach ([20, 21, 22, 23, 24] as $i => $day) {
            tpp_ticket($sfx, $prevStart->modify("+$day days")->format('Y-m-d') . ' 09:00:00', 10 + $i, $locId, $catId, $priId);
        }

        $summary = $svc->getTicketTrendReportPage($admin, [
            'granularity' => 'month',
            'from_date' => $prevStart->format('Y-m-d'),
            'to_date' => $observedTo->format('Y-m-d'),
            'location_id' => $locId,
        ])['summary'] ?? [];

        assert_same('2', (string) $summary['created']['value'], 'the latest (partial) period genuinely has 2 tickets');
        assert_same(
            'เท่าเดิม',
            (string) $summary['created']['delta_label'],
            'the previous month is cut to the same 10 days (2 vs 2) — before the fix it compared against all 7 and reported a −5 collapse'
        );
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE ticket_no LIKE ?')->execute(["TPP-$sfx-%"]);
        $pdo->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});

test('trend(partial): a COMPLETE latest period still compares against the whole previous period', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $sfx = bin2hex(random_bytes(4));

    $pdo = tpp_pdo();
    $pdo->prepare('INSERT INTO locations (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["TPPL-$sfx", "TppLoc-$sfx"]);
    $locId = (int) $pdo->lastInsertId();
    $catId = (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn();
    $priId = (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn();

    // anchor to the 1st before subtracting months (see the note in the first test — avoids month-end overflow)
    $monthAnchor = new DateTimeImmutable('first day of this month');
    $lastStart = $monthAnchor->modify('-2 months');
    $prevStart = $monthAnchor->modify('-3 months');
    $lastEnd = $lastStart->modify('last day of this month');

    try {
        tpp_ticket($sfx, $lastStart->format('Y-m-d') . ' 09:00:00', 1, $locId, $catId, $priId);
        // 3 in the previous month, one of them late — all must count because the latest period is complete
        tpp_ticket($sfx, $prevStart->format('Y-m-d') . ' 09:00:00', 2, $locId, $catId, $priId);
        tpp_ticket($sfx, $prevStart->modify('+1 day')->format('Y-m-d') . ' 09:00:00', 3, $locId, $catId, $priId);
        tpp_ticket($sfx, $prevStart->modify('+25 days')->format('Y-m-d') . ' 09:00:00', 4, $locId, $catId, $priId);

        $summary = $svc->getTicketTrendReportPage($admin, [
            'granularity' => 'month',
            'from_date' => $prevStart->format('Y-m-d'),
            'to_date' => $lastEnd->format('Y-m-d'),
            'location_id' => $locId,
        ])['summary'] ?? [];

        assert_same('1', (string) $summary['created']['value'], 'the finished latest period has 1 ticket');
        assert_contains_str(
            '-2',
            (string) $summary['created']['delta_label'],
            'a finished period is compared against the FULL previous month (1 vs 3) — equalisation must not kick in'
        );
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE ticket_no LIKE ?')->execute(["TPP-$sfx-%"]);
        $pdo->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
    }
});
