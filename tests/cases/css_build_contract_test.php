<?php

declare(strict_types=1);

// ux-refactor-2 F1: the shipped build command was broken from a clean checkout. build-css.sh/.bat called
// tools/tailwindcss, but that binary is git-ignored and never fetched — running ./build-css.sh died with
// "No such file or directory", yet the docs told buyers to run it. The scripts now self-fetch the PINNED
// Tailwind standalone binary (no Node/npm) before building. These guards keep that contract from rotting back.

test('css-build(F1): build-css.sh self-fetches the pinned Tailwind binary (not a bare call to a missing file)', function (): void {
    $root = dirname(__DIR__, 2);
    $sh = (string) file_get_contents($root . '/build-css.sh');

    // Pins an explicit version (so a rebuild reproduces the version the template was built with).
    assert_true(preg_match('/TAILWIND_VERSION="?3\.\d+\.\d+"?/', $sh) === 1, 'build-css.sh must pin an explicit Tailwind 3.x version');
    // Only runs the binary when present; otherwise downloads it first.
    assert_true(str_contains($sh, 'if [ ! -x'), 'build-css.sh must guard on the binary existing before running it');
    assert_true(str_contains($sh, 'releases/download'), 'build-css.sh must download the standalone binary from the Tailwind releases URL');
    assert_true(
        str_contains($sh, 'curl') || str_contains($sh, 'wget'),
        'build-css.sh must fetch the binary with curl or wget'
    );
    // Handles the buyer's OS/arch rather than assuming this dev machine.
    assert_true(str_contains($sh, 'uname'), 'build-css.sh must detect the OS/arch for the right standalone build');
});

test('css-build(F1): build-css.bat self-fetches the pinned binary too, at the SAME version', function (): void {
    $root = dirname(__DIR__, 2);
    $sh = (string) file_get_contents($root . '/build-css.sh');
    $bat = (string) file_get_contents($root . '/build-css.bat');

    assert_true(str_contains($bat, 'if not exist'), 'build-css.bat must download the binary when it is missing');
    assert_true(str_contains($bat, 'releases/download'), 'build-css.bat must download from the Tailwind releases URL');

    // The two scripts must pin the SAME version — a drift would build two different stylesheets per-OS.
    preg_match('/TAILWIND_VERSION="?(3\.\d+\.\d+)"?/', $sh, $shV);
    preg_match('/TAILWIND_VERSION=(3\.\d+\.\d+)/', $bat, $batV);
    assert_true(($shV[1] ?? 'a') === ($batV[1] ?? 'b'), 'build-css.sh and build-css.bat must pin the same Tailwind version');
});

test('css-build(F1): the compiled stylesheet ships so a buyer who never builds still has styles', function (): void {
    $root = dirname(__DIR__, 2);
    $built = $root . '/public/assets/css/app.css';
    assert_true(is_file($built), 'the compiled public/assets/css/app.css must ship with the template');
    assert_true(filesize($built) > 50000, 'the shipped stylesheet must be the real compiled build, not a stub');

    // The docs must not tell a buyer to run a build that assumes a pre-placed binary — the script fetches it.
    $doc = (string) file_get_contents($root . '/docs/testing-guide.md');
    assert_true(str_contains($doc, 'build-css'), 'the setup guide should reference the build script');
});
