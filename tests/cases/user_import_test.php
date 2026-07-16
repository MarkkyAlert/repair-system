<?php
declare(strict_types=1);

// The is_uploaded_file() shadow that lets ParsesCsvUpload's origin guard pass in CLI (so the CSV is parsed)
// now lives once in tests/shadow_functions.php, loaded before every case. See the note there.

namespace {

    use App\Services\UserImportService;

    // Tests for UserImportService (CSV user import). validateRows/executeImport take arrays directly, so most
    // tests need no file. parseUploadedFile is exercised too (the central is_uploaded_file shadow in
    // tests/shadow_functions.php lets it read a real temp file in CLI). Error
    // handling is collect-per-row: validateRows returns {valid, invalid[{line,errors}], total}; executeImport
    // returns {imported, skipped[{line,username,reason}], reset_emails_queued} — neither throws on a bad row.
    // Everything seeded/imported is deleted in finally (net-zero on the test DB).

    function ui_service(): UserImportService
    {
        return tvm_container()->get(UserImportService::class);
    }

    function ui_pdo(): PDO
    {
        return tvm_container()->get(PDO::class);
    }

    function ui_seed_user(string $username, string $email): int
    {
        ui_pdo()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, "x", "Seed", "requester", 1, NOW(), NOW())')
            ->execute([$username, $email]);
        return (int) ui_pdo()->lastInsertId();
    }

    /** @return array{id:int, code:string} an active department the importer will resolve. */
    function ui_seed_department(): array
    {
        $code = 'IMP' . strtoupper(bin2hex(random_bytes(2)));
        ui_pdo()->prepare('INSERT INTO departments (code, name, description, is_active, created_at, updated_at) VALUES (?, ?, "", 1, NOW(), NOW())')
            ->execute([$code, 'Imp Dept ' . $code]);
        return ['id' => (int) ui_pdo()->lastInsertId(), 'code' => $code];
    }

    function ui_delete_users(array $usernames): void
    {
        foreach ($usernames as $u) {
            ui_pdo()->prepare('DELETE FROM users WHERE username = ?')->execute([$u]);
        }
    }

    /** A fully-valid raw CSV row (as parseUploadedFile would yield); override a key to break one branch. */
    function ui_raw(array $overrides = []): array
    {
        $s = bin2hex(random_bytes(3));
        return array_merge([
            '_line' => 2,
            'username' => 'imp_' . $s,
            'email' => 'imp_' . $s . '@x.test',
            'full_name' => 'Import User',
            'role' => 'requester',
            'department_code' => '',
            'phone' => '',
            'password' => '',
        ], $overrides);
    }

    /** A validateRows-shaped valid row for executeImport; explicit password → no auto-generate/reset email. */
    function ui_exec_row(array $overrides = []): array
    {
        $s = bin2hex(random_bytes(3));
        return array_merge([
            'line' => 20,
            'username' => 'exec_' . $s,
            'email' => 'exec_' . $s . '@x.test',
            'full_name' => 'Exec User',
            'role' => 'requester',
            'department_id' => null,
            'phone' => '',
            'password' => 'ValidPass123',
            'auto_password' => false,
        ], $overrides);
    }

    function ui_invalid_for(array $result, int $line): ?array
    {
        foreach ($result['invalid'] as $entry) {
            if ((int) $entry['line'] === $line) {
                return $entry;
            }
        }
        return null;
    }

    function ui_has_error(?array $entry, string $needle): bool
    {
        if ($entry === null) {
            return false;
        }
        foreach ($entry['errors'] as $error) {
            if (str_contains((string) $error, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** A real temp .csv file with the given bytes (deleted by the caller). */
    function ui_tmp_csv(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'uimp_') . '.csv';
        file_put_contents($path, $bytes);
        return $path;
    }

    function ui_file(string $csv, string $name = 'users.csv'): array
    {
        return ['name' => $name, 'tmp_name' => ui_tmp_csv($csv), 'size' => strlen($csv), 'error' => UPLOAD_ERR_OK];
    }

    // ── validateRows: per-branch validation with correct line + message ──

    test('userImport.validateRows: partitions good/bad rows with the right line and message per branch', function (): void {
        $good = ui_raw(['_line' => 2, 'password' => 'GoodPass123']);
        $rows = [
            $good,
            ui_raw(['_line' => 3, 'username' => '', 'email' => '', 'full_name' => '']),          // required
            ui_raw(['_line' => 4, 'username' => 'ab']),                                            // username format
            ui_raw(['_line' => 5, 'email' => 'not-an-email']),                                     // email format
            ui_raw(['_line' => 6, 'role' => 'superuser']),                                         // role
            ui_raw(['_line' => 7, 'phone' => 'abc-not-a-phone']),                                  // phone format
            ui_raw(['_line' => 8, 'password' => 'short']),                                         // password < 8
        ];

        $result = ui_service()->validateRows($rows);

        assert_same(7, $result['total'], 'total counts every row');
        // the good row is valid and its line is preserved
        $validLines = array_map(static fn (array $r): int => (int) $r['line'], $result['valid']);
        assert_true(in_array(2, $validLines, true), 'the fully-valid row (line 2) is valid');

        assert_true(ui_has_error(ui_invalid_for($result, 3), 'จำเป็นต้องมี'), 'line 3 → required fields');
        assert_true(ui_has_error(ui_invalid_for($result, 4), 'username ต้องมี 3-50'), 'line 4 → username format');
        assert_true(ui_has_error(ui_invalid_for($result, 5), 'email format'), 'line 5 → email format');
        assert_true(ui_has_error(ui_invalid_for($result, 6), 'role ต้องเป็น'), 'line 6 → role');
        assert_true(ui_has_error(ui_invalid_for($result, 7), 'phone format'), 'line 7 → phone format');
        assert_true(ui_has_error(ui_invalid_for($result, 8), 'password ต้องมีอย่างน้อย 8'), 'line 8 → short password');
    });

    test('userImport.validateRows(dup-in-file): the FIRST occurrence stays valid, later duplicates are flagged', function (): void {
        // NB: seenUsernames/seenEmails are recorded only for rows that pass (line 115), so the code keeps the
        // first occurrence and rejects subsequent ones — it does NOT flag "both".
        $rows = [
            ui_raw(['_line' => 2, 'username' => 'dupname', 'email' => 'a@x.test', 'password' => 'GoodPass123']),
            ui_raw(['_line' => 3, 'username' => 'dupname', 'email' => 'b@x.test', 'password' => 'GoodPass123']), // dup username
            ui_raw(['_line' => 4, 'username' => 'uniq1', 'email' => 'same@x.test', 'password' => 'GoodPass123']),
            ui_raw(['_line' => 5, 'username' => 'uniq2', 'email' => 'same@x.test', 'password' => 'GoodPass123']), // dup email
        ];

        $result = ui_service()->validateRows($rows);

        $validLines = array_map(static fn (array $r): int => (int) $r['line'], $result['valid']);
        assert_true(in_array(2, $validLines, true), 'first username occurrence (line 2) is valid');
        assert_true(in_array(4, $validLines, true), 'first email occurrence (line 4) is valid');
        assert_true(ui_has_error(ui_invalid_for($result, 3), 'username ซ้ำกับแถวอื่นในไฟล์'), 'line 3 → duplicate username in file');
        assert_true(ui_has_error(ui_invalid_for($result, 5), 'email ซ้ำกับแถวอื่นในไฟล์'), 'line 5 → duplicate email in file');
    });

    test('userImport.validateRows(dup-with-DB + department): existing username/email are flagged; unknown dept is flagged', function (): void {
        $s = bin2hex(random_bytes(4));
        $existingUser = "seed_$s";
        $existingEmail = "seed_$s@x.test";
        $seedId = ui_seed_user($existingUser, $existingEmail);
        $dept = ui_seed_department();
        try {
            $rows = [
                ui_raw(['_line' => 2, 'username' => $existingUser, 'email' => "new_$s@x.test", 'password' => 'GoodPass123']),   // username in DB
                ui_raw(['_line' => 3, 'username' => "new2_$s", 'email' => $existingEmail, 'password' => 'GoodPass123']),        // email in DB
                ui_raw(['_line' => 4, 'department_code' => 'NOPE_' . $s, 'password' => 'GoodPass123']),                          // unknown dept
                ui_raw(['_line' => 5, 'department_code' => $dept['code'], 'password' => 'GoodPass123']),                         // valid dept
            ];

            $result = ui_service()->validateRows($rows);

            assert_true(ui_has_error(ui_invalid_for($result, 2), 'username มีอยู่ในระบบแล้ว'), 'line 2 → username exists in DB');
            assert_true(ui_has_error(ui_invalid_for($result, 3), 'email มีอยู่ในระบบแล้ว'), 'line 3 → email exists in DB');
            assert_true(ui_has_error(ui_invalid_for($result, 4), 'ไม่พบในระบบ'), 'line 4 → unknown department_code');

            $valid = array_values(array_filter($result['valid'], static fn (array $r): bool => (int) $r['line'] === 5));
            assert_same(1, count($valid), 'line 5 (valid department_code) passes');
            assert_same($dept['id'], (int) $valid[0]['department_id'], 'the department_code resolves to its id');
        } finally {
            ui_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$seedId]);
            ui_pdo()->prepare('DELETE FROM departments WHERE id = ?')->execute([$dept['id']]);
        }
    });

    // ── executeImport ──

    test('userImport.executeImport: valid rows are inserted with a hashed (never plaintext) password', function (): void {
        $row = ui_exec_row(['role' => 'technician']);
        try {
            $result = ui_service()->executeImport([$row]);
            assert_same(1, (int) $result['imported'], 'one user imported');
            assert_same(0, count($result['skipped']), 'nothing skipped');

            $stored = ui_pdo()->query('SELECT role, password_hash FROM users WHERE username = ' . ui_pdo()->quote($row['username']))->fetch(PDO::FETCH_ASSOC);
            assert_true($stored !== false, 'user row exists');
            assert_same('technician', $stored['role'], 'role stored');
            assert_true($stored['password_hash'] !== 'ValidPass123', 'password is NOT stored as plaintext');
            assert_true(password_verify('ValidPass123', (string) $stored['password_hash']), 'password_hash verifies the original password');
        } finally {
            ui_delete_users([$row['username']]);
        }
    });

    test('userImport.executeImport: an UNEXPECTED row failure is skipped AND logged; a duplicate is skipped SILENTLY (error-review F6)', function (): void {
        $originalLog = (string) ini_get('error_log');

        // (a) an unexpected failure (DB outage simulated by FailingPdo on the INSERT) — skipped, but the root
        // cause must be logged (previously hidden behind the generic "เกิดข้อผิดพลาดในการบันทึก" message)
        $row = ui_exec_row(['username' => 'f6imp_' . bin2hex(random_bytes(3))]);
        $tmp = tempnam(sys_get_temp_dir(), 'uimpfail_') . '.log';
        try {
            ini_set('error_log', $tmp);
            $result = null;
            with_failing_pdo('INSERT INTO users', function () use ($row, &$result): void {
                $result = ui_service()->executeImport([$row]);
            });
            ini_set('error_log', $originalLog);

            assert_same(0, (int) $result['imported'], 'the row could not be inserted');
            assert_same(1, count($result['skipped']), 'the failing row is skipped, not fatal to the batch');
            assert_contains_str('[user.import.row]', (string) @file_get_contents($tmp), 'the unexpected (non-duplicate) failure root cause is logged');
        } finally {
            ini_set('error_log', $originalLog);
            @unlink($tmp);
            ui_delete_users([$row['username']]);
        }

        // (b) a DUPLICATE is an EXPECTED skip — it must NOT be logged as an error (gating: only non-duplicates log)
        $s = bin2hex(random_bytes(4));
        $existing = "f6dup_$s";
        $seedId = ui_seed_user($existing, "f6dup_$s@x.test");
        $tmp2 = tempnam(sys_get_temp_dir(), 'uimpdup_') . '.log';
        try {
            ini_set('error_log', $tmp2);
            $dup = ui_service()->executeImport([ui_exec_row(['username' => $existing, 'email' => "other_$s@x.test"])]);
            ini_set('error_log', $originalLog);

            assert_same(1, count($dup['skipped']), 'the duplicate row is skipped');
            assert_true(!str_contains((string) @file_get_contents($tmp2), '[user.import.row]'), 'a duplicate skip is expected and must NOT be logged as an error');
        } finally {
            ini_set('error_log', $originalLog);
            @unlink($tmp2);
            ui_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$seedId]);
        }
    });

    test('userImport.executeImport: a reset-email failure is logged (root cause) while the user is still created (error-review-2 F2)', function (): void {
        // auto_password rows send a reset email; a failure there must not break the import, records who missed
        // it, AND now logs the root cause (the exception object was previously discarded). Fail only the
        // password_resets write so the user INSERT still succeeds.
        $s = bin2hex(random_bytes(3));
        $row = ui_exec_row(['username' => "f2reset_$s", 'email' => "f2reset_$s@x.test", 'auto_password' => true, 'password' => '']);
        $tmp = tempnam(sys_get_temp_dir(), 'uireset_') . '.log';
        $originalLog = (string) ini_get('error_log');

        try {
            ini_set('error_log', $tmp);
            $result = null;
            with_failing_pdo('password_resets', function () use ($row, &$result): void {
                $result = ui_service()->executeImport([$row]);
            });
            ini_set('error_log', $originalLog);

            assert_same(1, (int) $result['imported'], 'the user is still created despite the reset-email failure');
            $log = (string) @file_get_contents($tmp);
            assert_contains_str('[user.import.reset]', $log, 'the reset-email failure root cause is logged (was discarded)');
            // O3: the log must identify the user WITHOUT the raw email (PII)
            assert_true(!str_contains($log, $row['email']), 'the raw recipient email must NOT be written to the server log');
            assert_contains_str($row['username'], $log, 'the user is identified by username instead');
        } finally {
            ini_set('error_log', $originalLog);
            @unlink($tmp);
            ui_delete_users([$row['username']]);
        }
    });

    test('userImport.executeImport(resilience): a row colliding at insert is skipped; the rest still import', function (): void {
        $s = bin2hex(random_bytes(4));
        $existing = "clash_$s";
        $seedId = ui_seed_user($existing, "clash_$s@x.test");
        $survivor = ui_exec_row(['username' => "ok_$s", 'email' => "ok_$s@x.test", 'line' => 32]);
        try {
            $rows = [
                ui_exec_row(['username' => $existing, 'email' => "other_$s@x.test", 'line' => 31]), // collides on username
                $survivor,
            ];

            $result = ui_service()->executeImport($rows);

            assert_same(1, (int) $result['imported'], 'only the non-colliding row imported (batch did not abort)');
            assert_same(1, count($result['skipped']), 'exactly one row skipped');
            assert_same($existing, (string) $result['skipped'][0]['username'], 'the colliding username is the one skipped');
            assert_same(31, (int) $result['skipped'][0]['line'], 'the skipped entry keeps its line');
            assert_true((string) $result['skipped'][0]['reason'] !== '', 'a skip reason is recorded');

            $survivorExists = ui_pdo()->query('SELECT COUNT(*) FROM users WHERE username = ' . ui_pdo()->quote($survivor['username']))->fetchColumn();
            assert_same(1, (int) $survivorExists, 'the survivor row was inserted despite the earlier collision');
        } finally {
            ui_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$seedId]);
            ui_delete_users([$survivor['username']]);
        }
    });

    // ── parseUploadedFile / ParsesCsvUpload (shared trait) ──

    test('userImport.parseUploadedFile: parses a valid CSV — header, CSV escaping, _line numbering, blank-row skip', function (): void {
        $csv = "username,email,full_name,role,department_code,phone,password\n"
            . "jdoe,jdoe@x.test,\"Doe, John \"\"JD\"\"\",requester,,,\n"
            . "\n" // blank line — skipped
            . "asmith,asmith@x.test,Alice Smith,manager,,,\n";
        $file = ui_file($csv);
        try {
            $rows = ui_service()->parseUploadedFile($file);
            assert_same(2, count($rows), 'the blank line is skipped; two data rows remain');
            assert_same(2, (int) $rows[0]['_line'], 'first data row is line 2 (header is line 1)');
            assert_same('Doe, John "JD"', $rows[0]['full_name'], 'quoted field with comma + escaped quotes is parsed by fgetcsv');
            assert_same('jdoe', $rows[0]['username'], 'columns are keyed by header name');
            assert_same(4, (int) $rows[1]['_line'], 'the second data row keeps its real line number (blank line counted)');
        } finally {
            @unlink($file['tmp_name']);
        }
    });

    test('userImport.parseUploadedFile: enforces the synchronous row cap — default lowered to 50 (perf-review F1)', function (): void {
        // Each imported user is bcrypt-hashed in-request (deliberately slow), so the row cap bounds how long
        // the admin waits and the risk of a web-server timeout mid-import. The default is 50; a 51-row file is
        // rejected and the message states the actual cap (raising it back to 200 would let 51 through → reds).
        $csv = "username,email,full_name,role,department_code,phone,password\n";
        for ($i = 1; $i <= 51; $i++) {
            $csv .= "user$i,user$i@x.test,User $i,requester,,,\n";
        }
        $file = ui_file($csv);
        try {
            $threw = false;
            try {
                ui_service()->parseUploadedFile($file);
            } catch (DomainException $e) {
                $threw = true;
                assert_true(str_contains($e->getMessage(), 'ไม่เกิน 50'), 'the cap message names the current 50-row limit: ' . $e->getMessage());
            }
            assert_true($threw, '51 rows exceeds the 50-row synchronous cap and is rejected');
        } finally {
            @unlink($file['tmp_name']);
        }
    });

    test('userImport.parseUploadedFile: rejects a missing column, an empty file, an oversized file, and a non-.csv name', function (): void {
        // missing 'password' column
        $missing = ui_file("username,email,full_name,role,department_code,phone\njdoe,j@x.test,J,requester,,\n");
        // empty file
        $empty = ui_file('');
        // oversized (reported size beats the 1MB cap; content is tiny)
        $oversized = ui_file("username,email,full_name,role,department_code,phone,password\na,a@x.test,A,requester,,,\n");
        $oversized['size'] = 2 * 1024 * 1024;
        // wrong extension
        $wrongExt = ui_file("username,email,full_name,role,department_code,phone,password\n", 'users.txt');
        try {
            $expect = static function (array $file, string $needle, string $ctx): void {
                $threw = false;
                try {
                    ui_service()->parseUploadedFile($file);
                } catch (DomainException $e) {
                    $threw = true;
                    assert_true(str_contains($e->getMessage(), $needle), "$ctx — message: " . $e->getMessage());
                }
                assert_true($threw, "$ctx — must throw");
            };
            $expect($missing, 'ไม่ครบ column', 'missing required column');
            $expect($empty, 'ว่างเปล่า', 'empty CSV');
            $expect($oversized, 'ขนาดเกิน', 'oversized file');
            $expect($wrongExt, 'เฉพาะไฟล์ .csv', 'non-.csv extension');
        } finally {
            @unlink($missing['tmp_name']);
            @unlink($empty['tmp_name']);
            @unlink($oversized['tmp_name']);
            @unlink($wrongExt['tmp_name']);
        }
    });

    test('userImport.executeImport(auto_password): a reset-email failure is surfaced, not swallowed (user still created)', function (): void {
        // empty password → auto-generate a random one → a reset email is attempted; if that fails the user is
        // still created but nobody knows the password, so the failure must be reported (not silently swallowed).
        $row = ui_exec_row(['password' => '', 'auto_password' => true]);

        // real repos, but an AuthService whose createPasswordReset always fails (e.g. the mail queue is down)
        $failingAuth = new class () extends \App\Services\AuthService {
            public function __construct()
            {
            }

            public function createPasswordReset(string $email): ?string
            {
                throw new RuntimeException('reset email queue is down');
            }
        };
        $importer = new \App\Services\UserImportService(
            tvm_container()->get(\App\Repositories\AdminRepository::class),
            tvm_container()->get(\App\Repositories\UserRepository::class),
            $failingAuth
        );

        try {
            $result = $importer->executeImport([$row]);

            assert_same(1, (int) $result['imported'], 'the user is still created (import does not fail on a reset-email error)');
            assert_same(0, (int) $result['reset_emails_queued'], 'no reset email was queued');
            assert_same(1, count($result['reset_failures']), 'the reset failure is surfaced (not swallowed)');
            assert_same($row['username'], (string) ($result['reset_failures'][0]['username'] ?? ''), 'the failing user is named so an admin can reset them manually');

            $exists = ui_pdo()->query('SELECT COUNT(*) FROM users WHERE username = ' . ui_pdo()->quote($row['username']))->fetchColumn();
            assert_same(1, (int) $exists, 'the user row exists despite the reset-email failure');
        } finally {
            ui_delete_users([$row['username']]);
        }
    });

    // R3-F3 / security-review coverage gap: the import confirm is scoped to a one-time token so a second preview
    // in another tab (which replaces the session batch) can't make the first tab's confirm import the wrong rows.
    // verified_import_rows() is the pure guard both import controllers delegate to.
    test('import token (R3-F3): rows returned only when the submitted token matches the previewed batch', function (): void {
        $rows = [['username' => 'u1'], ['username' => 'u2']];
        $batch = ['token' => 'batch-token-abc', 'rows' => $rows];

        // allow — the exact previewed rows come back for the matching token
        assert_same($rows, verified_import_rows($batch, 'batch-token-abc'), 'a matching token returns the previewed rows');

        // deny — every wrong shape refuses and never returns rows
        $deny = static function (mixed $b, string $token, string $ctx): void {
            $threw = false;
            try {
                verified_import_rows($b, $token);
            } catch (DomainException $e) {
                $threw = str_contains($e->getMessage(), 'ไม่ตรงกับไฟล์') || str_contains($e->getMessage(), 'ไม่พบข้อมูล');
            }
            assert_true($threw, $ctx);
        };
        $deny($batch, 'WRONG-TOKEN', 'a mismatched token (a second tab replaced the batch) is rejected');
        $deny($batch, '', 'an empty submitted token is rejected');
        $deny(['token' => '', 'rows' => $rows], 'batch-token-abc', 'a batch with no token is rejected');
        $deny(['token' => 'batch-token-abc', 'rows' => []], 'batch-token-abc', 'an empty batch is rejected');
        $deny([], 'batch-token-abc', 'a missing/non-array batch is rejected');
    });
}
