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

test('requirements(D3): a boot failure points IT support to the diagnostic', function (): void {
    $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    assert_true(
        str_contains($index, 'check-requirements.php'),
        'the bootstrap-failure message must reference /check-requirements.php so a fresh-install error is actionable'
    );
});
