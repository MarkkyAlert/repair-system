<?php
declare(strict_types=1);

use App\Core\AuthManager;
use App\Services\RememberMeService;

// Security tests for RememberMeService (persistent "remember me" login). Drives the real service against
// the test DB, manipulating the cookie via $_COOKIE directly (the service reads/writes $_COOKIE itself).
// setcookie() emits a "headers already sent" warning under the CLI harness (dots were printed) — service
// calls are prefixed with @ to hush ONLY that; state is verified from $_COOKIE + the DB, never from the
// warning. auth->login/logout use Session::put/forget (no Session::regenerate), so the restore path —
// including the auth->login step — runs under CLI; we call auth->logout() to force auth->check() false.

function rm_service(): RememberMeService
{
    return tvm_container()->get(RememberMeService::class);
}

function rm_auth(): AuthManager
{
    return tvm_container()->get(AuthManager::class);
}

function rm_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function rm_seed_user(): int
{
    $s = bin2hex(random_bytes(4));
    rm_pdo()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, "x", "RM User", "requester", 1, NOW(), NOW())')
        ->execute(["rm_$s", "rm_$s@x.t"]);
    return (int) rm_pdo()->lastInsertId();
}

/** Stored remember_token hash for a user (null when the column is NULL). */
function rm_token_of(int $userId): ?string
{
    $v = rm_pdo()->query("SELECT remember_token FROM users WHERE id = $userId")->fetchColumn();
    return ($v === false || $v === null) ? null : (string) $v;
}

/** Raw token from the cookie the service just wrote ("userId|raw"). */
function rm_cookie_raw(): string
{
    $cookie = (string) ($_COOKIE[RememberMeService::COOKIE_NAME] ?? '');
    return str_contains($cookie, '|') ? explode('|', $cookie, 2)[1] : '';
}

function rm_cleanup(int $userId): void
{
    rm_auth()->logout();
    unset($_COOKIE[RememberMeService::COOKIE_NAME]);
    rm_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
}

// ── security properties ──

test('remember-me: issueFor stores a sha256 hash, never the raw token', function (): void {
    $userId = rm_seed_user();
    try {
        rm_auth()->logout();
        unset($_COOKIE[RememberMeService::COOKIE_NAME]);

        @rm_service()->issueFor($userId);

        $cookie = (string) ($_COOKIE[RememberMeService::COOKIE_NAME] ?? '');
        assert_true(str_contains($cookie, '|'), 'a cookie was written');
        [$cookieUser, $raw] = explode('|', $cookie, 2);
        assert_same($userId, (int) $cookieUser, 'cookie carries the user id');
        assert_true(preg_match('/^[a-f0-9]{64}$/', $raw) === 1, 'raw token is 64 hex chars');

        $stored = rm_token_of($userId);
        assert_same(hash('sha256', $raw), $stored, 'DB stores sha256(raw)');
        assert_true($stored !== $raw, 'DB does NOT store the raw token (a DB leak is not directly usable)');
    } finally {
        rm_cleanup($userId);
    }
});

test('remember-me(security): a token with a mismatched user_id cannot impersonate', function (): void {
    $userId = rm_seed_user();
    try {
        rm_auth()->logout();
        @rm_service()->issueFor($userId);
        $raw = rm_cookie_raw();
        $originalHash = rm_token_of($userId);

        // same (valid) raw, but claim a DIFFERENT user_id
        rm_auth()->logout();
        $_COOKIE[RememberMeService::COOKIE_NAME] = '999999999|' . $raw;
        $restored = @rm_service()->attemptRestore();

        assert_false($restored, 'mismatched user_id must NOT restore');
        assert_false(isset($_COOKIE[RememberMeService::COOKIE_NAME]), 'the cookie is cleared on mismatch');
        assert_false(rm_auth()->check(), 'nobody is logged in');
        assert_same($originalHash, rm_token_of($userId), 'the real user\'s token was not rotated (restore failed first)');
    } finally {
        rm_cleanup($userId);
    }
});

