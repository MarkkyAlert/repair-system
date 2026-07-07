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
        auth_cleanup(null, [$login]);
    }
});
