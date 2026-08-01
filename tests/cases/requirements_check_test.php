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

test('requirements(D2): the checker runs on OLD PHP and hides details only for a healthy install', function (): void {
    $root = dirname(__DIR__, 2);
    $src = (string) file_get_contents($root . '/public/check-requirements.php');

    // It must parse and run on a pre-8.1 interpreter to be able to report "your PHP is too old", so it must
    // avoid syntax that fatals on older versions.
    foreach (['readonly ', 'enum ', '): never', '): mixed', 'first-class'] as $forbidden) {
        assert_true(!str_contains($src, $forbidden), "check-requirements.php must avoid 8.1+ syntax: {$forbidden}");
    }
    assert_true(
        preg_match('/(?<![A-Za-z0-9_])match\s*\(/', $src) !== 1,
        'check-requirements.php must avoid the PHP 8 match expression without mistaking preg_match() for it'
    );
    // The array-init style (array(...) not [...]) is a deliberate signal it targets old interpreters.
    assert_true(str_contains($src, '$REQUIRED_EXT = array('), 'the checker should use conservative array() syntax');
    assert_true(str_contains($src, 'php_version_supported(PHP_VERSION'), 'the checker must gate on both supported PHP bounds');

    // Info-disclosure guard: once a healthy app is set up it stops revealing extension/DB details. A broken
    // installed app must still show the failed check, otherwise IT sees a false "installed" green screen.
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
    assert_contains_str("\$MAX_PHP_EXCLUSIVE = '8.6.0'", $src, 'the checker must reject the first version excluded by the locked spreadsheet dependency');
    assert_contains_str('php_version_supported(PHP_VERSION, $MIN_PHP, $MAX_PHP_EXCLUSIVE)', $src, 'both bounds must affect the actual readiness verdict');

    assert_true(
        preg_match('/(function php_version_supported\([\s\S]*?\n\})\n\n\/\/ ReportRepository/', $src, $helperMatch) === 1,
        'the exact standalone PHP range helper can be isolated for a behavioral check'
    );
    $phpHelperSource = preg_replace('/^function php_version_supported/', 'function', $helperMatch[1]);
    if (!is_string($phpHelperSource)) {
        throw new RuntimeException('could not convert the extracted PHP range helper into a closure');
    }
    $phpVersionSupported = eval('return ' . $phpHelperSource . ';');
    if (!is_callable($phpVersionSupported)) {
        throw new RuntimeException('the extracted PHP range helper is not callable');
    }
    assert_true((bool) $phpVersionSupported('8.2.0', '8.2.0', '8.6.0'), 'PHP 8.2 is supported');
    assert_true((bool) $phpVersionSupported('8.5.99', '8.2.0', '8.6.0'), 'PHP 8.5 is supported');
    assert_false((bool) $phpVersionSupported('8.1.99', '8.2.0', '8.6.0'), 'PHP below the floor is rejected');
    assert_false((bool) $phpVersionSupported('8.6.0', '8.2.0', '8.6.0'), 'PHP 8.6 is rejected by the locked dependency range');

    foreach (['README.md', 'INSTALL.md', 'docs/testing-guide.md'] as $path) {
        $doc = (string) file_get_contents($root . '/' . $path);
        assert_contains_str('PHP 8.2–8.5', $doc, "$path must state both ends of the supported runtime range");
        assert_false(str_contains($doc, 'PHP 8.2 ขึ้นไป'), "$path must not imply unbounded PHP support");
    }

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

test('requirements(DB): docs and pre-install checker reject database versions missing window functions', function (): void {
    $root = dirname(__DIR__, 2);
    $src = (string) file_get_contents($root . '/public/check-requirements.php');
    $readme = (string) file_get_contents($root . '/README.md');
    $install = (string) file_get_contents($root . '/INSTALL.md');
    $reports = (string) file_get_contents($root . '/app/Repositories/ReportRepository.php');

    assert_contains_str('ROW_NUMBER()', $reports, 'the production reports genuinely require window-function support');
    assert_contains_str("\$MIN_MYSQL = '8.0.0'", $src, 'the checker must require MySQL 8.0');
    assert_contains_str("\$MIN_MARIADB = '10.3.0'", $src, 'the checker must require MariaDB 10.3');
    assert_contains_str('SELECT VERSION()', $src, 'the checker must inspect the connected server, not trust the PDO driver name');
    assert_contains_str('database_version_result', $src, 'the server version must be evaluated before declaring the host ready');
    assert_contains_str('MySQL 8.0+', $readme, 'README must state the real MySQL floor');
    assert_contains_str('MariaDB 10.3+', $readme, 'README must state the real MariaDB floor');
    assert_contains_str('MySQL 8.0+', $install, 'INSTALL must state the real MySQL floor');
    assert_contains_str('MariaDB 10.3+', $install, 'INSTALL must state the real MariaDB floor');
    assert_false(str_contains($readme, 'MySQL 5.7+'), 'the unsupported old floor must not return as a supported prerequisite');

    // Execute the exact standalone helper without including the whole diagnostic (which would connect using .env).
    assert_true(
        preg_match('/(function database_version_result\([\s\S]*?\n\})\n\n\/\/ --- checks/', $src, $helperMatch) === 1,
        'the database version helper can be isolated for a behavioral check'
    );
    $databaseHelperSource = preg_replace('/^function database_version_result/', 'function', $helperMatch[1]);
    if (!is_string($databaseHelperSource)) {
        throw new RuntimeException('could not convert the extracted database range helper into a closure');
    }
    $databaseVersionResult = eval('return ' . $databaseHelperSource . ';');
    if (!is_callable($databaseVersionResult)) {
        throw new RuntimeException('the extracted database range helper is not callable');
    }
    assert_true((bool) $databaseVersionResult('8.0.36', '8.0.0', '10.3.0')[0], 'MySQL 8 passes');
    assert_false((bool) $databaseVersionResult('5.7.44', '8.0.0', '10.3.0')[0], 'MySQL 5.7 fails');
    $maria = $databaseVersionResult('5.5.5-10.4.28-MariaDB', '8.0.0', '10.3.0');
    assert_true((bool) $maria[0], 'MariaDB compatibility prefix must not be mistaken for server version 5.5.5');
    assert_contains_str('10.4.28', (string) $maria[1], 'the real MariaDB version is reported');
    assert_false((bool) $databaseVersionResult('10.2.44-MariaDB', '8.0.0', '10.3.0')[0], 'MariaDB below the floor fails');
});

test('requirements(D2): ready means every required install check passed, not only PHP/extensions', function (): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/public/check-requirements.php');

    assert_contains_str(
        '$fatal === 0 && $envExists && $dbOk && $dbVersionOk && $schemaOk && $storageOk',
        $src,
        'a missing .env, DB, schema, supported DB version or writable storage must block the green ready message'
    );
    assert_true(
        strpos($src, '$allOk = $fatal === 0') < strpos($src, 'if ($alreadyInstalled && $allOk)'),
        'an existing setup may hide diagnostics only after PHP, DB version, schema and storage all pass'
    );
});

test('requirements(D2): the two copy-ready cron commands stay on separate lines', function (): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/public/check-requirements.php');

    assert_contains_str(
        'htmlspecialchars($cronScript, ENT_QUOTES, \'UTF-8\') . "\\n"',
        $src,
        'PHP consumes the source newline after a closing tag, so the first command must emit its own newline'
    );
});

test('requirements(D3): a boot failure points IT support to the diagnostic', function (): void {
    $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    assert_true(
        str_contains($index, 'check-requirements.php'),
        'the bootstrap-failure message must reference /check-requirements.php so a fresh-install error is actionable'
    );
});
