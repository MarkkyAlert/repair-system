<?php

declare(strict_types=1);

use App\Services\LoginRateLimiter;

// error-review-5 F1: the login/reset/guest rate limiter reads a JSON file. When that file can't be opened or
// locked, or its JSON is corrupt (partial write, disk error, manual edit), the limiter returned an EMPTY state
// — i.e. it forgot every recorded attempt and let logins through un-throttled (fail-open) with no trace. The
// write path likewise ignored ftruncate/fwrite/fflush results, so a full disk silently corrupted the file.
// The fail-open POLICY is unchanged here (a storage fault must not lock every user out); the fix is
// OBSERVABILITY — every degradation is now logged so support sees it. Captures error_log to a temp file.

test('rate-limiter(F1): a corrupt state file is logged, and fail-open is preserved (not silently reset)', function (): void {
    $stateFile = tempnam(sys_get_temp_dir(), 'rl_') . '.json';
    file_put_contents($stateFile, '{ this is not valid json at all '); // corrupt on purpose
    $limiter = new LoginRateLimiter($stateFile);

    $logFile = tempnam(sys_get_temp_dir(), 'rllog_') . '.log';
    $originalLog = (string) ini_get('error_log');
    ini_set('error_log', $logFile);

    try {
        $blocked = $limiter->tooManyAttempts('login:someuser', 5, 900);
        $logged = (string) @file_get_contents($logFile);

        assert_false($blocked, 'fail-open is preserved — a corrupt file must not lock everyone out (policy unchanged)');
        assert_contains_str('[ratelimit]', $logged, 'the corrupt state is now logged (was returned as empty, silently)');
        assert_contains_str('corrupt JSON', $logged, 'the log names the actual cause so support can act');
        assert_true(strlen($logged) > 0, 'a diagnostic is written — no longer log_bytes=0 on a corrupt file');
    } finally {
        ini_set('error_log', $originalLog);
        @unlink($logFile);
        @unlink($stateFile);
    }
});

test('rate-limiter(F1): valid state still works — a hit is recorded and the file self-heals corruption', function (): void {
    $stateFile = tempnam(sys_get_temp_dir(), 'rl_') . '.json';
    file_put_contents($stateFile, 'not json'); // start corrupt
    $limiter = new LoginRateLimiter($stateFile);

    try {
        // a write (hit) reads the corrupt state as empty, then persists VALID json → self-healed
        $limiter->hit('login:heal', 900);
        $decoded = json_decode((string) file_get_contents($stateFile), true);
        assert_true(is_array($decoded), 'after a hit the file holds valid JSON again (corruption self-heals on write)');
        assert_true(isset($decoded['login:heal']), 'the recorded attempt is persisted');
    } finally {
        @unlink($stateFile);
    }
});

// The open/lock/write failure branches can't be forced deterministically in-process (they need a real fopen/
// flock/disk failure), so source-lock that each degradation emits a [ratelimit] diagnostic rather than a
// silent empty-return / ignored write result.
test('rate-limiter(F1): every storage-failure branch emits a diagnostic (source-lock)', function (): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/LoginRateLimiter.php');

    // read(): open + shared-lock failures
    assert_contains_str("cannot open ' . \$this->filePath . ' for read", $src, 'a read-open failure is logged');
    assert_contains_str('shared lock failed on', $src, 'a shared-lock failure is logged');
    // decode(): corrupt json
    assert_contains_str('corrupt JSON in', $src, 'a corrupt-JSON decode is logged');
    // mutate(): the write steps are checked (return value not discarded)
    assert_true(preg_match('/if \(ftruncate\([^)]*\) === false\)/', $src) === 1, 'ftruncate result is checked');
    assert_true(preg_match('/if \(fwrite\([^)]*\) === false\)/', $src) === 1, 'fwrite result is checked');
    assert_true(preg_match('/if \(fflush\([^)]*\) === false\)/', $src) === 1, 'fflush result is checked');
});
