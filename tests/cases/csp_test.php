<?php
declare(strict_types=1);

// Tests for the Content-Security-Policy builder (content_security_policy) and its per-request nonce
// (csp_nonce). The security value lives in script-src: it must carry the request nonce and the Chart.js CDN
// but must NOT allow 'unsafe-inline', so an injected <script> without the nonce cannot execute. style-src
// intentionally keeps 'unsafe-inline' — inline style="" attributes are pervasive and cannot be nonced.

/** Return the single directive beginning with "$name " from a "; "-joined policy (or '' if absent). */
function csp_directive(string $policy, string $name): string
{
    foreach (explode('; ', $policy) as $directive) {
        if (str_starts_with($directive, $name . ' ')) {
            return $directive;
        }
    }

    return '';
}

test('csp(script-src): carries the nonce + Chart.js CDN and refuses unsafe-inline', function (): void {
    $scriptSrc = csp_directive(content_security_policy('NONCEtest123'), 'script-src');

    assert_contains_str("'self'", $scriptSrc, 'script-src allows self');
    assert_contains_str('https://cdn.jsdelivr.net', $scriptSrc, 'script-src allows the Chart.js CDN');
    assert_contains_str("'nonce-NONCEtest123'", $scriptSrc, 'script-src carries the per-request nonce');
    assert_false(
        str_contains($scriptSrc, "'unsafe-inline'"),
        'script-src must NOT allow unsafe-inline — that would let an injected inline script run'
    );
});

test('csp(other directives): sane defaults lock the page down; fonts + inline styles allowed by design', function (): void {
    $policy = content_security_policy('n0nce');

    assert_contains_str("default-src 'self'", $policy, 'default-src self');
    assert_contains_str("object-src 'none'", $policy, 'object-src none (no plugins)');
    assert_contains_str("base-uri 'self'", $policy, 'base-uri self (no <base> hijack)');
    assert_contains_str("frame-ancestors 'self'", $policy, 'frame-ancestors self (anti-clickjacking)');
    assert_contains_str("form-action 'self'", $policy, 'form-action self');

    $styleSrc = csp_directive($policy, 'style-src');
    assert_contains_str("'unsafe-inline'", $styleSrc, 'style-src allows unsafe-inline by design (pervasive inline styles)');
    assert_contains_str('https://fonts.googleapis.com', $styleSrc, 'style-src allows the Google Fonts stylesheet');
    assert_contains_str('https://fonts.gstatic.com', csp_directive($policy, 'font-src'), 'font-src allows Google Fonts files');
});

test('csp(nonce): a per-request nonce is non-empty and stable within the request', function (): void {
    $a = csp_nonce();
    $b = csp_nonce();
    assert_same($a, $b, 'csp_nonce() is cached per request so the header and inline script match');
    assert_true(strlen($a) >= 16, 'the nonce carries real entropy');
    assert_contains_str("'nonce-{$a}'", content_security_policy($a), 'the policy embeds the live nonce');
});
