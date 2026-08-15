<?php
declare(strict_types=1);

use App\Core\Request;
use App\Repositories\TicketReadRepository;
use App\Services\AdminService;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// Validation + happy-path tests for AdminService user CRUD (createUser / updateUser). Reject branches
// throw before any DB write (nothing to clean up); the happy paths seed a department, create/update a
// real user in the test DB, and delete both in finally.

function au_service(): AdminService
{
    return tvm_container()->get(AdminService::class);
}

/** Bind a Request in the container so AuditLogger::record() (called by createUser/updateUser) can run —
 *  in production a Request is always bound; the CLI harness has none. Capture() defaults to GET "/". */
function au_bind_request(): void
{
    tvm_container()->instance(Request::class, Request::capture());
}

function au_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function au_admin(): array
{
    return ['id' => 4, 'role' => 'admin'];
}

/** A fully-valid createUser input; override individual keys to exercise a single failing branch. */
function au_valid_input(array $overrides = []): array
{
    $suffix = bin2hex(random_bytes(4));
    return array_merge([
        'username' => 'newuser_' . $suffix,
        'full_name' => 'New User',
        'email' => 'newuser_' . $suffix . '@example.com',
        'role' => 'requester',
        'password' => 'ValidPass123',
        'password_confirmation' => 'ValidPass123',
        'department_id' => 0,
        'is_active' => '1',
    ], $overrides);
}

/** Assert createUser($overrides applied to a valid base) throws exactly $message. Nothing is persisted. */
function au_reject_create(array $overrides, string $message, string $context): void
{
    $threw = false;
    try {
        au_service()->createUser(au_admin(), au_valid_input($overrides));
    } catch (DomainException $e) {
        $threw = true;
        assert_same($message, $e->getMessage(), $context);
    }
    assert_true($threw, "$context — must throw");
}

