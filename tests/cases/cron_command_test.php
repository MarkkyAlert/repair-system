<?php

declare(strict_types=1);

use App\Services\BackupService;

// The backup only exists if cron actually runs it, and cron is not a shell you have ever typed in: it reads no
// profile, starts in the user's home directory, and carries a PATH of roughly /usr/bin:/bin.
//
// The admin screen used to print `0 2 * * * php bin/backup-database.php`. Run the way cron runs it, that fails
// twice over — the relative path does not resolve from cron's working directory, and a bare `php` is not on
// cron's PATH. Measured: `/bin/sh: php: command not found`. Nothing was ever backed up, and the only signal was
// a "สำรองข้อมูลค้างนาน" badge 48 hours later, on a tab nobody opens until they need the backup.
//
// So the printed line has to name real paths. PHP_BINARY cannot supply them — measured under this project's own
// Apache it is an empty string, and under php-fpm it names the fpm binary rather than the CLI — which is why
// php_cli_binary() derives the path from PHP_BINDIR instead.

test('cron(command): the printed line names real, absolute paths — nothing cron has to resolve for itself', function (): void {
    $cron = tvm_container()->get(BackupService::class)->getStatus()['cron'] ?? [];
    $line = (string) ($cron['line'] ?? '');

    assert_true($line !== '', 'the admin screen still prints a cron line');
    assert_true(str_starts_with($line, '0 2 * * * '), 'it is still a daily 02:00 schedule');

    // the two things cron cannot work out on its own
    $php = (string) ($cron['php'] ?? '');
    $script = (string) ($cron['script'] ?? '');
    assert_true(str_starts_with($php, '/'), 'the PHP interpreter is given as a full path, not the bare word php');
    assert_true(str_starts_with($script, '/'), 'the script is given as a full path — cron does not start in the application folder');
    assert_true(is_file($script), 'and that script really is there');
    assert_contains_str($php . ' ' . $script, $line, 'the line is built from those two paths');

    // failures have to land somewhere the owner can actually read
    assert_contains_str('2>&1', $line, 'errors are captured, not thrown away — a silent cron failure is how a backup gap goes unnoticed');
    $log = (string) ($cron['log'] ?? '');
    assert_true(str_starts_with($log, '/'), 'the log is a full path too');
    assert_true(
        !str_starts_with($log, '/var/log/'),
        'the log stays inside the application, where the site owner can write and read it — /var/log usually needs root'
    );
});

test('cron(command): the interpreter the line names can actually run the script', function (): void {
    $cron = tvm_container()->get(BackupService::class)->getStatus()['cron'] ?? [];
    $php = (string) ($cron['php'] ?? '');
    $script = (string) ($cron['script'] ?? '');

    assert_true(is_executable($php), 'the named interpreter is executable');

    // run it the way cron would: no shell profile, cron's PATH, and NOT from the application directory.
    // --dry-run so this proves the wiring without writing a backup.
    $cmd = 'env -i PATH=/usr/bin:/bin HOME=' . escapeshellarg((string) (getenv('HOME') ?: '/tmp'))
        . ' /bin/sh -c ' . escapeshellarg('cd / && ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --dry-run')
        . ' 2>&1';
    $output = (string) shell_exec($cmd);

    assert_true(
        !str_contains($output, 'command not found'),
        'the printed interpreter resolves under cron\'s PATH — this is the exact failure the old bare `php` produced: ' . trim($output)
    );
    assert_contains_str('[backup]', $output, 'the script boots and reports, started from a directory that is not the application root: ' . trim($output));
});

