<?php
declare(strict_types=1);

use App\Core\Request;
use App\Services\ReferenceDataService;

// Tests for ReferenceDataService (admin master-data CRUD). Covers the shared code+name validation
// (via department), the full priority validation set, the not-found guards for bad ids, two happy
// round-trips (department + priority), and — most importantly — the delete-in-use guards. Every seeded
// row is removed in finally (children cascade where applicable).

function rd_service(): ReferenceDataService
{
    return tvm_container()->get(ReferenceDataService::class);
}

function rd_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function rd_admin(): array
{
    return ['id' => 4, 'role' => 'admin'];
}

/** Bind a Request so AuditLogger::record() (fired by every successful create/update/delete) can run
 *  under CLI — production always has one bound; the harness doesn't. */
function rd_bind_request(): void
{
    tvm_container()->instance(Request::class, Request::capture());
}

function rd_valid_dept(array $overrides = []): array
{
    return array_merge([
        'code' => 'RDD' . strtoupper(bin2hex(random_bytes(3))),
        'name' => 'RD Dept ' . bin2hex(random_bytes(4)),
        'description' => 'x',
        'is_active' => '1',
    ], $overrides);
}

function rd_valid_priority(array $overrides = []): array
{
    return array_merge([
        'code' => 'RDP' . strtoupper(bin2hex(random_bytes(3))),
        'name' => 'RD Priority ' . bin2hex(random_bytes(4)),
        'level' => random_int(50, 98),
        'color' => '#ff0000',
        'response_hours' => '2',
        'resolution_hours' => '8',
        'sort_order' => 5,
        'is_active' => '1',
    ], $overrides);
}

test('reference data: a malformed numeric priority level / sort_order is rejected (round F1)', function (): void {
    rd_bind_request();
    foreach ([['level' => '50junk'], ['sort_order' => '5junk']] as $override) {
        $threw = false;
        try {
            rd_service()->createPriority(rd_admin(), rd_valid_priority($override));
        } catch (DomainException) {
            $threw = true;
        }
        assert_true($threw, 'a malformed ' . array_key_first($override) . ' is rejected, not coerced to its prefix');
    }
});

test('reference data: a non-numeric SLA hours on a ticket category is rejected, not stored as a 0-minute SLA (round F1)', function (): void {
    // (float)"abc" === 0.0 would silently create a category with a 0-minute SLA (always overdue). strict_float rejects it.
    rd_bind_request();
    $threw = false;
    try {
        rd_service()->createTicketCategory(rd_admin(), [
            'code' => 'RDF1' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'RD F1 Category',
            'response_hours' => 'abc',
            'resolution_hours' => '8',
            'sort_order' => 5,
            'is_active' => '1',
        ]);
    } catch (DomainException) {
        $threw = true;
    }
    assert_true($threw, 'a non-numeric response_hours must be rejected, not stored as a 0-minute SLA');
});

// R4-F2: server bounds must match the DB columns — category name is VARCHAR(150) (an earlier round wrongly
// capped it at 100), and priority name/color/sort had NO bound so a crafted over-length value hit a DB error.
test('reference data(R4-F2): category name allows 150 but rejects 151; priority bounds name100/color30/sort255', function (): void {
    rd_bind_request();
    $code = 'RDC' . strtoupper(bin2hex(random_bytes(3)));
    $catId = 0;
    try {
        // a 150-char category name matches ticket_categories.name VARCHAR(150) → must be accepted
        rd_service()->createTicketCategory(rd_admin(), [
            'code' => $code, 'name' => str_repeat('ก', 150),
            'response_hours' => '2', 'resolution_hours' => '8', 'sort_order' => 5, 'is_active' => '1',
        ]);
        $catId = (int) rd_pdo()->query('SELECT id FROM ticket_categories WHERE code = ' . rd_pdo()->quote($code))->fetchColumn();
        assert_true($catId > 0, 'a 150-char category name is accepted (matches VARCHAR(150))');

        // 151 must be rejected before the DB, with a friendly message
        $threw = false;
        try {
            rd_service()->createTicketCategory(rd_admin(), [
                'code' => 'RDC' . strtoupper(bin2hex(random_bytes(3))), 'name' => str_repeat('ก', 151),
                'response_hours' => '2', 'resolution_hours' => '8', 'sort_order' => 5, 'is_active' => '1',
            ]);
        } catch (DomainException $e) {
            $threw = str_contains($e->getMessage(), 'ยาวเกินกำหนด');
        }
        assert_true($threw, 'a 151-char category name is rejected');

        // priority: name ≤100, color ≤30, sort_order ≤255 — each crafted over-bound value throws before the DB
        foreach ([['name' => str_repeat('x', 101)], ['color' => str_repeat('a', 31)], ['sort_order' => 256]] as $bad) {
            $t = false;
            try {
                rd_service()->createPriority(rd_admin(), rd_valid_priority($bad));
            } catch (DomainException) {
                $t = true;
            }
            assert_true($t, 'priority over-bound ' . array_key_first($bad) . ' is rejected before the DB');
        }
    } finally {
        if ($catId > 0) {
            rd_pdo()->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute(['category_sla_' . $catId]);
            rd_pdo()->prepare('DELETE FROM ticket_categories WHERE id = ?')->execute([$catId]);
        }
    }
});