test('remember-me(security): attemptRestore rotates the token — the old one cannot be reused', function (): void {
    $userId = rm_seed_user();
    try {
        rm_auth()->logout();
        @rm_service()->issueFor($userId);
        $oldRaw = rm_cookie_raw();
        $oldHash = rm_token_of($userId);
        assert_same(hash('sha256', $oldRaw), $oldHash, 'precondition: DB holds the issued hash');

        rm_auth()->logout(); // ensure check() is false so the cookie path runs
        $restored = @rm_service()->attemptRestore();
        assert_true($restored, 'a valid cookie restores the session');

        $newHash = rm_token_of($userId);
        assert_true($newHash !== null && $newHash !== $oldHash, 'the token was ROTATED to a new hash on restore');
        assert_same(hash('sha256', rm_cookie_raw()), $newHash, 'the new cookie matches the new DB hash');

        // replay the OLD cookie → its hash is gone from the DB
        rm_auth()->logout();
        $_COOKIE[RememberMeService::COOKIE_NAME] = $userId . '|' . $oldRaw;
        $replay = @rm_service()->attemptRestore();
        assert_false($replay, 'the OLD (pre-rotation) token cannot be reused');
    } finally {
        rm_cleanup($userId);
    }
});

test('remember-me(security): clearCurrent revokes the token (logout kills persistent login)', function (): void {
    $userId = rm_seed_user();
    try {
        rm_auth()->logout();
        @rm_service()->issueFor($userId);
        $raw = rm_cookie_raw();
        assert_true(rm_token_of($userId) !== null, 'token issued');

        @rm_service()->clearCurrent();
        assert_same(null, rm_token_of($userId), 'token nulled in the DB');
        assert_false(isset($_COOKIE[RememberMeService::COOKIE_NAME]), 'cookie cleared');

        // the old cookie can no longer restore
        rm_auth()->logout();
        $_COOKIE[RememberMeService::COOKIE_NAME] = $userId . '|' . $raw;
        assert_false(@rm_service()->attemptRestore(), 'a revoked token cannot restore');
    } finally {
        rm_cleanup($userId);
    }
});

test('remember-me(security): issueFor sets a ~30-day server-side expiry', function (): void {
    $userId = rm_seed_user();
    try {
        rm_auth()->logout();
        $before = time();
        @rm_service()->issueFor($userId);

        $exp = rm_pdo()->query("SELECT remember_token_expires_at FROM users WHERE id = $userId")->fetchColumn();
        assert_true($exp !== false && $exp !== null, 'expires_at is set on issue');
        $expected = $before + RememberMeService::LIFETIME_SECONDS; // 30 days
        assert_true(abs(strtotime((string) $exp) - $expected) <= 120, "expiry is ~30 days out (got $exp)");
    } finally {
        rm_cleanup($userId);
    }
});

