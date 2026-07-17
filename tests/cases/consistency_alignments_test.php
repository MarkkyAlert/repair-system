<?php

declare(strict_types=1);

// consistency-review: three "use the existing shared thing" alignments — behaviour is unchanged (the existing
// auth/comment/setup tests still pass), so these source-locks pin the alignment so it can't regress to the
// duplicated form.

test('consistency: revokeRememberMe delegates to RememberMeService, not the repository directly', function (): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AuthController.php');

    assert_contains_str('$this->rememberMe->revokeAllForUser(', $src, 'revoke uses the purpose-built service method');
    // the two re-implemented calls must be gone from the controller (they live inside revokeAllForUser now)
    assert_true(!str_contains($src, '$this->rememberMe->clearCurrent()'), 'the controller no longer re-implements revoke via clearCurrent()');
    assert_true(!str_contains($src, 'updateRememberToken($userId, null)'), 'the controller no longer NULLs the token against the repo directly');
});

test('consistency: CommentsController negotiates JSON via the shared helper, not a private recompute', function (): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/CommentsController.php');

    assert_contains_str('request_wants_json(', $src, 'uses the shared content-negotiation helper');
    assert_true(!str_contains($src, "HTTP_ACCEPT"), 'no private Accept-header recompute remains (it would drift from the helper)');
});

test('consistency: a concurrent-setup lock conflict is a DomainException (expected/retry), not a RuntimeException', function (): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/SetupController.php');

    assert_true(
        preg_match("/throw new DomainException\('ระบบกำลังตั้งค่าอยู่/u", $src) === 1,
        'the "setup already in progress, try again" lock conflict throws DomainException per the taxonomy'
    );
    assert_true(
        preg_match("/throw new RuntimeException\('ระบบกำลังตั้งค่าอยู่/u", $src) === 0,
        'it is no longer a RuntimeException (which would read as an operational failure to log)'
    );
});

test('consistency(F1): the logo-update lock conflict is a DomainException too, matching the setup lock', function (): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/SystemSettingsService.php');

    assert_true(
        preg_match("/throw new DomainException\('ระบบกำลังอัปเดตโลโก้/u", $src) === 1,
        'the "logo update in progress, try again" lock conflict throws DomainException (expected/retry)'
    );
    assert_true(
        preg_match("/throw new \\\\?RuntimeException\('ระบบกำลังอัปเดตโลโก้/u", $src) === 0,
        'the logo lock is no longer a RuntimeException'
    );
});
