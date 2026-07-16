<?php

declare(strict_types=1);

// error-review F1: a raw infrastructure error (PDOException — which extends RuntimeException) must NOT be
// caught by a controller's user-facing `catch (DomainException|RuntimeException)` and flashed/echoed to the
// client with its SQLSTATE message. Every such catch must be preceded by a `catch (\PDOException) { throw; }`
// guard so the DB error propagates to the entry-point handler, which logs it (log_uncaught_exception) and
// returns a generic 500 — proven separately in uncaught_handler_test. The app's OWN curated Thai
// RuntimeExceptions ("ไม่สามารถสร้างไฟล์ PDF ได้" etc.) still reach the user catch and show their friendly
// message; only the raw DB exception is diverted.
//
// Source-lock (same style as the route-inventory security guards): removing a guard reds this.

test('F1: every controller user-facing catch is shielded by a PDOException re-throw guard (no raw SQL leak)', function (): void {
    $controllers = glob(dirname(__DIR__, 2) . '/app/Controllers/*.php') ?: [];
    assert_true($controllers !== [], 'controllers are found');

    $totalCombined = 0;
    $totalGuarded = 0;
    $unguarded = [];

    foreach ($controllers as $file) {
        $src = (string) file_get_contents($file);

        // every user-facing catch that also swallows RuntimeException (and thus PDOException)
        $combined = preg_match_all('/catch \(DomainException\s*\|\s*RuntimeException/', $src);
        // ...each of which must be immediately preceded by a PDOException re-throw guard
        $guarded = preg_match_all(
            '/catch \(\\\\PDOException \$\w+\)\s*\{\s*throw \$\w+;.*?\}\s*catch \(DomainException\s*\|\s*RuntimeException/s',
            $src
        );

        $totalCombined += $combined;
        $totalGuarded += $guarded;
        if ($guarded < $combined) {
            $unguarded[] = basename($file) . " ($guarded/$combined guarded)";
        }
    }

    assert_true($totalCombined > 0, 'the combined user-facing catch pattern still exists to guard');
    assert_same([], $unguarded, 'every DomainException|RuntimeException catch must have a preceding PDOException re-throw guard: ' . implode(', ', $unguarded));
    assert_same($totalCombined, $totalGuarded, "all $totalCombined user-facing catches are PDO-guarded");
});