test('remember-me(security): a token past its server-side expiry is rejected (NULL treated as expired)', function (): void {
    $userId = rm_seed_user();
    try {
        rm_auth()->logout();
        @rm_service()->issueFor($userId);
        $raw = rm_cookie_raw(); // a genuinely valid raw + user_id

        // force the server-side expiry into the past
        rm_pdo()->prepare('UPDATE users SET remember_token_expires_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 3600), $userId]);

        rm_auth()->logout();
        $_COOKIE[RememberMeService::COOKIE_NAME] = $userId . '|' . $raw;
        assert_false(@rm_service()->attemptRestore(), 'an expired token must NOT restore even with a valid raw + user_id');
        assert_false(isset($_COOKIE[RememberMeService::COOKIE_NAME]), 'the expired cookie is cleared');
    } finally {
        rm_cleanup($userId);
    }
});

// ── reject / guard paths (never reach auth->login) ──

test('remember-me: malformed / unknown / empty cookies are rejected and cleared; issueFor(<=0) is a no-op', function (): void {
    $userId = rm_seed_user();
    try {
        // no "|" separator
        rm_auth()->logout();
        $_COOKIE[RememberMeService::COOKIE_NAME] = 'garbage-no-pipe';
        assert_false(@rm_service()->attemptRestore(), 'cookie without "|" is rejected');
        assert_false(isset($_COOKIE[RememberMeService::COOKIE_NAME]), 'malformed cookie cleared');

        // raw is not 64 hex
        rm_auth()->logout();
        $_COOKIE[RememberMeService::COOKIE_NAME] = $userId . '|deadbeef';
        assert_false(@rm_service()->attemptRestore(), 'cookie with a bad raw is rejected');
        assert_false(isset($_COOKIE[RememberMeService::COOKIE_NAME]), 'cleared');

        // valid format but not in the DB
        rm_auth()->logout();
        $_COOKIE[RememberMeService::COOKIE_NAME] = $userId . '|' . str_repeat('a', 64);
        assert_false(@rm_service()->attemptRestore(), 'unknown token is rejected');
        assert_false(isset($_COOKIE[RememberMeService::COOKIE_NAME]), 'cleared');

        // empty cookie
        rm_auth()->logout();
        unset($_COOKIE[RememberMeService::COOKIE_NAME]);
        assert_false(@rm_service()->attemptRestore(), 'no cookie → false');

        // issueFor with a non-positive id writes nothing
        unset($_COOKIE[RememberMeService::COOKIE_NAME]);
        @rm_service()->issueFor(0);
        assert_false(isset($_COOKIE[RememberMeService::COOKIE_NAME]), 'issueFor(0) writes no cookie');
        @rm_service()->issueFor(-5);
        assert_false(isset($_COOKIE[RememberMeService::COOKIE_NAME]), 'issueFor(-5) writes no cookie');
    } finally {
        rm_cleanup($userId);
    }
});

// F1 (logic review): a password reset/change must revoke EVERY remember-me session, not just the acting
// device's. Before the fix, resetPasswordUsingToken never touched remember_token and changePassword's
// clearCurrent() only cleared it when the acting device held a cookie — so a stolen/stale remember cookie kept
// logging in after the owner reset (via email link, no cookie) or changed the password from another device.

function rm_reset_repo(): App\Repositories\PasswordResetRepository
{
    return tvm_container()->get(App\Repositories\PasswordResetRepository::class);
}

function rm_auth_service(): App\Services\AuthService
{
    return tvm_container()->get(App\Services\AuthService::class);
}

function rm_seed_user_with_password(string $plain): array
{
    $s = bin2hex(random_bytes(4));
    rm_pdo()->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (?, ?, ?, "RM User", "requester", 1, NOW(), NOW())')
        ->execute(["rm_$s", "rm_$s@x.t", password_hash($plain, PASSWORD_BCRYPT)]);

    return [(int) rm_pdo()->lastInsertId(), "rm_$s@x.t"];
}

test('remember-me(security): a password RESET revokes the remember token — a pre-reset cookie can no longer restore (F1)', function (): void {
    [$userId, $email] = rm_seed_user_with_password('irrelevant-old');
    try {
        @rm_service()->issueFor($userId);
        $preResetCookie = (string) $_COOKIE[RememberMeService::COOKIE_NAME];
        assert_true(rm_token_of($userId) !== null, 'precondition: a remember token is stored');

        // owner resets via the email link (NO session, NO cookie present on this request)
        $raw = bin2hex(random_bytes(16));
        rm_reset_repo()->create($email, hash('sha256', $raw), date('Y-m-d H:i:s', time() + 3600));
        $result = rm_reset_repo()->resetPasswordUsingToken($email, hash('sha256', $raw), password_hash('brand-new-pass', PASSWORD_BCRYPT));
        assert_same('success', $result, 'the reset succeeded');

        assert_true(rm_token_of($userId) === null, 'the reset NULLs the remember token for the whole account');

        // an attacker still holding the pre-reset cookie replays it → must be rejected
        rm_auth()->logout();
        $_COOKIE[RememberMeService::COOKIE_NAME] = $preResetCookie;
        assert_false(@rm_service()->attemptRestore(), 'a cookie issued before the reset can no longer log in');
    } finally {
        rm_pdo()->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);
        rm_cleanup($userId);
    }
});

