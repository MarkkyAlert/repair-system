<?php

declare(strict_types=1);

// perf-review F7 + F3: report/dashboard/cron scan filters must be index-backed, not full scans.
//   - F7 dashboard "ค่าเฉลี่ยการปิดงานรายเดือน" filters tickets.resolved_at by year window
//   - F7 CSAT report filters ticket_ratings.created_at by window then joins back to tickets
//   - F3 SLA breach cron scans ticket_sla_tracks WHERE status='pending' AND target_at < NOW() ORDER BY target_at,id
// Guards that the indexes exist (someone editing schema.sql can't silently drop them) AND that the
// query optimizer recognizes each index for that exact predicate (possible_keys). possible_keys is
// deterministic regardless of table size; the actual `key` chosen depends on row counts (a tiny test
// table may still scan), so we assert the index is AVAILABLE to the query, not that it is picked here.

function ri_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** @return list<string> Key_name values present on $table. */
function ri_index_names(string $table): array
{
    $rows = ri_pdo()->query("SHOW INDEX FROM $table")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_values(array_unique(array_map(static fn (array $r): string => (string) ($r['Key_name'] ?? ''), $rows)));
}

/** possible_keys the optimizer lists for $sql (proves the index is usable for that predicate). */
function ri_possible_keys(string $sql): string
{
    $row = ri_pdo()->query('EXPLAIN ' . $sql)->fetch(PDO::FETCH_ASSOC) ?: [];

    return (string) ($row['possible_keys'] ?? '');
}

test('F7: tickets.resolved_at is index-backed for the dashboard year-window filter', function (): void {
    assert_true(
        in_array('idx_tickets_resolved_at', ri_index_names('tickets'), true),
        'idx_tickets_resolved_at must exist on tickets'
    );

    $possible = ri_possible_keys(
        "SELECT COUNT(*) FROM tickets t
         WHERE t.resolved_at >= '2026-01-01 00:00:00' AND t.resolved_at < '2027-01-01 00:00:00'"
    );
    assert_contains_str('idx_tickets_resolved_at', $possible, 'optimizer must be able to use the resolved_at index');
});

test('F7: ticket_ratings.created_at is index-backed for the CSAT window filter', function (): void {
    assert_true(
        in_array('idx_ticket_ratings_created_at', ri_index_names('ticket_ratings'), true),
        'idx_ticket_ratings_created_at must exist on ticket_ratings'
    );

    $possible = ri_possible_keys(
        "SELECT COUNT(*) FROM ticket_ratings tr
         WHERE tr.created_at >= '2026-01-01 00:00:00' AND tr.created_at <= '2026-12-31 23:59:59'"
    );
    assert_contains_str('idx_ticket_ratings_created_at', $possible, 'optimizer must be able to use the created_at index');
});

test('F3: ticket_sla_tracks is index-backed for the overdue-breach cron scan', function (): void {
    assert_true(
        in_array('idx_ticket_sla_tracks_due', ri_index_names('ticket_sla_tracks'), true),
        'idx_ticket_sla_tracks_due must exist on ticket_sla_tracks'
    );

    $possible = ri_possible_keys(
        "SELECT ts.id FROM ticket_sla_tracks ts
         WHERE ts.status = 'pending' AND ts.target_at < NOW()
         ORDER BY ts.target_at ASC, ts.id ASC"
    );
    assert_contains_str('idx_ticket_sla_tracks_due', $possible, 'optimizer must be able to use the (status, target_at, id) index for the cron scan');
});
