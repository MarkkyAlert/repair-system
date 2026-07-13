# Security guards & mutation checklist

Every security control in this codebase is backed by a test that **fails when the guard is removed** — this
is the project's form of mutation testing. This file is the inventory: each guard, where it lives, the test
that locks it, and the exact mutation a reviewer can apply to prove the test still bites (make it RED, then
restore). Run the whole suite with `php tests/run.php` (see [tests/README.md](../tests/README.md)).

The discipline for any new guard: **break the guard first, watch only its test go red, then restore and
commit.** If a change touches a control below without reddening its row, the lock has rotted — fix the test.

| # | Guard | Enforced in | Locked by | Mutation to prove the lock (make RED, then restore) |
|---|-------|-------------|-----------|------------------------------------------------------|
| 1 | **Role gate on every `/admin/*` route** (BAC) | `require_role()` at controller entry / `adminUpdate()` / `handleUpdate()` | `admin_route_gate_test.php` | Delete the `require_role(` call from any admin handler |
| 2 | **CSRF on every state-changing POST** | `csrf_validate()` / `handleUpdate()` / `adminUpdate()` | `csrf_route_gate_test.php`, `csrf_test.php`, `guest_track_csrf_test.php` | Remove `csrf_validate()` from a mutation handler (e.g. `CommentsController::update`) |
| 3 | **Session-fixation defense** (rotate id on identity change) | `Session::regenerate()` in `AuthService::attemptLogin/logout/changePassword` + `RememberMeService::attemptRestore` | `session_fixation_test.php` | Delete `Session::regenerate()` from any of those four methods |
| 4 | **Login anti-enumeration** (generic error) | `AuthService::attemptLogin` — one message for wrong-password / unknown / disabled | `auth_test.php` | Make the unknown-user branch throw a distinct message |
| 5 | **Login constant-time** (no timing oracle) | `AuthService::attemptLogin` verifies a dummy hash for unknown accounts | `auth_test.php` (counting `password_verify` shadow) | Restore the `!$user \|\| password_verify(...)` short-circuit |
| 6 | **Password-reset side-effect parity** | `AuthService::createPasswordReset` — no token/email for unknown/inactive | `auth_test.php` | Drop the `!(bool) $user['is_active']` guard so inactive leaks a token |
| 7 | **Reset token single-use + expiry** | `AuthService::resetPassword` → `PasswordResetRepository::resetPasswordUsingToken` (delete-on-use, expiry check) | `auth_test.php` | Skip the row delete after a successful reset / ignore `expires_at` |
| 8 | **Login rate limiting** (per-account + per-IP spraying) | `LoginRateLimiter` (file-backed) | `auth_test.php` | Bypass the `tooManyAttempts` check before the cap |
| 9 | **Remember-me token rotation/replay** | `RememberMeService` (sha256-hashed token, rotate on restore) | `remember_me_test.php` | Reissue the same raw token instead of rotating |
| 10 | **Upload MIME whitelist** (content-sniffed, not extension) | `AttachmentService::validateUploads` (finfo whitelist; ext derived from MIME) | `attachment_test.php` | Accept a PHP script renamed `evil.jpg` / trust the client extension |
| 11 | **Attachment IDOR** (visibility-scoped access) | `AttachmentService::getVisibleAttachment` → `findVisibleTicketById` | `attachment_test.php` | Fetch by attachment id without the ticket-visibility join |
| 12 | **Output escaping** (stored/reflected XSS) | `e()` in every view + rendered comment sink | `security_helpers_test.php`, `render_xss_test.php` | Swap an `e($x)` view echo for a raw `$x` |
| 13 | **Content-Security-Policy** (no `unsafe-inline` script) | `content_security_policy()` + per-response nonce (`View::render`) | `csp_test.php` | Add `'unsafe-inline'` to `script-src` |
| 14 | **Static security headers** (nosniff / X-Frame / Referrer) | `security_headers()` + `emit_security_headers()` (`public/index.php`) | `csp_test.php` | Change/remove a header value in `security_headers()` |
| 15 | **Open-redirect defense** | `sanitize_return_path()` — same-origin only | `security_helpers_test.php` | Stop rejecting absolute `http(s)://` URLs |
| 16 | **CSV/formula injection** in exports | `sanitize_export_cell()` (`ReportExporter`) | `export_cell_sanitize_test.php`, `export_builder_sanitize_test.php` | Stop prefixing `=`/`+`/`-`/`@` leading cells |
| 17 | **Debug-in-production refusal** | `is_unsafe_production_debug()` fail-fast (`public/index.php`) | `config_guard_test.php` | Let the app serve with `APP_DEBUG=true` under `APP_ENV=production` |
| 18 | **Setup lockout after first run** | `SetupController::requiresSetupRedirect` (admin-exists / flag) | `setup_gate_test.php` | Allow `/setup` to re-run once an admin exists |
| 19 | **Report/ticket data visibility** (row-level authz) | `ReportService` scope + `TicketPolicy` owner scoping | `report_access_test.php`, `ticket_visibility_test.php`, `notification_test.php` | Return rows outside the viewer's scope |

## Not covered by the CI suite (out of scope, tracked separately)

- **HTTP-multipart E2E** (real upload / spoofed-MIME reject / unauthorized download over the wire) — belongs in
  the Playwright `e2e/` suite (`@axe-core/playwright` net already lives there), not the zero-dependency PHP
  harness. Service-level upload security **is** covered (rows 10–11).
- **Deploy config** — HTTPS enablement, `.htaccess` being honored by the web server, `.env` secret hygiene,
  `composer audit` for dependency CVEs. These are ops/runtime concerns, not code, and can't be asserted here.
  The code supports them (e.g. `session.secure` follows HTTPS; security headers are emitted in code — row 14).

## Residuals (accepted, documented)

- **Password-reset timing** — an active account does ~2 extra DB writes vs. an unknown one. The response text,
  side effects (row 6), and rate-limit are equalized; the small, noisy, rate-limited DB-write delta is an
  accepted residual rather than padded with a latency floor. See `AuthService::createPasswordReset`.
