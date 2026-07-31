<?php

declare(strict_types=1);

use App\Core\View;
use App\Services\ReportService;
use Smalot\PdfParser\Parser;

// AN-02 (2026-07-26): the overview KPI card and its PDF printed "คะแนนเฉลี่ย 5.0" with no sample size, so a single
// five-star review looked identical to a hundred of them. getSummary already computes rating_count — the payload
// simply dropped it. A manager quoting "5.0 satisfaction" from one review is the exact low-data trap the rest of
// the reporting layer guards against (the CSAT page, the technician table and the executive card all show their
// base already).
//
// Gating the label on the review COUNT rather than on avg > 0 also closes a second hole: a period whose reviews
// are all the lowest score would have read '-' ("no data") instead of a real, bad score.

function ors_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('overview rating: a 5.0 built from ONE review says so on the page payload and in the PDF', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $pdo = ors_pdo();
    $sfx = bin2hex(random_bytes(4));

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["ORSD-$sfx", "OrsDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();
    $locationId = (int) $pdo->query('SELECT id FROM locations LIMIT 1')->fetchColumn();
    $catId = (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn();
    $priId = (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn();
    $filters = ['department_id' => $deptId]; // department_id is an accepted report filter; location_id is not

    try {
        $requested = date('Y-m-d H:i:s', strtotime('-3 days'));
        $resolved = date('Y-m-d H:i:s', strtotime('-2 days'));
        $pdo->prepare(
            'INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id,
                ticket_category_id, priority_id, status, approval_status, requested_at, resolved_at, completed_at, created_at, updated_at)
             VALUES (?, ?, "x", 1, ?, ?, ?, ?, "completed", "approved", ?, ?, ?, NOW(), NOW())'
        )->execute(["ORS-$sfx", 'rating sample', $deptId, $locationId, $catId, $priId, $requested, $resolved, $resolved]);
        $ticketId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, cycle, score, feedback, created_at, updated_at)
                       VALUES (?, 1, 3, 1, 5, "ดีมาก", ?, ?)')->execute([$ticketId, $resolved, $resolved]);

        $summary = $svc->getReportPageData($admin, $filters)['summary'] ?? [];
        assert_same('5.0', (string) ($summary['avgRatingLabel'] ?? ''), 'the average itself is unchanged');
        assert_same(
            1,
            (int) ($summary['ratingCount'] ?? -1),
            'the payload carries the sample size so the card can say the 5.0 came from a single review'
        );

        $pdf = (string) ($svc->exportPdf($admin, $filters)['content'] ?? '');
        assert_true(str_starts_with($pdf, '%PDF-'), 'sanity: a real PDF was produced');
        $text = (new Parser())->parseContent($pdf)->getText();
        assert_contains_str('จาก 1 รีวิว', $text, 'the printed PDF discloses the sample size next to the score');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE ticket_no = ?')->execute(["ORS-$sfx"]);
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

test('overview rating: with no reviews the card stays "-" and claims no sample', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $pdo = ors_pdo();
    $sfx = bin2hex(random_bytes(4));

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["ORSD-$sfx", "OrsDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();
    $locationId = (int) $pdo->query('SELECT id FROM locations LIMIT 1')->fetchColumn();
    $catId = (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn();
    $priId = (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn();

    try {
        $pdo->prepare(
            'INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id,
                ticket_category_id, priority_id, status, approval_status, requested_at, created_at, updated_at)
             VALUES (?, ?, "x", 1, ?, ?, ?, ?, "in_progress", "approved", NOW(), NOW(), NOW())'
        )->execute(["ORS2-$sfx", 'no rating', $deptId, $locationId, $catId, $priId]);

        $summary = $svc->getReportPageData($admin, ['department_id' => $deptId])['summary'] ?? [];
        assert_same('-', (string) ($summary['avgRatingLabel'] ?? ''), 'no reviews = no score, not 0.0');
        assert_same(0, (int) ($summary['ratingCount'] ?? -1), 'and the sample size is honestly zero');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE ticket_no = ?')->execute(["ORS2-$sfx"]);
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

// AN-02 verify follow-up (2026-07-26): the KPI card and the PDF summary block were fixed, but the technician
// mini-table on the overview — and its twin inside the overview PDF — still printed a bare "5.0". Those two
// tables are where a manager actually compares PEOPLE, so a score from one review sitting next to a score from
// a hundred is the most damaging place to omit the base. The earlier test only covered the card and the summary
// block, so it stayed green while two outputs were still wrong: this one renders the real view and the real PDF
// and reads the technician row itself.
test('overview rating: the technician mini-table shows the review count on the page AND in the PDF', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $pdo = ors_pdo();
    $sfx = bin2hex(random_bytes(4));

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["ORST-$sfx", "OrsTechDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();
    $locationId = (int) $pdo->query('SELECT id FROM locations LIMIT 1')->fetchColumn();
    $catId = (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn();
    $priId = (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn();
    $filters = ['department_id' => $deptId];

    // a throwaway technician so the row is unambiguous
    $pdo->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
                   VALUES (?, ?, "x", ?, "technician", 1, NOW(), NOW())')
        ->execute(["orstech_$sfx", "orstech_$sfx@x.test", "OrsTech $sfx"]);
    $techId = (int) $pdo->lastInsertId();

    try {
        $requested = date('Y-m-d H:i:s', strtotime('-3 days'));
        $resolved = date('Y-m-d H:i:s', strtotime('-2 days'));
        $pdo->prepare(
            'INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id,
                ticket_category_id, priority_id, assigned_technician_id, status, approval_status, requested_at,
                resolved_at, completed_at, created_at, updated_at)
             VALUES (?, ?, "x", 1, ?, ?, ?, ?, ?, "completed", "approved", ?, ?, ?, NOW(), NOW())'
        )->execute(["ORST-$sfx", 'tech rating', $deptId, $locationId, $catId, $priId, $techId, $requested, $resolved, $resolved]);
        $ticketId = (int) $pdo->lastInsertId();
        // the resolver credit the technician report reads comes from the activity log
        $pdo->prepare("INSERT INTO ticket_activity_logs (ticket_id, actor_id, action, from_status, to_status, created_at)
                       VALUES (?, ?, 'ticket_resolved', 'in_progress', 'resolved', ?)")->execute([$ticketId, $techId, $resolved]);
        $pdo->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, cycle, score, feedback, created_at, updated_at)
                       VALUES (?, 1, ?, 1, 5, "ดีมาก", ?, ?)')->execute([$ticketId, $techId, $resolved, $resolved]);

        $page = $svc->getReportPageData($admin, $filters);
        $row = null;
        foreach (($page['technicianPerformance'] ?? []) as $tech) {
            if ((string) ($tech['full_name'] ?? '') === "OrsTech $sfx") {
                $row = $tech;
            }
        }
        assert_true($row !== null, 'the technician appears in the overview mini-table');
        assert_same('5.0', (string) $row['avg_rating_label'], 'the score itself');
        assert_same(1, (int) $row['rating_count'], 'built from a single review');

        // (1) the rendered overview page must print the base next to that score
        $html = \App\Core\View::capture('reports/index', $page);
        assert_contains_str(
            '5.0</span> <small',
            $html,
            'the mini-table prints the review count beside the score, not a bare 5.0'
        );
        assert_contains_str('(1)</small>', $html, 'and the count is the actual sample size');

        // (2) the overview PDF carries the same disclosure in its technician table
        $pdf = (string) ($svc->exportPdf($admin, $filters)['content'] ?? '');
        $text = (new Parser())->parseContent($pdf)->getText();
        assert_contains_str('5.0 (1)', $text, 'the printed technician table shows the score with its review count');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE ticket_no = ?')->execute(["ORST-$sfx"]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$techId]);
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});

// AN-02 round 4 (2026-07-26): the executive KPI card shows BOTH periods' scores but disclosed the sample size of
// only the current one — "คะแนนเฉลี่ย 5.0 · งวดก่อน 4.0 · จาก 1 รีวิว". The reader cannot tell whether that 4.0 is
// a solid forty-review baseline or another single review, which is exactly the comparison the card exists to
// support. Every other surface now names its base; the previous period was the last one left anonymous.
test('executive rating: BOTH periods disclose their review count, not just the current one', function (): void {
    $svc = tvm_container()->get(ReportService::class);
    $admin = ['id' => 4, 'role' => 'admin'];
    $pdo = ors_pdo();
    $sfx = bin2hex(random_bytes(4));

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["ORSE-$sfx", "OrsExecDept-$sfx"]);
    $deptId = (int) $pdo->lastInsertId();
    $locationId = (int) $pdo->query('SELECT id FROM locations LIMIT 1')->fetchColumn();
    $catId = (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn();
    $priId = (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn();

    $rate = static function (string $no, string $when, int $score) use ($pdo, $deptId, $locationId, $catId, $priId): void {
        $pdo->prepare(
            'INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id,
                ticket_category_id, priority_id, status, approval_status, requested_at, resolved_at, completed_at,
                created_at, updated_at)
             VALUES (?, ?, "x", 1, ?, ?, ?, ?, "completed", "approved", ?, ?, ?, NOW(), NOW())'
        )->execute([$no, 'exec rating', $deptId, $locationId, $catId, $priId, $when, $when, $when]);
        $pdo->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, technician_id, cycle, score, feedback, created_at, updated_at)
                       VALUES (?, 1, 3, 1, ?, "x", ?, ?)')->execute([(int) $pdo->lastInsertId(), $score, $when, $when]);
    };

    try {
        // this month: a single 5. previous month: three reviews averaging 4 — the comparison the card invites.
        // Anchor to the 1st before shifting a month: "-1 month" from a 31-day month (e.g. run on Jul 31) would
        // overflow into the CURRENT month; day-01 exists in every month, so the previous-month seed lands right.
        $thisMonth = date('Y-m-01 10:00:00');
        $prevMonth = date('Y-m-01 10:00:00', strtotime('first day of last month'));
        $rate("ORSE-$sfx-1", $thisMonth, 5);
        foreach ([1, 2, 3] as $i) {
            $rate("ORSE-$sfx-P$i", $prevMonth, 4);
        }

        $page = $svc->getExecutiveSummaryPage($admin, ['preset' => 'month', 'department_id' => $deptId]);
        $rating = null;
        foreach (($page['kpis'] ?? []) as $kpi) {
            if ((string) ($kpi['label'] ?? '') === 'คะแนนเฉลี่ย') {
                $rating = $kpi;
            }
        }
        assert_true($rating !== null, 'the rating KPI card exists');
        assert_same('จาก 1 รีวิว', (string) ($rating['sample_label'] ?? ''), 'the current period already named its base');
        assert_same(
            'จาก 3 รีวิว',
            (string) ($rating['prev_sample_label'] ?? ''),
            'the previous period must name its base too — otherwise "งวดก่อน 4.0" could be forty reviews or one'
        );

        // Assert the RENDERS, not just the payload. An earlier round of this work edited two views with a
        // mismatched search string, so the edits silently never applied while a payload-only test stayed green.
        $html = View::capture('reports/executive', $page);
        assert_contains_str('จาก 3 รีวิว', $html, 'the executive page prints the previous period base');
        $pdf = (string) ($svc->exportExecutiveSummaryPdf($admin, ['preset' => 'month', 'department_id' => $deptId])['content'] ?? '');
        assert_contains_str('จาก 3 รีวิว', (new Parser())->parseContent($pdf)->getText(), 'and so does the printed PDF');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE ticket_no LIKE ?')->execute(["ORSE-$sfx-%"]);
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
    }
});
