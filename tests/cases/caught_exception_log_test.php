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