test('cron(command): the standalone pre-install page resolves the interpreter the same way', function (): void {
    // check-requirements.php must run before composer install and before the app can boot, so it cannot call
    // php_cli_binary(). It carries its own copy — which is exactly how the two drift apart. This pins them.
    $page = (string) file_get_contents(BASE_PATH . '/public/check-requirements.php');

    assert_contains_str('PHP_BINDIR', $page, 'the diagnostic derives the interpreter from PHP_BINDIR, like the helper does');
    assert_true(
        !preg_match('/\* \* \* \* \* php |0 2 \* \* \* php /', $page),
        'it no longer prints a cron line starting with a bare `php`'
    );
    assert_contains_str('$phpCliPath . \' \' . $cronScript', $page, 'the SLA/email line is built from the resolved path');
    assert_contains_str('$phpCliPath . \' \' . $backupScript', $page, 'and so is the backup line');

    // the helper's own contract, since the copy above mirrors it
    $php = php_cli_binary();
    assert_true($php !== 'php', 'on a machine with a real PHP install the helper finds it rather than giving up');
    assert_true(is_executable($php), 'and what it returns can be executed');
});

// The other half of the same problem. Fixing the paths got PHP running under cron, and then the dump died with
// exit 127 — MYSQLDUMP_BIN defaulted to the bare name `mysqldump`, resolved through the PATH that cron does not
// have. On XAMPP and MAMP, where MySQL lives outside the system directories, that is every night, forever.
test('cron(backup): the dump finds mysqldump without relying on cron\'s PATH', function (): void {
    $worker = (string) file_get_contents(BASE_PATH . '/bin/backup-database.php');
    // the wiring, not just the presence of the function — a resolver nothing calls fixes nothing
    assert_contains_str(
        "resolve_mysqldump_bin((string) env('MYSQLDUMP_BIN', ''))",
        $worker,
        'the dump binary actually goes through the resolver, instead of defaulting to the bare name PATH has to find'
    );

    // an explicitly configured path always wins — we must not silently substitute a different binary
    assert_true(
        (bool) preg_match('/if \(\$configured !== \'\'\) \{\s*return \$configured;/', $worker),
        'a MYSQLDUMP_BIN set in .env is used as given, so a wrong setting reports itself instead of being papered over'
    );

    // the search list must be absolute paths, since resolving by name is the failure being fixed
    assert_true((bool) preg_match_all('#^\s+\'(/[^\']+/mysqldump)\',#m', $worker, $m) > 0, 'it searches real locations');
    foreach ($m[1] as $candidate) {
        assert_true(str_starts_with($candidate, '/'), "search location {$candidate} is absolute");
    }
    assert_contains_str('PHP_BINDIR', $worker, 'and it looks beside the running PHP first — on XAMPP/MAMP mysqldump sits there');
});

test('cron(backup): a missing mysqldump says what to change — in BOTH shapes the failure takes', function (): void {
    // "Cannot run this command" reaches PHP two different ways depending on the platform, and the first attempt
    // at this fix only handled one of them. On the XAMPP PHP, proc_open spawns something that exits 127. On the
    // Homebrew PHP 8.5, posix_spawn fails and proc_open returns false outright — which skipped the message
    // entirely and printed a raw proc_open warning instead. Both are the same problem to the owner, so both
    // must produce the same actionable text. Each case below drives the REAL worker.
    $backupDir = BASE_PATH . '/storage/backups';
    $before = glob($backupDir . '/db-*.sql.gz') ?: [];

    $fake127 = (string) tempnam(sys_get_temp_dir(), 'nodump_');
    file_put_contents($fake127, "#!/bin/sh\nexit 127\n");
    chmod($fake127, 0755);

    $shapes = [
        'exit 127' => $fake127,
        'nothing to spawn' => sys_get_temp_dir() . '/definitely-not-installed-mysqldump-' . bin2hex(random_bytes(4)),
    ];

    try {
        foreach ($shapes as $shape => $bin) {
            $cmd = 'DB_NAME=repair_system_test MYSQLDUMP_BIN=' . escapeshellarg($bin)
                . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/bin/backup-database.php');
            $out = [];
            $exitCode = 0;
            exec($cmd . ' 2>&1', $out, $exitCode);
            $output = implode("\n", $out);

            assert_same(1, $exitCode, "({$shape}) the worker still fails loudly");
            assert_contains_str('mysqldump', $output, "({$shape}) it names the actual problem");
            assert_contains_str($bin, $output, "({$shape}) and shows which path it tried, so a wrong MYSQLDUMP_BIN is obvious");
            assert_contains_str('MYSQLDUMP_BIN', $output, "({$shape}) and names the setting to change — actionable, not a dead end");
            assert_true(!str_contains($output, 'proc_open'), "({$shape}) no raw PHP warning: that tells a non-developer nothing");
            assert_true(!str_contains($output, 'posix_spawn'), "({$shape}) nor the platform call underneath it");
            assert_same($before, glob($backupDir . '/db-*.sql.gz') ?: [], "({$shape}) and no half-written artifact is left behind");
        }
    } finally {
        @unlink($fake127);
        foreach (array_diff(glob($backupDir . '/db-*.sql.gz') ?: [], $before) as $leftover) {
            @unlink($leftover);
        }
    }
});

