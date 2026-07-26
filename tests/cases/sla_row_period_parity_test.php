<?php

declare(strict_types=1);

use App\Services\ReportService;

// AN-01 follow-up (2026-07-26): the aggregate SLA numbers were frozen to the period end, but the DETAIL TABLE
// underneath them — and every export built from it — recomputed SLA in the service layer against time().
// So the header could say "0 breached this month" while the row right below it was flagged เกินกำหนด, because
// the row was quietly looking at today's clock. The service also skipped only 'cancelled', so a REJECTED ticket
// was excluded from the totals yet still shown as an SLA breach in its own row.
//
// A report that disagrees with itself on one screen is worse than one that is merely wrong: the reader cannot
// tell which half to believe. The row and the header must answer as of the same moment.

function srp_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** @return array{0:int,1:int,2:int,3:int} dept, location, category, priority */
function srp_dims(string $sfx): array
{
    $pdo = srp_pdo();
    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute(["SRPD-$sfx", "SrpDept-$sfx"]);

    return [
        (int) $pdo->lastInsertId(),
        (int) $pdo->query('SELECT id FROM locations LIMIT 1')->fetchColumn(),
        (int) $pdo->query('SELECT id FROM ticket_categories LIMIT 1')->fetchColumn(),
        (int) $pdo->query('SELECT id FROM priorities LIMIT 1')->fetchColumn(),
    ];
}

function srp_ticket(string $no, array $d, string $status, string $requestedAt, string $targetAt): int
{
    srp_pdo()->prepare(
        'INSERT INTO tickets (ticket_no, title, description, requester_id, requester_department_id, location_id,
            ticket_category_id, priority_id, status, approval_status, requested_at, response_due_at,
            resolution_due_at, created_at, updated_at)
         VALUES (?, ?, "x", 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    )->execute([
        $no, "row parity $status", $d[0], $d[1], $d[2], $d[3], $status,
        $status === 'rejected' ? 'rejected' : 'approved', $requestedAt, $targetAt, $targetAt,
    ]);
    $ticketId = (int) srp_pdo()->lastInsertId();
    srp_pdo()->prepare("INSERT INTO ticket_sla_tracks (ticket_id, metric_type, cycle, target_at, status, created_at)
                        VALUES (?, 'resolution', 1, ?, 'pending', ?)")->execute([$ticketId, $targetAt, $requestedAt]);

    return $ticketId;
}

/** the detail row for a ticket_no, as the screen renders it */
function srp_row(array $filters, string $ticketNo): ?array
{
    $page = tvm_container()->get(ReportService::class)->getReportPageData(['id' => 4, 'role' => 'admin'], $filters);
    foreach (($page['rows'] ?? []) as $row) {
        if ((string) ($row['ticket_no'] ?? '') === $ticketNo) {
            return $row;
        }
    }

    return null;
}

test('sla(row): a deadline that passes AFTER the period end is not flagged overdue in the detail row or export', function (): void {
    $sfx = bin2hex(random_bytes(4));
    $d = srp_dims($sfx);
    $monthStart = new DateTimeImmutable(date('Y-m-01', strtotime('-6 months')));
    $monthEnd = $monthStart->modify('last day of this month');
    $filters = [
        'from_date' => $monthStart->format('Y-m-d'),
        'to_date' => $monthEnd->format('Y-m-d'),
        'department_id' => $d[0],
    ];
    $ticketNo = "SRP-$sfx";

    try {
        // requested inside the closed month; its deadline only arrives a month later, so as of the period end
        // nothing was late — yet by today's clock it is long overdue.
        srp_ticket(
            $ticketNo,
            $d,
            'in_progress',
            $monthStart->modify('+20 days')->format('Y-m-d H:i:s'),
            $monthEnd->modify('+30 days')->format('Y-m-d H:i:s')
        );

        $svc = tvm_container()->get(ReportService::class);
        $page = $svc->getReportPageData(['id' => 4, 'role' => 'admin'], $filters);

        // the header already reports zero breaches for that month — the row must agree
        $headerBreached = 0;
        foreach (['response', 'resolution'] as $metric) {
            $headerBreached += (int) ($page['slaCompliance']['overall'][$metric]['breached'] ?? 0);
        }
        assert_same(0, $headerBreached, 'sanity: the frozen header says nothing breached in that month');

        $row = srp_row($filters, $ticketNo);
        assert_true($row !== null, 'the ticket appears in the detail table');
        assert_false(
            (bool) $row['sla_overdue'],
            'the row must not read เกินกำหนด while the header above it says zero — both answer as of the period end'
        );
        assert_same('ไม่ใช่', (string) $row['sla_overdue_label'], 'and its printed label agrees');

        // the export is built from the same mapper and must carry the same verdict
        $csv = (string) ($svc->exportCsv(['id' => 4, 'role' => 'admin'], $filters)['content'] ?? '');
        $exportedVerdict = null;
        foreach (explode("\n", trim(substr($csv, 3))) as $line) {
            if (!str_contains($line, $ticketNo)) {
                continue;
            }
            // compare the FIELD, not a substring — "ไม่ใช่" contains "ใช่"
            foreach (str_getcsv(trim($line)) as $field) {
                if (in_array(trim((string) $field), ['ใช่', 'ไม่ใช่'], true)) {
                    $exportedVerdict = trim((string) $field);
                    break 2;
                }
            }
        }
        assert_same('ไม่ใช่', $exportedVerdict, 'the exported row carries the same not-overdue verdict as the screen');
    } finally {
        srp_pdo()->prepare('DELETE FROM tickets WHERE ticket_no = ?')->execute([$ticketNo]);
        srp_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$d[0]]);
    }
});

test('sla(row): a REJECTED ticket is "ไม่คิด SLA" in its row, exactly like a cancelled one', function (): void {
    $sfx = bin2hex(random_bytes(4));
    $d = srp_dims($sfx);
    $requested = date('Y-m-d H:i:s', strtotime('-10 days'));
    $target = date('Y-m-d H:i:s', strtotime('-9 days')); // long past, so the clock would call it breached
    $filters = ['department_id' => $d[0]];

    try {
        srp_ticket("SRPR-$sfx", $d, 'rejected', $requested, $target);
        srp_ticket("SRPC-$sfx", $d, 'cancelled', $requested, $target);

        $rejected = srp_row($filters, "SRPR-$sfx");
        $cancelled = srp_row($filters, "SRPC-$sfx");
        assert_true($rejected !== null && $cancelled !== null, 'both rows are listed');

        assert_same('ไม่คิด SLA', (string) $cancelled['sla_label'], 'baseline: cancelled work is not judged on SLA');
        assert_same(
            'ไม่คิด SLA',
            (string) $rejected['sla_label'],
            'rejected work is not judged either — it is already excluded from the totals, so the row must match'
        );
        assert_false((bool) $rejected['sla_overdue'], 'and it is certainly not counted as a breach');
    } finally {
        srp_pdo()->prepare('DELETE FROM tickets WHERE ticket_no IN (?, ?)')->execute(["SRPR-$sfx", "SRPC-$sfx"]);
        srp_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$d[0]]);
    }
});
