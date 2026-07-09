<?php
declare(strict_types=1);

// Locks the production-debug config guard. is_unsafe_production_debug() decides whether the web entry point
// (public/index.php) should refuse to serve: APP_DEBUG=true under APP_ENV=production makes the uncaught
// handler rethrow and leak stack traces to clients, so that combination is the only unsafe one — local dev
// (APP_ENV=local) with debug on is fine.

test('config-guard: only production + debug is unsafe; every other combo is allowed', function (): void {
    assert_true(is_unsafe_production_debug('production', true), 'production + debug leaks stack traces → unsafe');
    assert_false(is_unsafe_production_debug('production', false), 'production without debug is safe');
    assert_false(is_unsafe_production_debug('local', true), 'local dev with debug is fine');
    assert_false(is_unsafe_production_debug('local', false), 'local without debug is fine');
});
