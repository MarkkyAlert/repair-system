<?php

declare(strict_types=1);

use App\Services\DemoDataService;

// Run the real demo loader inside an outer transaction. The transaction is always rolled back, so this verifies
// the operation buyers use without leaving sample rows in the shared test database.
test('demo-load(integrity): generated lifecycle, work order, SLA, approval, history, and rating data agree', function (): void {
    $container = tvm_container();
    $database = $container->get(PDO::class);
    $originalConfig = $container->get('config');
    $demoConfig = $originalConfig;
    $demoConfig['app']['allow_demo_data'] = true;
    $container->instance('config', $demoConfig);

    try {
        $database->beginTransaction();
        $database->exec('DELETE FROM tickets');

        $result = $container->get(DemoDataService::class)->load(4);
        assert_same(20, (int) ($result['tickets'] ?? 0), 'the real demo operation created its complete ticket pack');
        // B-5 (owner decision): the demo pack may only contain states a buyer's own users can actually reach.
        // Nothing in the application writes 'closed' — it is a deliberately retained future terminal (the commit
        // that dropped on_hold kept it on purpose), so seeding it showed buyers a status they could never
        // reproduce. The enum and the terminal-status sets are untouched; the demo simply ends at completed.
        assert_same(
            0,
            (int) $database->query("SELECT COUNT(*) FROM tickets WHERE status = 'closed'")->fetchColumn(),
            'the demo pack contains no ticket in a state the application cannot produce'
        );
        assert_true(
            (int) $database->query("SELECT COUNT(*) FROM tickets WHERE status = 'completed' AND completed_at IS NOT NULL")->fetchColumn() > 0,
            'finished work is still represented — it just ends at completed, which the real flow does reach'
        );

        $timestampViolations = (int) $database->query(
            "SELECT COUNT(*) FROM tickets
             WHERE (status IN ('assigned','accepted','in_progress','resolved','completed','closed') AND assigned_at IS NULL)
                OR (status IN ('accepted','in_progress','resolved','completed','closed') AND first_response_at IS NULL)
                OR (status IN ('in_progress','resolved','completed','closed') AND started_at IS NULL)
                OR (status IN ('resolved','completed','closed') AND resolved_at IS NULL)
                OR (status IN ('completed','closed') AND completed_at IS NULL)
                OR (status = 'cancelled' AND cancelled_at IS NULL)
                OR (status <> 'cancelled' AND cancelled_at IS NOT NULL)
                OR (status = 'closed' AND closed_at IS NULL)
                OR (status <> 'closed' AND closed_at IS NOT NULL)
                OR (assigned_at IS NOT NULL AND assigned_at < approved_at)
                OR (first_response_at IS NOT NULL AND first_response_at < assigned_at)
                OR (started_at IS NOT NULL AND started_at < assigned_at)
                OR (resolved_at IS NOT NULL AND resolved_at < started_at)
                OR (completed_at IS NOT NULL AND completed_at < resolved_at)
                OR (closed_at IS NOT NULL AND closed_at < completed_at)"
        )->fetchColumn();
        assert_same(0, $timestampViolations, 'every demo status has a possible, forward-moving timeline');

        $workOrderViolations = (int) $database->query(
            "SELECT COUNT(*)
             FROM tickets t
             LEFT JOIN work_orders wo ON wo.ticket_id = t.id
             WHERE (t.status IN ('assigned','accepted','in_progress','resolved','completed','closed') AND wo.id IS NULL)
                OR (t.status IN ('assigned','accepted','in_progress','resolved','completed','closed')
                    AND (t.assigned_technician_id IS NULL OR wo.technician_id <> t.assigned_technician_id))
                OR (t.status = 'assigned' AND wo.status <> 'assigned')
                OR (t.status = 'accepted' AND wo.status <> 'accepted')
                OR (t.status = 'in_progress' AND wo.status <> 'in_progress')
                OR (t.status IN ('resolved','completed','closed') AND wo.status <> 'completed')
                OR (wo.status IN ('accepted','in_progress','completed') AND wo.accepted_at IS NULL)
                OR (wo.status IN ('in_progress','completed') AND wo.started_at IS NULL)
                OR (wo.status = 'completed' AND wo.completed_at IS NULL)
                OR (t.first_response_at IS NOT NULL AND wo.accepted_at IS NOT NULL AND t.first_response_at <> wo.accepted_at)
                OR (t.started_at IS NOT NULL AND wo.started_at IS NOT NULL AND t.started_at <> wo.started_at)
                OR (t.resolved_at IS NOT NULL AND wo.completed_at IS NOT NULL AND t.resolved_at <> wo.completed_at)"
        )->fetchColumn();
        assert_same(0, $workOrderViolations, 'ticket and work-order state describe the same work');

        $approvalViolations = (int) $database->query(
            "SELECT COUNT(*)
             FROM tickets t
             LEFT JOIN ticket_approvals a ON a.ticket_id = t.id
             WHERE (t.approval_status = 'approved' AND (a.id IS NULL OR a.action <> 'approved' OR a.acted_at IS NULL))
                OR (t.approval_status = 'rejected' AND (a.id IS NULL OR a.action <> 'rejected' OR a.acted_at IS NULL))
                OR (t.approval_status = 'pending' AND (a.id IS NULL OR a.action <> 'pending' OR a.acted_at IS NOT NULL))
                OR (t.approval_status = 'not_required' AND a.id IS NOT NULL)"
        )->fetchColumn();
        assert_same(0, $approvalViolations, 'every approval state has matching approval evidence');

        $historyViolations = (int) $database->query(
            "SELECT COUNT(*)
             FROM tickets t
             LEFT JOIN ticket_activity_logs latest ON latest.id = (
                 SELECT l.id FROM ticket_activity_logs l
                 WHERE l.ticket_id = t.id AND l.to_status IS NOT NULL
                 ORDER BY l.created_at DESC, l.id DESC LIMIT 1
             )
             WHERE latest.id IS NULL OR latest.to_status <> t.status"
        )->fetchColumn();
        assert_same(0, $historyViolations, 'the latest history entry agrees with the current ticket status');

        $slaViolations = $database->query(
            "SELECT COUNT(*) FROM (
                 SELECT s.ticket_id, s.metric_type
                 FROM ticket_sla_tracks s
                 JOIN tickets t ON t.id = s.ticket_id
                 LEFT JOIN (
                     SELECT ticket_id, COUNT(*) AS reopens
                     FROM ticket_activity_logs
                     WHERE action = 'ticket_reopened'
                     GROUP BY ticket_id
                 ) r ON r.ticket_id = s.ticket_id
                 GROUP BY s.ticket_id, s.metric_type, r.reopens
                 HAVING COUNT(*) <> 1 + COALESCE(r.reopens, 0)
             ) cycle_mismatch
             UNION ALL
             SELECT COUNT(*)
             FROM ticket_sla_tracks s
             WHERE (s.status = 'pending' AND (s.achieved_at IS NOT NULL OR s.breached_at IS NOT NULL))
                OR (s.status = 'met' AND (s.achieved_at IS NULL OR s.achieved_at > s.target_at OR s.breached_at IS NOT NULL))
                OR (s.status = 'breached' AND s.breached_at IS NULL)"
        )->fetchAll(PDO::FETCH_COLUMN);
        assert_same(0, array_sum(array_map('intval', $slaViolations)), 'SLA fields and cycle counts match the lifecycle');

        $ratingViolations = (int) $database->query(
            "SELECT COUNT(*)
             FROM ticket_ratings r
             JOIN tickets t ON t.id = r.ticket_id
             LEFT JOIN (
                 SELECT ticket_id,
                        SUM(action = 'ticket_completed') AS completions,
                        SUM(action = 'ticket_resolved') AS resolutions,
                        SUM(action = 'ticket_reopened') AS reopens
                 FROM ticket_activity_logs
                 GROUP BY ticket_id
             ) events ON events.ticket_id = r.ticket_id
             WHERE t.status NOT IN ('completed','closed')
                OR r.cycle <> 1 + COALESCE(events.reopens, 0)
                OR COALESCE(events.completions, 0) = 0
                OR COALESCE(events.resolutions, 0) < 1"
        )->fetchColumn();
        assert_same(0, $ratingViolations, 'ratings belong only to a completed lifecycle cycle');

        // A buyer tours the product with the accounts the demo hands them and nothing else. It used to hand over
        // an admin and three technicians, so the two screens that decide the sale — the phone-first reporting form
        // and the approval queue — could not be opened at all, and every ticket in every list read
        // "ผู้แจ้ง: ผู้ดูแลระบบ", which looks like broken data rather than a sample.
        $roles = $database->query(
            "SELECT role, COUNT(*) FROM users WHERE username LIKE '%_demo%' GROUP BY role"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (['technician', 'manager', 'requester'] as $role) {
            assert_true(
                (int) ($roles[$role] ?? 0) > 0,
                "the demo pack creates a {$role} account so that role can actually be logged into (got: "
                . json_encode($roles, JSON_UNESCAPED_UNICODE) . ')'
            );
        }

        // and the caller is told how to log in as them — the password is random per load and shown once
        $accounts = $result['demo_accounts'] ?? null;
        assert_true(is_array($accounts) && ($accounts['password'] ?? '') !== '', 'the load result carries the shared demo password');
        assert_same(
            ['ผู้แจ้ง', 'หัวหน้างาน', 'ช่าง'],
            array_keys((array) ($accounts['usernames'] ?? [])),
            'and names one account per role a buyer needs to try'
        );

        // tickets are reported by the requester accounts, not by the admin who pressed the button
        $adminReported = (int) $database->query(
            "SELECT COUNT(*) FROM tickets t JOIN users u ON u.id = t.requester_id WHERE u.role = 'admin'"
        )->fetchColumn();
        assert_same(0, $adminReported, 'no demo ticket is reported by the admin account');
        $distinctRequesters = (int) $database->query('SELECT COUNT(DISTINCT requester_id) FROM tickets')->fetchColumn();
        assert_true($distinctRequesters >= 2, "the ผู้แจ้ง column shows more than one name (got {$distinctRequesters})");

        // The behaviours a buyer is most impressed by are the ones that only appear when the awkward case exists:
        // a QR sticker still stuck to a machine that was written off, a category the company stopped using, an
        // account belonging to someone who left. With a pack of only healthy rows they are invisible, and the
        // evaluation misses them entirely — so the demo has to contain one of each.
        $cases = [
            'ทรัพย์สินที่ปลดระวาง' => "SELECT COUNT(*) FROM assets WHERE status IN ('retired','disposed')",
            'บัญชีที่ถูกปิด' => 'SELECT COUNT(*) FROM users WHERE is_active = 0',
            'หมวดหมู่ที่ปิดใช้งาน' => 'SELECT COUNT(*) FROM ticket_categories WHERE is_active = 0',
            'สถานที่ที่ปิดใช้งาน' => 'SELECT COUNT(*) FROM locations WHERE is_active = 0',
            'งานที่เคยเปิดใหม่' => "SELECT COUNT(*) FROM ticket_activity_logs WHERE action = 'ticket_reopened'",
            'คำขอจากคนนอกที่รอตรวจ' => "SELECT COUNT(*) FROM guest_ticket_requests WHERE status = 'new'",
            'คำขอจากคนนอกที่แปลงเป็นใบงานแล้ว' => 'SELECT COUNT(*) FROM guest_ticket_requests WHERE converted_ticket_id IS NOT NULL',
        ];
        foreach ($cases as $label => $sql) {
            assert_true((int) $database->query($sql)->fetchColumn() > 0, "the demo pack contains {$label} so a buyer can meet it without being told");
        }

        // and at least one ticket carries a description long enough to prove Thai text is not silently truncated
        $longest = (int) $database->query('SELECT MAX(CHAR_LENGTH(description)) FROM tickets')->fetchColumn();
        assert_true($longest >= 200, "one sample ticket is written the way a real reporter writes (longest: {$longest} chars)");

        // a converted guest request must point at a ticket that exists — a dangling id would show as a broken link
        $dangling = (int) $database->query(
            'SELECT COUNT(*) FROM guest_ticket_requests g
             LEFT JOIN tickets t ON t.id = g.converted_ticket_id
             WHERE g.converted_ticket_id IS NOT NULL AND t.id IS NULL'
        )->fetchColumn();
        assert_same(0, $dangling, 'every converted guest request opens a ticket that is really there');
    } finally {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        $container->instance('config', $originalConfig);
    }
});

