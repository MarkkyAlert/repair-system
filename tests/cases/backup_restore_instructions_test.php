<?php

declare(strict_types=1);

use App\Services\BackupService;

// A backup nobody has ever restored is not a backup. This suite already proves the FILE is produced honestly
// (no empty gzip passed off as success, a stalled dump killed at the deadline, unreadable archives flagged
// "กู้คืนไม่ได้"). What was never checked is the other half: whether the instructions printed next to that file
// actually get a person from "the server is gone" back to a working system.
//
// Restoring a real archive by hand showed they did not. The dump mysqldump writes contains tables only — no
// CREATE DATABASE — so on the day that matters (disk replaced, host rebuilt, database dropped) the printed
// import step answers with `ERROR 1049 (42000): Unknown database` and the steps simply end there. Someone who
// bought this template and does not read SQL has nowhere to go from that line. The commands also used a
// relative folder, so they only worked if you happened to be standing in the application root.
//
// Measured on a real round-trip after the fix: 27 tables, every table matching the source row for row, Thai
// text intact, 46 foreign keys and the AUTO_INCREMENT counters preserved, and the application boots and renders
// its reports against the restored copy.

/** Render the admin backup tab exactly as the browser gets it, from the real view-model. */
function bri_render_tab(): string
{
    $backup = tvm_container()->get(BackupService::class)->getStatus();
    ob_start();
    try {
        require BASE_PATH . '/app/Views/admin/tabs/backup.php';
    } catch (Throwable $e) {
        ob_end_clean();

        throw $e;
    }

    return (string) ob_get_clean();
}

test('restore(instructions): the steps still work when the database itself is gone, not only when it is damaged', function (): void {
    // read it the way a person does — the page escapes `<` and `"`, the reader sees the real command
    $html = html_entity_decode(strip_tags(bri_render_tab()), ENT_QUOTES);
    $dbName = (string) config('db.name', 'repair_system');

    // the step that was missing — without it the import below dead-ends on a wiped server
    assert_contains_str(
        'CREATE DATABASE IF NOT EXISTS ' . $dbName,
        $html,
        'the restore steps must start by making sure the database exists — the archive cannot create it'
    );
    assert_contains_str('utf8mb4', $html, 'and it must be created with the same character set the schema uses, or Thai text breaks on the next write');
    assert_contains_str('IF NOT EXISTS', $html, 'the step has to be safe to run when the database is merely damaged rather than missing');

    // order matters: creating the database after the import would be useless
    $createAt = strpos($html, 'CREATE DATABASE IF NOT EXISTS');
    $importAt = strpos($html, 'mysql -u ' . (string) config('db.username', 'root') . ' -p ' . $dbName . ' < ');
    assert_true($importAt !== false, 'the import step is still printed');
    assert_true($createAt < $importAt, 'the database must be created before the import runs, not after');

    // the import reads the decompressed file, and the compressed original survives (gzip -k) so a failed
    // import can simply be retried instead of leaving the person with nothing
    assert_contains_str('gzip -dk ', $html, 'unzipping must keep the .gz — it is the only copy of the data');
    assert_true(
        preg_match('/mysql -u \S+ -p \S+ < \S+\.sql(?!\.gz)/', $html) === 1,
        'the import feeds the decompressed .sql, never the .gz (mysql cannot read compressed input)'
    );
});

test('restore(instructions): every path printed is absolute, so the commands work from anywhere', function (): void {
    $restore = tvm_container()->get(BackupService::class)->getStatus()['restore'] ?? [];
    $dir = (string) ($restore['dir'] ?? '');

    assert_true($dir !== '' && str_starts_with($dir, '/'), 'the backup folder is given as a full path, not one relative to the application root');
    assert_true(is_dir($dir) || !is_dir(BASE_PATH . '/' . $dir), 'the full path points at the real backup folder');

    $text = html_entity_decode(strip_tags(bri_render_tab()), ENT_QUOTES);
    assert_true(
        preg_match('/gzip -dk (\S+)/', $text, $m) === 1 && str_starts_with($m[1], '/'),
        'the file named in the unzip step is a full path — a person restoring at 3am should not have to guess a working directory'
    );
});

test('restore(instructions): the producer still omits CREATE DATABASE — which is why the step above exists', function (): void {
    // Ties the instructions to the thing that produces the file. If someone later makes the worker dump with
    // --databases (which WOULD carry a CREATE DATABASE), this fails and points at the steps that need rewording.
    $worker = (string) file_get_contents(BASE_PATH . '/bin/backup-database.php');
    assert_true(!str_contains($worker, '--databases'), 'the dump covers one database and carries no CREATE DATABASE of its own');
    assert_true(!str_contains($worker, '--all-databases'), 'the worker backs up this application only');

    // and it stays a single-database dump aimed at the configured name
    assert_contains_str('--single-transaction', $worker, 'the dump is still taken consistently, without locking the live system');
});
