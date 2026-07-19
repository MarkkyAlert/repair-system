<?php

declare(strict_types=1);

// Deployment-readiness guards for the sold template (deploy-review). Each protects an installability fix so
// it can't silently regress before a release.

test('deploy(D9): schema.sql warns that it is destructive / fresh-install only', function (): void {
    $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');

    // The warning must sit in the header, BEFORE the first destructive statement, so an operator sees it first.
    $firstDrop = stripos($schema, 'DROP TABLE');
    assert_true($firstDrop !== false, 'schema.sql is expected to contain DROP TABLE (fresh-install dump)');
    $header = substr($schema, 0, (int) $firstDrop);

    assert_true(stripos($header, 'FRESH INSTALL') !== false, 'schema.sql header must say FRESH INSTALL ONLY');
    assert_true(
        str_contains($header, 'ข้อมูลเดิมทั้งหมดจะหาย') || stripos($header, 'ALL existing data') !== false,
        'schema.sql header must warn (TH+EN) that re-importing destroys existing data'
    );
    assert_true(stripos($header, 'database/upgrades/') !== false, 'schema.sql header must point existing systems to database/upgrades/');
});

test('deploy(D10): .env.production.example ships, covers the same keys, and defaults to safe production values', function (): void {
    $root = dirname(__DIR__, 2);
    $prodPath = $root . '/.env.production.example';
    assert_true(is_file($prodPath), '.env.production.example must ship for production installs');

    $keys = static function (string $path): array {
        preg_match_all('/^([A-Z][A-Z0-9_]+)=/m', (string) file_get_contents($path), $m);
        $k = array_unique($m[1]);
        sort($k);
        return $k;
    };
    // Drift-lock: production template must carry exactly the same keys as .env.example (no missing/extra env).
    assert_same(
        $keys($root . '/.env.example'),
        $keys($prodPath),
        '.env.production.example must have the same key set as .env.example (env drift)'
    );

    // Production-safe defaults.
    $prod = (string) file_get_contents($prodPath);
    assert_true((bool) preg_match('/^APP_ENV=production$/m', $prod), 'APP_ENV must be production');
    assert_true((bool) preg_match('/^APP_DEBUG=false$/m', $prod), 'APP_DEBUG must be false');
    assert_true((bool) preg_match('/^SESSION_SECURE=true$/m', $prod), 'SESSION_SECURE must be true (HTTPS-only)');
    assert_true((bool) preg_match('/^ALLOW_DEMO_DATA=false$/m', $prod), 'ALLOW_DEMO_DATA must be false');
    assert_true((bool) preg_match('/^MAIL_DRIVER=smtp$/m', $prod), 'MAIL_DRIVER must be smtp (real email)');
    assert_true((bool) preg_match('/^DB_PASSWORD=\S+/m', $prod), 'DB_PASSWORD must not be blank in the production template');
});

test('deploy(D7): the root .htaccess blocks direct access to secrets + internals, and recommends docroot=public', function (): void {
    $ht = (string) file_get_contents(dirname(__DIR__, 2) . '/.htaccess');

    // Sensitive files (dotfiles incl. .env, plus .sql/.lock/etc.) must be denied.
    assert_true((bool) preg_match('/<FilesMatch[^>]*env/i', $ht), '.htaccess must deny .env via a FilesMatch');
    assert_true((bool) preg_match('/<FilesMatch[^>]*\^\\\\\./', $ht), '.htaccess must deny dotfiles (^\\.) — .git*, .env, etc.');
    assert_true(stripos($ht, 'Require all denied') !== false || stripos($ht, 'Deny from all') !== false, '.htaccess must actually deny (2.4 or 2.2 syntax)');

    // Internal directories must be forbidden (403) for the whole-folder-in-docroot case.
    assert_true((bool) preg_match('/RewriteRule\s+\^\([^)]*\)\S*\s+-\s+\[F\]/', $ht, $blk), '.htaccess must have a [F] RewriteRule blocking internal dirs');
    foreach (['config', 'storage', 'database', 'app', 'vendor'] as $dir) {
        assert_true(str_contains($blk[0], $dir), ".htaccess [F] rule must block the {$dir} directory");
    }

    // Primary recommendation must be documented inline for whoever inspects the file.
    assert_true(stripos($ht, 'public/') !== false && stripos($ht, 'document root') !== false, '.htaccess must recommend pointing the docroot at public/');
});