test('demo(dept): ผู้แจ้งตัวอย่างทุกคนต้องมีแผนก และกราฟแยกตามแผนกต้องมีของจริงให้ดู', function (): void {
    // เดิมผู้แจ้งสองคนถูกตั้งรหัสแผนกเป็น 'OFFICE' ซึ่งไม่มีอยู่จริง (มีแค่ IT/FACILITY/ADMIN) ทั้งคู่จึงถูกสร้าง
    // โดยไม่มีแผนก — กราฟ "แยกตามแผนก" ที่ข้อมูลตัวอย่างมีไว้โชว์ตอนขายจึงว่างเปล่าโดยไม่มีใครสังเกต
    //
    // รันตัวโหลดจริงในทรานแซกชันแล้ว rollback เหมือนเทสต์ตัวแรกของไฟล์นี้ ฐานทดสอบที่ใช้ร่วมกันจะได้ไม่มีของค้าง
    $container = tvm_container();
    $database = $container->get(PDO::class);
    $originalConfig = $container->get('config');
    $demoConfig = $originalConfig;
    $demoConfig['app']['allow_demo_data'] = true;
    $container->instance('config', $demoConfig);

    try {
        $database->beginTransaction();
        $database->exec('DELETE FROM tickets');
        $container->get(DemoDataService::class)->load(4);

        $orphan = (int) $database->query("SELECT COUNT(*) FROM users WHERE username LIKE '%_demo%' AND department_id IS NULL")->fetchColumn();
        assert_same(0, $orphan, 'บัญชีตัวอย่างต้องมีแผนกครบทุกคน');

        $departments = (int) $database->query(
            "SELECT COUNT(DISTINCT department_id) FROM users WHERE username LIKE '%_demo%' AND department_id IS NOT NULL"
        )->fetchColumn();
        assert_true($departments >= 2, 'บัญชีตัวอย่างต้องกระจายอย่างน้อย 2 แผนก ไม่งั้นกราฟแยกตามแผนกมีแท่งเดียว');
    } finally {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        $container->instance('config', $originalConfig);
    }
});