test('cron(backup): on this machine the resolution works end to end, with an empty PATH', function (): void {
    // The whole point: nothing configured, no PATH to fall back on. The expectation is decided by what this
    // machine actually has — NOT by what the run happens to return, or the test would pass either way and catch
    // no regression at all. If a mysqldump exists in any known location, the worker is required to find it.
    $known = [
        PHP_BINDIR . '/mysqldump', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/opt/homebrew/bin/mysqldump',
        '/usr/local/mysql/bin/mysqldump', '/opt/lampp/bin/mysqldump',
        '/Applications/XAMPP/xamppfiles/bin/mysqldump', '/Applications/MAMP/Library/bin/mysqldump',
    ];
    $available = array_values(array_filter($known, static fn (string $p): bool => is_file($p) && is_executable($p)));

    $backupDir = BASE_PATH . '/storage/backups';
    $before = glob($backupDir . '/db-*.sql.gz') ?: [];

    try {
        $cmd = 'env -i PATH= DB_NAME=repair_system_test HOME=' . escapeshellarg((string) (getenv('HOME') ?: '/tmp'))
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/bin/backup-database.php') . ' --keep=1000';
        $out = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $out, $exitCode);
        $output = implode("\n", $out);

        if ($available !== []) {
            assert_same(
                0,
                $exitCode,
                'mysqldump exists at ' . implode(', ', $available) . ' so the worker must find it with no PATH and no setting: ' . $output
            );
            assert_contains_str('[backup] wrote', $output, 'a successful run reports what it wrote');
            $produced = array_diff(glob($backupDir . '/db-*.sql.gz') ?: [], $before);
            assert_true($produced !== [], 'and a real archive exists afterwards');
            foreach ($produced as $path) {
                $gz = gzopen($path, 'rb');
                assert_true($gz !== false, 'the archive opens as a gzip');
                $head = (string) gzread($gz, 4096);
                gzclose($gz);
                assert_contains_str('CREATE TABLE', $head, 'and it contains real schema — produced with no PATH at all');
            }
        } else {
            assert_contains_str(
                'ไม่พบคำสั่ง mysqldump',
                $output,
                'this machine has no mysqldump in any known location, so the worker must say exactly that: ' . $output
            );
        }
    } finally {
        foreach (array_diff(glob($backupDir . '/db-*.sql.gz') ?: [], $before) as $leftover) {
            @unlink($leftover);
        }
    }
});

test('cron(command): the install guide does not hand out a command that dies under cron', function (): void {
    $install = (string) file_get_contents(BASE_PATH . '/INSTALL.md');

    assert_true(
        !preg_match('/^\s*php \/[^\n]*bin\/[a-z-]+\.php/m', $install),
        'no cron command in the guide starts with a bare `php` — that is the "command not found" shape'
    );
    assert_contains_str('command not found', $install, 'and the guide warns about that failure by name, so a reader recognises it');
    assert_contains_str('MYSQLDUMP_BIN', $install, 'the backup step points at the setting to use when mysqldump lives outside the standard path');
});