test('deploy(D5): the production cron guidance is host-agnostic and points to the checker', function (): void {
    $root = dirname(__DIR__, 2);
    $guide = (string) file_get_contents($root . '/docs/testing-guide.md');

    // The old production cron line hardcoded this dev machine's XAMPP path — it must be gone.
    assert_true(
        !str_contains($guide, '*/5 * * * * /Applications/XAMPP'),
        'the production cron line must not hardcode the dev machine XAMPP php path'
    );
    // Host-agnostic guidance: placeholder path, cPanel steps, and a pointer to the auto-generating checker.
    assert_true(str_contains($guide, 'cPanel'), 'the cron guide must include cPanel Cron Jobs steps');
    assert_true(str_contains($guide, 'check-requirements.php'), 'the cron guide must point to check-requirements.php for the exact command');
    assert_true((bool) preg_match('#\*/5 \* \* \* \* php /ABSOLUTE/PATH#', $guide), 'the cron guide must show a host-agnostic placeholder command');

    // The diagnostic prints a real cron line for this install.
    $checker = (string) file_get_contents($root . '/public/check-requirements.php');
    assert_true(str_contains($checker, '*/5 * * * * php') && str_contains($checker, 'run-maintenance-cron.php'), 'the checker must print the exact cron command');
});

test('deploy(D1,D6): the release-packaging script bundles vendor and excludes secrets/dev data', function (): void {
    $root = dirname(__DIR__, 2);
    $path = $root . '/bin/package-release.sh';
    assert_true(is_file($path), 'bin/package-release.sh must ship so a clean release can be built');
    $s = (string) file_get_contents($path);

    // D1: production vendor/ must be bundled (shared hosts can't run composer).
    assert_true(str_contains($s, 'composer install') && str_contains($s, '--no-dev'), 'must run composer install --no-dev to bundle production vendor/');
    assert_true(str_contains($s, 'vendor/autoload.php'), 'must verify vendor/autoload.php ended up in the package');

    // D6: build from tracked files only, and explicitly drop secrets + real data.
    assert_true(str_contains($s, 'git archive'), 'must build from tracked files (git archive) — not the working tree');
    assert_true((bool) preg_match('/rm -f[^\n]*\.env\b/', $s), 'must strip .env from the package');
    assert_true((bool) preg_match('/storage\/backups\/\*/', $s), 'must strip real DB backups from the package');
    foreach (['e2e', '.github', 'handoff'] as $devJunk) {
        assert_true(str_contains($s, $devJunk), "must strip dev-only {$devJunk} from the package");
    }
    assert_true((bool) preg_match('/zip /', $s), 'must produce a .zip artifact');
});

test('deploy(D4): the handover doc set ships and stays anchored to the real install flow', function (): void {
    $root = dirname(__DIR__, 2);

    // The docs are the selling point for a semi-dev buyer — all five (plus LICENSE) must ship.
    foreach (['README.md', 'INSTALL.md', 'ADMIN-GUIDE.md', 'CUSTOMIZE.md', 'REPORT-GUIDE.md', 'LICENSE'] as $doc) {
        assert_true(is_file($root . '/' . $doc), "{$doc} must ship with the template");
    }
    // The commercial license must state the agreed model (single organisation, no resale).
    $license = (string) file_get_contents($root . '/LICENSE');
    assert_true(stripos($license, 'one') !== false && stripos($license, 'organization') !== false, 'LICENSE must scope use to one organization');
    assert_true(stripos($license, 'resell') !== false || stripos($license, 'ขายต่อ') !== false, 'LICENSE must forbid resale');

    // INSTALL must point at the real install mechanics (not invented steps): the diagnostic, phpMyAdmin
    // import, the /setup wizard, and the cron the app actually needs.
    $install = (string) file_get_contents($root . '/INSTALL.md');
    foreach (['check-requirements.php', 'phpMyAdmin', 'schema.sql', 'run-maintenance-cron.php', 'setup'] as $anchor) {
        assert_true(stripos($install, $anchor) !== false, "INSTALL.md must reference the real step: {$anchor}");
    }

    // The packaging script must NOT strip the buyer docs (they must be in the sold zip).
    $pkg = (string) file_get_contents($root . '/bin/package-release.sh');
    assert_true(
        preg_match('/rm[^\n]*\b(README|INSTALL|ADMIN-GUIDE|CUSTOMIZE|REPORT-GUIDE)\.md/', $pkg) !== 1,
        'package-release.sh must not strip the buyer-facing docs from the release'
    );
    assert_true(preg_match('/rm[^\n]*\bLICENSE\b/', $pkg) !== 1, 'package-release.sh must not strip LICENSE from the release');
});