test('demo(master): แถวที่ข้ามเพราะมีอยู่แล้วต้องถูกนับและรายงานกลับ', function (): void {
    // ตัวช่วยติดตั้งเคยรายงานว่า "โหลดสำเร็จ" ทั้งที่บนเส้นทางติดตั้งตามคู่มือ (นำเข้า seed_reference.sql ก่อน)
    // รหัสชนกันเกือบทั้งชุดแล้วถูกกลืนทิ้งเงียบ ๆ ผู้ซื้อจึงเข้าใจผิดว่าหมวดหมู่ที่เห็นมาจากข้อมูลตัวอย่าง
    $service = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/DemoDataService.php');
    assert_true(str_contains($service, '$this->skippedExisting++'), 'ต้องนับแถวที่ข้าม ไม่ใช่กลืน exception ทิ้งเฉย ๆ');
    assert_true(str_contains($service, "\$created['skipped_existing']"), 'ต้องส่งจำนวนที่ข้ามกลับไปกับผลลัพธ์');

    assert_true(
        str_contains(demo_accounts_message(['skipped_existing' => 7]), '7'),
        'ข้อความที่ผู้ติดตั้งเห็นต้องบอกจำนวนที่ข้าม'
    );
    assert_same('', demo_accounts_message([]), 'ถ้าไม่มีอะไรข้ามก็ไม่ต้องมีข้อความรก');
});
