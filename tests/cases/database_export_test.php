<?php

declare(strict_types=1);

use App\Services\DatabaseExportService;

// deploy-review D8: shared hosting usually disables proc_open/exec and ships no mysqldump, so the cron backup
// silently produces nothing and the admin had no in-product way to back up before an update. DatabaseExportService
// is a pure-PDO dump the admin can download. These guards prove it is actually restorable and properly gated.

test('backup(D8): the PDO dump round-trips a table byte-for-byte (quotes, newlines, unicode, NULL)', function (): void {
    $cfg = tvm_container()->get('config')['db'];
    $root = tvm_container()->get(PDO::class);
    $connect = static fn (string $db): PDO => new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$db};charset=utf8mb4",
        (string) $cfg['username'],
        (string) $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $src = 'rs_export_src_test';
    $dst = 'rs_export_dst_test';
    try {
        foreach ([$src, $dst] as $db) {
            $root->exec("DROP DATABASE IF EXISTS `{$db}`");
            $root->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4");
        }

        $source = $connect($src);
        $source->exec('CREATE TABLE gizmo (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(200), note TEXT, qty INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $insert = $source->prepare('INSERT INTO gizmo (name, note, qty) VALUES (?, ?, ?)');
        $insert->execute(["O'Brien \"quoted\"", "line1\nline2 ; DROP", 5]);   // quotes + newline + semicolon
        $insert->execute(['ไทย ünicode 日本', null, null]);                     // unicode + NULLs
        $insert->execute(['back`tick` and \\ slash', '50% off', 0]);           // backtick + backslash + zero

        $sql = (new DatabaseExportService($source))->toSql();
        assert_contains_str('SET FOREIGN_KEY_CHECKS = 0', $sql, 'dump must disable FK checks for an order-independent restore');
        assert_contains_str('DROP TABLE IF EXISTS `gizmo`', $sql, 'dump must drop the table before recreating');
        assert_contains_str('CREATE TABLE', $sql, 'dump must recreate structure');
        assert_contains_str('INSERT INTO `gizmo`', $sql, 'dump must carry the data');

        // Restore into a clean DB and compare — proves the dump is genuinely restorable, escaping and all.
        $restored = $connect($dst);
        $restored->exec($sql);
        assert_same(
            $source->query('SELECT id, name, note, qty FROM gizmo ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $restored->query('SELECT id, name, note, qty FROM gizmo ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'restored rows must match the source exactly (structure + tricky values)'
        );
    } finally {
        foreach ([$src, $dst] as $db) {
            try {
                $root->exec("DROP DATABASE IF EXISTS `{$db}`");
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
    }
});

test('backup(D8): the admin can download an on-demand gzipped backup (wired + gated)', function (): void {
    $root = dirname(__DIR__, 2);
    assert_true(str_contains((string) file_get_contents($root . '/config/routes.php'), "/admin/backup/download"), 'the on-demand backup route must ship');

    $ctrl = (string) file_get_contents($root . '/app/Controllers/AdminController.php');
    assert_true(str_contains($ctrl, 'function downloadBackup'), 'AdminController::downloadBackup must exist');
    assert_true(str_contains($ctrl, 'gzencode'), 'the download must be gzipped');
    // admin_route_gate + csrf_route_gate walk routes.php and enforce these too; assert here for locality.
    assert_true(str_contains($ctrl, "require_role(\$viewer, ['admin']"), 'downloadBackup must enforce the admin role gate');
    assert_true(str_contains($ctrl, 'csrf_validate()'), 'downloadBackup must validate CSRF');

    // The UI must expose it (works where cron/mysqldump cannot).
    assert_true(str_contains((string) file_get_contents($root . '/app/Views/admin/tabs/backup.php'), '/admin/backup/download'), 'the backup tab must offer the on-demand download');
});
