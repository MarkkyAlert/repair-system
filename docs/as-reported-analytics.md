# As-reported (immutable) analytics — F1

## The decision

A reporting **period is immutable once it closes**. A ticket's contribution to a past period's
throughput, MTTR, technician attribution, SLA and CSAT must **not change** when that ticket is later
reopened, reassigned, or re-rated. The owner confirmed this ("as-reported"): the January dashboard a
manager screenshotted must read the same number in July.

The immutable source of truth is the append-only event log **`ticket_activity_logs`**
(`action='ticket_resolved'` written at resolve with `actor_id` = the resolving technician;
`action='ticket_reopened'` written at reopen). Rows are never updated or deleted in the normal flow.

## Why the current reports drift

Several reports read **mutable current-state columns** that `reopenTicket` rewrites:

- `reopenTicket` NULLs `first_response_at`, `resolved_at`, `completed_at`, and **resets** the single
  `ticket_sla_tracks` row (and a later re-rate overwrites the single `ticket_ratings` row in place).
- **Trend "resolved"** grouped by `DATE_FORMAT(t.resolved_at)` filtering `t.resolved_at IS NOT NULL`
  → a ticket resolved in January then reopened has `resolved_at = NULL` and **disappears from
  January's throughput/MTTR** entirely.
- **Technician performance** attributes resolved work by `t.assigned_technician_id` (current assignee)
  → a reassign moves a past resolution's credit to whoever is assigned now.
- **Reopen/FTF technician dimension** joins `t.assigned_technician_id` → reassign moves the reopen
  "blame" off the technician who actually resolved it.

## What is event-sourced vs. what needs a per-cycle snapshot

| Metric | Nature | As-reported source |
|--------|--------|--------------------|
| Throughput (resolved count) | **event** | one `ticket_resolved` event per closure, bucketed by `event.created_at` |
| MTTR | **event** | `resolve_event.created_at − t.requested_at` (`requested_at` is stable) |
| Technician attribution | **event** | `resolve_event.actor_id` (who actually resolved), not `assigned_technician_id` |
| Reopen / FTF cohort date | **event** (already) | `ticket_resolved.created_at` bounds the cohort window |
| SLA compliance per period | **snapshot** | needs the resolution due-date **in effect at that cycle** — lost when `reopenTicket` overwrites `resolution_due_at` and resets the SLA track |
| CSAT per period | **snapshot** | needs the rating **as it stood for that cycle** — lost when a re-rate overwrites the single `ticket_ratings` row |

Throughput/MTTR/attribution are recoverable from the log with **no schema change**. SLA and CSAT are
**not** fully recoverable for a reopened ticket without persisting a per-cycle snapshot, because the
first cycle's due-date / rating is destroyed on reopen/re-rate.

## Phase 1 (this PR) — event-source throughput, MTTR, attribution

No schema change. Reads shift from mutable columns to `ticket_activity_logs`.

### Multi-resolve de-dup rule (locked by test)

A ticket can carry several `ticket_resolved` events (resolve → reopen → resolve …). The rule:

1. **Per period, count each ticket once.** Collapse a ticket's resolve events to **one representative
   resolve per (ticket, bucket)** = `MAX(created_at)` within that bucket. A ticket resolved twice in
   the same month counts once for that month; a ticket resolved in two different months counts once in
   **each** — each period genuinely saw a closure. This mirrors the reopen report's existing round-5
   rule ("any `ticket_reopened` **within** the window counts", `reopen_rate_report_test.php:117`).