// R5-F1: is_numeric() accepts "1e999" (→ INF → (int) 0 minutes, a silent always-breach SLA) and finite values
// that overflow the INT UNSIGNED minute columns (→ DB error). Category also used to clamp a negative to 0.
test('reference data(R5-F1): category SLA rejects negative, non-finite (1e999) and INT-overflow hours', function (): void {
    rd_bind_request();
    foreach (['-1', '1e999', '100000000'] as $bad) { // negative · INF · finite overflow (6e9 min > 4.29e9)
        foreach (['response_hours', 'resolution_hours'] as $field) {
            $code = 'RDS' . strtoupper(bin2hex(random_bytes(3)));
            $threw = false;
            try {
                rd_service()->createTicketCategory(rd_admin(), array_merge([
                    'code' => $code, 'name' => 'RD SLA Cat',
                    'response_hours' => '2', 'resolution_hours' => '8', 'sort_order' => 5, 'is_active' => '1',
                ], [$field => $bad]));
            } catch (DomainException) {
                $threw = true;
            }
            assert_true($threw, "category $field=$bad is rejected");
            assert_same(0, (int) rd_pdo()->query('SELECT COUNT(*) FROM ticket_categories WHERE code = ' . rd_pdo()->quote($code))->fetchColumn(), "no category row created for $field=$bad");
        }
    }
});

test('reference data(R5-F1): priority SLA rejects non-finite (1e999) and INT-overflow hours', function (): void {
    rd_bind_request();
    foreach (['1e999', '100000000'] as $bad) {
        foreach (['response_hours', 'resolution_hours'] as $field) {
            $threw = false;
            try {
                rd_service()->createPriority(rd_admin(), rd_valid_priority([$field => $bad]));
            } catch (DomainException) {
                $threw = true;
            }
            assert_true($threw, "priority $field=$bad is rejected before the DB");
        }
    }
});

// ── shared validation (code + name) — one entity is enough (buildMasterPayload is shared) ──

test('reference data: shared code+name validation (via department)', function (): void {
    foreach ([['code' => ''], ['name' => '']] as $bad) {
        $threw = false;
        try {
            rd_service()->createDepartment(rd_admin(), rd_valid_dept($bad));
        } catch (DomainException $e) {
            $threw = true;
            assert_same('กรุณากรอกรหัสและชื่อแผนกให้ครบถ้วน', $e->getMessage());
        }
        assert_true($threw, 'missing code or name must throw');
    }
});

// ── priority validation (the richest ruleset) ──

test('reference data: createPriority validation branches', function (): void {
    $reject = static function (array $overrides, string $msg, string $ctx): void {
        $threw = false;
        try {
            rd_service()->createPriority(rd_admin(), rd_valid_priority($overrides));
        } catch (DomainException $e) {
            $threw = true;
            assert_same($msg, $e->getMessage(), $ctx);
        }
        assert_true($threw, "$ctx — must throw");
    };
    $reject(['name' => ''], 'กรุณากรอกชื่อ Priority', 'empty name');
    $reject(['response_hours' => 'abc'], 'SLA ต้องเป็นตัวเลขชั่วโมง', 'non-numeric SLA');
    $reject(['response_hours' => '-1'], 'SLA ต้องไม่ติดลบ', 'negative SLA');
    $reject(['code' => 'A'], 'รหัส Priority ต้องเป็น A-Z, 0-9, ขีดกลาง หรือขีดล่าง ความยาว 2-50 ตัวอักษร', 'bad code format');
    $reject(['level' => 0], 'ระดับ Priority ต้องอยู่ระหว่าง 1-99', 'level below range');
    $reject(['level' => 100], 'ระดับ Priority ต้องอยู่ระหว่าง 1-99', 'level above range');
});

// ── not-found guards: update/delete with an id that does not exist ──

