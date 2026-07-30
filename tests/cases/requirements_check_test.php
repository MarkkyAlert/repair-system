<?php

declare(strict_types=1);

// deploy-review D2/D3: there was no way for IT support to know their host met the requirements before install
// (no PHP-version/extension check), and a DB/config mistake surfaced only as a generic 500. public/check-
// requirements.php is a standalone pre-install diagnostic. These guards keep it honest: it must cover every
// extension the shipped libraries need, run on OLD PHP (to report "PHP too old"), hide details once installed,
// and the boot-failure path must point IT support to it.

test('requirements(D2): the checker covers every extension the shipped libraries require', function (): void {
    $root = dirname(__DIR__, 2);
    $src = (string) file_get_contents($root . '/public/check-requirements.php');

    // Extensions the checker declares.
    assert_true(preg_match('/\$REQUIRED_EXT = array\((.*?)\);/s', $src, $m) === 1, 'REQUIRED_EXT array must be present');
    preg_match_all("/'([a-z0-9_]+)'/", $m[1], $tok);
    $declared = $tok[1];

    // Extensions the RUNTIME (non-dev) libraries declare in composer.lock — the source of truth.
    $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true);
    $needed = ['pdo', 'pdo_mysql']; // the app's own MySQL driver — not a library dependency
    foreach (($lock['packages'] ?? []) as $pkg) {
        foreach (array_keys($pkg['require'] ?? []) as $req) {
            if (str_starts_with($req, 'ext-')) {
                $needed[] = substr($req, 4);
            }
        }
    }
    $missing = array_values(array_diff(array_unique($needed), $declared));
    sort($missing);
    assert_same([], $missing, 'check-requirements.php must list these required extensions: ' . implode(', ', $missing));
});

test('requirements(D2): the checker runs on OLD PHP (no 8.1-only syntax) and hides details once installed', function (): void {
    $root = dirname(__DIR__, 2);
    $src = (string) file_get_contents($root . '/public/check-requirements.php');

    // It must parse and run on a pre-8.1 interpreter to be able to report "your PHP is too old", so it must
    // avoid syntax that fatals on older versions.
    foreach (['readonly ', 'enum ', 'match (', 'match(', '): never', '): mixed', 'first-class'] as $forbidden) {
        assert_true(!str_contains($src, $forbidden), "check-requirements.php must avoid 8.1+ syntax: {$forbidden}");
    }
    // The array-init style (array(...) not [...]) is a deliberate signal it targets old interpreters.
    assert_true(str_contains($src, '$REQUIRED_EXT = array('), 'the checker should use conservative array() syntax');
    assert_true(str_contains($src, "version_compare(PHP_VERSION"), 'the checker must gate on the PHP version');

    // Info-disclosure guard: once the app is set up it must stop revealing extension/DB details.
    assert_true(str_contains($src, 'alreadyInstalled') && str_contains($src, 'setup_completed'), 'the checker must hide details once setup_completed');
    assert_true(str_contains($src, 'ลบไฟล์'), 'the checker must tell the operator to delete it after install');
});

// static-review #1: composer.lock's phpspreadsheet -> zipstream-php declares php-64bit ^8.2, but composer.json,
// README and the pre-install checker all said "8.1". A buyer on PHP 8.1 (or 32-bit) passed check-requirements.php
// and then hit an un-installable / un-runnable vendor/ — the deal-breaker for a shared-hosting template. These
// guards lock the human-facing floor to the machine-enforced one so they can never drift apart again.
test('requirements(#1): the checker floor matches composer.json and demands 64-bit', function (): void {
    $root = dirname(__DIR__, 2);
    $src = (string) file_get_contents($root . '/public/check-requirements.php');

    // composer.json is the developer-facing floor Composer enforces on install.
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
    $phpConstraint = (string) ($composer['require']['php'] ?? $composer['require']['php-64bit'] ?? '');
    assert_true(preg_match('/(\d+)\.(\d+)/', $phpConstraint, $cm) === 1, 'composer.json must declare a php floor');
    $composerFloor = $cm[1] . '.' . $cm[2];
    // the constraint must be at least 8.2 — the real minimum zipstream-php needs (below that, install fails)
    assert_true(
        version_compare($composerFloor . '.0', '8.2.0', '>='),
        "composer.json php floor must be >= 8.2 (zipstream-php needs php-64bit ^8.2); got {$phpConstraint}"
    );

    // check-requirements.php is the human-facing gate that runs BEFORE composer — its floor must match, or a
    // host it green-lights could still fail composer install.
    assert_true(preg_match("/\\\$MIN_PHP\\s*=\\s*'(\\d+)\\.(\\d+)/", $src, $mm) === 1, '$MIN_PHP must be set');
    assert_same($composerFloor, $mm[1] . '.' . $mm[2], 'check-requirements $MIN_PHP major.minor must equal composer.json floor');

    // zipstream-php's platform requirement is php-64bit — the checker must reject 32-bit PHP before composer does.
    assert_true(str_contains($src, 'PHP_INT_SIZE === 8'), 'the checker must gate on 64-bit PHP (PHP_INT_SIZE === 8)');
});

test('requirements(#1): composer.lock still fits the documented 8.2–8.5 tested range', function (): void {
    // The ceiling is not arbitrary: phpspreadsheet caps its own php constraint below 8.6, which is why 8.5 is the
    // top of the tested range in README/check-requirements. If a dependency bump moved that cap, the docs (and the
    // php-compat CI matrix leg) would need to move with it — this catches the drift.
    $root = dirname(__DIR__, 2);
    $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true);
    $spread = null;
    foreach (($lock['packages'] ?? []) as $pkg) {
        if (($pkg['name'] ?? '') === 'phpoffice/phpspreadsheet') {
            $spread = (string) ($pkg['require']['php'] ?? '');
        }
    }
    assert_true($spread !== null, 'phpspreadsheet must be present in composer.lock');
    // it declares an upper bound below 8.6 (e.g. ">=8.1.0 <8.6.0"); 8.5.x installs, 8.6 would not
    assert_true(str_contains($spread, '<8.6') || str_contains($spread, '< 8.6'), "phpspreadsheet php constraint expected to cap below 8.6; got {$spread}");
});

test('requirements(#1): the CI workflow exercises both ends of the supported PHP range', function (): void {
    // The docs promise 8.2–8.5; CI must actually run the suite on both, or "supported" is just a claim.
    $wf = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/tests.yml');
    assert_true(str_contains($wf, '"8.2"') && str_contains($wf, '"8.5"'), 'the workflow must run PHP 8.2 and 8.5');
});

test('requirements(D3): a boot failure points IT support to the diagnostic', function (): void {
    $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    assert_true(
        str_contains($index, 'check-requirements.php'),
        'the bootstrap-failure message must reference /check-requirements.php so a fresh-install error is actionable'
    );
});