test('createUser: validation branches each reject with the right message', function (): void {
    au_reject_create(['username' => ''], 'กรุณากรอกชื่อผู้ใช้ ชื่อ อีเมล และรหัสผ่านให้ครบถ้วน', 'missing required field');
    au_reject_create(['username' => 'ab'], 'ชื่อผู้ใช้ต้องมี 3-50 ตัวอักษร และใช้ได้เฉพาะ a-z, 0-9, จุด, ขีดกลาง และขีดล่าง', 'username too short');
    au_reject_create(['username' => 'bad user!'], 'ชื่อผู้ใช้ต้องมี 3-50 ตัวอักษร และใช้ได้เฉพาะ a-z, 0-9, จุด, ขีดกลาง และขีดล่าง', 'username has forbidden chars');
    au_reject_create(['email' => 'not-an-email'], 'รูปแบบอีเมลผู้ใช้งานไม่ถูกต้อง', 'invalid email');
    au_reject_create(['role' => 'superuser'], 'Role ผู้ใช้งานไม่ถูกต้อง', 'invalid role');
    au_reject_create(['password_confirmation' => 'Mismatch123'], 'ยืนยันรหัสผ่านไม่ตรงกัน', 'password confirmation mismatch');
    au_reject_create(['password' => 'short7', 'password_confirmation' => 'short7'], 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร', 'password too short');
    au_reject_create(['department_id' => 999999999], 'Department ที่เลือกไม่ถูกต้อง', 'non-existent department');

    // F6: bound to the DB columns so over-long values give a friendly message, not a strict-mode DB error.
    au_reject_create(['full_name' => str_repeat('ก', 151)], 'ชื่อผู้ใช้งานยาวเกินกำหนด (ไม่เกิน 150 ตัวอักษร)', 'full_name over users.full_name(150)');
    au_reject_create(['phone' => str_repeat('0', 31)], 'เบอร์โทรยาวเกินกำหนด (ไม่เกิน 30 ตัวอักษร)', 'phone over users.phone(30)');
    au_reject_create(['email' => str_repeat('a', 185) . '@x.test'], 'รูปแบบอีเมลผู้ใช้งานไม่ถูกต้อง', 'email over *_email(190) rejected by length-bounded is_valid_email');
});

test('createUser: happy path stores a hashed password (not plaintext) + correct fields', function (): void {
    $deptId = 0;
    $userId = 0;
    $input = au_valid_input(['role' => 'technician']);
    try {
        au_bind_request();
        // a real department so the department_id branch is exercised on the success path
        au_pdo()->prepare('INSERT INTO departments (code, name, description, is_active, created_at, updated_at) VALUES (?, ?, "", 1, NOW(), NOW())')
            ->execute(['AUD-' . bin2hex(random_bytes(3)), 'Auth Test Dept ' . bin2hex(random_bytes(4))]);
        $deptId = (int) au_pdo()->lastInsertId();
        $input['department_id'] = $deptId;

        au_service()->createUser(au_admin(), $input);

        $row = au_pdo()->query('SELECT * FROM users WHERE username = ' . au_pdo()->quote($input['username']))->fetch(PDO::FETCH_ASSOC);
        assert_true($row !== false, 'user was created');
        $userId = (int) $row['id'];
        assert_same($input['email'], $row['email'], 'email stored');
        assert_same('technician', $row['role'], 'role stored');
        assert_same($deptId, (int) $row['department_id'], 'department_id stored');
        assert_true($row['password_hash'] !== 'ValidPass123', 'password is NOT stored as plaintext');
        assert_true(password_verify('ValidPass123', (string) $row['password_hash']), 'password_hash verifies the original password');
    } finally {
        if ($userId > 0) {
            au_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
        if ($deptId > 0) {
            au_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
        }
    }
});

test('updateUser: rejects bad input, then updates real fields on the happy path', function (): void {
    $suffix = bin2hex(random_bytes(4));
    au_pdo()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, "x", "Before Name", "requester", 1, NOW(), NOW())')
        ->execute(["upd_$suffix", "upd_$suffix@example.com"]);
    $userId = (int) au_pdo()->lastInsertId();

    try {
        // reject branches (throw before touching the row)
        $reject = static function (array $input, string $msg, string $ctx) use ($userId): void {
            $threw = false;
            try {
                au_service()->updateUser($userId, au_admin(), $input);
            } catch (DomainException $e) {
                $threw = true;
                assert_same($msg, $e->getMessage(), $ctx);
            }
            assert_true($threw, "$ctx — must throw");
        };
        $reject(['full_name' => '', 'email' => 'a@b.com'], 'กรุณากรอกชื่อและอีเมลผู้ใช้งานให้ครบถ้วน', 'missing name');
        $reject(['full_name' => 'X', 'email' => 'bad-email'], 'รูปแบบอีเมลผู้ใช้งานไม่ถูกต้อง', 'invalid email');
        $reject(['full_name' => 'X', 'email' => 'a@b.com', 'role' => 'root'], 'Role ผู้ใช้งานไม่ถูกต้อง', 'invalid role');
        // invalid department must throw a friendly DomainException (not reach the FK → PDOException/500). round F2
        $reject(['full_name' => 'X', 'email' => 'a@b.com', 'role' => 'requester', 'department_id' => 999999999], 'Department ที่เลือกไม่ถูกต้อง', 'invalid department');

        // happy path — values actually change
        au_bind_request();
        au_service()->updateUser($userId, au_admin(), ['full_name' => 'After Name', 'email' => "after_$suffix@example.com", 'role' => 'manager', 'is_active' => '1', 'original_version' => 1]);
        $row = au_pdo()->query("SELECT full_name, email, role FROM users WHERE id = $userId")->fetch(PDO::FETCH_ASSOC);
        assert_same('After Name', $row['full_name'], 'full_name updated');
        assert_same("after_$suffix@example.com", $row['email'], 'email updated');
        assert_same('manager', $row['role'], 'role updated');
    } finally {
        au_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
});

// R7-F3: the admin user form carries a hidden original_version; a save from a STALE snapshot (Admin B still
// on an old page after Admin A saved) must be rejected by the version CAS, not silently overwrite A's change.
test('updateUser(R7-F3): a stale full-form save is rejected by the version lock; the newer value survives', function (): void {
    $suffix = bin2hex(random_bytes(4));
    au_pdo()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, "x", "V1", "requester", 1, NOW(), NOW())')
        ->execute(["ver_$suffix", "ver_$suffix@example.com"]);
    $userId = (int) au_pdo()->lastInsertId();
    au_bind_request();

    try {
        // both admins opened the form at version 1. Admin A saves first → version → 2.
        au_service()->updateUser($userId, au_admin(), ['full_name' => 'By Admin A', 'email' => "ver_$suffix@example.com", 'role' => 'requester', 'is_active' => '1', 'original_version' => 1]);
        assert_same('By Admin A', (string) au_pdo()->query("SELECT full_name FROM users WHERE id = $userId")->fetchColumn(), 'Admin A saved');
        assert_same(2, (int) au_pdo()->query("SELECT version FROM users WHERE id = $userId")->fetchColumn(), 'version bumped to 2');

        // Admin B submits their still-open form (original_version 1) → rejected, A's value untouched.
        $threw = false;
        try {
            au_service()->updateUser($userId, au_admin(), ['full_name' => 'By Admin B (stale)', 'email' => "ver_$suffix@example.com", 'role' => 'requester', 'is_active' => '1', 'original_version' => 1]);
        } catch (DomainException $e) {
            $threw = str_contains($e->getMessage(), 'ถูกแก้ไขโดยผู้ใช้อื่น');
        }
        assert_true($threw, 'the stale (version 1) save is rejected');
        assert_same('By Admin A', (string) au_pdo()->query("SELECT full_name FROM users WHERE id = $userId")->fetchColumn(), "Admin A's newer value survives — no silent overwrite");
    } finally {
        au_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
});

test('admin user: a malformed numeric department ("1junk") is rejected on create and update (round F1)', function (): void {
    au_bind_request();
    $suffix = bin2hex(random_bytes(4));

    $threwCreate = false;
    try {
        au_service()->createUser(au_admin(), au_valid_input(['username' => "mdep_$suffix", 'email' => "mdep_$suffix@example.com", 'department_id' => '1junk']));
    } catch (DomainException) {
        $threwCreate = true;
    }
    assert_true($threwCreate, 'createUser rejects a malformed department_id (not coerced to 1)');

    au_pdo()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, "x", "Dep", "requester", 1, NOW(), NOW())')
        ->execute(["mdepu_$suffix", "mdepu_$suffix@example.com"]);
    $userId = (int) au_pdo()->lastInsertId();
    try {
        $threwUpdate = false;
        try {
            au_service()->updateUser($userId, au_admin(), ['full_name' => 'Dep', 'email' => "mdepu_$suffix@example.com", 'role' => 'requester', 'department_id' => '1junk']);
        } catch (DomainException) {
            $threwUpdate = true;
        }
        assert_true($threwUpdate, 'updateUser rejects a malformed department_id');
    } finally {
        au_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
});

test('updateUser: demoting/deactivating a user with open work is blocked (round M2)', function (): void {
    au_bind_request();
    $suffix = bin2hex(random_bytes(4));
    // a technician with an open (in_progress) assigned ticket
    au_pdo()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, "x", "Tech Open", "technician", 1, NOW(), NOW())')
        ->execute(["mtech_$suffix", "mtech_$suffix@example.com"]);
    $techId = (int) au_pdo()->lastInsertId();
    au_pdo()->prepare("INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, assigned_technician_id, status, approval_status, requested_at) VALUES (?, 'x', 'x', 1, 1, 1, 1, ?, 'in_progress', 'approved', NOW())")
        ->execute(["MTECH-$suffix", $techId]);
    $techTicket = (int) au_pdo()->lastInsertId();
    // a requester with an open ticket
    au_pdo()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, "x", "Req Open", "requester", 1, NOW(), NOW())')
        ->execute(["mreq_$suffix", "mreq_$suffix@example.com"]);
    $reqId = (int) au_pdo()->lastInsertId();
    au_pdo()->prepare("INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, approval_status, requested_at) VALUES (?, 'x', 'x', ?, 1, 1, 1, 'in_progress', 'approved', NOW())")
        ->execute(["MREQ-$suffix", $reqId]);
    $reqTicket = (int) au_pdo()->lastInsertId();

    $blocked = static function (int $userId, array $input, string $ctx): void {
        $threw = false;
        try {
            au_service()->updateUser($userId, au_admin(), $input);
        } catch (DomainException) {
            $threw = true;
        }
        assert_true($threw, "$ctx — must be blocked while work is open");
    };

    try {
        $blocked($techId, ['full_name' => 'Tech Open', 'email' => "mtech_$suffix@example.com", 'role' => 'requester', 'is_active' => '1'], 'demote technician with open work');
        $blocked($techId, ['full_name' => 'Tech Open', 'email' => "mtech_$suffix@example.com", 'role' => 'technician', 'is_active' => '0'], 'deactivate technician with open work');
        $blocked($reqId, ['full_name' => 'Req Open', 'email' => "mreq_$suffix@example.com", 'role' => 'requester', 'is_active' => '0'], 'deactivate requester with open ticket');
    } finally {
        au_pdo()->prepare('DELETE FROM tickets WHERE id IN (?, ?)')->execute([$techTicket, $reqTicket]);
        au_pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$techId, $reqId]);
    }
});

