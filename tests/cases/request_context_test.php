<?php

declare(strict_types=1);

use App\Core\Request;

// Pre-ship sweep #3: request() is declared ?Request but threw when no Request was bound, because Request cannot be
// autowired (its constructor needs method/path/query/input/server). AuditLogger::record() calls request(), so in a
// no-HTTP-request context — a cron or CLI writing an audit log — record() blew up instead of falling back to
// $_SERVER. In the suite this stayed hidden: whichever test ran first bound a Request::capture() and left it in the
// container, so later audit tests inherited it (false green). run.php now clears the Request binding before every
// test, and request() returns null instead of throwing. This locks the contract directly.

test('isolation(request): request() returns null in a no-request context — it must never throw', function (): void {
    $container = tvm_container();
    $instances = new ReflectionProperty($container, 'instances');
    $instances->setAccessible(true);

    $before = $instances->getValue($container);
    $hadRequest = array_key_exists(Request::class, $before);
    $originalRequest = $before[Request::class] ?? null;

    try {
        // Ensure no Request is bound, exactly like a cron/CLI process (and like run.php's per-test reset).
        $bag = $instances->getValue($container);
        unset($bag[Request::class]);
        $instances->setValue($container, $bag);

        // The contract: ?Request means "maybe none", not "throw when none".
        assert_same(null, request(), 'request() must yield null when nothing is bound, not throw a container error');

        // And a bound Request is still returned — the null path is the only thing that changed.
        $container->instance(Request::class, new Request('GET', '/x', [], [], ['REMOTE_ADDR' => '10.0.0.1']));
        $resolved = request();
        assert_true($resolved instanceof Request, 'a bound Request is still returned normally');
        assert_same('10.0.0.1', (string) ($resolved->server['REMOTE_ADDR'] ?? ''), 'and it is the one that was bound');
    } finally {
        if ($hadRequest && $originalRequest instanceof Request) {
            $container->instance(Request::class, $originalRequest);
        } else {
            $bag = $instances->getValue($container);
            unset($bag[Request::class]);
            $instances->setValue($container, $bag);
        }
    }
});