// F1 atomicity (logic-review round 2): the remember-token revocation must live IN the password UPDATE, not a
// separate call that could fail after the password already changed. UserRepository::updatePassword alone must
// leave the token NULL — proving the two are one atomic statement.
test('remember-me(security): updatePassword clears the remember token in the same statement (atomic revoke) (F1)', function (): void {
    [$userId] = rm_seed_user_with_password('atomic-old');
    try {
        @rm_service()->issueFor($userId);
        assert_true(rm_token_of($userId) !== null, 'precondition: a remember token is stored');

        // ONLY the password write — no separate revoke call. The token must already be gone.
        $ok = tvm_container()->get(App\Repositories\UserRepository::class)
            ->updatePassword($userId, password_hash('atomic-new', PASSWORD_BCRYPT));

        assert_true($ok, 'the password update ran');
        assert_true(rm_token_of($userId) === null, 'updatePassword itself NULLs the remember token (revocation is atomic with the password change)');
    } finally {
        rm_cleanup($userId);
    }
});

test('remember-me(security): a password CHANGE from a device without the cookie still revokes a remembered device (F1)', function (): void {
    [$userId] = rm_seed_user_with_password('old-pass-123');
    try {
        // device A is "remembered": token stored, cookie held by A
        @rm_service()->issueFor($userId);
        $deviceACookie = (string) $_COOKIE[RememberMeService::COOKIE_NAME];
        assert_true(rm_token_of($userId) !== null, 'precondition: device A has a stored remember token');

        // owner changes the password from device B — a plain session with NO remember cookie
        unset($_COOKIE[RememberMeService::COOKIE_NAME]);
        $viewer = ['id' => $userId, 'role' => 'requester', 'full_name' => 'RM User', 'email' => 'rm@x.t'];
        rm_auth()->login($viewer);
        @rm_auth_service()->changePassword($viewer, 'old-pass-123', 'new-pass-456', 'new-pass-456');

        assert_true(rm_token_of($userId) === null, 'the change revokes the remember token for every device, not just the acting one');

        // device A replays its still-held cookie → must be rejected
        rm_auth()->logout();
        $_COOKIE[RememberMeService::COOKIE_NAME] = $deviceACookie;
        assert_false(@rm_service()->attemptRestore(), "device A's cookie can no longer restore after the password change");
    } finally {
        rm_cleanup($userId);
    }
});

// F1 (logic-review round 3): clearCurrent (logout) trusted the cookie's claimed user_id and NULLed THAT
// user's token — so anyone could revoke another user's persistent login by forging a cookie "<victimId>|<junk>".
// The clear must key on the token HASH, so a forged cookie matches no row and touches nothing.
test('remember-me(security): a forged cookie with another user\'s id cannot revoke that user\'s token (F1)', function (): void {
    [$victimId] = rm_seed_user_with_password('victim-pass');
    [$attackerId] = rm_seed_user_with_password('attacker-pass');
    try {
        @rm_service()->issueFor($victimId);
        $victimTokenBefore = rm_token_of($victimId);
        assert_true($victimTokenBefore !== null, 'precondition: the victim has a stored remember token');

        // attacker forges a cookie carrying the VICTIM's id + a bogus 64-hex token, then hits logout
        $_COOKIE[RememberMeService::COOKIE_NAME] = $victimId . '|' . str_repeat('a', 64);
        @rm_service()->clearCurrent();

        assert_same($victimTokenBefore, rm_token_of($victimId), "the victim's remember token is untouched by a forged cookie");
    } finally {
        unset($_COOKIE[RememberMeService::COOKIE_NAME]);
        rm_cleanup($victimId);
        rm_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$attackerId]);
    }
});

test('remember-me: attemptRestore short-circuits to true when already authenticated (cookie untouched)', function (): void {
    $userId = rm_seed_user();
    try {
        rm_auth()->login(['id' => $userId, 'role' => 'requester', 'full_name' => 'RM User', 'email' => 'rm@x.t']);
        assert_true(rm_auth()->check(), 'precondition: authenticated');

        $_COOKIE[RememberMeService::COOKIE_NAME] = 'should-not-be-read';
        $ok = @rm_service()->attemptRestore();

        assert_true($ok, 'already-authenticated → true immediately');
        assert_same('should-not-be-read', (string) ($_COOKIE[RememberMeService::COOKIE_NAME] ?? ''), 'cookie is not touched when already authenticated');
    } finally {
        rm_cleanup($userId);
    }
});