test('updateUser(integrity): deactivating a manager releases live tickets to the shared manager queue', function (): void {
    au_bind_request();
    $suffix = bin2hex(random_bytes(4));
    au_pdo()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, "x", "Manager Owner", "manager", 1, NOW(), NOW())'
    )->execute(["mowner_$suffix", "mowner_$suffix@example.com"]);
    $managerId = (int) au_pdo()->lastInsertId();
    $ticketId = 0;
    $completedTicketId = 0;

    try {
        $reference = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
        $ticketId = tvm_container()->get(TicketService::class)->createTicket(
            ['id' => 1, 'role' => 'requester'],
            [
                'submission_token' => bin2hex(random_bytes(32)),
                'title' => 'Manager ownership integrity probe',
                'description' => 'The approving manager becomes the live ticket owner.',
                'priority_id' => (int) $reference['priorities'][0]['id'],
                'ticket_category_id' => (int) $reference['categories'][0]['id'],
                'location_id' => (int) $reference['locations'][0]['id'],
                'impact_level' => 'medium',
                'urgency_level' => 'medium',
            ],
            []
        );
        tvm_container()->get(TicketWorkflowService::class)->approveTicket(
            $ticketId,
            ['id' => $managerId, 'role' => 'manager'],
            ['note' => '']
        );

        au_pdo()->prepare(
            "INSERT INTO tickets (
                ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id,
                assigned_manager_id, status, approval_status, requested_at, approved_at, resolved_at, completed_at
             ) VALUES (?, 'Completed history', 'Terminal ownership must stay as history', 1, ?, ?, ?, ?,
                       'completed', 'approved', NOW(), NOW(), NOW(), NOW())"
        )->execute([
            "MOWNER-DONE-$suffix",
            (int) $reference['locations'][0]['id'],
            (int) $reference['categories'][0]['id'],
            (int) $reference['priorities'][0]['id'],
            $managerId,
        ]);
        $completedTicketId = (int) au_pdo()->lastInsertId();

        $deactivationError = null;
        try {
            au_service()->updateUser($managerId, au_admin(), [
                'full_name' => 'Manager Owner',
                'email' => "mowner_$suffix@example.com",
                'role' => 'manager',
                'is_active' => '0',
                'original_version' => '1',
            ]);
        } catch (Throwable $exception) {
            $deactivationError = $exception;
        }
        assert_true(
            $deactivationError === null,
            'manager offboarding must succeed after releasing live work; got: ' . ($deactivationError?->getMessage() ?? 'none')
        );

        $manager = au_pdo()->query("SELECT role, is_active FROM users WHERE id = $managerId")->fetch(PDO::FETCH_ASSOC);
        assert_same('manager', (string) $manager['role'], 'the owner keeps the manager role');
        assert_same(0, (int) $manager['is_active'], 'the manager account is deactivated');
        assert_true(
            au_pdo()->query("SELECT assigned_manager_id FROM tickets WHERE id = $ticketId")->fetchColumn() === null,
            'the live ticket is released to the shared manager queue'
        );
        assert_same(
            $managerId,
            (int) au_pdo()->query("SELECT assigned_manager_id FROM tickets WHERE id = $completedTicketId")->fetchColumn(),
            'a completed ticket keeps its historical manager'
        );

        $invalidLiveOwners = (int) au_pdo()->query(
            "SELECT COUNT(*)
             FROM tickets t
             INNER JOIN users manager ON manager.id = t.assigned_manager_id
             WHERE t.status NOT IN ('completed', 'rejected', 'cancelled', 'closed')
               AND (manager.is_active <> 1 OR manager.role NOT IN ('manager', 'admin'))"
        )->fetchColumn();
        assert_same(0, $invalidLiveOwners, 'no live ticket remains linked to an inactive or invalid manager');

        $nextManager = ['id' => 2, 'role' => 'manager'];
        $queue = tvm_container()->get(TicketService::class)->getTicketIndexData($nextManager);
        $queueIds = array_map(static fn (array $ticket): int => (int) $ticket['id'], $queue['tickets']);
        assert_true(in_array($ticketId, $queueIds, true), 'the released ticket appears in another manager\'s real queue');

        $detail = tvm_container()->get(TicketService::class)->getTicketDetailData($ticketId, $nextManager);
        assert_true($detail !== null, 'another manager can open the released ticket');
        assert_true(
            (bool) ($detail['workflow']['canAssign'] ?? false),
            'the released ticket shows the actionable assign-technician control'
        );

        tvm_container()->get(TicketWorkflowService::class)->assignTechnician(
            $ticketId,
            $nextManager,
            ['technician_id' => 3, 'instructions' => 'รับช่วงจากหัวหน้างานที่ปิดบัญชี']
        );
        assert_same(
            'assigned',
            (string) au_pdo()->query("SELECT status FROM tickets WHERE id = $ticketId")->fetchColumn(),
            'another manager can actually assign the released ticket'
        );
    } finally {
        $ticketIds = array_values(array_filter([$ticketId, $completedTicketId], static fn (int $id): bool => $id > 0));
        foreach ($ticketIds as $cleanupTicketId) {
            au_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$cleanupTicketId]);
        }
        if ($ticketIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($ticketIds), '?'));
            au_pdo()->prepare("DELETE FROM tickets WHERE id IN ($placeholders)")->execute($ticketIds);
        }
        au_pdo()->prepare("DELETE FROM audit_logs WHERE entity_type = 'user' AND entity_id = ?")->execute([$managerId]);
        au_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$managerId]);
    }
});

