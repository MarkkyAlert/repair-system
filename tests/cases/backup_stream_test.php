<?php

declare(strict_types=1);

use App\Services\BackupService;
use App\Services\DatabaseExportService;

// The admin "back up & download" button used to build the entire database as one PHP string and then gzip a second
// copy of it, so peak memory was 2-3x the database size. On a 308MB database that blew the memory limit and the
// browser got HTTP 500 with an empty body — no error page, nothing. That is the worst possible failure for a backup
// button: the operator is told to press it before every update, and a blank page can be mistaken for "it downloaded".
// The dump is now produced and compressed in chunks, so memory is flat, and every attempt is recorded so an attempt
// that dies halfway is reported on the panel instead of passing for a working file.

/** Build a scratch database with a known amount of data. Returns [dsn, user, password]. */
function bkstream_scratch_db(string $name, int $rows, int $width): array
{
    $cfg = tvm_container()->get('config')['db'];
    $root = tvm_container()->get(PDO::class);
    $root->exec("DROP DATABASE IF EXISTS `{$name}`");
    $root->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4");

    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, (string) $cfg['username'], (string) $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE bulk (id INT PRIMARY KEY AUTO_INCREMENT, payload LONGTEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $perStatement = 100;
    $insert = $pdo->prepare('INSERT INTO bulk (payload) VALUES ' . implode(',', array_fill(0, $perStatement, '(?)')));
    $filler = str_repeat('x', $width);
    for ($done = 0; $done < $rows; $done += $perStatement) {
        $insert->execute(array_fill(0, $perStatement, $filler));
    }

    return [$dsn, (string) $cfg['username'], (string) $cfg['password']];
}

/** Dump the scratch database in a child PHP process with a hard memory cap. Returns its combined output. */
function bkstream_child(string $mode, string $dsn, string $user, string $password, string $memoryLimit): string
{
    $code = '$pdo = new PDO(' . var_export($dsn, true) . ', ' . var_export($user, true) . ', ' . var_export($password, true)
        . ', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
        . '$svc = new App\Services\DatabaseExportService($pdo);'
        . '$bytes = 0;'
        . ($mode === 'stream'
            ? '$svc->streamTo(function (string $c) use (&$bytes): void { $bytes += strlen($c); });'
            : '$bytes = strlen($svc->toSql());')
        . 'printf("DUMPED:%d PEAK:%d", $bytes, memory_get_peak_usage(true));';

    // No bootstrap on purpose: the child needs the autoloader and one PDO, nothing else. That keeps its
    // baseline memory tiny, so the cap measures the dump itself rather than the framework.
    $command = escapeshellarg(PHP_BINARY)
        . ' -d memory_limit=' . escapeshellarg($memoryLimit)
        . ' -r ' . escapeshellarg('require ' . var_export(BASE_PATH . '/vendor/autoload.php', true) . ';' . $code)
        . ' 2>&1';

    return (string) shell_exec($command);
}

test('backup: a database larger than the memory limit dumps fine streamed, and dies buffered', function (): void {
    $name = 'rs_backup_stream_test';
    // 6,000 rows x 4KB = ~23MB of data against a 24MB cap: comfortably over the limit for anything that holds
    // the dump (or the result set) in memory, comfortably under it for anything that streams.
    [$dsn, $user, $password] = bkstream_scratch_db($name, 6000, 4000);

    try {
        $streamed = bkstream_child('stream', $dsn, $user, $password, '24M');
        assert_true(
            (bool) preg_match('/DUMPED:(\d+) PEAK:(\d+)/', $streamed, $m),
            "streaming a 23MB database under a 24MB cap must succeed, got: " . substr($streamed, 0, 400)
        );
        assert_true((int) $m[1] > 20_000_000, 'the streamed dump must actually carry the ~23MB of data, got ' . $m[1] . ' bytes');
        assert_true(
            (int) $m[2] < 12_000_000,
            'peak memory must stay far below the size of the data — it was ' . round((int) $m[2] / 1048576, 1) . 'MB'
        );

        // The control: the same dump built as one string in the same child, same cap. This is what shipped before,
        // and it proves the assertion above is measuring streaming rather than a cap that was never tight.
        $buffered = bkstream_child('buffer', $dsn, $user, $password, '24M');
        assert_true(
            str_contains($buffered, 'Allowed memory size'),
            'building the whole dump in memory must still exhaust the same cap (otherwise this test proves nothing), got: '
                . substr($buffered, 0, 300)
        );
    } finally {
        tvm_container()->get(PDO::class)->exec("DROP DATABASE IF EXISTS `{$name}`");
    }
});

test('backup: the dump ends with a completion marker, so a half-written file is recognisable', function (): void {
    $sql = tvm_container()->get(DatabaseExportService::class)->toSql();

    assert_contains_str(DatabaseExportService::COMPLETION_MARKER, $sql, 'a finished dump must say so at the end');
    assert_true(
        str_contains(substr($sql, -120), DatabaseExportService::COMPLETION_MARKER),
        'the marker must be the last thing in the file, not somewhere in the middle'
    );
    // Truncation is what actually happens when a dump is cut short: the marker is simply not there.
    assert_true(
        !str_contains(substr($sql, 0, (int) (strlen($sql) * 0.9)), DatabaseExportService::COMPLETION_MARKER),
        'a dump cut short must NOT contain the marker'
    );
});

test('backup: the admin download streams the dump — it must never build the whole thing in memory', function (): void {
    $controller = (string) file_get_contents(BASE_PATH . '/app/Controllers/AdminController.php');

    assert_true(str_contains($controller, 'Response::streamDownload'), 'the download must go out through the streaming response');
    assert_true(str_contains($controller, 'streamTo('), 'the dump must be produced in chunks');
    assert_true(str_contains($controller, 'deflate_add'), 'compression must be incremental, not one gzencode of the whole dump');
    // The regression that matters: switching back to the buffering API reintroduces the blank 500 on a big database.
    assert_true(
        !str_contains($controller, 'toSql()') && !str_contains($controller, 'gzencode('),
        'downloadBackup must not use the whole-dump-in-memory API'
    );
    assert_true(str_contains($controller, 'recordOnDemandStart('), 'the attempt must be recorded before the first byte goes out');
});

test('backup: the panel reports the outcome of the last on-demand backup (ok / interrupted / failed)', function (): void {
    $service = tvm_container()->get(BackupService::class);
    $pdo = tvm_container()->get(PDO::class);
    $keys = [
        BackupService::ONDEMAND_STARTED_KEY,
        BackupService::ONDEMAND_FINISHED_KEY,
        BackupService::ONDEMAND_BYTES_KEY,
        BackupService::ONDEMAND_ERROR_KEY,
    ];
    $clear = static function () use ($pdo, $keys): void {
        $pdo->prepare('DELETE FROM system_settings WHERE setting_key IN (?, ?, ?, ?)')->execute($keys);
    };

    try {
        $clear();
        assert_same('never', $service->getStatus()['on_demand']['state'], 'no attempt yet reads as never');

        // Started but never finished — the shape left behind when PHP is killed mid-dump. Nothing else can
        // report this: the code that would have written a failure never got to run.
        $service->recordOnDemandStart();
        assert_same('interrupted', $service->getStatus()['on_demand']['state'], 'a start with no finish must read as interrupted');

        $service->recordOnDemandResult(true, 139_610);
        $ok = $service->getStatus()['on_demand'];
        assert_same('ok', $ok['state'], 'a completed backup reads as ok');
        assert_same(139610, $ok['bytes'], 'the size actually sent is kept');
        assert_contains_str('KB', $ok['size_human'], 'the panel gets a human-readable size, not raw bytes');

        $service->recordOnDemandStart();
        $service->recordOnDemandResult(false, 42, 'ดิสก์เต็ม');
        $failed = $service->getStatus()['on_demand'];
        assert_same('failed', $failed['state'], 'a reported failure reads as failed');
        assert_same('ดิสก์เต็ม', $failed['error'], 'the reason is kept so the panel can show it');
    } finally {
        $clear();
    }
});

test('backup: the backup tab shows the last on-demand result instead of staying silent', function (): void {
    $render = static fn (array $onDemand): string => render_partial('admin/tabs/backup', [
        'backup' => ['on_demand' => $onDemand, 'restore' => [], 'files' => []],
    ]);

    $ok = $render(['state' => 'ok', 'finished_at' => '2026-07-27 21:00:00', 'size_human' => '139.6 KB']);
    assert_contains_str('สำรองสำเร็จ', $ok, 'a successful backup must be confirmed on screen');
    assert_contains_str('139.6 KB', $ok, 'the confirmation must include the size of the file that was downloaded');

    $interrupted = $render(['state' => 'interrupted', 'started_at' => '2026-07-27 21:00:00']);
    assert_contains_str('ไม่จบ', $interrupted, 'an unfinished backup must say so');
    assert_contains_str('กู้คืนไม่ได้', $interrupted, 'and must say the downloaded file cannot be restored');

    $failed = $render(['state' => 'failed', 'finished_at' => '2026-07-27 21:00:00', 'error' => 'ต่อฐานข้อมูลไม่ได้']);
    assert_contains_str('ต่อฐานข้อมูลไม่ได้', $failed, 'a failure must show its reason');

    $never = $render(['state' => 'never']);
    assert_true(
        !str_contains($never, 'สำรองสำเร็จ') && !str_contains($never, 'ไม่จบ'),
        'with no attempt on record the panel must claim nothing'
    );
});