test('reference data: update/delete a non-existent id → not found', function (): void {
    $cases = [
        static fn () => rd_service()->updateDepartment(999999999, rd_admin(), rd_valid_dept()),
        static fn () => rd_service()->deleteDepartment(999999999, rd_admin()),
        static fn () => rd_service()->deletePriority(999999999, rd_admin()),
    ];
    $expected = ['ไม่พบแผนกที่ต้องการแก้ไข', 'ไม่พบแผนกที่ต้องการลบ', 'ไม่พบ Priority ที่ต้องการลบ'];
    foreach ($cases as $i => $call) {
        $threw = false;
        try {
            $call();
        } catch (DomainException $e) {
            $threw = true;
            assert_same($expected[$i], $e->getMessage(), "case $i");
        }
        assert_true($threw, "non-existent id case $i must throw");
    }
});

// ── happy round-trips: create → read → update → read → delete → gone ──

test('reference data: department round-trip (create/update/delete)', function (): void {
    rd_bind_request();
    $input = rd_valid_dept();
    $renamed = 'RD Dept Renamed ' . bin2hex(random_bytes(4));
    $deptId = 0;
    try {
        rd_service()->createDepartment(rd_admin(), $input);
        $deptId = (int) rd_pdo()->query('SELECT id FROM departments WHERE code = ' . rd_pdo()->quote($input['code']))->fetchColumn();
        assert_true($deptId > 0, 'department created + readable');

        rd_service()->updateDepartment($deptId, rd_admin(), rd_valid_dept(['code' => $input['code'], 'name' => $renamed]));
        assert_same($renamed, (string) rd_pdo()->query("SELECT name FROM departments WHERE id = $deptId")->fetchColumn(), 'name updated');

        rd_service()->deleteDepartment($deptId, rd_admin());
        assert_same(0, (int) rd_pdo()->query("SELECT COUNT(*) FROM departments WHERE id = $deptId")->fetchColumn(), 'department deleted');
        $deptId = 0;
    } finally {
        if ($deptId > 0) {
            rd_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
        }
    }
});

test('reference data: priority round-trip (create/update/delete)', function (): void {
    rd_bind_request();
    $input = rd_valid_priority();
    $renamed = 'RD Priority Renamed ' . bin2hex(random_bytes(4));
    $priorityId = 0;
    try {
        rd_service()->createPriority(rd_admin(), $input);
        $priorityId = (int) rd_pdo()->query('SELECT id FROM priorities WHERE code = ' . rd_pdo()->quote($input['code']))->fetchColumn();
        assert_true($priorityId > 0, 'priority created + readable');
        assert_same(120, (int) rd_pdo()->query("SELECT response_time_minutes FROM priorities WHERE id = $priorityId")->fetchColumn(), 'response 2h → 120 min');

        rd_service()->updatePriority($priorityId, rd_admin(), rd_valid_priority(['name' => $renamed, 'response_hours' => '3']));
        $row = rd_pdo()->query("SELECT name, response_time_minutes FROM priorities WHERE id = $priorityId")->fetch(PDO::FETCH_ASSOC);
        assert_same($renamed, $row['name'], 'name updated');
        assert_same(180, (int) $row['response_time_minutes'], 'response updated 3h → 180 min');

        rd_service()->deletePriority($priorityId, rd_admin());
        assert_same(0, (int) rd_pdo()->query("SELECT COUNT(*) FROM priorities WHERE id = $priorityId")->fetchColumn(), 'priority deleted');
        $priorityId = 0;
    } finally {
        if ($priorityId > 0) {
            rd_pdo()->prepare('DELETE FROM priorities WHERE id = ?')->execute([$priorityId]);
        }
    }
});

// ── delete-in-use guards (must refuse + keep the row) ──

test('reference data: cannot delete a department that has a user (guard + row survives)', function (): void {
    rd_bind_request();
    $input = rd_valid_dept();
    $deptId = 0;
    $userId = 0;
    try {
        rd_service()->createDepartment(rd_admin(), $input);
        $deptId = (int) rd_pdo()->query('SELECT id FROM departments WHERE code = ' . rd_pdo()->quote($input['code']))->fetchColumn();
        rd_pdo()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, department_id, is_active, created_at, updated_at) VALUES (?, ?, "x", "Dept User", "requester", ?, 1, NOW(), NOW())')
            ->execute(['rdu_' . bin2hex(random_bytes(4)), 'rdu_' . bin2hex(random_bytes(4)) . '@x.t', $deptId]);
        $userId = (int) rd_pdo()->lastInsertId();

        $threw = false;
        try {
            rd_service()->deleteDepartment($deptId, rd_admin());
        } catch (DomainException $e) {
            $threw = true;
            assert_same('แผนกนี้ถูกใช้งานแล้ว กรุณาปิดใช้งานแทนการลบ', $e->getMessage());
        }
        assert_true($threw, 'deleting an in-use department must throw');
        assert_same(1, (int) rd_pdo()->query("SELECT COUNT(*) FROM departments WHERE id = $deptId")->fetchColumn(), 'department was NOT deleted');
    } finally {
        if ($userId > 0) {
            rd_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
        if ($deptId > 0) {
            rd_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
        }
    }
});