test('updateUser(integrity): a manager with no live owned ticket can still be deactivated', function (): void {
    au_bind_request();
    $suffix = bin2hex(random_bytes(4));
    au_pdo()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, "x", "Free Manager", "manager", 1, NOW(), NOW())'
    )->execute(["mfree_$suffix", "mfree_$suffix@example.com"]);
    $managerId = (int) au_pdo()->lastInsertId();

    try {
        au_service()->updateUser($managerId, au_admin(), [
            'full_name' => 'Free Manager',
            'email' => "mfree_$suffix@example.com",
            'role' => 'manager',
            'is_active' => '0',
            'original_version' => '1',
        ]);
        assert_same(
            0,
            (int) au_pdo()->query("SELECT is_active FROM users WHERE id = $managerId")->fetchColumn(),
            'the guard is limited to managers who still own live tickets'
        );
    } finally {
        au_pdo()->prepare("DELETE FROM audit_logs WHERE entity_type = 'user' AND entity_id = ?")->execute([$managerId]);
        au_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$managerId]);
    }
});

test('updateUser(integrity): releasing manager work and deactivating the account are atomic', function (): void {
    au_bind_request();
    $suffix = bin2hex(random_bytes(4));
    au_pdo()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, "x", "Atomic Manager", "manager", 1, NOW(), NOW())'
    )->execute(["matomic_$suffix", "matomic_$suffix@example.com"]);
    $managerId = (int) au_pdo()->lastInsertId();
    au_pdo()->prepare(
        "INSERT INTO tickets (
            ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id,
            assigned_manager_id, status, approval_status, requested_at, approved_at
         ) VALUES (?, 'Atomic release', 'The two writes must commit or roll back together', 1, 1, 1, 1, ?,
                   'approved', 'approved', NOW(), NOW())"
    )->execute(["MATOMIC-$suffix", $managerId]);
    $ticketId = (int) au_pdo()->lastInsertId();

    try {
        $injectedFailure = false;
        with_failing_pdo('UPDATE users', function () use ($managerId, $suffix, &$injectedFailure): void {
            try {
                tvm_container()->get(AdminService::class)->updateUser($managerId, au_admin(), [
                    'full_name' => 'Atomic Manager',
                    'email' => "matomic_$suffix@example.com",
                    'role' => 'manager',
                    'is_active' => '0',
                    'original_version' => '1',
                ]);
            } catch (RuntimeException $exception) {
                $injectedFailure = str_contains($exception->getMessage(), 'FailingPdo');
            }
        });
        assert_true($injectedFailure, 'the test injected a failure after the ticket-release write');
        assert_same(
            1,
            (int) au_pdo()->query("SELECT is_active FROM users WHERE id = $managerId")->fetchColumn(),
            'the account remains active after the transaction fails'
        );
        assert_same(
            $managerId,
            (int) au_pdo()->query("SELECT assigned_manager_id FROM tickets WHERE id = $ticketId")->fetchColumn(),
            'the ticket release is rolled back with the failed account update'
        );
    } finally {
        au_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        au_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$managerId]);
    }
});