2. **MTTR** for a (ticket, bucket) uses that representative resolve time minus `requested_at`.
3. **SLA / CSAT** (Phase 1, best-effort until snapshots land) attach to the ticket's **final** resolve
   bucket only (`is_final` = the representative equals the ticket's global-max resolve), so the single
   current `resolution_due_at` / `ticket_ratings` row is counted **once**, never double-counted across
   the buckets a reopened ticket appears in.

### Cross-dimension resolved-total invariant (preserved)

`reopen_rate_report_test.php` locks: `Σ resolved` over the technician LEFT-JOIN dimension equals
`Σ resolved` over the department LEFT-JOIN dimension (a ticket has exactly one value per dimension, so
`COUNT(DISTINCT ticket_id)` summed over groups = total distinct resolved tickets). **Any** attribution
that splits a single ticket across two groups of one dimension breaks this. The rule above keeps each
ticket collapsed to a single representative per period, so attribution never multiplies a ticket across
a dimension's groups — the invariant holds. (This is exactly why a naïve `t.assigned_technician_id →
r.actor_id` swap on the reopen dimension is wrong: a ticket resolved twice by two actors would land in
two technician groups. Deferred to Phase 2 with the representative-resolver join.)

### Rounding

MTTR keeps the single pipeline the repo already uses (`ROUND(AVG(TIMESTAMPDIFF(MINUTE, …)), 1)` →
minutes → `/60` once in the service), so screen and export agree.

## What Phase 1 shipped

- `ReportRepository::getTicketTrendResolved` buckets on the `ticket_resolved` event
  (`ticket_trend_report_test.php` + `duration_backwards_timestamp_test.php` migrated to seed the event;
  new lock: a resolved-then-reopened ticket stays in its past bucket).
- `report_lineage_test.php` E2E: create→resolve→reopen→reassign through the real services, asserting the
  month's throughput is +1 after resolve and unchanged after reopen/reassign, that reopen NULLed
  `resolved_at`, that the `ticket_resolved` event survived, and that the reopen cohort is as-reported.

The `/reports` **overview mini** technician table stays a current-assignee quick snapshot (like the live
workload beside it); the dedicated Technician Performance report is the intended as-reported record and is
addressed in Phase 2 below.

## Phase 2 (scoped follow-up) — attribution surfaces + per-cycle SLA + CSAT snapshots

### Technician-report + reopen-dimension attribution (deferred — test-intent collisions)

Event-sourcing the **technician-report** resolved/MTTR columns and the **reopen technician dimension** to the
resolve-event actor is correct, but each collides with owner-documented behaviour that a faithful migration
must *rewrite*, not merely re-seed — so each belongs in its own reviewable change:

- `technician_performance_report_test.php`: "status='resolved' with NULL resolved_at → `resolved`=1, MTTR
  '-'" encodes the resolved_at-based rule. Under event-sourcing MTTR comes from the event, so this case's
  meaning changes (a status-resolved ticket with no resolve event is simply not counted).
- `reopen_rate_report_test.php`: "null technician → ยังไม่มอบหมาย" asserts the *current-assignee* semantics;
  resolver attribution puts the ticket under its resolver instead. The fix must join to the ticket's
  **representative** (latest) resolver — one resolver per ticket — to keep the cross-dimension resolved-total
  invariant, and the test's documented intent updated with the owner.

### Per-cycle SLA + CSAT snapshots

To make SLA and CSAT fully immutable, stop overwriting per-cycle state:

- **`ticket_sla_tracks`**: add a `cycle` column, drop `UNIQUE(ticket_id, metric_type)` in favour of
  `UNIQUE(ticket_id, metric_type, cycle)`. `reopenTicket` **appends** a new cycle instead of resetting;
  `markSlaAchieved` targets the latest cycle. Current-state SLA queries (summary "overdue", hotspot,
  backlog) filter to the **latest** cycle; as-reported period queries pick the cycle whose
  resolve/breach falls in the window.
- **`ticket_ratings`**: drop `UNIQUE(ticket_id)`; append a rating row per cycle; CSAT-by-period reads
  the rating whose cycle resolved in the window.
- **Reopen technician dimension**: join to the actor of the ticket's **representative** (latest-in-
  cohort) resolve event — one resolver per ticket — preserving the cross-dimension invariant.

This touches ~10 SLA/CSAT read sites and ~15 test files, so it is a separate reviewable change. Until
it lands, Phase 1 already makes the headline metric (throughput/MTTR/attribution) immutable, and SLA/CSAT
per period are **no worse than today** (today a reopened ticket vanishes from the period entirely).
