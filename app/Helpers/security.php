<?php
declare(strict_types=1);

/**
 * Per-request CSP nonce. Generated once and cached for the life of the request so the value emitted in the
 * Content-Security-Policy header is the same one stamped on the inline <script> (theme-init). Each HTTP
 * request is a fresh PHP process, so the static cache is naturally per-request.
 */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }

    return $nonce;
}

/**
 * Content-Security-Policy for HTML responses.
 *
 * script-src carries the per-request nonce (for the one inline theme-init script) plus the Chart.js CDN, and
 * deliberately OMITS 'unsafe-inline' — that is the whole point: an injected <script> without the nonce cannot
 * run. style-src keeps 'unsafe-inline' because inline style="" attributes are pervasive in the views and
 * cannot be nonced (style injection is far lower risk than script execution), plus the Google Fonts sheet.
 */
function content_security_policy(string $nonce): string
{
    return implode('; ', [
        "default-src 'self'",
        "script-src 'self' https://cdn.jsdelivr.net 'nonce-{$nonce}'",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com",
        "img-src 'self' data:",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
    ]);
}
