# E2E (Playwright)

Browser release tests for the critical cross-layer journeys. This is the outermost tier of the
pyramid: deliberately focused, on top of the PHP unit/integration suite in `../tests`.

| Spec | Journey | Cross-layer evidence |
|---|---|---|
| `tests/login.spec.ts` | Bad login → recovery → dashboard → logout | Real browser session handoff and revocation |
| `tests/guest-scan.spec.ts` | QR submit → tracking → admin convert → updated tracking | Public/admin UI, request+ticket DB state, notification, QR channel |
| `tests/ticket-lifecycle.spec.ts` | requester → admin → technician → requester | UI transitions plus ticket/work order/rating/activity/notification DB state |
| `tests/admin-user.spec.ts` | Admin create + two-tab stale update + requester denial | CRUD persistence, optimistic lock, cross-role boundary |
| `tests/import-preview.spec.ts` | CSV import success + stale preview rejection | Upload/preview/confirm, DB row, imported-user login, guard |
| `tests/attachment-upload.spec.ts` | Ticket upload → DB/disk → authenticated download | Multipart UI, persisted metadata/file, reload, anonymous denial |
| `tests/notification-preferences.spec.ts` | Toggle → save → DB → reload | Frontend preference state reconciles with persistence |
| `tests/password-reset.spec.ts` | Request → queued link → reset → replay denial → login | Safe queue-backed test inbox, one-time token, auth persistence |
| `tests/report-export.spec.ts` | Executive CSV → downloaded payload + export audit | File response, completed audit row, requester denial |
| `tests/http-api-regression.spec.ts` | 59 HTTP contract/security cases | Routing, session, CSRF, method guard, validation, role/IDOR, upload, DB state and cleanup |
| `tests/a11y.spec.ts` | Axe checks + keyboard focus traps | Browser-rendered accessibility guard for key pages |

## Run

```bash
cd e2e
npm ci
npx playwright install chromium
npm test
npm run test:api
npx playwright test --headed
npx playwright show-report
```

Playwright starts/stops the app itself: `php -S` against the **seeded test DB** (`repair_system_test`,
built by `../tests/setup_test_db.sh`) — never the dev DB. Nothing to start manually.

- Requires the test DB to exist and be seeded (`../tests/setup_test_db.sh`).
- `global-setup.ts` captures DB high-water marks and clears only guest/password-reset rate-limit keys.
- `global-teardown.ts` deletes marker rows, reset tokens, related notifications, uploaded files,
  flow-specific rate limits, and DB side effects created after the baseline (`audit_logs`,
  `login_attempts`, `email_queue`, `export_jobs`).
- Override the PHP/MySQL binaries with `PHP_BIN` / `MYSQL_BIN`; the port with `E2E_PORT`.
- Override the API JSONL destination with `QA_API_RUN_LOG`; request secrets and response tokens are masked.
- CI runs this suite in `.github/workflows/tests.yml` before the PHP suite.

## Setup gate (found by E2E, now fixed)

`public/index.php` sends requests to `/setup` until setup is done. It used to check **only** the
`setup_completed` flag, which the seed didn't set — so a seeded/admin-provisioned deploy looped
`/setup ↔ /login` (the app was unreachable over HTTP; the PHP suite missed it because it bypasses
HTTP). Fixed: the gate now treats setup as done when the flag is set **or** an active admin already
exists (`SetupController::requiresSetupRedirect`, covered by `tests/cases/setup_gate_test.php`), and
the seed sets `setup_completed=1`. E2E therefore needs no workaround — a seeded DB just works.

## Selectors

No brittle CSS-class selectors and no changes to app views: form fields by `name=`, buttons by
role+text, and status transitions by the stable per-status form ids (`#action-assign`,
`#action-start`, `#action-resolve`, `#action-complete`).
