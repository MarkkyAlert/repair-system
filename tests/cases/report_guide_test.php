<?php
declare(strict_types=1);

use App\Services\ReportService;

// Drift-lock for คู่มืออ่านรายงาน (/reports/guide). The guide now documents the color thresholds a manager
// uses to read a report ("SLA ≥90% = เขียว", "%เปิดซ้ำ ≥20% = แดง", ...). If those numbers silently drift
// from what the reports actually colour, the guide becomes wrong — worse than no guide. This test pins BOTH
// sides to one canonical set of cutoffs:
//   (1) the code — every tone comes from a single-source method (slaComplianceTone / csatTone /
//       reopenTone / breachTone) or a named risk-score constant; asserted at each boundary.
//   (2) the guide file — asserts app/Views/reports/guide.php still prints those same cutoff tokens.
// Change a cutoff in the code and (1) goes red; change it in the guide and (2) goes red — either way you are
// forced to update the other. (BI-review #3: interpretability / guide-vs-code drift.)

function rg_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

test('report guide: code tone thresholds match the documented cutoffs (drift lock)', function (): void {
    $svc = rg_service();
    $tone = static fn (string $method, mixed $arg): string => (string) call_private($svc, $method, [$arg]);

    // SLA / ตรงเวลา — สูง = ดี: ≥90 เขียว · ≥75 เหลือง · ต่ำกว่าแดง · null = ยังไม่มีข้อมูล
    assert_same('default', $tone('slaComplianceTone', null), 'SLA null = ยังไม่มีข้อมูล');
    assert_same('success', $tone('slaComplianceTone', 90.0), 'SLA ≥90% = เขียว');
    assert_same('warning', $tone('slaComplianceTone', 89.99), 'SLA ต่ำกว่า 90 = เหลือง');
    assert_same('warning', $tone('slaComplianceTone', 75.0), 'SLA ≥75% = เหลือง');
    assert_same('danger', $tone('slaComplianceTone', 74.99), 'SLA ต่ำกว่า 75 = แดง');

    // (no completionTone — the technician completion % was removed as a non-immutable people-eval metric, R12)

    // คะแนนความพึงพอใจ / คะแนนช่าง (CSAT) — สูง = ดี: ≥4.0 เขียว · ≥3.0 เหลือง · ต่ำกว่าแดง
    assert_same('success', $tone('csatTone', 4.0), 'CSAT ≥4.0 = เขียว');
    assert_same('warning', $tone('csatTone', 3.99), 'CSAT ต่ำกว่า 4 = เหลือง');
    assert_same('warning', $tone('csatTone', 3.0), 'CSAT ≥3.0 = เหลือง');
    assert_same('danger', $tone('csatTone', 2.99), 'CSAT ต่ำกว่า 3 = แดง');

    // %เปิดซ้ำ (reopen) — ต่ำ = ดี: ≥20 แดง · ≥10 เหลือง · ต่ำกว่าเขียว
    assert_same('danger', $tone('reopenTone', 20.0), 'reopen ≥20% = แดง');
    assert_same('warning', $tone('reopenTone', 19.99), 'reopen ต่ำกว่า 20 = เหลือง');
    assert_same('warning', $tone('reopenTone', 10.0), 'reopen ≥10% = เหลือง');
    assert_same('success', $tone('reopenTone', 9.99), 'reopen ต่ำกว่า 10 = เขียว');

    // %เกิน SLA / overdue (breach) — ต่ำ = ดี: ≥25 แดง · ≥10 เหลือง · ต่ำกว่าเขียว
    assert_same('danger', $tone('breachTone', 25.0), 'breach ≥25% = แดง');
    assert_same('warning', $tone('breachTone', 24.99), 'breach ต่ำกว่า 25 = เหลือง');
    assert_same('warning', $tone('breachTone', 10.0), 'breach ≥10% = เหลือง');
    assert_same('success', $tone('breachTone', 9.99), 'breach ต่ำกว่า 10 = เขียว');

    // คะแนนความเสี่ยง (สุขภาพทรัพย์สิน / พื้นที่ปัญหา) — คะแนนสูง = แย่ (named constants)
    $const = static fn (string $name): int => (int) (new ReflectionClass(ReportService::class))->getConstant($name);
    assert_same(4, $const('HEALTH_REPLACE_SCORE'), 'health ควรเปลี่ยน = score ≥4');
    assert_same(2, $const('HEALTH_WATCH_SCORE'), 'health เฝ้าระวัง = score ≥2');
    assert_same(3, $const('HOTSPOT_PROBLEM_SCORE'), 'hotspot พื้นที่ปัญหา = score ≥3');
    assert_same(2, $const('HOTSPOT_WATCH_SCORE'), 'hotspot เฝ้าระวัง = score ≥2');
});

