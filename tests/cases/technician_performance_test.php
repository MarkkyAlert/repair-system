<?php
declare(strict_types=1);

use App\Services\ReportService;

// Test for the Technician Performance report. Uses a throwaway technician (no baseline) for exact
// assertions, and gives one ticket BOTH a rating and a work_order to prove the LEFT JOINs don't
// fan-out (assigned count must equal the number of tickets, not double).

function tp_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function tp_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

test('technician performance: per-tech aggregates + no fan-out from rating/work_order joins', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $techId = 0;
    $t1 = 0;
    $t2 = 0;

    try {
        tp_pdo()->prepare(
            "INSERT INTO users (username, email, password_hash, full_name, role) VALUES (?, ?, 'x', 'TP Test Tech', 'technician')"
        )->execute(["tptech_$rid", "tptech_$rid@example.com"]);
        $techId = (int) tp_pdo()->lastInsertId();

        // T1: resolved 2h after request (MTTR 120min = 2.0h) + rating 4 + work_order labor 60min
        $req = date('Y-m-d H:i:s', time() - 7200);
        $res = date('Y-m-d H:i:s');
        tp_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, resolved_at)
             VALUES (?, 'tp', 'x', 1, 1, 1, 1, ?, 'resolved', ?, ?)"
        )->execute(["TPT-$rid-1", $techId, $req, $res]);
        $t1 = (int) tp_pdo()->lastInsertId();
        tp_pdo()->prepare('INSERT INTO ticket_ratings (ticket_id, requester_id, score) VALUES (?, 1, 4)')->execute([$t1]);
        tp_pdo()->prepare(
            "INSERT INTO work_orders (work_order_no, ticket_id, technician_id, assigned_by, status, labor_minutes) VALUES (?, ?, ?, 4, 'completed', 60)"
        )->execute(["WOT-$rid-1", $t1, $techId]);

        // T2: in_progress (open) + work_order labor 30min (no rating)
        tp_pdo()->prepare(
            "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status)
             VALUES (?, 'tp', 'x', 1, 1, 1, 1, ?, 'in_progress')"
        )->execute(["TPT-$rid-2", $techId]);
        $t2 = (int) tp_pdo()->lastInsertId();
        tp_pdo()->prepare(
            "INSERT INTO work_orders (work_order_no, ticket_id, technician_id, assigned_by, status, labor_minutes) VALUES (?, ?, ?, 4, 'in_progress', 30)"
        )->execute(["WOT-$rid-2", $t2, $techId]);

        $rows = tp_service()->getReportPageData($admin, [])['technicianPerformance'];
        $me = null;
        foreach ($rows as $r) {
            if ($r['full_name'] === 'TP Test Tech') {
                $me = $r;
                break;
            }
        }

        assert_true($me !== null, 'technician appears in the performance table');
        assert_same(2, $me['assigned'], 'assigned = 2 tickets (no fan-out: T1 has BOTH a rating AND a work_order)');
        assert_same(1, $me['resolved'], 'resolved = 1');
        assert_same(1, $me['open'], 'open = 1');
        assert_same('50.0%', $me['completion_label'], 'completion 1/2 = 50%');
        assert_same('2.0', $me['mttr_hours_label'], 'MTTR 120min = 2.0 hrs');
        assert_same('4.0', $me['avg_rating_label'], 'avg rating = 4.0 (only T1 rated)');
        assert_same('1.5', $me['labor_hours_label'], 'labor 60+30 = 90min = 1.5 hrs');
    } finally {
        foreach ([$t1, $t2] as $ticketId) {
            if ($ticketId > 0) {
                tp_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // ratings + work_orders cascade
            }
        }
        if ($techId > 0) {
            tp_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$techId]);
        }
    }
});

test('technician performance: MTTR rounds to hours the same way the summary avg does (no double-rounding)', function (): void {
    // Regression: two resolved tickets of 17min + 120min average to 68.5min. The summary card computes
    // ROUND(AVG,1)=68.5 → 1.1h. The technician query used to ROUND(AVG,0)=69 → 1.15 → 1.2h, so the same
    // work showed 1.1 up top and 1.2 in the table. Both pipelines must now yield 1.1 for identical data.
    $admin = ['id' => 4, 'role' => 'admin'];
    $rid = bin2hex(random_bytes(4));
    $techId = 0;
    $t1 = 0;
    $t2 = 0;

    try {
        tp_pdo()->prepare(
            "INSERT INTO users (username, email, password_hash, full_name, role) VALUES (?, ?, 'x', 'TP Round Tech', 'technician')"
        )->execute(["tpround_$rid", "tpround_$rid@example.com"]);
        $techId = (int) tp_pdo()->lastInsertId();

        foreach ([17, 120] as $i => $minutes) {
            tp_pdo()->prepare(
                "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, requested_at, resolved_at)
                 VALUES (?, 'tp', 'x', 1, 1, 1, 1, ?, 'resolved', ?, ?)"
            )->execute(["TPR-$rid-$i", $techId, date('Y-m-d H:i:s', time() - $minutes * 60), date('Y-m-d H:i:s')]);
            ${'t' . ($i + 1)} = (int) tp_pdo()->lastInsertId();
        }

        $rows = tp_service()->getReportPageData($admin, [])['technicianPerformance'];
        $me = null;
        foreach ($rows as $r) {
            if ($r['full_name'] === 'TP Round Tech') {
                $me = $r;
                break;
            }
        }

        assert_true($me !== null, 'technician appears in the performance table');
        assert_same('1.1', $me['mttr_hours_label'], 'avg 68.5min rounds to 1.1h (was 1.2h under double-rounding)');
    } finally {
        foreach ([$t1, $t2] as $ticketId) {
            if ($ticketId > 0) {
                tp_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
            }
        }
        if ($techId > 0) {
            tp_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$techId]);
        }
    }
});
