<?php

declare(strict_types=1);

// ux-review F1: dates were shown two ways — Thai พ.ศ. on ticket pages vs date('d/m/Y') (ค.ศ.) from services.
// One shared absolute formatter (thai_datetime) now backs both, and human_date() is idempotent so a value a
// service already formatted isn't double-formatted (which used to silently reparse d/m/Y as a US M/D date —
// swapping day/month — or, after the switch to Thai, collapse to "-").

test('date(F1): thai_datetime renders the absolute Thai Buddhist-year format', function (): void {
    assert_same('15 ม.ค. 2563 09:05', thai_datetime('2020-01-15 09:05:00'), 'string input → dd MonthAbbr <พ.ศ.> HH:MM');
    assert_same('15 ม.ค. 2563', thai_datetime('2020-01-15 09:05:00', false), 'withTime=false drops the time');
    assert_same('15 ม.ค. 2563 09:05', thai_datetime(strtotime('2020-01-15 09:05:00')), 'unix-timestamp input works too');
    assert_same('-', thai_datetime(null), 'null → -');
    assert_same('-', thai_datetime(''), 'empty → -');
    assert_true(!str_contains(thai_datetime('2020-01-15 09:05:00'), '2020'), 'the year is Buddhist (2563), never Gregorian');
});

test('date(F1): human_date is idempotent — a raw date formats, an already-formatted one passes through', function (): void {
    // raw → formatted (a past date, so it hits the absolute branch regardless of the clock)
    assert_same('15 ม.ค. 2563 09:05', human_date('2020-01-15 09:05:00'), 'a raw datetime is formatted to Thai');
    // already-formatted → returned unchanged (previously became "-")
    assert_same('15 ม.ค. 2563 09:05', human_date('15 ม.ค. 2563 09:05'), 'an already-formatted Thai date passes through unchanged');
    assert_same('-', human_date('-'), 'a dash stays a dash');
    assert_same('-', human_date(null), 'null → -');
    assert_same('-', human_date(''), 'empty → -');
});

// ux-review-4 F2: the dashboard showed English months (Jan..Dec) + Gregorian year while the rest of the
// system is Thai พ.ศ. Owner decision: Thai everywhere. Display is Thai; query/filter values stay Gregorian.

test('date(F2): thai_year displays Buddhist years (display-only, idempotent)', function (): void {
    assert_same('2569', thai_year(2026), 'Gregorian 2026 → Buddhist 2569');
    assert_same('2569', thai_year('2026'), 'a string year works too');
    assert_same('2569', thai_year(2569), 'an already-Buddhist year is unchanged (safe to double-apply)');
    assert_same('0', thai_year(0), 'an empty/zero year gets no bogus +543');
});

test('date(F2): the dashboard chart + year filter read the Thai calendar (Thai months, พ.ศ. labels, ค.ศ. values)', function (): void {
    $data = tvm_container()->get(\App\Services\TicketService::class)->getDashboardData(['id' => 4, 'role' => 'admin'], []);

    $monthLabels = $data['charts']['monthlyTickets']['labels'] ?? [];
    assert_true(
        in_array('ม.ค.', $monthLabels, true) && in_array('ธ.ค.', $monthLabels, true),
        'the monthly chart uses Thai month abbreviations'
    );
    assert_false(in_array('Jan', $monthLabels, true), 'no English month labels remain on the dashboard');

    $yearOptions = $data['filters']['yearOptions'] ?? [];
    assert_true($yearOptions !== [], 'the year filter has options');
    foreach ($yearOptions as $opt) {
        // label is shown in พ.ศ. (>= 2500) while the value stays Gregorian (< 2500) so the query is unchanged
        assert_true((int) $opt['label'] >= 2500, "year option is displayed in พ.ศ. (label {$opt['label']})");
        assert_true((int) $opt['value'] > 0 && (int) $opt['value'] < 2500, "year option value stays ค.ศ. for the query (value {$opt['value']})");
    }
});