test('updateUser(integrity): demoting a manager also releases live tickets', function (): void {
    au_bind_request();
    $suffix = bin2hex(random_bytes(4));
    au_pdo()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, "x", "Demoted Manager", "manager", 1, NOW(), NOW())'
    )->execute(["mdemote_$suffix", "mdemote_$suffix@example.com"]);
    $managerId = (int) au_pdo()->lastInsertId();
    au_pdo()->prepare(
        "INSERT INTO tickets (
            ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id,
            assigned_manager_id, status, approval_status, requested_at, approved_at
         ) VALUES (?, 'Role release', 'A non-manager cannot own live manager work', 1, 1, 1, 1, ?,
                   'approved', 'approved', NOW(), NOW())"
    )->execute(["MDEMOTE-$suffix", $managerId]);
    $ticketId = (int) au_pdo()->lastInsertId();

    try {
        au_service()->updateUser($managerId, au_admin(), [
            'full_name' => 'Demoted Manager',
            'email' => "mdemote_$suffix@example.com",
            'role' => 'requester',
            'is_active' => '1',
            'original_version' => '1',
        ]);
        assert_same(
            'requester',
            (string) au_pdo()->query("SELECT role FROM users WHERE id = $managerId")->fetchColumn(),
            'the manager is demoted'
        );
        assert_true(
            au_pdo()->query("SELECT assigned_manager_id FROM tickets WHERE id = $ticketId")->fetchColumn() === null,
            'live manager work is released when the role changes'
        );
    } finally {
        au_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        au_pdo()->prepare("DELETE FROM audit_logs WHERE entity_type = 'user' AND entity_id = ?")->execute([$managerId]);
        au_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$managerId]);
    }
});

