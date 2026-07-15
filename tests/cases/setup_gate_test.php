<?php
declare(strict_types=1);

use App\Controllers\SetupController;
use App\Repositories\SettingsRepository;

// Guards the first-run setup gate (public/index.php → SetupController::requiresSetupRedirect).
// It must send a genuinely-fresh install to /setup, but must NOT loop /setup↔/login on a seed/
// admin-provisioned deploy (admin exists, setup_completed flag not yet set). Both directions are
// asserted — fixing the loop must not open a hole that lets an un-set-up system skip /setup.

function sg_settings(): SettingsRepository
{
    return tvm_container()->get(SettingsRepository::class);
}

function sg_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function sg_delete_flag(): void
{
    sg_pdo()->exec("DELETE FROM system_settings WHERE setting_key = 'setup_completed'");
}

function sg_set_flag(string $value): void
{
    sg_pdo()->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, value_type, is_public, updated_by, created_at, updated_at)
         VALUES ('setup_completed', ?, 'bool', 0, NULL, NOW(), NOW())
         ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()"
    )->execute([$value, $value]);
}

test('setup gate: seed/admin deploy is NOT sent to /setup (no loop); fresh install IS', function (): void {
    $pdo = sg_pdo();
    $original = sg_settings()->getByKey('setup_completed'); // restore afterwards

    $needs = static fn (): bool => SetupController::requiresSetupRedirect(sg_settings(), sg_pdo());

    try {
        assert_true(SetupController::hasActiveAdmin($pdo), 'seed test DB has an active admin');

        // Case A — admin exists, flag NOT set (the exact scenario that looped /setup↔/login) → no redirect
        sg_delete_flag();
        assert_false($needs(), 'admin exists + no flag → NOT redirected to /setup (the loop bug is fixed)');

        // Case B — flag set → no redirect
        sg_set_flag('1');
        assert_false($needs(), 'setup_completed=1 → NOT redirected');

        // Case C — fresh install: no admin AND no flag → MUST redirect (no setup-skip hole)
        sg_delete_flag();
        $pdo->beginTransaction();
        try {
            $pdo->exec("UPDATE users SET is_active = 0 WHERE role = 'admin'");
            assert_false(SetupController::hasActiveAdmin($pdo), 'precondition: no active admin');
            assert_true($needs(), 'no admin + not completed → redirected to /setup (fresh install still gated)');
        } finally {
            $pdo->rollBack(); // restore the seed admins
        }
    } finally {
        if ($original === null) {
            sg_delete_flag();
        } else {
            sg_set_flag((string) ($original['setting_value'] ?? '1'));
        }
    }
});

// R6-F1 (security-review coverage gap): the concurrency fix rechecks admin/flag INSIDE the setup lock, so a
// second setup that races in after the first created an admin creates NOTHING. The seeded test DB already has
// an active admin, which is exactly the "race loser" state — runFirstRunSetup must refuse and add no admin.
test('setup(R6-F1): runFirstRunSetup refuses to create a second admin when one already exists', function (): void {
    $controller = tvm_container()->get(SetupController::class);
    $adminCount = static fn (): int => (int) sg_pdo()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn();
    $before = $adminCount();
    assert_true($before >= 1, 'precondition: the seeded DB already has an active admin (the race-loser state)');

    $threw = false;
    try {
        $controller->runFirstRunSetup('Race Test App', [
            'admin_username' => 'raceadmin' . bin2hex(random_bytes(3)),
            'admin_email' => 'race_' . bin2hex(random_bytes(3)) . '@x.test',
            'admin_full_name' => 'Race Admin',
            'admin_password' => 'ValidPass123',
            'load_demo' => '0',
        ]);
    } catch (DomainException $e) {
        $threw = str_contains($e->getMessage(), 'ตั้งค่าเรียบร้อยแล้ว');
    }

    assert_true($threw, 'a setup run while an admin exists is refused by the under-lock recheck');
    assert_same($before, $adminCount(), 'no second admin was created');
});
