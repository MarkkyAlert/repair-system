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
