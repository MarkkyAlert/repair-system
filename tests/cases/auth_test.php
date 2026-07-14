<?php
declare(strict_types=1);

use App\Services\AuthService;
use App\Services\LoginRateLimiter;

// Integration tests for AuthService validation/security branches against the test DB. Each seeded user is
// removed in finally (login_attempts.matched_user_id is ON DELETE SET NULL, but we also purge the rows we
// created by attempted_login). We only drive branches that throw BEFORE Session::regenerate()/auth->login()
// — the happy paths of attemptLogin() and changePassword() call Session::regenerate() (static), which is not
// usable under CLI, so they are intentionally not covered here.

$_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'auth-test-cli';

function auth_service(): AuthService
{
    return tvm_container()->get(AuthService::class);
}

function auth_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function auth_rate_limiter(): LoginRateLimiter
{
    return tvm_container()->get(LoginRateLimiter::class);
}

/** Seed a real user; returns ['id','username','email','password'(plain)]. Pass ['is_active'=>0] etc. to override. */
function auth_seed_user(array $overrides = []): array
{
    $suffix = bin2hex(random_bytes(4));
    $plain = 'Correct-Horse-1';
    $cols = array_merge([
        'username' => 'authtest_' . $suffix,
        'email' => 'authtest_' . $suffix . '@example.com',
        'password_hash' => password_hash($plain, PASSWORD_BCRYPT),
        'full_name' => 'Auth Test',
        'role' => 'requester',
        'is_active' => 1,
    ], $overrides);

    $fields = implode(', ', array_keys($cols));
    $placeholders = implode(', ', array_map(static fn (string $k): string => ":$k", array_keys($cols)));
    auth_pdo()->prepare("INSERT INTO users ($fields) VALUES ($placeholders)")->execute($cols);

    return [
        'id' => (int) auth_pdo()->lastInsertId(),
        'username' => (string) $cols['username'],
        'email' => (string) $cols['email'],
        'password' => $plain,
    ];
}