test('report guide: /reports/guide still prints the same cutoff tokens (drift lock, guide side)', function (): void {
    $guide = (string) file_get_contents(BASE_PATH . '/app/Views/reports/guide.php');

    // each token below is the on-page cutoff that must equal the code assertions above
    $tokens = [
        '≥ 90%',                // SLA green
        '≥ 4.0',                // CSAT green
        '≥ 20%',                // reopen red
        '≥ 25%',                // breach red
        'ควรเปลี่ยน (≥ 4)',      // asset-health replace score
        'พื้นที่ปัญหา (≥ 3)',    // hotspot problem score
    ];
    foreach ($tokens as $token) {
        assert_contains_str($token, $guide, "guide must still state the cutoff \"{$token}\" (matches the code)");
    }

    // and the low-data caveat that stops "5.0 จาก 1 รีวิว" being read as a real result
    assert_contains_str('ข้อมูลน้อย', $guide, 'guide warns about drawing conclusions from tiny samples');
});

test('report guide: glossary defines the non-obvious metrics — formula + direction (round-2 #7)', function (): void {
    $guide = (string) file_get_contents(BASE_PATH . '/app/Views/reports/guide.php');

    // a manager can read a number correctly only if the guide says what it MEANS. These metrics are easy to
    // misread, so the glossary must name and define each (not just list the report that shows it).
    $terms = [
        'อภิธานศัพท์',                 // the glossary section itself
        'MTBF',                        // mean time between failures
        'First-Time-Fix',              // FTF / ปิดจบรอบเดียว
        'สัดส่วนโหลด',                 // workload share
        'เวลาตอบรับ',                  // first response
        'เวลาซ่อมเฉลี่ย',              // MTTR
        'คะแนนสุขภาพทรัพย์สิน',        // asset-health score
    ];
    foreach ($terms as $term) {
        assert_contains_str($term, $guide, "glossary must define \"{$term}\"");
    }

    // definition anchors so each entry is a real definition (formula / base / direction), not just the term
    assert_contains_str('จำนวนครั้ง', $guide, 'MTBF entry shows the per-interval formula');
    assert_contains_str('ไม่ขึ้นกับช่วงวันที่', $guide, 'workload share is defined as a live snapshot');
});

// BI-review F2: two interpretability traps the guide must state so managers don't misread the numbers —
// (1) "net" is only แจ้ง−ปิด (it does NOT subtract cancel/reject, so it isn't an exact backlog delta), and
// (2) "completion %" uses a different denominator on the executive vs technician page (don't compare them
// across pages). Drift-lock: the guide file must keep saying both, or this reddens.
test('report guide: documents the net-is-not-backlog + completion-denominator caveats (F2)', function (): void {
    $guide = (string) file_get_contents(BASE_PATH . '/app/Views/reports/guide.php');

    assert_contains_str('สุทธิ (net)', $guide, 'guide defines the "net" metric');
    assert_contains_str('ยังไม่หักงานที่ยกเลิก/ปฏิเสธ', $guide, 'guide warns net does not subtract cancel/reject (not an exact backlog change)');
    // R12: the guide must state completion is executive-only and explain WHY the technician page has no per-tech %
    assert_contains_str('เฉพาะสรุปผู้บริหาร', $guide, 'guide scopes the completion % to the executive report only');
    assert_contains_str('รายงานผลงานช่าง "ไม่มี" อัตราปิดงานรายคน', $guide, 'guide explains the technician report has no per-tech completion %');
    // R10-F1: the guide must also state the three "resolved" counts use different grains on purpose
    assert_contains_str('ยอดปิดงาน — นับต่างกันตามหน้า', $guide, 'guide documents the executive/trend/technician resolved-count grain difference');
});

// BI-review (ChatGPT R10) F3: the short "at a glance" lines on the guide + trend page must NOT assert the
// created-over-resolved line "= งานค้างกำลังสะสม" (backlog IS accumulating) as fact — net doesn't subtract
// cancel/reject, so it's only a rough signal. This drift-locks the definitive claim OUT and the caveat wording IN,
// matching the detailed hint that already says so (otherwise the eyebrow contradicts the glossary).
test('report guide/trend: the at-a-glance net line is a hedged signal, not a definitive backlog claim (R10-F3)', function (): void {
    $guide = (string) file_get_contents(BASE_PATH . '/app/Views/reports/guide.php');
    $trend = (string) file_get_contents(BASE_PATH . '/app/Views/reports/trend.php');

    foreach (['guide' => $guide, 'trend' => $trend] as $name => $html) {
        assert_false(str_contains($html, 'เส้นปิด = งานค้างกำลังสะสม'), "$name must not state created-over-resolved as a definitive backlog claim");
        assert_contains_str('สัญญาณคร่าว ๆ ว่างานค้างอาจเพิ่ม', $html, "$name hedges the net line as a rough signal");
    }
});

