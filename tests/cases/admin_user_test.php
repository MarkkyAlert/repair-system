<?php
declare(strict_types=1);

use App\Core\Request;
use App\Services\AdminService;

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

        // happy path — values actually change
        au_bind_request();
        au_service()->updateUser($userId, au_admin(), ['full_name' => 'After Name', 'email' => "after_$suffix@example.com", 'role' => 'manager', 'is_active' => '1']);
        $row = au_pdo()->query("SELECT full_name, email, role FROM users WHERE id = $userId")->fetch(PDO::FETCH_ASSOC);
        assert_same('After Name', $row['full_name'], 'full_name updated');
        assert_same("after_$suffix@example.com", $row['email'], 'email updated');
        assert_same('manager', $row['role'], 'role updated');
    } finally {
        au_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
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
        ]);
        $role = (string) au_pdo()->query("SELECT role FROM users WHERE id = $tempId")->fetchColumn();
        assert_same('requester', $role, 'with two admins present, demoting one succeeds');
    } finally {
        au_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$tempId]);
    }
});