test('manager cleanup SQL: legacy live tickets are released while completed history is preserved', function (): void {
    $suffix = bin2hex(random_bytes(4));
    au_pdo()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, "x", "Legacy Manager", "manager", 0, NOW(), NOW())'
    )->execute(["mlegacy_$suffix", "mlegacy_$suffix@example.com"]);
    $managerId = (int) au_pdo()->lastInsertId();

    $insert = au_pdo()->prepare(
        "INSERT INTO tickets (
            ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id,
            assigned_manager_id, status, approval_status, requested_at, approved_at, resolved_at, completed_at
         ) VALUES (?, ?, 'Legacy cleanup probe', 1, 1, 1, 1, ?, ?, 'approved', NOW(), NOW(), ?, ?)"
    );
    $insert->execute(["MLEGACY-LIVE-$suffix", 'Legacy live', $managerId, 'approved', null, null]);
    $liveTicketId = (int) au_pdo()->lastInsertId();
    $insert->execute(["MLEGACY-DONE-$suffix", 'Legacy completed', $managerId, 'completed', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    $completedTicketId = (int) au_pdo()->lastInsertId();

    try {
        $sql = file_get_contents(__DIR__ . '/../../database/upgrades/migrate_release_inactive_manager_tickets.sql');
        assert_true(is_string($sql) && $sql !== '', 'the legacy cleanup SQL file exists');
        $firstRun = au_pdo()->exec($sql);
        assert_true($firstRun !== false && $firstRun >= 1, 'the cleanup releases the legacy live ticket');
        assert_true(
            au_pdo()->query("SELECT assigned_manager_id FROM tickets WHERE id = $liveTicketId")->fetchColumn() === null,
            'the legacy live ticket moves to the shared queue'
        );
        assert_same(
            $managerId,
            (int) au_pdo()->query("SELECT assigned_manager_id FROM tickets WHERE id = $completedTicketId")->fetchColumn(),
            'the cleanup preserves completed-ticket history'
        );
        assert_same(0, (int) au_pdo()->exec($sql), 'running the cleanup again makes no further changes');
    } finally {
        au_pdo()->prepare('DELETE FROM tickets WHERE id IN (?, ?)')->execute([$liveTicketId, $completedTicketId]);
        au_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$managerId]);
    }
});

test('updateUser(integrity): a stale request from an inactive manager cannot approve and own a new ticket', function (): void {
    $suffix = bin2hex(random_bytes(4));
    au_pdo()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, "x", "Inactive Manager", "manager", 0, NOW(), NOW())'
    )->execute(["minactive_$suffix", "minactive_$suffix@example.com"]);
    $managerId = (int) au_pdo()->lastInsertId();
    $ticketId = 0;

    try {
        $reference = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
        $ticketId = tvm_container()->get(TicketService::class)->createTicket(
            ['id' => 1, 'role' => 'requester'],
            [
                'submission_token' => bin2hex(random_bytes(32)),
                'title' => 'Inactive manager race probe',
                'description' => 'A request started before deactivation must not assign an inactive owner.',
                'priority_id' => (int) $reference['priorities'][0]['id'],
                'ticket_category_id' => (int) $reference['categories'][0]['id'],
                'location_id' => (int) $reference['locations'][0]['id'],
                'impact_level' => 'medium',
                'urgency_level' => 'medium',
            ],
            []
        );

        $blocked = false;
        try {
            tvm_container()->get(TicketWorkflowService::class)->approveTicket(
                $ticketId,
                ['id' => $managerId, 'role' => 'manager'],
                ['note' => 'stale request']
            );
        } catch (DomainException $exception) {
            $blocked = str_contains($exception->getMessage(), 'หัวหน้างานไม่พร้อมใช้งาน');
        }
        assert_true($blocked, 'the repository rechecks manager role/active under lock before assigning ownership');

        $ticket = au_pdo()->query(
            "SELECT status, approval_status, assigned_manager_id FROM tickets WHERE id = $ticketId"
        )->fetch(PDO::FETCH_ASSOC);
        assert_same('pending_approval', (string) $ticket['status'], 'approval was rolled back');
        assert_same('pending', (string) $ticket['approval_status'], 'approval status remains pending');
        assert_true($ticket['assigned_manager_id'] === null, 'no inactive manager is assigned');
    } finally {
        if ($ticketId > 0) {
            au_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
            au_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        au_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$managerId]);
    }
});

