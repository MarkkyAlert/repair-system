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

test('cron(command): the install guide does not hand out a command that dies under cron', function (): void {
    $install = (string) file_get_contents(BASE_PATH . '/INSTALL.md');

    assert_true(
        !preg_match('/^\s*php \/[^\n]*bin\/[a-z-]+\.php/m', $install),
        'no cron command in the guide starts with a bare `php` — that is the "command not found" shape'
    );
    assert_contains_str('command not found', $install, 'and the guide warns about that failure by name, so a reader recognises it');
    assert_contains_str('MYSQLDUMP_BIN', $install, 'the backup step points at the setting to use when mysqldump lives outside the standard path');
});
