<?php
declare(strict_types=1);

use App\Core\Csrf;

// Tests for CSRF synchronizer-token validation (Csrf::validate + the csrf_token()/csrf_validate() helpers).
// The token is a per-session bin2hex(random_bytes(32)) compared with hash_equals; a mismatch, a null/absent
// submitted token, or a session that carries no token must all be rejected. Every state-changing controller
// calls csrf_validate() before mutating — but the services those controllers drive do NOT check CSRF, so this
// is the only place the guard can be unit-locked. Regression target: if validate() ever short-circuits to a
// no-op the deny cases go red instead of CSRF silently opening. $_SESSION/$_POST are saved and restored so the
// tests leave no shared-state residue for later cases.

function csrf_with_session_token(?string $token, callable $fn): void
{
    $original = $_SESSION['_csrf_token'] ?? null;
    if ($token === null) {
        unset($_SESSION['_csrf_token']);
    } else {
        $_SESSION['_csrf_token'] = $token;
    }
    try {
        $fn();
    } finally {
        if ($original === null) {
            unset($_SESSION['_csrf_token']);
        } else {
            $_SESSION['_csrf_token'] = $original;
        }
    }
}

test('csrf(allow): a submitted token matching the session token validates without throwing', function (): void {
    csrf_with_session_token('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', function (): void {
        Csrf::validate('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'); // no exception thrown == accepted
        assert_true(true, 'matching token accepted');
    });
});

test('csrf(deny): wrong token / null token / no session token are all rejected', function (): void {
    // (a) submitted token does not match the session token
    csrf_with_session_token('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', function (): void {
        $rejected = false;
        try {
            Csrf::validate('deadbeefdeadbeefdeadbeefdeadbeef');
        } catch (RuntimeException) {
            $rejected = true;
        }
        assert_true($rejected, 'a mismatching token is rejected');
    });

    // (b) no token submitted at all
    csrf_with_session_token('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', function (): void {
        $rejected = false;
        try {
            Csrf::validate(null);
        } catch (RuntimeException) {
            $rejected = true;
        }
        assert_true($rejected, 'a null submitted token is rejected');
    });

    // (c) session carries no token → nothing can validate against it
    csrf_with_session_token(null, function (): void {
        $rejected = false;
        try {
            Csrf::validate('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');
        } catch (RuntimeException) {
            $rejected = true;
        }
        assert_true($rejected, 'validation fails when the session has no token');
    });
});

test('csrf(no-leak): the rejection message does not expose the expected session token', function (): void {
    csrf_with_session_token('supersecrettokenvalue0000000000', function (): void {
        try {
            Csrf::validate('wrong');
        } catch (RuntimeException $e) {
            assert_false(
                str_contains($e->getMessage(), 'supersecrettokenvalue0000000000'),
                'the error message must not leak the session token'
            );
        }
    });
});

test('csrf(token): csrf_token() is stable within a session and the token it issues validates', function (): void {
    // start from a session with no token so csrf_token() has to mint a fresh one; the helper restores after
    csrf_with_session_token(null, function (): void {
        $t1 = csrf_token();
        $t2 = csrf_token();
        assert_same($t1, $t2, 'csrf_token() returns the same token for the life of the session');
        assert_true(strlen($t1) >= 32, 'the issued token carries real entropy (>= 32 hex chars)');
        Csrf::validate($t1); // the freshly issued token must pass its own validation
        assert_true(true, 'a token issued by csrf_token() validates');
    });
});

test('csrf(helper): csrf_validate() reads $_POST[_csrf] — matching passes, mismatch throws', function (): void {
    $originalSession = $_SESSION['_csrf_token'] ?? null;
    $originalPost = $_POST['_csrf'] ?? null;
    $_SESSION['_csrf_token'] = 'tok_helper_1234567890abcdef1234';
    try {
        $_POST['_csrf'] = 'tok_helper_1234567890abcdef1234';
        csrf_validate(); // the exact call site every state-changing controller uses
        assert_true(true, 'a matching $_POST[_csrf] passes csrf_validate()');

        $_POST['_csrf'] = 'not-the-token';
        $rejected = false;
        try {
            csrf_validate();
        } catch (RuntimeException) {
            $rejected = true;
        }
        assert_true($rejected, 'a mismatching $_POST[_csrf] is rejected by csrf_validate()');
    } finally {
        if ($originalSession === null) {
            unset($_SESSION['_csrf_token']);
        } else {
            $_SESSION['_csrf_token'] = $originalSession;
        }
        if ($originalPost === null) {
            unset($_POST['_csrf']);
        } else {
            $_POST['_csrf'] = $originalPost;
        }
    }
});
