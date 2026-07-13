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

/**
 * Static (non-CSP) security response headers. These mirror the `Header always set` lines in public/.htaccess,
 * but living in application code means a buyer who deploys behind nginx, or on Apache with AllowOverride None,
 * still gets them — the .htaccess copy is a belt-and-suspenders for Apache, not the only source. CSP is emitted
 * separately (View::render) because it carries a per-request nonce; these three are constant so they live here.
 *
 * @return array<string, string>
 */
function security_headers(): array
{
    return [
        // stop browsers MIME-sniffing a response into a more dangerous type (e.g. an inline attachment → HTML)
        'X-Content-Type-Options' => 'nosniff',
        // legacy clickjacking defense; CSP frame-ancestors 'self' covers modern browsers, this covers the rest
        'X-Frame-Options' => 'SAMEORIGIN',
        // don't leak full URLs (with ids/tokens) in the Referer to other origins
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];
}

/**
 * Emit security_headers() on the current response. Called once per request (public/index.php) so every
 * response type — HTML, JSON, file download, redirect — inherits them, regardless of web server. No-op once
 * output has begun (CLI/tests), matching the CSP guard in View::render.
 */
function emit_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    foreach (security_headers() as $name => $value) {
        header($name . ': ' . $value);
    }
}
