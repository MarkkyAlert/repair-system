<?php
declare(strict_types=1);

use App\Services\AuthService;
use App\Services\RememberMeService;

// Session-fixation defense lock. On any event that changes WHO a session belongs to — a successful login, a
// logout, a password change, and a remember-me cookie restore — the code must call Session::regenerate() so a
// session id planted before the change cannot be replayed afterward. The catch: session_regenerate_id() needs
// a live PHP session, which the CLI harness cannot provide (auth_test.php documents exactly this — the login
// happy path is skipped for it). So we cannot drive these end-to-end. Instead we pin the guard at the source
// level, the same technique admin_route_gate_test uses for controller gates: each method's body must still
// contain the regenerate call. Delete the anti-fixation call from any one and only its case reddens.
// (security-review coverage gap)

/** The source text of one class method (declaration line → closing brace), via reflection. */
function af_method_source(string $method, string $class = AuthService::class): string
{
    $ref = new ReflectionMethod($class, $method);
    $lines = file((string) $ref->getFileName(), FILE_IGNORE_NEW_LINES) ?: [];
    $slice = array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1);

    return implode("\n", $slice);
}

test('auth(anti-fixation): a successful login regenerates the session id', function (): void {
    assert_contains_str(
        'Session::regenerate(',
        af_method_source('attemptLogin'),
        'attemptLogin must regenerate the session on success — otherwise a pre-login session id planted by an attacker survives authentication (session fixation)'
    );
});

test('auth(anti-fixation): logout regenerates the session id so the authenticated id is not left reusable', function (): void {
    assert_contains_str(
        'Session::regenerate(',
        af_method_source('logout'),
        'logout must regenerate the session so the just-ended authenticated id cannot be reused'
    );
});

test('auth(anti-fixation): a password change regenerates the session id (re-anchor after a credential change)', function (): void {
    assert_contains_str(
        'Session::regenerate(',
        af_method_source('changePassword'),
        'changePassword must regenerate the session after the credential change'
    );
});

test('auth(anti-fixation): a remember-me restore regenerates the session id before authenticating', function (): void {
    // attemptRestore() elevates the current session to authenticated off a remember cookie — the same kind of
    // identity change as a login — so it must rotate the id first, or a planted session id is silently promoted.
    assert_contains_str(
        'Session::regenerate(',
        af_method_source('attemptRestore', RememberMeService::class),
        'RememberMeService::attemptRestore must regenerate the session before auth->login(), like attemptLogin does'
    );
});
