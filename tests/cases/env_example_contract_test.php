<?php

declare(strict_types=1);

// ux-refactor F5: .env.example must be the COMPLETE contract of the env keys the app reads, so a semi-dev
// buyer on a shared host can find every knob (paths, timeouts, write dirs) instead of editing source or
// opening a support ticket. This fails the suite if config/ or the CLI tools read an env key that
// .env.example does not mention.

test('env-contract(F5): every env key the app reads is documented in .env.example', function (): void {
    $root = dirname(__DIR__, 2);

    $sources = array_merge(glob($root . '/config/*.php') ?: [], glob($root . '/bin/*.php') ?: []);
    $srcKeys = [];
    foreach ($sources as $file) {
        $code = (string) file_get_contents($file);
        preg_match_all("/(?:Env::(?:get|bool|int)\\(|(?<![\\w])env\\(|getenv\\()'([A-Z][A-Z0-9_]{2,})'/", $code, $m);
        foreach ($m[1] as $key) {
            $srcKeys[$key] = true;
        }
    }
    assert_true(count($srcKeys) > 20, 'sanity: found the env keys the app reads (' . count($srcKeys) . ')');

    $env = (string) file_get_contents($root . '/.env.example');

    $missing = [];
    foreach (array_keys($srcKeys) as $key) {
        // documented = a "KEY=" line OR a mention anywhere (e.g. an explanatory comment line)
        if (preg_match('/(^|[^A-Z0-9_])' . preg_quote($key, '/') . '([^A-Z0-9_]|$)/m', $env) !== 1) {
            $missing[] = $key;
        }
    }
    sort($missing);
    assert_same([], $missing, '.env.example is missing supported env keys: ' . implode(', ', $missing));
});
