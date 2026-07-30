<?php
declare(strict_types=1);

// static-review #2: Session::start() set the cookie lifetime (7200s = 2h) and the app enforces a 60-minute idle
// timeout, but it never set session.gc_maxlifetime — so PHP's default of 1440s (24 minutes) governed server-side
// session data. On shared hosting whose GC honours that default, a user idle for ~25 minutes could have their
// session file reaped and be logged out, well before the intended 60-minute idle window. Session::start() now
// raises gc_maxlifetime to at least max(cookie lifetime, idle timeout). This is a floor under PHP's GC; the
// app-level idle check (AuthMiddleware + Session::isIdleExpired) still owns the actual timeout decision.

test('session(#2): gc_maxlifetime is raised to the intended lifetime, not left at PHP default 1440', function (): void {
    $cfg = (array) config('session', []);
    $cookie = (int) ($cfg['lifetime'] ?? 7200);
    $idleSeconds = (int) ($cfg['idle_timeout_minutes'] ?? 0) * 60;
    $expected = max($cookie, $idleSeconds, 1440);

    // bootstrap.php ran Session::start($config['session']) at boot, so the running INI already reflects the fix.
    assert_same(
        $expected,
        (int) ini_get('session.gc_maxlifetime'),
        'gc_maxlifetime must equal max(cookie lifetime, idle timeout, 1440) — leaving PHP default 1440 reaps sessions ~24min early'
    );

    // With the shipped config (2h cookie, 60m idle) the raised value must comfortably clear the 24-minute default
    // that caused the premature logout, and must never fall below the app's own idle window.
    assert_true($expected >= $idleSeconds, 'the server-side lifetime must cover the app-level idle window');
    assert_true($expected > 1440, 'the shipped config raises gc_maxlifetime well above the 24-minute PHP default');
});

test('session(#2): the two timeout layers agree — PHP GC floor never expires before the app idle window', function (): void {
    // The fix must not fight M-9 (the app-level idle timeout). gc_maxlifetime is a floor that keeps PHP from
    // reaping too early; Session::isIdleExpired is the real gate. Prove the floor sits at/above the idle window so
    // a session cannot vanish server-side while the app still considers it live.
    $cfg = (array) config('session', []);
    $idleMinutes = (int) ($cfg['idle_timeout_minutes'] ?? 60);
    $gc = (int) ini_get('session.gc_maxlifetime');
    assert_true($gc >= $idleMinutes * 60, 'PHP GC lifetime must be >= the app idle window so the two layers do not contradict');

    // and the app idle check itself still trips exactly at the configured window (unchanged behaviour)
    $_SESSION['_last_activity'] = time() - ($idleMinutes * 60) - 5;
    assert_true(App\Core\Session::isIdleExpired($idleMinutes), 'the app-level idle check still expires a session past the window');
    $_SESSION['_last_activity'] = time() - 5;
    assert_false(App\Core\Session::isIdleExpired($idleMinutes), 'a freshly-touched session is still considered live');
    unset($_SESSION['_last_activity']);
});
