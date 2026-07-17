<?php

declare(strict_types=1);

use App\Services\AdminService;

// dup-review F2: the same user-field rules (phone format, full_name ≤150) were re-implemented per flow and had
// DRIFTED — the admin create/update flow checked only phone LENGTH (not format) that profile/import enforce, and
// the first-run setup skipped the full_name length the other flows apply. These lock the parity so a user a flow
// would reject can't slip in through another.

test('user-validation(F2): admin create/update reject a malformed phone, like the profile/import flows do', function (): void {
    $svc = tvm_container()->get(AdminService::class);
    $admin = ['id' => 1, 'role' => 'admin'];
    // everything valid EXCEPT the phone (letters/symbols) — the phone-format check must be the one that fires,
    // and it fires before any DB write, so no user is created.
    $base = [
        'username' => 'parity_' . bin2hex(random_bytes(3)),
        'full_name' => 'Parity Test',
        'email' => 'parity_' . bin2hex(random_bytes(3)) . '@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'phone' => 'not-a-phone#!',
        'role' => 'requester',
    ];

    $createErr = null;
    try {
        $svc->createUser($admin, $base);
    } catch (DomainException $e) {
        $createErr = $e->getMessage();
    }
    assert_true($createErr !== null && str_contains($createErr, 'เบอร์โทร'), 'createUser rejects a malformed phone (was: length-only)');

    $updateErr = null;
    try {
        $svc->updateUser(1, $admin, $base + ['original_version' => 1]);
    } catch (DomainException $e) {
        $updateErr = $e->getMessage();
    }
    assert_true($updateErr !== null && str_contains($updateErr, 'เบอร์โทร'), 'updateUser rejects a malformed phone too');

    // a well-formed phone must NOT trip the phone-format rule (it may fail later for other reasons, but not phone)
    $goodPhoneErr = null;
    try {
        $svc->updateUser(1, $admin, ['full_name' => 'Ok', 'email' => 'ok@example.com', 'phone' => '081-234-5678', 'role' => 'requester', 'original_version' => 1]);
    } catch (DomainException $e) {
        $goodPhoneErr = $e->getMessage();
    }
    assert_true($goodPhoneErr === null || !str_contains($goodPhoneErr, 'เบอร์โทร'), 'a well-formed phone passes the format rule');
});

// Structural parity: every flow that accepts these fields runs the SAME shared validators, so the rules can't
// drift apart again (this is the specific de-dup the owner asked for — align the rules, keep each flow's own
// required/duplicate/message handling). Setup has no phone field, so only its full_name length is asserted.
test('user-validation(F2): all user flows apply the shared phone/full_name rules (parity source-lock)', function (): void {
    $root = dirname(__DIR__, 2);
    $phoneFlows = [
        'app/Services/AdminService.php',
        'app/Services/AuthService.php',
        'app/Services/UserImportService.php',
    ];
    foreach ($phoneFlows as $file) {
        $src = (string) file_get_contents($root . '/' . $file);
        assert_contains_str('valid_phone_format(', $src, "$file must validate phone FORMAT via the shared helper, not just length");
    }

    $nameFlows = [
        'app/Services/AdminService.php',
        'app/Services/AuthService.php',
        'app/Services/UserImportService.php',
        'app/Controllers/SetupController.php',
    ];
    foreach ($nameFlows as $file) {
        $src = (string) file_get_contents($root . '/' . $file);
        $hasMaxLen = str_contains($src, "require_max_length(\$fullName, 150") || preg_match('/(mb_strlen|strlen)\(\$fullName\)\s*>\s*150/', $src) === 1;
        assert_true($hasMaxLen, "$file must enforce the full_name ≤150 length like the other user flows");
    }
});
