<?php

declare(strict_types=1);

use App\Core\View;
use App\Services\ReportService;
use Smalot\PdfParser\Parser;

// AN-02 round 3 (2026-07-26): the trend report's per-period TABLE already carried จำนวนรีวิว, but the three
// surfaces above it did not — the "คะแนนเฉลี่ย (งวดล่าสุด)" card, the same block in the trend PDF, and the CSAT
// chart payload, which shipped only the averages. So a period whose 5.00 came from a single review looked
// identical to one backed by forty, and hovering the chart told the reader nothing either.
//
// The period rows have carried rating_count all along (buildTrendPeriodRow); it simply never reached the summary,
// the chart or the printed page.

function trs_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** One rated, completed ticket inside the current month for a throwaway department. */
function trs_seed(string $sfx, int $deptId, int $score): void
{
    $pdo = trs_pdo();
    $locationId = (int) $pdo->query('SELECT id FROM locations LIMIT 1')->fetchColumn();
    $catId = (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn();
    $priId = (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn();
    $requested = date('Y-m-d H:i:s', strtotime('-2 days'));
    $resolved = date('Y-m-d H:i:s', strtotime('-1 day'));

    $pdo->prepare(
        'INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id,
            ticket_category_id, priority_id, assigned_technician_id, status, approval_status, requested_at,
            resolved_at, completed_at, created_at, updated_at)
         VALUES (?, ?, "x", 1, ?, ?, ?, ?, 3, "completed", "approved", ?, ?, ?, NOW(), NOW())'
    )->execute(["TRS-$sfx", 'trend rating', $deptId, $locationId, $catId, $priId, $requested, $resolved, $resolved]);
    $ticketId = (int) $pdo->lastInsertId();

    // the trend reads its per-cycle CSAT off the resolve event + rating cycle
    $pdo->prepare("INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status, created_at)
                   VALUES (?, 3, 'ticket_resolved', 'in_progress', 'resolved', ?)")->execute([$ticketId, $resolved]);
    $pdo->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, cycle, score, feedback, created_at, updated_at)
                   VALUES (?, 1, 3, 1, ?, "x", ?, ?)')->execute([$ticketId, $score, $resolved, $resolved]);
}

test('trend rating: the งวดล่าสุด card, its PDF and the chart all disclose how many reviews the score came from', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $pdo = trs_pdo();
    $sfx = bin2hex(random_bytes(4));

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["TRSD-$sfx", "TrsDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();

    $filters = [
        'granularity' => 'month',
        'from_date' => date('Y-m-01', strtotime('-2 months')),
        'to_date' => date('Y-m-d'),
        'department_id' => $deptId,
    ];

    try {
        trs_seed($sfx, $deptId, 5); // exactly one review, top score

        $page = $svc->getTicketTrendReportPage($admin, $filters);

        // (1) the summary card payload must carry the sample size, not just the average
        assert_same('5.00', (string) ($page['summary']['csat']['value'] ?? ''), 'the average itself');
        assert_same(
            1,
            (int) ($page['summary']['csat']['sample_count'] ?? -1),
            'the latest-period card carries the review count behind that 5.00'
        );

        // (2) the chart payload must ship the per-bucket counts so a point can be read honestly
        $counts = $page['charts']['trendCsat']['sample_counts'] ?? null;
        assert_true(is_array($counts), 'the CSAT chart ships a parallel series of review counts');
        assert_same(1, (int) array_sum($counts), 'and they add up to the single review that exists');

        // (3) the rendered page states it in words
        $html = View::capture('reports/trend', $page);
        assert_contains_str('จาก 1 รีวิว', $html, 'the trend page tells the reader the score came from one review');

        // (4) so does the printed PDF
        $pdf = (string) ($svc->exportTicketTrendPdf($admin, $filters)['content'] ?? '');
        assert_true(str_starts_with($pdf, '%PDF-'), 'sanity: a real PDF was produced');
        assert_contains_str('จาก 1 รีวิว', (new Parser())->parseContent($pdf)->getText(), 'the trend PDF discloses it too');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE ticket_no = ?')->execute(["TRS-$sfx"]);
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

test('trend rating: a period with no reviews claims no sample rather than a fake zero', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $pdo = trs_pdo();
    $sfx = bin2hex(random_bytes(4));

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["TRSD-$sfx", "TrsDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();

    $filters = [
        'granularity' => 'month',
        'from_date' => date('Y-m-01', strtotime('-2 months')),
        'to_date' => date('Y-m-d'),
        'department_id' => $deptId,
    ];

    try {
        $page = $svc->getTicketTrendReportPage($admin, $filters);
        assert_same('-', (string) ($page['summary']['csat']['value'] ?? ''), 'no reviews = no score');
        assert_same(0, (int) ($page['summary']['csat']['sample_count'] ?? -1), 'and an honest zero base');

        $html = View::capture('reports/trend', $page);
        assert_false(str_contains($html, 'จาก 0 รีวิว'), 'an empty period does not advertise a zero-review sample');
    } finally {
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

// The chart tooltip lives in JavaScript, so no PHP test can prove Chart.js actually paints it — that was verified
// in the browser (the CSAT tooltip renders afterBody "จาก 1 รีวิว" on the rated point, and "" on an empty bucket).
// What this DOES lock is the wiring between the two halves: the backend ships sample_counts and the frontend
// consumes it. If either side is deleted the contract breaks silently, and the payload assertions above would
// still pass. Deliberately a source check, and named as one — it is not evidence of rendering.
test('trend rating(wiring): the chart script consumes the sample_counts the backend ships', function (): void {
    $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/app.js');

    assert_contains_str('sample_counts', $js, 'the chart script reads the per-bucket review counts');
    assert_contains_str('รีวิว', $js, 'and renders them in words next to the score');
    assert_true(
        str_contains($js, 'afterLabel') || str_contains($js, 'afterBody'),
        'the counts are attached to the tooltip, not merely parsed and dropped'
    );
});

// AN-02 round 5 (2026-07-26): the trend card compares two periods' scores but shipped the review count of only
// the LATEST one, so "คะแนนเฉลี่ย 5.00 · เทียบงวดก่อน +1.00" hid that the 4.00 it was measured against came from
// three reviews and the 5.00 from one. Every other trend surface (chart, table, CSV/XLSX) already carried both
// counts — only the card and its PDF twin dropped the previous period.
test('trend rating: the card and its PDF name the review count of BOTH periods, not just the latest', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $pdo = trs_pdo();
    $sfx = bin2hex(random_bytes(4));

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["TRSP-$sfx", "TrsPrevDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();
    $locationId = (int) $pdo->query('SELECT id FROM locations LIMIT 1')->fetchColumn();
    $catId = (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn();
    $priId = (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn();

    $rate = static function (string $no, string $when, int $score) use ($pdo, $deptId, $locationId, $catId, $priId): void {
        $pdo->prepare(
            'INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id,
                ticket_category_id, priority_id, assigned_technician_id, status, approval_status, requested_at,
                resolved_at, completed_at, created_at, updated_at)
             VALUES (?, ?, "x", 1, ?, ?, ?, ?, 3, "completed", "approved", ?, ?, ?, NOW(), NOW())'
        )->execute([$no, 'trend prev', $deptId, $locationId, $catId, $priId, $when, $when, $when]);
        $ticketId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status, created_at)
                       VALUES (?, 3, 'ticket_resolved', 'in_progress', 'resolved', ?)")->execute([$ticketId, $when]);
        $pdo->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, cycle, score, feedback, created_at, updated_at)
                       VALUES (?, 1, 3, 1, ?, "x", ?, ?)')->execute([$ticketId, $score, $when, $when]);
    };

    $filters = [
        'granularity' => 'month',
        // anchor to first-of-month before shifting: "-1 month" from a 31-day month (run on the 31st) overflows
        // into the current month, collapsing the this-vs-prev split. "first day of last month" never overflows.
        'from_date' => date('Y-m-01', strtotime('first day of last month')),
        'to_date' => date('Y-m-d'),
        'department_id' => $deptId,
    ];

    try {
        // known answer: previous month 3 reviews averaging 4, this month a single 5
        $prevWhen = date('Y-m-01 10:00:00', strtotime('first day of last month'));
        foreach ([1, 2, 3] as $i) {
            $rate("TRSP-$sfx-P$i", $prevWhen, 4);
        }
        $rate("TRSP-$sfx-C1", date('Y-m-01 10:00:00'), 5);

        $page = $svc->getTicketTrendReportPage($admin, $filters);
        assert_same(1, (int) ($page['summary']['csat']['sample_count'] ?? -1), 'latest period base');
        assert_same(
            3,
            (int) ($page['summary']['csat']['prev_sample_count'] ?? -1),
            'the card must also carry the previous period base — the delta is meaningless without both'
        );

        // and it must reach the reader, not just the payload
        $html = View::capture('reports/trend', $page);
        assert_contains_str('จาก 3 รีวิว', $html, 'the trend page prints the previous period base');
        $pdf = (string) ($svc->exportTicketTrendPdf($admin, $filters)['content'] ?? '');
        assert_contains_str('จาก 3 รีวิว', (new Parser())->parseContent($pdf)->getText(), 'and so does the trend PDF');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE ticket_no LIKE ?')->execute(["TRSP-$sfx-%"]);
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});
