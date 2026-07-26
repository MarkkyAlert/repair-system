<?php

declare(strict_types=1);

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
