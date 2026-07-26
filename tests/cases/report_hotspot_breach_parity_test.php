<?php

declare(strict_types=1);

use App\Services\ReportService;

// BI-review (2026-07-26, owner decision): the problem-hotspot "%เกิน SLA" column must mean
// "% ของงานในช่วงที่พลาดกำหนด" — the same question the SLA-breach page answers.
//
// It used to mix two populations: the numerator counted only tickets that were STILL OPEN and past due
// (t.status NOT IN terminal), while the denominator was COUNT(*) over every ticket in the window. An area that
// missed every deadline but then closed the work reported 0.0% with a green ปกติ badge, while the SLA-breach page
// reported 100.0% danger for the identical area and window — a 100-point contradiction on the one page whose
// purpose is "which area do I fix first". The rate also fed the hotspot score, so the worst areas ranked lowest.
//
// The rate is now breached-jobs ÷ jobs-with-a-verdict, and cancelled/rejected work is out of the population
// entirely (see report_sla_population_test) so a withdrawn request cannot dilute the percentage.

function hbp_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function hbp_dims(string $sfx): array
{
    $pdo = hbp_pdo();
    $pdo->prepare('INSERT INTO locations (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["HBPL-$sfx", "HbpLoc-$sfx"]);

    return [
        'loc' => (int) $pdo->lastInsertId(),
        'cat' => (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn(),
        'pri' => (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn(),
    ];
}

/**
 * One ticket with a single resolution track in a known SLA state.
 * $verdict: 'breached' (closed late), 'met' (closed on time), 'pending_due' (still open, past target),
 *           'pending_future' (still open, not due yet — must not be judged at all).
 */
function hbp_ticket(array $d, string $sfx, string $verdict, int $n): void
{
    $requested = date('Y-m-d H:i:s', strtotime('-20 days'));
    $target = date('Y-m-d H:i:s', strtotime('-19 days'));
    $closedLate = date('Y-m-d H:i:s', strtotime('-5 days'));
    $closedEarly = date('Y-m-d H:i:s', strtotime('-19 days -2 hours'));

    $isClosed = in_array($verdict, ['breached', 'met'], true);
    $resolvedAt = $verdict === 'breached' ? $closedLate : ($verdict === 'met' ? $closedEarly : null);
    $status = $isClosed ? 'completed' : 'in_progress';
    if ($verdict === 'pending_future') {
        $target = date('Y-m-d H:i:s', strtotime('+5 days'));
    }

    hbp_pdo()->prepare(
        'INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id,
            priority_id, status, approval_status, requested_at, resolution_due_at, resolved_at, completed_at,
            created_at, updated_at)
         VALUES (?, ?, "x", 1, ?, ?, ?, ?, "approved", ?, ?, ?, ?, NOW(), NOW())'
    )->execute([
        "HBP-$sfx-$n", "hbp $verdict $n", $d['loc'], $d['cat'], $d['pri'], $status,
        $requested, $target, $resolvedAt, $resolvedAt,
    ]);
    $ticketId = (int) hbp_pdo()->lastInsertId();

    $trackStatus = match ($verdict) {
        'breached' => 'breached',
        'met' => 'met',
        default => 'pending',
    };
    hbp_pdo()->prepare(
        'INSERT INTO ticket_sla_tracks (ticket_id, metric_type, cycle, target_at, achieved_at, breached_at, status, created_at)
         VALUES (?, "resolution", 1, ?, ?, ?, ?, ?)'
    )->execute([
        $ticketId,
        $target,
        $verdict === 'met' ? $closedEarly : null,
        $verdict === 'breached' ? $closedLate : null,
        $trackStatus,
        $requested,
    ]);
}

function hbp_row(array $d, string $label): ?array
{
    $page = tvm_container()->get(ReportService::class)
        ->getProblemHotspotReportPage(['id' => 4, 'role' => 'admin'], ['dimension' => 'location']);
    foreach (($page['rows'] ?? []) as $row) {
        if (($row['label'] ?? '') === $label) {
            return $row;
        }
    }

    return null;
}

function hbp_cleanup(array $d, string $sfx): void
{
    hbp_pdo()->prepare('DELETE FROM tickets WHERE ticket_no LIKE ?')->execute(["HBP-$sfx-%"]);
    hbp_pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$d['loc']]);
}

test('hotspot(parity): an area that closed every job LATE reads 100% เกิน SLA, matching the SLA-breach page', function (): void {
    $sfx = bin2hex(random_bytes(4));
    $d = hbp_dims($sfx);
    $label = "HbpLoc-$sfx";

    try {
        for ($i = 1; $i <= 4; $i++) {
            hbp_ticket($d, $sfx, 'breached', $i);
        }

        $row = hbp_row($d, $label);
        assert_true($row !== null, 'the area appears on the hotspot page');
        assert_same(4, (int) $row['overdue_count'], 'all four late closures are counted as missed deadlines');
        assert_same('100.0%', (string) $row['overdue_rate_label'], 'was 0.0% before the fix — closed-late work was invisible');
        assert_same('danger', (string) $row['overdue_tone'], 'the badge is red, not a green ปกติ');

        // the two pages must now answer the same question with the same number
        $breach = tvm_container()->get(ReportService::class)
            ->getSlaBreachReportPage(['id' => 4, 'role' => 'admin'], ['dimension' => 'location']);
        $breachRate = null;
        foreach (($breach['rows'] ?? []) as $r) {
            if (($r['label'] ?? '') === $label) {
                $breachRate = (string) $r['breach_rate_label'];
            }
        }
        assert_same((string) $row['overdue_rate_label'], (string) $breachRate, 'hotspot and SLA-breach agree for the same area/window');
    } finally {
        hbp_cleanup($d, $sfx);
    }
});

test('hotspot(parity): the rate is missed ÷ judged — on-time closures dilute it, undecided work is not judged', function (): void {
    $sfx = bin2hex(random_bytes(4));
    $d = hbp_dims($sfx);
    $label = "HbpLoc-$sfx";

    try {
        hbp_ticket($d, $sfx, 'breached', 1);
        hbp_ticket($d, $sfx, 'met', 2);
        hbp_ticket($d, $sfx, 'met', 3);
        hbp_ticket($d, $sfx, 'met', 4);

        $row = hbp_row($d, $label);
        assert_same('25.0%', (string) $row['overdue_rate_label'], '1 missed out of 4 judged jobs');

        // a job whose deadline has not arrived yet is neither met nor missed — it must not enter the base
        hbp_ticket($d, $sfx, 'pending_future', 5);
        $after = hbp_row($d, $label);
        assert_same('25.0%', (string) $after['overdue_rate_label'], 'work that is not yet due does not change the rate');
        assert_same(1, (int) $after['overdue_count'], 'and it is certainly not counted as missed');
    } finally {
        hbp_cleanup($d, $sfx);
    }
});

test('hotspot(parity): an area with no judged jobs shows "-" not a green 0.0%', function (): void {
    $sfx = bin2hex(random_bytes(4));
    $d = hbp_dims($sfx);
    $label = "HbpLoc-$sfx";

    try {
        hbp_ticket($d, $sfx, 'pending_future', 1); // exists, but nothing can be judged yet

        $row = hbp_row($d, $label);
        assert_true($row !== null, 'the area still appears (it has a ticket)');
        assert_same('-', (string) $row['overdue_rate_label'], 'no verdict yet = no data, never 0.0%');
        assert_same('default', (string) $row['overdue_tone'], 'and no reassuring green badge');
    } finally {
        hbp_cleanup($d, $sfx);
    }
});