/** Remove seeded user + any login_attempts rows created for the given login strings. */
function auth_cleanup(?int $userId, array $logins = []): void
{
    $pdo = auth_pdo();
    foreach ($logins as $login) {
        $pdo->prepare('DELETE FROM login_attempts WHERE attempted_login = ?')->execute([$login]);
    }
    if ($userId !== null) {
        $pdo->prepare('DELETE FROM login_attempts WHERE matched_user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
}

/** Drive attemptLogin() expecting a failure; returns the thrown message (fails loudly if it did not throw). */
function auth_login_error(string $login, string $password, string $ip): string
{
    try {
        auth_service()->attemptLogin($login, $password, $ip);
    } catch (DomainException $e) {
        return $e->getMessage();
    }

    throw new RuntimeException("attemptLogin should have thrown for login=$login");
}

/** Seed a password_resets row. token is stored as sha256(rawToken) (AuthService hashes before lookup). */
function auth_seed_reset(string $email, string $rawToken, string $expiresAt): void
{
    auth_pdo()->prepare('INSERT INTO password_resets (email, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)')
        ->execute([$email, hash('sha256', $rawToken), $expiresAt]);
}

function auth_delete_resets(string $email): void
{
    auth_pdo()->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);
}

function auth_password_hash(int $userId): string
{
    return (string) auth_pdo()->query('SELECT password_hash FROM users WHERE id = ' . $userId)->fetchColumn();
}

/** How many password_resets rows exist for an email (side-effect probe for the reset anti-enumeration test). */
function auth_reset_row_count(string $email): int
{
    $stmt = auth_pdo()->prepare('SELECT COUNT(*) FROM password_resets WHERE email = ?');
    $stmt->execute([$email]);

    return (int) $stmt->fetchColumn();
}

/** How many email_queue rows are addressed to an email (side-effect probe for the reset anti-enumeration test). */
function auth_queued_email_count(string $email): int
{
    $stmt = auth_pdo()->prepare('SELECT COUNT(*) FROM email_queue WHERE to_email = ?');
    $stmt->execute([$email]);

    return (int) $stmt->fetchColumn();
}

// ── password reset: pure validation (throws before touching the repo — no seed needed) ──

test('auth: resetPassword throws when new password != confirmation', function (): void {
    $threw = false;
    try {
        auth_service()->resetPassword('user@example.com', 'sometoken', 'NewPass123', 'Different123');
    } catch (DomainException $e) {
        $threw = true;
        assert_same('ยืนยันรหัสผ่านไม่ตรงกัน', $e->getMessage());
    }
    assert_true($threw, 'mismatched confirmation must throw');
});

test('auth: resetPassword throws when new password is shorter than 8 chars', function (): void {
    // password === confirmation so we pass the mismatch check and reach the length check
    $threw = false;
    try {
        auth_service()->resetPassword('user@example.com', 'sometoken', 'short7', 'short7');
    } catch (DomainException $e) {
        $threw = true;
        assert_same('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร', $e->getMessage());
    }
    assert_true($threw, 'too-short password must throw');
});

// ── password reset: token CONSUMPTION (single-use / expiry / wrong token) — drives resetPasswordUsingToken ──

test('auth(reset): a token works once — after a successful reset the same token is rejected (single-use)', function (): void {
    $u = auth_seed_user();
    $raw = bin2hex(random_bytes(16));
    auth_seed_reset($u['email'], $raw, date('Y-m-d H:i:s', time() + 3600));
    try {
        // first use succeeds (returns without throwing) and actually changes the password
        auth_service()->resetPassword($u['email'], $raw, 'BrandNewPass123', 'BrandNewPass123');
        assert_true(password_verify('BrandNewPass123', auth_password_hash($u['id'])), 'the new password now verifies');

        // second use of the SAME token → the row was deleted on success → 'missing'
        $threw = false;
        try {
            auth_service()->resetPassword($u['email'], $raw, 'AnotherPass456', 'AnotherPass456');
        } catch (DomainException $e) {
            $threw = true;
            assert_same('ไม่พบคำขอรีเซ็ตรหัสผ่าน', $e->getMessage());
        }
        assert_true($threw, 'the token cannot be reused (single-use)');
        assert_true(password_verify('BrandNewPass123', auth_password_hash($u['id'])), 'the rejected replay did NOT change the password again');
    } finally {
        auth_delete_resets($u['email']);
        auth_cleanup($u['id']);
    }
});

test('auth(reset): an expired token is rejected and the password is unchanged', function (): void {
    $u = auth_seed_user();
    $raw = bin2hex(random_bytes(16));
    $originalHash = auth_password_hash($u['id']);
    auth_seed_reset($u['email'], $raw, date('Y-m-d H:i:s', time() - 3600)); // already expired
    try {
        $threw = false;
        try {
            auth_service()->resetPassword($u['email'], $raw, 'BrandNewPass123', 'BrandNewPass123');
        } catch (DomainException $e) {
            $threw = true;
            assert_same('ลิงก์รีเซ็ตรหัสผ่านหมดอายุแล้ว', $e->getMessage());
        }
        assert_true($threw, 'an expired token must throw');
        assert_same($originalHash, auth_password_hash($u['id']), 'the password_hash was NOT changed by an expired-token attempt');
    } finally {
        auth_delete_resets($u['email']);
        auth_cleanup($u['id']);
    }
});

test('auth(reset): a wrong token is rejected and the password is unchanged', function (): void {
    $u = auth_seed_user();
    $originalHash = auth_password_hash($u['id']);
    auth_seed_reset($u['email'], bin2hex(random_bytes(16)), date('Y-m-d H:i:s', time() + 3600)); // valid row, different token
    try {
        $threw = false;
        try {
            auth_service()->resetPassword($u['email'], 'the-wrong-raw-token', 'BrandNewPass123', 'BrandNewPass123');
        } catch (DomainException $e) {
            $threw = true;
            assert_same('โทเค็นรีเซ็ตรหัสผ่านไม่ถูกต้อง', $e->getMessage());
        }
        assert_true($threw, 'a non-matching token must throw');
        assert_same($originalHash, auth_password_hash($u['id']), 'the password_hash was NOT changed by a wrong-token attempt');
    } finally {
        auth_delete_resets($u['email']);
        auth_cleanup($u['id']);
    }
});

// ── change password: needs a real user + correct current password to reach the new-password checks ──

test('auth: changePassword throws when current password is wrong', function (): void {
    $u = auth_seed_user();
    try {
        $threw = false;
        try {
            auth_service()->changePassword(['id' => $u['id']], 'not-the-current-pw', 'BrandNew123', 'BrandNew123');
        } catch (DomainException $e) {
            $threw = true;
            assert_same('รหัสผ่านปัจจุบันไม่ถูกต้อง', $e->getMessage());
        }
        assert_true($threw, 'wrong current password must throw');
    } finally {
        auth_cleanup($u['id']);
    }
});

test('auth: changePassword throws when new password != confirmation', function (): void {
    $u = auth_seed_user();
    try {
        $threw = false;
        try {
            auth_service()->changePassword(['id' => $u['id']], $u['password'], 'BrandNew123', 'Different123');
        } catch (DomainException $e) {
            $threw = true;
            assert_same('ยืนยันรหัสผ่านใหม่ไม่ตรงกัน', $e->getMessage());
        }
        assert_true($threw, 'mismatched new confirmation must throw');
    } finally {
        auth_cleanup($u['id']);
    }
});

test('auth: changePassword throws when new password is shorter than 8 chars', function (): void {
    $u = auth_seed_user();
    try {
        $threw = false;
        try {
            auth_service()->changePassword(['id' => $u['id']], $u['password'], 'short7', 'short7');
        } catch (DomainException $e) {
            $threw = true;
            assert_same('รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร', $e->getMessage());
        }
        assert_true($threw, 'too-short new password must throw');
    } finally {
        auth_cleanup($u['id']);
    }
});

// ── update profile: invalid email format ──

test('auth: updateProfile throws on invalid email format', function (): void {
    $u = auth_seed_user();
    try {
        $threw = false;
        try {
            auth_service()->updateProfile(['id' => $u['id']], ['full_name' => 'New Name', 'email' => 'not-an-email', 'phone' => '']);
        } catch (DomainException $e) {
            $threw = true;
            assert_same('รูปแบบอีเมลไม่ถูกต้อง', $e->getMessage());
        }
        assert_true($threw, 'invalid email must throw');
    } finally {
        auth_cleanup($u['id']);
    }
});

// ── login: anti-enumeration — wrong password / unknown user / disabled account share ONE generic message ──

test('auth: login returns identical generic error for wrong-password / unknown-user / disabled (no enumeration)', function (): void {
    $active = auth_seed_user();
    $disabled = auth_seed_user(['is_active' => 0]);
    $ip = '203.0.113.10';
    $unknownLogin = 'nobody_' . bin2hex(random_bytes(4));

    try {
        $msgWrongPassword = auth_login_error($active['username'], 'totally-wrong-password', $ip);
        $msgUnknownUser = auth_login_error($unknownLogin, 'whatever', $ip);
        $msgDisabled = auth_login_error($disabled['username'], $disabled['password'], $ip); // correct pw, but inactive

        assert_same('ชื่อผู้ใช้ อีเมล หรือรหัสผ่านไม่ถูกต้อง', $msgWrongPassword, 'wrong password → generic message');
        assert_same($msgWrongPassword, $msgUnknownUser, 'unknown user must return the SAME message as wrong password (anti-enumeration)');
        assert_same($msgWrongPassword, $msgDisabled, 'disabled account must return the SAME message (anti-enumeration)');
    } finally {
        auth_cleanup($active['id'], [$active['username'], $unknownLogin]);
        auth_cleanup($disabled['id'], [$disabled['username']]);
        foreach ([$active['username'], $unknownLogin, $disabled['username']] as $login) {
            auth_rate_limiter()->clear('login:' . sha1(strtolower($login) . '|' . $ip));
        }
        // the per-IP bucket (L1) accumulates across the file-based limiter's runs — clear it so repeated
        // suite runs within the 15-min window don't push this fixed IP over the cap and trip the throttle
        auth_rate_limiter()->clear('login-ip:' . sha1($ip));
    }
});

// The message above hides account existence in the RESPONSE BODY. This closes the TIMING side-channel behind
// it: an unknown login used to short-circuit past password_verify (`!$user || password_verify(...)`), so it
// returned ~100ms faster than a real-user-wrong-password and an attacker could enumerate valid accounts by the
// clock. attemptLogin now runs a bcrypt verify on EVERY attempt (a throwaway dummy hash when the user is
// unknown). Proven deterministically via the counting password_verify shadow — no flaky wall-clock timing.
test('auth(anti-enumeration): an unknown login still spends a bcrypt verify — closes the timing oracle', function (): void {
    $ip = '203.0.113.11';
    $unknownLogin = 'ghost_' . bin2hex(random_bytes(4));
    password_verify_calls_reset();
    try {
        auth_login_error($unknownLogin, 'whatever-password', $ip); // throws the same generic error as a real user

        assert_true(
            password_verify_calls() >= 1,
            'attemptLogin must run password_verify even for a login that does not exist — otherwise the response is faster for unknown accounts and the clock reveals which usernames/emails are real'
        );
    } finally {
        auth_cleanup(null, [$unknownLogin]);
        auth_rate_limiter()->clear('login:' . sha1(strtolower($unknownLogin) . '|' . $ip));
        auth_rate_limiter()->clear('login-ip:' . sha1($ip));
    }
});

// F2: password-reset anti-enumeration — SIDE-EFFECT parity. The response text is already identical for known
// and unknown emails (AuthController flashes one generic message) and the rate-limiter is hit unconditionally,
// so this locks the OTHER observable channel: an unknown / inactive email must leave NO reset row and NO queued
// email, while an active one creates exactly one of each — otherwise a leaked token, a delivered mail, or a
// queue side effect would reveal which emails are real accounts. (The residual DB-write timing delta between
// the branches is an accepted, rate-limited residual — documented in createPasswordReset.)
test('auth(anti-enumeration): password reset creates a token+email ONLY for an active account; unknown/inactive leave no trace', function (): void {
    $active = auth_seed_user();
    $inactive = auth_seed_user(['is_active' => 0]);
    $unknownEmail = 'ghost_' . bin2hex(random_bytes(4)) . '@example.com';
    $ip = '203.0.113.12';
    $_SERVER['REMOTE_ADDR'] = $ip;

    try {
        // active → a url is returned, and exactly one reset row + one queued email are created
        $url = auth_service()->createPasswordReset($active['email']);
        assert_true($url !== null, 'an active account gets a reset url');
        assert_same(1, auth_reset_row_count($active['email']), 'active → exactly one reset row');
        assert_same(1, auth_queued_email_count($active['email']), 'active → exactly one queued email');

        // inactive → null and NO side effect (existence must not leak via a token/mail)
        assert_true(auth_service()->createPasswordReset($inactive['email']) === null, 'inactive account returns null');
        assert_same(0, auth_reset_row_count($inactive['email']), 'inactive → no reset row');
        assert_same(0, auth_queued_email_count($inactive['email']), 'inactive → no queued email');

        // unknown → null and NO side effect
        assert_true(auth_service()->createPasswordReset($unknownEmail) === null, 'unknown email returns null');
        assert_same(0, auth_reset_row_count($unknownEmail), 'unknown → no reset row');
        assert_same(0, auth_queued_email_count($unknownEmail), 'unknown → no queued email');
    } finally {
        foreach ([$active['email'], $inactive['email'], $unknownEmail] as $em) {
            auth_delete_resets($em);
            auth_pdo()->prepare('DELETE FROM email_queue WHERE to_email = ?')->execute([$em]);
            auth_rate_limiter()->clear('pwreset:' . sha1($em . '|' . $ip));
        }
        auth_cleanup($active['id']);
        auth_cleanup($inactive['id']);
    }
});

// ── login: rate limiting blocks once the attempt cap is exceeded ──

test('auth: login is blocked once the attempt cap is exceeded', function (): void {
    $ip = '203.0.113.20';
    $login = 'ratelimited_' . bin2hex(random_bytes(4));
    $key = 'login:' . sha1(strtolower($login) . '|' . $ip); // must match AuthService::limiterKey()
    $limiter = auth_rate_limiter();

    try {
        for ($i = 0; $i < 5; $i++) { // default cap is 5
            $limiter->hit($key);
        }

        $threw = false;
        try {
            auth_service()->attemptLogin($login, 'anything', $ip);
        } catch (DomainException $e) {
            $threw = true;
            assert_contains_str('เกินกำหนด', $e->getMessage());
        }
        assert_true($threw, 'exceeding the attempt cap must be blocked');
    } finally {
        $limiter->clear($key);
        $limiter->clear('login-ip:' . sha1($ip)); // clear the per-IP bucket (L1) too so it can't accumulate across runs
        auth_cleanup(null, [$login]);
    }
});

// ── login: per-IP cap blunts password-spraying (many usernames, one source) ──

test('auth: a saturated source IP blocks even an untried username (spraying blocked)', function (): void {
    $ip = '203.0.113.30';
    $ipKey = 'login-ip:' . sha1($ip); // must match AuthService::ipLimiterKey()
    $freshLogin = 'sprayvictim_' . bin2hex(random_bytes(4)); // never tried → its per-account bucket is empty
    $accountKey = 'login:' . sha1(strtolower($freshLogin) . '|' . $ip);
    $limiter = auth_rate_limiter();

    try {
        // simulate 20 failed logins spread across many usernames from this one IP (the per-account cap of 5
        // never fires because each username has its own bucket — only the per-IP cap catches the spray)
        for ($i = 0; $i < 20; $i++) { // IP cap is 20
            $limiter->hit($ipKey, 900);
        }

        // a brand-new username whose per-account bucket is untouched must still be blocked from this IP
        $msg = auth_login_error($freshLogin, 'anything', $ip);
        assert_contains_str('เกินกำหนด', $msg, 'a saturated source IP blocks even an untried username');
    } finally {
        $limiter->clear($ipKey);
        $limiter->clear($accountKey);
        auth_cleanup(null, [$freshLogin]);
    }
});

test('auth: a saturated IP does not lock out a different IP (no global lockout)', function (): void {
    $ipSaturated = '203.0.113.31';
    $ipOther = '203.0.113.32';
    $ipKeySaturated = 'login-ip:' . sha1($ipSaturated);
    $ipKeyOther = 'login-ip:' . sha1($ipOther);
    $login = 'otheripuser_' . bin2hex(random_bytes(4));
    $accountKeyOther = 'login:' . sha1(strtolower($login) . '|' . $ipOther);
    $limiter = auth_rate_limiter();

    try {
        for ($i = 0; $i < 20; $i++) {
            $limiter->hit($ipKeySaturated, 900);
        }

        // same untried username, but from a DIFFERENT IP → must reach the credential check (generic message),
        // proving one bad IP cannot lock the whole world out
        $msg = auth_login_error($login, 'anything', $ipOther);
        assert_same('ชื่อผู้ใช้ อีเมล หรือรหัสผ่านไม่ถูกต้อง', $msg, 'a different IP is unaffected by the saturated one');
    } finally {
        $limiter->clear($ipKeySaturated);
        $limiter->clear($ipKeyOther);
        $limiter->clear($accountKeyOther);
        auth_cleanup(null, [$login]);
    }
});

// F6: profile update fields are bound to their DB columns (users.full_name VARCHAR(150), email 190-bounded via
// is_valid_email) so over-long input gives a friendly message, not a raw strict-mode DB error.
test('updateProfile(F6): full_name over 150 and an over-190 email are rejected with a friendly message', function (): void {
    $seeded = auth_seed_user();
    $userId = (int) $seeded['id'];
    $viewer = ['id' => $userId, 'role' => 'requester'];

    try {
        $threw = false;
        try {
            auth_service()->updateProfile($viewer, ['full_name' => str_repeat('ก', 151), 'email' => $seeded['email'], 'phone' => '']);
        } catch (DomainException $e) {
            $threw = str_contains($e->getMessage(), 'ยาวเกินกำหนด');
        }
        assert_true($threw, 'full_name over users.full_name(150) is rejected');

        $threw2 = false;
        try {
            auth_service()->updateProfile($viewer, ['full_name' => 'OK Name', 'email' => str_repeat('a', 185) . '@x.test', 'phone' => '']);
        } catch (DomainException $e) {
            $threw2 = str_contains($e->getMessage(), 'อีเมล');
        }
        assert_true($threw2, 'an email longer than 190 is rejected (length-bounded is_valid_email)');

        assert_same('Auth Test', (string) auth_pdo()->query("SELECT full_name FROM users WHERE id = $userId")->fetchColumn(), 'no mutation on a rejected profile update');
    } finally {
        auth_cleanup($userId);
    }
});
