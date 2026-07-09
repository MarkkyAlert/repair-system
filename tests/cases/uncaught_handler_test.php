<?php
declare(strict_types=1);

// Locks the entry-point uncaught-exception logging. index.php's last-resort catch now calls
// log_uncaught_exception() before rendering the safe 500 page — otherwise an unexpected prod 500 leaves no
// server-side record (only a bare status in the access log) and the team is blind to it. Captures error_log
// to a temp file and asserts the exception's marker/class/message/trace are written. The full detail goes to
// the SERVER log only; the HTTP response stays a generic message via Response::abort (no client leak).

test('uncaught-handler: log_uncaught_exception writes the marker, class, message and trace to the error log', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'uncaught_') . '.log';
    $originalLog = (string) ini_get('error_log');
    ini_set('error_log', $tmp);

    try {
        log_uncaught_exception(new RuntimeException('boom from a deep layer'));

        $logged = (string) @file_get_contents($tmp);
        assert_contains_str('[uncaught]', $logged, 'the line is tagged so support can grep it');
        assert_contains_str('RuntimeException', $logged, 'the exception class is recorded');
        assert_contains_str('boom from a deep layer', $logged, 'the message is recorded');
        assert_contains_str('Stack trace', $logged, 'the stack trace is recorded (debuggable)');
    } finally {
        ini_set('error_log', $originalLog);
        @unlink($tmp);
    }
});