test('reference data: cannot delete a priority that a ticket uses (guard + row survives)', function (): void {
    rd_bind_request();
    $input = rd_valid_priority();
    $priorityId = 0;
    $ticketId = 0;
    try {
        rd_service()->createPriority(rd_admin(), $input);
        $priorityId = (int) rd_pdo()->query('SELECT id FROM priorities WHERE code = ' . rd_pdo()->quote($input['code']))->fetchColumn();

        $loc = (int) rd_pdo()->query('SELECT COALESCE((SELECT id FROM locations LIMIT 1), 1)')->fetchColumn();
        $cat = (int) rd_pdo()->query('SELECT COALESCE((SELECT id FROM ticket_categories LIMIT 1), 1)')->fetchColumn();
        rd_pdo()->prepare('INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at) VALUES (?, "RD", "x", 1, ?, ?, ?, "in_progress", NOW())')
            ->execute(['RD-' . bin2hex(random_bytes(4)), $loc, $cat, $priorityId]);
        $ticketId = (int) rd_pdo()->lastInsertId();

        $threw = false;
        try {
            rd_service()->deletePriority($priorityId, rd_admin());
        } catch (DomainException $e) {
            $threw = true;
            assert_same('Priority นี้ถูกใช้งานในรายการแจ้งซ่อมแล้ว กรุณาปิดใช้งานแทนการลบ', $e->getMessage());
        }
        assert_true($threw, 'deleting an in-use priority must throw');
        assert_same(1, (int) rd_pdo()->query("SELECT COUNT(*) FROM priorities WHERE id = $priorityId")->fetchColumn(), 'priority was NOT deleted');
    } finally {
        if ($ticketId > 0) {
            rd_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        if ($priorityId > 0) {
            rd_pdo()->prepare('DELETE FROM priorities WHERE id = ?')->execute([$priorityId]);
        }
    }
});

test('reference data: cannot delete a ticket category that a ticket uses (guard + row survives)', function (): void {
    rd_bind_request();
    $suffix = bin2hex(random_bytes(4));
    $catId = 0;
    $ticketId = 0;
    try {
        rd_pdo()->prepare('INSERT INTO ticket_categories (code, name, sort_order, is_active, created_at, updated_at) VALUES (?, ?, 0, 1, NOW(), NOW())')
            ->execute(['RDCAT' . strtoupper($suffix), 'RD Category ' . $suffix]);
        $catId = (int) rd_pdo()->lastInsertId();

        $loc = (int) rd_pdo()->query('SELECT COALESCE((SELECT id FROM locations LIMIT 1), 1)')->fetchColumn();
        $pri = (int) rd_pdo()->query('SELECT COALESCE((SELECT id FROM priorities LIMIT 1), 1)')->fetchColumn();
        rd_pdo()->prepare('INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at) VALUES (?, "RD", "x", 1, ?, ?, ?, "in_progress", NOW())')
            ->execute(['RDC-' . $suffix, $loc, $catId, $pri]);
        $ticketId = (int) rd_pdo()->lastInsertId();

        $threw = false;
        try {
            rd_service()->deleteTicketCategory($catId, rd_admin());
        } catch (DomainException $e) {
            $threw = true;
            assert_same('หมวดหมู่งานนี้ถูกใช้งานแล้ว กรุณาปิดใช้งานแทนการลบ', $e->getMessage());
        }
        assert_true($threw, 'deleting an in-use ticket category must throw');
        assert_same(1, (int) rd_pdo()->query("SELECT COUNT(*) FROM ticket_categories WHERE id = $catId")->fetchColumn(), 'category was NOT deleted');
    } finally {
        if ($ticketId > 0) {
            rd_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        if ($catId > 0) {
            rd_pdo()->prepare('DELETE FROM ticket_categories WHERE id = ?')->execute([$catId]);
        }
    }
});
