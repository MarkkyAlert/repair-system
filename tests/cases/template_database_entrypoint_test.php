<?php

declare(strict_types=1);

// ux-refactor F1 (packaging): the sale package must ship exactly one, guarded path to demo accounts —
// seed_demo.sql (gated by ALLOW_DEMO_DATA + the /setup flow). A fresh install has NO accounts; the admin is
// created through setup. This guard fails the suite if any database/*.sql seeds public accounts outside the
// demo path (e.g. the old seed_step2_auth.sql, which created requester/manager/technician/admin with known
// hashes and was referenced by no supported install/test path).

test('db-entrypoint(F1): only seed_demo.sql may create user accounts; the legacy auth seed is gone', function (): void {
    $dbDir = dirname(__DIR__, 2) . '/database';

    // The dead public-account seed must not be shipped.
    assert_false(is_file($dbDir . '/seed_step2_auth.sql'), 'seed_step2_auth.sql must be removed from the package (unguarded public accounts)');

    // Fresh-install entrypoints must not seed any users.
    foreach (['schema.sql', 'seed_reference.sql'] as $fresh) {
        $sql = (string) file_get_contents($dbDir . '/' . $fresh);
        assert_true(
            preg_match('/insert\s+into\s+`?users`?/i', $sql) !== 1,
            "{$fresh} (a fresh-install entrypoint) must not INSERT users — the admin is created via /setup"
        );
    }

    // Any OTHER .sql that inserts users is an unguarded public-account leak — only seed_demo.sql is allowed.
    $offenders = [];
    foreach (glob($dbDir . '/*.sql') ?: [] as $file) {
        if (basename($file) === 'seed_demo.sql') {
            continue;
        }
        $sql = (string) file_get_contents($file);
        if (preg_match('/insert\s+into\s+`?users`?/i', $sql) === 1) {
            $offenders[] = basename($file);
        }
    }
    sort($offenders);
    assert_same([], $offenders, 'these SQL files seed accounts outside the guarded demo path: ' . implode(', ', $offenders));
});

test('db-entrypoint(F3): fresh-install SQL is separated from legacy upgrades (no migrate_*.sql at the database/ root)', function (): void {
    $root = dirname(__DIR__, 2);

    // The database/ root ships ONLY the fresh-install entrypoints; one-off upgrades live in database/upgrades/.
    $rootSql = array_map('basename', glob($root . '/database/*.sql') ?: []);
    sort($rootSql);
    assert_same(
        ['schema.sql', 'seed_demo.sql', 'seed_reference.sql'],
        $rootSql,
        'database/ root must contain only the fresh-install entrypoints (upgrades belong in database/upgrades/)'
    );
    assert_true(count(glob($root . '/database/upgrades/*.sql') ?: []) > 0, 'legacy upgrade scripts must live in database/upgrades/');

    // .env.example must not tell a FRESH install to run the (non-idempotent) upgrade scripts.
    $env = (string) file_get_contents($root . '/.env.example');
    assert_true(
        preg_match('/run\s+(any\s+)?`?database\/migrate_/i', $env) !== 1,
        '.env.example must not instruct a fresh install to run migrate_*.sql (they error against the current schema)'
    );
});

test('db-entrypoint(F7): the test-DB helper resolves mysql portably (PATH / MYSQL_BIN, not a hardcoded XAMPP default)', function (): void {
    // The shipped helper must run on a buyer's Linux/Windows/other-XAMPP box, not only this dev machine.
    $sh = (string) file_get_contents(dirname(__DIR__) . '/setup_test_db.sh');
    assert_true(str_contains($sh, 'command -v mysql'), 'setup_test_db.sh must fall back to mysql from PATH');
    assert_true(str_contains($sh, 'MYSQL_BIN'), 'setup_test_db.sh must honour a MYSQL_BIN override');
    assert_true(
        preg_match('/MYSQL="\$\{MYSQL_BIN:-\/Applications\/XAMPP/', $sh) !== 1,
        'the absolute XAMPP path must not be the primary default (only a last-resort fallback branch)'
    );
});
