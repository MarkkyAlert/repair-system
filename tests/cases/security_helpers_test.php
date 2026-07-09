<?php
declare(strict_types=1);

// Tests for two output-boundary security helpers that had no coverage:
//   • e()                   — the XSS escape wrapper used in every view (htmlspecialchars ENT_QUOTES/UTF-8)
//   • sanitize_return_path() — the open-redirect guard for post-login/redirect "return to" paths
// Both are pure functions, so allow+deny is exhaustive. Regression targets: e() silently stops escaping →
// stored/reflected XSS; sanitize_return_path stops rejecting absolute URLs → open redirect off a login link.

// ── G3: e() — HTML escaping ──────────────────────────────────────────────────

test('escape(deny): script/quote/amp metacharacters are all entity-encoded, none survive raw', function (): void {
    $out = e('<script>alert("x")</script>');
    assert_false(str_contains($out, '<script>'), 'the raw <script> tag must not survive');
    assert_contains_str('&lt;script&gt;', $out, 'angle brackets are entity-encoded');
    assert_contains_str('&quot;', $out, 'double quotes are entity-encoded (ENT_QUOTES)');

    assert_same('&amp;', e('&'), 'ampersand is encoded');
    assert_same('&#039;', e("'"), 'single quotes are encoded (ENT_QUOTES)');
    assert_same('&quot;', e('"'), 'double quotes are encoded');
});

test('escape(allow): plain text passes through unchanged and non-strings are coerced safely', function (): void {
    assert_same('สวัสดี ชาวโลก', e('สวัสดี ชาวโลก'), 'plain UTF-8 text is untouched');
    assert_same('123', e(123), 'an int is coerced to its string form');
    assert_same('', e(null), 'null coerces to an empty string, not a crash');
});

// ── G4: sanitize_return_path() — open-redirect guard ─────────────────────────

test('return-path(allow): a relative in-app path is preserved (and normalised to a single leading slash)', function (): void {
    assert_same('/tickets/5', sanitize_return_path('/tickets/5'), 'an absolute-path in-app URL is kept');
    assert_same('/tickets/5', sanitize_return_path('tickets/5'), 'a missing leading slash is added');
    assert_same('/tickets/5', sanitize_return_path('/tickets/5/'), 'a trailing slash is trimmed');
    assert_same('/', sanitize_return_path('/'), 'the root path stays root');
});

test('return-path(deny): an absolute http(s) URL is refused → falls back to /dashboard', function (): void {
    assert_same('/dashboard', sanitize_return_path('https://evil.com/steal'), 'https absolute URL is rejected');
    assert_same('/dashboard', sanitize_return_path('http://EVIL.com'), 'the scheme check is case-insensitive');
    assert_same('/dashboard', sanitize_return_path('   https://evil.com   '), 'surrounding whitespace does not smuggle a URL through');
    assert_same('/dashboard', sanitize_return_path(''), 'an empty path falls back to /dashboard');
});

test('return-path(deny): protocol-relative and backslash tricks collapse to a same-origin path', function (): void {
    // "//evil.com" would be a protocol-relative open redirect if it survived — it must collapse to one slash
    assert_same('/evil.com', sanitize_return_path('//evil.com'), 'protocol-relative // is collapsed to same-origin');
    assert_same('/evil.com', sanitize_return_path('/\\evil.com'), 'a backslash trick is normalised then collapsed');
    assert_false(str_starts_with(sanitize_return_path('//evil.com'), '//'), 'the result never keeps a protocol-relative prefix');
});
