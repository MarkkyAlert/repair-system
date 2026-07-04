# Tests

Zero-dependency regression suite (composer/PHPUnit are not installed in this environment).

## Run

```bash
php tests/run.php          # or: composer test
```

Exit code `0` = all pass, `1` = at least one failure (CI-friendly).

## Layout

- `run.php` — boots the app autoloader + DI container, runs every `cases/*.php`, reports pass/fail.
- `harness.php` — `test('name', fn)` registration + `assert_*` helpers (throw on failure) + `call_private()` (reflection for private pure methods).
- `cases/helpers_test.php` — pure helper functions: `normalize_date_range` (the reversed-range dashboard fix), `ticket_status_*` / `ticket_terminal_statuses` / `severity_values` / `asset_status_values` single-source lists + Thai labels, `truthy_input`, `paginate`.
- `cases/viewmodel_test.php` — service view-model builders extracted from templates this round: ticket filter chips / urgent alerts, dashboard `summarizeChart` / primary CTA / cron health, asset filter chips.

## Scope + next steps

These cover the **pure logic** touched by recent refactors — a safety net for the enum/label/date consolidations and the view-model extractions. They do **not** yet cover the DB-mutating ticket **workflow** (approve/assign/resolve/…); those need a seeded test database and are the pieces to add **before** splitting `TicketService`/`TicketRepository` into `TicketWorkflowService` / `TicketReadRepository`.

Porting to PHPUnit later is mechanical: `test()` → `public function testX()`, `assert_same()` → `$this->assertSame()`, `call_private()` → a reflection helper on the TestCase.
