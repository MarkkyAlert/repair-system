# E2E (Playwright)

Browser tests for the **3 golden paths** — the flows that hurt most if they break. This is the
outermost tier of the pyramid: deliberately thin, on top of the PHP unit/integration suite in `../tests`.

| Spec | Golden path | Why E2E (not a PHP test) |
|---|---|---|
| `tests/login.spec.ts` | Login → dashboard | Exercises the real session handoff (`Session::regenerate()`), which the PHP tests can't run under CLI |
| `tests/guest-scan.spec.ts` | Guest scans a QR → submits a repair request | Public flow used by outsiders; highest blast radius |
| `tests/ticket-lifecycle.spec.ts` | requester → admin approve+assign → technician accept+start+resolve → requester complete | Cross-role journey through the real UI; multi-role via storageState |

## Run

```bash
cd e2e
npm install          # first time only (installs @playwright/test)
npx playwright install chromium   # first time only
npx playwright test               # run all 3 golden paths
npx playwright test --headed      # watch it drive the browser
npx playwright show-report        # open the last HTML report
```

Playwright starts/stops the app itself: `php -S` against the **seeded test DB** (`repair_system_test`,
built by `../tests/setup_test_db.sh`) — never the dev DB. Nothing to start manually.

- Requires the test DB to exist and be seeded (`../tests/setup_test_db.sh`).
- `global-setup.ts` marks the DB `setup_completed` (the seed omits it — see note) and clears the
  `guest_*` rate-limit keys so repeated runs don't self-throttle.
- `global-teardown.ts` deletes everything the run created (rows whose title/name starts with `E2E`).
- Override the PHP/MySQL binaries with `PHP_BIN` / `MYSQL_BIN`; the port with `E2E_PORT`.

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