// AN-01 follow-up (2026-07-26): the guide claims a closed period freezes its ปิดงาน · MTTR · SLA · คะแนน.
// That sentence was false for SLA until the as-of fix — the queries still picked the newest cycle and judged
// overdue against NOW(), so a reopen rewrote a closed month. A doc that promises a guarantee the code does not
// keep is worse than no doc, so both halves are pinned here: the promise stays in the guide, and the code keeps
// it (sla_period_freeze_test drives the reopen end-to-end). Also pins the ≥30 backlog boundary, which the guide
// printed as ">30 วัน" while the query, the screen, the export and the PDF all use >= 30.
test('report guide: the period-freeze promise and the ≥30 backlog boundary match the code (AN-01)', function (): void {
    $guide = (string) file_get_contents(BASE_PATH . '/app/Views/reports/guide.php');
    $repo = (string) file_get_contents(BASE_PATH . '/app/Repositories/ReportRepository.php');

    // (1) the guide still promises the freeze — including SLA by name
    assert_contains_str('งวดที่จบแล้วตรึงยอดปิด · MTTR · SLA · คะแนน', $guide, 'the guide promises closed periods are frozen');

    // (2) the code keeps it: every SLA verdict tied to a reporting PERIOD goes through the as-of helpers,
    //     whose cutoff is the period end (and only falls back to NOW() when no window is selected).
    assert_contains_str('latestSlaCycleAsOf', $repo, 'SLA cycle selection is as-of the period end');
    assert_contains_str('slaBreachedAsOf', $repo, 'the overdue verdict is as-of the period end');

    //     Exactly ONE clock-based SLA verdict may remain: the overview's "งานค้างที่เลยกำหนด" card, which is a
    //     deliberate live-backlog snapshot (it reads the live t.status, and the guide carves backlog numbers out
    //     of the freeze promise). A second occurrence means a period metric slipped back onto the clock.
    $clockVerdict = "ts.status = 'pending' AND ts.target_at < NOW()";
    assert_same(1, substr_count($repo, $clockVerdict), 'only the live backlog card may judge SLA against the clock');
    $where = (int) strpos($repo, $clockVerdict);
    assert_contains_str('overdue_tickets', substr($repo, $where, 600), 'and that one occurrence is the live backlog card');

    // (3) the backlog boundary is inclusive in the code, so the guide must not print the exclusive form
    assert_false(str_contains($guide, '>30 วัน'), 'the guide must not print the exclusive >30 form');
    assert_contains_str('≥30 วัน', $guide, 'the guide uses the inclusive ≥30 boundary the query implements');

    // (4) the hotspot rate definition the code now implements is documented
    assert_contains_str('งานที่พลาดกำหนดในช่วง ÷ งานที่ตัดสินผลได้แล้ว', $guide, 'guide defines %เกิน SLA as missed over judged');
});

// AN-01 verify follow-up (2026-07-26): the first guard only scanned SQL strings in ReportRepository, so it sailed
// straight past a SIXTH SLA verdict living in the service layer — ReportService::buildSlaMetricState compared the
// deadline to time(). The header was frozen to the period end while the detail row and every export below it
// still consulted today's clock, so one screen contradicted itself.
//
// SCOPE — read this before trusting it. This guard is a PATTERN SCANNER plus a couple of behavioural probes,
// and a scanner can never be complete. An independent review demonstrated it catching only 2 of 8 mutations:
// `time ()` with a space, strtotime('now'), date('U'), `NOW() > target_at` with the operands swapped, CURDATE(),
// and a clock added on the repository side all slip past it. Earlier revisions of this comment claimed far more
// than that, twice, which is worse than a weak guard because it stops people looking.
//
// What it is actually good for: it fails FAST and points at a method name when someone writes the obvious form.
// The real protection against a closed period rewriting itself is behavioural and lives in
// closed_period_immutability_test — snapshot a finished period, drive real workflow actions after it, demand the
// snapshot is byte-identical. That one is immune to which clock API (or none at all — a wrong-cycle deadline is
// not a clock bug) the regression happens to use, because it never reads the source.

