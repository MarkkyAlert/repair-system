<?php
declare(strict_types=1);

// Locks log_caught_exception(): the best-effort side-effect catches (notifications, cleanup, audit) route
// through it so a deliberately-swallowed failure still records the marker, caller context, and the exception
// CLASS + message + file:line — enough to debug without failing the main operation or emitting a noisy full
// stack trace. Captures error_log to a temp file (destination is otherwise set by php.ini).

test('caught-exception-log: records marker, context (key=value), and exception class/message', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'caught_') . '.log';
    $originalLog = (string) ini_get('error_log');
    ini_set('error_log', $tmp);

    try {
        log_caught_exception('notify.ticket', new RuntimeException('smtp refused'), ['event' => 'ticket.created', 'ticket' => 42]);

        $logged = (string) @file_get_contents($tmp);
        assert_contains_str('[notify.ticket]', $logged, 'the marker is recorded');
        assert_contains_str('event=ticket.created', $logged, 'context key=value is recorded');
        assert_contains_str('ticket=42', $logged, 'entity-id context is recorded');
        assert_contains_str('RuntimeException', $logged, 'the exception class is recorded (not just the message)');
        assert_contains_str('smtp refused', $logged, 'the exception message is recorded');
    } finally {
        ini_set('error_log', $originalLog);
        @unlink($tmp);
    }
});

// error-review F8: a per-request correlation id ties a user's "I got an error" to the exact server-log line.
// request_id() is stable within the request and prefixes every logged exception; it is also emitted as the
// X-Request-Id header and shown on the generic 500 page.
test('F8 (traceability): request_id is stable and prefixes the exception log for correlation', function (): void {
    $rid = request_id();
    assert_true(preg_match('/^[a-f0-9]{8}$/', $rid) === 1, 'request id is 8 hex chars, no PII');
    assert_same($rid, request_id(), 'the id is stable within the request (memoized), so header/page/log all match');

    $tmp = tempnam(sys_get_temp_dir(), 'req_') . '.log';
    $originalLog = (string) ini_get('error_log');
    ini_set('error_log', $tmp);
    try {
        log_caught_exception('t.marker', new RuntimeException('boom'), ['x' => 1]);
        assert_contains_str('[req:' . $rid . ']', (string) @file_get_contents($tmp), 'the log line carries the request id, so a reported reference finds the entry');
    } finally {
        ini_set('error_log', $originalLog);
        @unlink($tmp);
    }
});

// error-review-2 F3: the entry-point 500 handler must return JSON (with a reference) to an AJAX/fetch caller
// instead of an HTML page that breaks response.json(). request_wants_json is the content-negotiation decision.
test('F3 (traceability): request_wants_json detects AJAX/fetch callers so they get a JSON 500, not HTML', function (): void {
    assert_true(request_wants_json(['HTTP_ACCEPT' => 'application/json']), 'Accept: application/json → JSON');
    assert_true(request_wants_json(['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']), 'X-Requested-With: XMLHttpRequest → JSON');
    assert_true(request_wants_json(['HTTP_ACCEPT' => 'text/html, application/json;q=0.9']), 'a mixed Accept including json → JSON');
    assert_false(request_wants_json(['HTTP_ACCEPT' => 'text/html']), 'a plain browser page load → HTML');
    assert_false(request_wants_json([]), 'no headers → HTML (safe default)');
});

// error-review-2 F6: bootstrap silently swallowed ALL Throwable from its timezone lookup, so a real DB error
// looked like the expected "first-run, table not created yet" case. is_missing_table_error discriminates:
// ONLY a missing table is the silent first-run case; every other DB error is logged.
test('F6: is_missing_table_error flags only "table doesn\'t exist", not other DB errors', function (): void {
    $pdo = tvm_container()->get(PDO::class);

    $missing = null;
    try {
        $pdo->query('SELECT 1 FROM a_table_that_truly_does_not_exist_xyz');
    } catch (\PDOException $e) {
        $missing = $e;
    }
    assert_true($missing !== null && is_missing_table_error($missing), 'a real missing-table error is the expected first-run case (stays silent)');

    $otherDbError = null;
    try {
        $pdo->query('SELECT no_such_column_xyz FROM users');
    } catch (\PDOException $e) {
        $otherDbError = $e;
    }
    assert_true($otherDbError !== null && !is_missing_table_error($otherDbError), 'an unknown-column error is NOT missing-table → it would be logged, not swallowed');

    assert_false(is_missing_table_error(new \RuntimeException('not a PDO error')), 'a non-PDO exception is never a missing-table');
});