// ── last-admin invariant: the system must never be left with zero active admins ──
// AdminRepository::updateUser locks the target row + the active-admin set FOR UPDATE and refuses to demote /
// deactivate the final admin (compare-and-set under lock). Seed id 4 is the only active admin in the test DB.

test('updateUser(last-admin): demoting the sole active admin is blocked and leaves the row intact', function (): void {
    au_bind_request();
    try {
        $threw = false;
        try {
            au_service()->updateUser(4, au_admin(), [
                'full_name' => 'ผู้ดูแลระบบ',
                'email' => 'admin@example.com',
                'role' => 'requester',
                'is_active' => '1',
            ]);
        } catch (DomainException $e) {
            $threw = true;
            assert_same(
                'ไม่สามารถปิดหรือเปลี่ยน role ของผู้ดูแลระบบคนสุดท้ายได้',
                $e->getMessage(),
                'demoting the last admin is rejected'
            );
        }
        assert_true($threw, 'the last-admin demotion must throw');

        $admin = au_pdo()->query('SELECT role, is_active FROM users WHERE id = 4')->fetch(PDO::FETCH_ASSOC);
        assert_same('admin', (string) $admin['role'], 'the last admin keeps its role (no partial update)');
        assert_same(1, (int) $admin['is_active'], 'the last admin stays active');
    } finally {
        // defensive: if the guard were disabled (power-proof) the UPDATE would land — always restore the seed admin
        au_pdo()->exec("UPDATE users SET role = 'admin', is_active = 1 WHERE id = 4");
    }
});

test('updateUser(last-admin): with a second active admin, demoting one is allowed', function (): void {
    $suffix = bin2hex(random_bytes(4));
    au_pdo()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, "x", "Second Admin", "admin", 1, NOW(), NOW())'
    )->execute(["admin2_$suffix", "admin2_$suffix@example.com"]);
    $tempId = (int) au_pdo()->lastInsertId();
    au_bind_request();

    try {
        // now there are two active admins → demoting the temp one is permitted (id 4 remains an admin)
        au_service()->updateUser($tempId, au_admin(), [
            'full_name' => 'Second Admin',
            'email' => "admin2_$suffix@example.com",
            'role' => 'requester',
            'is_active' => '1',
            'original_version' => 1,
        ]);
        $role = (string) au_pdo()->query("SELECT role FROM users WHERE id = $tempId")->fetchColumn();
        assert_same('requester', $role, 'with two admins present, demoting one succeeds');
    } finally {
        au_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$tempId]);
    }
});

// R7-F4 (owner-confirmed best-effort): every caller records the audit AFTER the primary mutation/side-effect,
// so a failing audit insert must NOT propagate (the user would think the action failed and retry). AuditLogger
// swallows + logs the failure and returns normally.
test('audit(R7-F4): a failing audit-log insert is best-effort — record() does not throw', function (): void {
    $throwingRepo = new class () extends \App\Repositories\AuditLogRepository {
        public function __construct()
        {
        }

        public function record(array $payload): void
        {
            throw new \RuntimeException('audit backend down');
        }
    };
    $logger = new \App\Services\AuditLogger($throwingRepo);

    $threw = false;
    try {
        $logger->record(['id' => 4, 'role' => 'admin'], 'user.updated', 'user', 1, ['x' => 'y']);
    } catch (\Throwable) {
        $threw = true;
    }
    assert_false($threw, 'a failed audit insert is swallowed (logged), never surfaced to the caller');
});

