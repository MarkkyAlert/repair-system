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
