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
