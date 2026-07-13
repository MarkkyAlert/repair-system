<?php
declare(strict_types=1);

use App\Services\ReportService;

// Drift-lock for คู่มืออ่านรายงาน (/reports/guide). The guide now documents the color thresholds a manager
// uses to read a report ("SLA ≥90% = เขียว", "%เปิดซ้ำ ≥20% = แดง", ...). If those numbers silently drift
// from what the reports actually colour, the guide becomes wrong — worse than no guide. This test pins BOTH
// sides to one canonical set of cutoffs:
//   (1) the code — every tone comes from a single-source method (slaComplianceTone / completionTone /
//       csatTone / reopenTone / breachTone) or a named risk-score constant; asserted at each boundary.
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

    // อัตราปิดงาน (completion) — สูง = ดี: ≥80 เขียว · ≥60 เหลือง · ต่ำกว่าแดง
    assert_same('default', $tone('completionTone', null), 'completion null = ยังไม่มีข้อมูล');
    assert_same('success', $tone('completionTone', 80.0), 'completion ≥80% = เขียว');
    assert_same('warning', $tone('completionTone', 79.99), 'completion ต่ำกว่า 80 = เหลือง');
    assert_same('warning', $tone('completionTone', 60.0), 'completion ≥60% = เหลือง');
    assert_same('danger', $tone('completionTone', 59.99), 'completion ต่ำกว่า 60 = แดง');

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
        '≥ 80%',                // completion green
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
    assert_contains_str('ปิด ÷ (ปิด + งานค้างปัจจุบันของช่าง)', $guide, 'guide states the technician completion denominator (closed + still-open, one cohort)');
    assert_contains_str('อย่านำ % ข้ามสองหน้ามาเทียบกันตรง ๆ', $guide, 'guide warns the two completion %s are not directly comparable');
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
