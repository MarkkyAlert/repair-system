<?php

declare(strict_types=1);

use App\Controllers\SetupController;
use App\Repositories\SettingsRepository;

// deploy-review R2: the fresh-install path (empty DB → import schema → /setup wizard → login → dashboard) was
// only ever verified by hand, yet its worst bug — the /setup ↔ /login redirect loop — was found by walking a
// real install, not by reading code. setup_gate_test guards the gate LOGIC on the seeded DB; this drives the
// whole happy path from a TRULY empty schema against an isolated scratch DB (safe in CI, never touches the
// shared test DB), so a change that breaks a from-scratch install fails the suite instead of a buyer's install.

test('fresh-install(R2): empty DB → real schema/reference import → wizard provisions a login-ready admin, no /setup loop', function (): void {
    $cfg = tvm_container()->get('config')['db'];
    $rootPdo = tvm_container()->get(PDO::class);
    $rootDir = dirname(__DIR__, 2);
    $scratch = 'repair_system_fresh_install_test';

    $connect = static fn (string $db): PDO => new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$db};charset=utf8mb4",
        (string) $cfg['username'],
        (string) $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    try {
        // 1) A brand-new empty database, then import exactly what the setup guide tells a buyer to import
        //    (the phpMyAdmin path): schema.sql + seed_reference.sql. If either file breaks, this import throws.
        $rootPdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");
        $rootPdo->exec("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4");
        $fresh = $connect($scratch);
        $fresh->exec((string) file_get_contents($rootDir . '/database/schema.sql'));
        $fresh->exec((string) file_get_contents($rootDir . '/database/seed_reference.sql'));

        // 2) Fresh state: no admin, gated to /setup, reference master-data present.
        $settings = new SettingsRepository($fresh);
        assert_false(SetupController::hasActiveAdmin($fresh), 'a fresh install has no admin yet');
        assert_true(SetupController::requiresSetupRedirect($settings, $fresh), 'a fresh install is redirected to /setup');
        assert_true((int) $fresh->query('SELECT COUNT(*) FROM departments')->fetchColumn() > 0, 'seed_reference.sql loaded master data');

        // 3) Run the real setup wizard in a fresh process wired to the scratch DB (the container auto-wires
        //    SetupController's full dependency tree against DB_NAME=scratch — no hand-built stubs).
        $code = '$_ENV["DB_NAME"] = ' . var_export($scratch, true) . ';'
            . 'require ' . var_export($rootDir . '/vendor/autoload.php', true) . ';'
            . '[$c] = require ' . var_export($rootDir . '/bootstrap.php', true) . ';'
            . '$pdo = $c->get(PDO::class);'
            . '$before = App\\Controllers\\SetupController::requiresSetupRedirect($c->get(App\\Repositories\\SettingsRepository::class), $pdo);'
            . '$c->get(App\\Controllers\\SetupController::class)->runFirstRunSetup("Fresh Install Co", ['
            . '"admin_username" => "freshadmin", "admin_email" => "fresh@example.com",'
            . '"admin_full_name" => "Fresh Admin", "admin_password" => "fresh-pass-12345", "load_demo" => "0"]);'
            . '$after = App\\Controllers\\SetupController::requiresSetupRedirect($c->get(App\\Repositories\\SettingsRepository::class), $pdo);'
            . '$u = $pdo->query("SELECT role, is_active, password_hash FROM users WHERE username = \'freshadmin\'")->fetch(PDO::FETCH_ASSOC);'
            . '$app = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = \'app_name\'")->fetchColumn();'
            . '$done = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = \'setup_completed\'")->fetchColumn();'
            . 'echo json_encode(["gate_before" => (bool) $before, "gate_after" => (bool) $after, "app_name" => (string) $app,'
            . '"admin_active" => ($u && $u["role"] === "admin" && (int) $u["is_active"] === 1),'
            . '"setup_completed" => ((string) $done === "1"),'
            . '"password_valid" => ($u && password_verify("fresh-pass-12345", (string) $u["password_hash"]))]);';

        $out = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -r ' . escapeshellarg($code) . ' 2>&1'));
        $res = json_decode($out, true);
        assert_true(is_array($res), 'the setup wizard subprocess returned JSON (got: ' . substr($out, 0, 400) . ')');

        // 4) The wizard provisioned a usable, set-up system — and the loop stays fixed.
        assert_true($res['gate_before'] === true, 'before setup the gate must send you to /setup');
        assert_true($res['gate_after'] === false, 'AFTER setup the gate must NOT loop back to /setup (the /setup↔/login bug stays fixed)');
        assert_same('Fresh Install Co', $res['app_name'], 'the wizard must save the app name');
        assert_true($res['admin_active'] === true, 'the wizard must create an active admin');
        assert_true($res['setup_completed'] === true, 'the wizard must set setup_completed');
        assert_true($res['password_valid'] === true, 'the admin password the wizard stored must verify — i.e. login will work');
    } finally {
        $rootPdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");
    }
});
