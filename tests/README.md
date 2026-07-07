# Tests

Zero-dependency regression suite (composer/PHPUnit are not installed in this environment).

## Run

```bash
./tests/setup_test_db.sh   # once — creates repair_system_test (schema + seed)
php tests/run.php          # or: composer test
```

The suite runs against an **isolated `repair_system_test` database** (set in `run.php` via `$_ENV['DB_NAME']`), never the dev DB. Workflow tests insert a fresh ticket, drive the real service, assert, then delete it (child rows cascade) — repeatable, no residue. Exit code `0` = all pass, `1` = at least one failure (CI-friendly).

## Pre-push hook (run tests before every push)

`.githooks/pre-push` runs the suite before each `git push` and **aborts the push if anything fails**. It first checks that `repair_system_test` is reachable and has the schema loaded (via `tests/check_db.php`); if not, it tells you to run `./tests/setup_test_db.sh` instead of failing with a cryptic PDO error mid-suite.

Enable it **once per clone** — the hook lives in the repo, but `core.hooksPath` is stored in the local `.git/config`, which is not cloned:

```bash
git config core.hooksPath .githooks
```

- POSIX `sh`; works on macOS/Linux. It finds `php` on `PATH`, or set `PHP_BIN=/path/to/php git push`.
- **Emergency bypass:** `git push --no-verify` skips the hook. Use only when you genuinely must push without a green suite — CI runs the same tests on every push/PR anyway, so a red push will still be caught there.

## Layout

- `run.php` — boots the app autoloader + DI container, runs every `cases/*.php`, reports pass/fail.
- `harness.php` — `test('name', fn)` registration + `assert_*` helpers (throw on failure) + `call_private()` (reflection for private pure methods).
- `cases/helpers_test.php` — pure helper functions: `normalize_date_range` (the reversed-range dashboard fix), `ticket_status_*` / `ticket_terminal_statuses` / `severity_values` / `asset_status_values` single-source lists + Thai labels, `truthy_input`, `paginate`.
- `cases/viewmodel_test.php` — service view-model builders extracted from templates this round: ticket filter chips / urgent alerts, dashboard `summarizeChart` / primary CTA / cron health, asset filter chips.
- `cases/workflow_test.php` — **integration** tests driving the real ticket lifecycle against the test DB: approve, separation-of-duties guard, reject, assign, full happy path (approve→assign→accept→start→resolve), requester cancel.

## Scope + next steps

Pure-logic tests cover the enum/label/date consolidations + view-model extractions. Workflow tests cover the ticket lifecycle transitions — the regression net now in place **before** splitting `TicketService` / `TicketRepository` into `TicketWorkflowService` / `TicketReadRepository`.

Porting to PHPUnit later is mechanical: `test()` → `public function testX()`, `assert_same()` → `$this->assertSame()`, `call_private()` → a reflection helper on the TestCase.