// ── H-3: ทางตันตอนพนักงานลาออก ──
// กฎสองข้อที่แต่ละข้อถูกต้องในตัวเอง มาชนกันจนตัน: (1) งาน resolved มีแต่ผู้แจ้งเท่านั้นที่ยืนยันปิดได้
// (2) ผู้ใช้ที่ยังมีงานค้าง — ซึ่งจงใจนับ resolved ด้วย — ปิดบัญชีไม่ได้. พอผู้แจ้งลาออก คนเดียวที่ปลดล็อกได้
// คือคนที่ไม่อยู่แล้ว: ปิดงานก็ไม่ได้ ปิดบัญชีก็ไม่ได้ ต้องไปแก้ฐานข้อมูลมือเปล่า. เทสต์นี้ไล่ทั้งวงจร
// ว่าทางออกที่เจ้าของเลือก (แอดมินปิดแทนได้) ทำให้ตันหายจริง โดยที่ด่านกันปิดบัญชีเดิมยังทำงานอยู่.
test('adminUser H-3: an admin can finish a departed staff ticket and then close their account (deadlock is gone)', function (): void {
    au_bind_request();
    $pdo = au_pdo();
    $sfx = bin2hex(random_bytes(4));
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $tickets = tvm_container()->get(TicketService::class);
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();

    $pdo->prepare(
        'INSERT INTO users (username, password_hash, full_name, email, role, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, "requester", 1, NOW(), NOW())'
    )->execute(["leaver_$sfx", password_hash('x', PASSWORD_DEFAULT), 'Departing Staff', "leaver_$sfx@example.com"]);
    $leaverId = (int) $pdo->lastInsertId();
    $leaver = ['id' => $leaverId, 'role' => 'requester'];
    $ticketId = 0;

    $deactivate = static function () use ($leaverId, $pdo): void {
        au_service()->updateUser($leaverId, au_admin(), [
            'full_name' => 'Departing Staff',
            'email' => (string) $pdo->query("SELECT email FROM users WHERE id = $leaverId")->fetchColumn(),
            'phone' => '',
            'role' => 'requester',
            'department_id' => 0,
            'is_active' => '0',
            'original_version' => (int) $pdo->query("SELECT version FROM users WHERE id = $leaverId")->fetchColumn(),
        ]);
    };

    try {
        // the departing person files a ticket and a technician finishes the work
        $ticketId = $tickets->createTicket($leaver, [
            'submission_token' => bin2hex(random_bytes(32)),
            'title' => "H3 leaver $sfx",
            'description' => 'x',
            'priority_id' => (int) $ref['priorities'][0]['id'],
            'ticket_category_id' => (int) $ref['categories'][0]['id'],
            'location_id' => (int) $ref['locations'][0]['id'],
            'impact_level' => 'medium',
            'urgency_level' => 'medium',
        ], []);
        $wf->approveTicket($ticketId, au_admin(), ['note' => '']);
        $wf->assignTechnician($ticketId, au_admin(), ['technician_id' => 3, 'instructions' => '']);
        $wf->acceptAssignedWork($ticketId, ['id' => 3, 'role' => 'technician'], ['accept_note' => '']);
        $wf->resolveAssignedWork($ticketId, ['id' => 3, 'role' => 'technician'], [
            'diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => 5,
        ]);
        assert_same('resolved', (string) $pdo->query("SELECT status FROM tickets WHERE id = $ticketId")->fetchColumn(), 'sanity: the work is done and waiting on the requester');

        // …and then leaves without confirming. The account cannot be closed while that ticket is open —
        // this guard is correct and must stay.
        $blocked = false;
        try {
            $deactivate();
        } catch (DomainException $e) {
            $blocked = true;
            assert_contains_str('ยังมีงานแจ้งซ่อมที่เป็นผู้แจ้ง', $e->getMessage(), 'the open-ticket guard is what refuses');
        }
        assert_true($blocked, 'a requester with an unconfirmed ticket still cannot be deactivated');

        // the admin closes it on their behalf…
        $wf->completeResolvedTicket($ticketId, au_admin(), ['closure_note' => "ผู้แจ้งลาออก ($sfx)"]);
        assert_same('completed', (string) $pdo->query("SELECT status FROM tickets WHERE id = $ticketId")->fetchColumn(), 'the abandoned ticket reaches a terminal status');

        // …and the account can now be closed. Before this fix both steps were impossible: deadlock.
        $deactivate();
        assert_same(0, (int) $pdo->query("SELECT is_active FROM users WHERE id = $leaverId")->fetchColumn(), 'the departed account can finally be deactivated');
    } finally {
        if ($ticketId > 0) {
            $pdo->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
            // close-on-behalf writes a central audit row against the TICKET, not the user
            $pdo->prepare('DELETE FROM audit_logs WHERE entity_type = "ticket" AND entity_id = ?')->execute([$ticketId]);
            $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        $pdo->prepare('DELETE FROM audit_logs WHERE entity_type = "user" AND entity_id = ?')->execute([$leaverId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$leaverId]);
    }
});