test('sla as-of: every layer that judges "overdue" honours the period end, not the clock (cross-layer guard)', function (): void {
    $svc = rg_service();
    $state = new ReflectionMethod(ReportService::class, 'buildSlaMetricState');
    $state->setAccessible(true);
    $summary = new ReflectionMethod(ReportService::class, 'buildSlaSummary');
    $summary->setAccessible(true);

    $requested = date('Y-m-d H:i:s', strtotime('-10 days'));
    $target = date('Y-m-d H:i:s', strtotime('-2 days'));

    // (1) SERVICE behaviour — the same unfinished ticket, judged at two different reference moments
    assert_same(
        'breached',
        (string) ($state->invoke($svc, $target, null, $requested)['status'] ?? ''),
        'live view (no as-of): a deadline already past is breached'
    );
    assert_same(
        'pending',
        (string) ($state->invoke($svc, $target, null, $requested, strtotime('-5 days'))['status'] ?? ''),
        'as of a moment BEFORE the deadline it was not yet late — the row must not import today into a closed period'
    );

    // (2) SERVICE population — rejected work is not judged on SLA, exactly like cancelled, so the row cannot
    //     contradict the totals (the repository already excludes both)
    foreach (['cancelled', 'rejected'] as $status) {
        $row = $summary->invoke($svc, ['status' => $status, 'resolution_due_at' => $target, 'requested_at' => $requested]);
        assert_same('ไม่คิด SLA', (string) ($row['label'] ?? ''), "$status work is not judged on SLA in the detail row");
        assert_false((bool) ($row['is_overdue'] ?? true), "$status work is never flagged overdue");
    }

    // (3) SOURCE — no caller may drop the cutoff on the floor, and no new bare-clock SLA comparison may appear
    $service = (string) file_get_contents(BASE_PATH . '/app/Services/ReportService.php');
    assert_false(
        str_contains($service, 'mapReportRow($row)'),
        'every mapReportRow call site must pass the as-of cutoff through to the row SLA'
    );
    assert_false(
        str_contains($service, '$targetTimestamp < time()'),
        'the row SLA verdict must go through the as-of parameter, never a bare time()'
    );

    // (4) SOURCE, the whole REPORTING layer — repository + service together. Scoped to reporting on purpose:
    //     TicketReadRepository legitimately consults the clock (a technician's live queue, the "overdue" filter,
    //     the SLA alert cron) because those surfaces ARE "right now". Reporting is the layer that must answer as
    //     of a chosen period, so exactly one clock-based deadline test may survive here: the overview's live
    //     backlog card, which the guide explicitly excludes from the freeze promise.
    $reportingLayer = (string) file_get_contents(BASE_PATH . '/app/Repositories/ReportRepository.php') . $service;
    assert_same(
        1,
        substr_count($reportingLayer, 'target_at < NOW()'),
        'only the live backlog card may test a deadline against the clock anywhere in the reporting layer'
    );

    // (5) SOURCE, per METHOD — the previous version of this guard only drove buildSlaMetricState, so a clock
    //     verdict added anywhere ELSE in ReportService (e.g. `$deadline < time()` inside buildSlaSummary) sailed
    //     past it. Instead of trusting a list of known entry points, this asserts the inverse: every single use
    //     of the clock in ReportService must live in a method that is ALLOWED to be "now"-based. A new bare
    //     time() in any other method — existing or newly written — fails, whatever shape the comparison takes.
    $reflection = new ReflectionClass(ReportService::class);
    $lines = (array) file(BASE_PATH . '/app/Services/ReportService.php');
    $ownerOfLine = [];
    foreach ($reflection->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() !== ReportService::class) {
            continue;
        }
        for ($line = (int) $method->getStartLine(); $line <= (int) $method->getEndLine(); $line++) {
            $ownerOfLine[$line] = $method->getName();
        }
    }
    // methods whose whole job is a live/relative measurement, plus the one as-of helper and its fallback
    $mayUseClock = [
        'yearsSince',              // asset age today
        'warrantyState',           // in/out of warranty today
        'meanTimeBetweenFailures', // rejects future failure timestamps
        'daysSince',               // oldest-open age today
        'reportAsOfTimestamp',     // derives the as-of moment (and clamps it to now)
        'buildSlaMetricState',     // only as the `$asOf ?? time()` fallback, asserted below
    ];
    $offenders = [];
    foreach ($lines as $index => $text) {
        if (!str_contains((string) $text, 'time()') || str_contains((string) $text, 'thai_datetime(time())')) {
            continue; // print stamps ("generated at") are unambiguous and always live
        }
        $method = $ownerOfLine[$index + 1] ?? '(outside any method)';
        if (!in_array($method, $mayUseClock, true)) {
            $offenders[] = $method . ' @ line ' . ($index + 1);
        }
        if ($method === 'buildSlaMetricState' && !str_contains((string) $text, '$asOf ?? time()')) {
            $offenders[] = 'buildSlaMetricState uses a bare clock @ line ' . ($index + 1);
        }
    }
    assert_same(
        [],
        $offenders,
        'every clock use in ReportService must sit in a method allowed to be live: ' . implode(', ', $offenders)
    );
});
