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

## Phase 2 (this PR) — attribution surfaces + per-cycle SLA + CSAT snapshots

### Part A — resolver attribution (shipped)

Event-sourced the two attribution surfaces to the resolve-event **actor** (who actually resolved), not
`t.assigned_technician_id` (the current assignee, which a post-resolve reassign would move):

- **Technician Performance report** — new `ReportRepository::getTechnicianResolverStats()` aggregates the
  immutable `ticket_resolved` log keyed by actor: each `(resolver, ticket)` collapses to one representative
  resolve (`MAX(created_at)`), window filters on the event's `created_at`, backwards timestamps
  (`resolve < requested_at`) are dropped so MTTR is never negative — the same rules as the Phase 1 trend.
  `ReportService` overlays `resolved` / `mttr` / `resolution_base` onto each technician row (screen + export
  share `collectTechnicianPerformanceRows`, so parity holds). `assigned` / SLA / first-response / ratings /
  labor stay assignment-keyed; the `/reports` overview mini stays a current-assignee snapshot.
- **Reopen/FTF technician dimension** — `getReopenByDimension('technician')` now joins to the ticket's
  **representative resolver**: the actor of its latest-in-window `ticket_resolved` event, exactly ONE resolver
  per ticket (a correlated `rv.id = (… ORDER BY created_at DESC, id DESC LIMIT 1)`), so each ticket maps to a
  single technician group and the **cross-dimension resolved-total invariant** (Σ over technician == Σ over
  department) holds. Genuinely unattributed (→ ยังไม่มอบหมาย) only when the resolve event has a NULL actor.

Rewrote the two colliding test intents (not merely re-seeded), per the owner decision:
- `technician_performance_report_test.php`: "status='resolved' + NULL resolved_at → resolved=1, MTTR '-'"
  → under event-sourcing a status-resolved ticket with **no resolve event** is simply **not counted**
  (resolved=0, MTTR '-'); production always writes the event. Other seed-only tech tests were migrated to
  write the `ticket_resolved` event.
- `reopen_rate_report_test.php`: "null technician → ยังไม่มอบหมาย" (current-assignee semantics) → now proves
  resolver attribution, the NULL-actor unattributed path, and the preserved cross-dimension invariant.

E2E (`report_lineage_test.php`): tech 3 resolves, the ticket is reassigned to another tech — the Technician
Performance report and reopen dimension both keep the closure credited to tech 3, never the new assignee.

### Part B — per-cycle SLA + CSAT snapshots (shipped: schema + writers + readers; trend read checkpointed)

Schema: `ticket_sla_tracks` gains `cycle` (UNIQUE → `ticket_id, metric_type, cycle`); `ticket_ratings` gains
`cycle` (UNIQUE → `ticket_id, cycle`). `database/schema.sql` + `ALTER` on `repair_system` and
`repair_system_test`; existing rows default to cycle 1.

Writers (`TicketRepository`): `reopenTicket` **appends** a fresh pending SLA cycle (`currentTicketCycle()+1`)
instead of resetting the row, so a past cycle's due-date + met/breached verdict is frozen; `markSlaAchieved` /
`resetSlaTrack` target the **latest** cycle; `upsertTicketRating` **appends** a rating per cycle (idempotent
within a cycle) instead of UPDATE-in-place.

Readers: every **current-state** SLA/CSAT surface (dashboard + report summary overdue/breached, ticket detail
SLA + rating, sla-compliance / sla-breach current, problem-hotspot, "breached" list filters, pending-SLA
escalation list, technician + overview avg-rating) is pinned to the ticket's **latest** cycle via shared
`latestSlaCycleClause()` / `latestRatingCycleClause()` helpers — keeping every join fan-out-free now that
these tables are 1:many. **CSAT-by-period** reads (`getRatingByDimension` / `Distribution` / `Feedback`) stay
windowed on `tr.created_at`, which is now genuinely as-reported: an old cycle's rating keeps its original date
+ score, so a re-rate no longer restates a past period (locked by a new `csat_report_test.php` reconciliation
test). E2E (`report_lineage_test.php`): a full reopen cycle through the real services freezes the first
cycle's resolution-SLA snapshot byte-for-byte and stores the rating against the cycle actually rated.

**Note on the reopen→re-rate path:** the requester workflow only allows a reopen from `resolved` (a
`completed`/rated ticket is final — an unhappy requester duplicates instead), so a ticket is rated at most
once through the real flow. The per-cycle rating schema is still the correct immutable design and makes
CSAT-by-period fan-out-safe; the reconciliation test seeds two cycles directly to prove the period immutability.

### Checkpoint — Part B2: per-cycle TREND SLA/CSAT (documented follow-up)

The **trend** report's per-period SLA/CSAT columns (`getTicketTrendResolved`) still use the Phase-1
best-effort: the current `t.resolution_due_at` + the latest rating, attached to the ticket's **final** resolve
bucket (`is_final`; the rating join is now pinned to the latest cycle so it can't fan out). The per-cycle
snapshots are now **persisted** (reopen/re-rate append), so this is a pure **read** rework — but it needs a
`resolve-event ordinal → cycle` mapping (the event log has no `cycle` column) to attach each cycle's frozen
SLA verdict / rating to the bucket where that cycle resolved, plus migrating the trend tests (which seed the
`t.resolution_due_at` column + a single rating rather than per-cycle `ticket_sla_tracks` rows). Consequence
until it lands: the **first** period of a reopened ticket still shows no SLA/CSAT (its later cycle carries
them) — no worse than Phase 1, and the primary SLA compliance + CSAT-by-period reports are already
as-reported. Cleanest approach when picked up: compute per-bucket SLA directly from `ticket_sla_tracks`
resolution rows bucketed by `achieved_at`, and per-bucket CSAT from `ticket_ratings` bucketed by
`created_at`, merged by bucket in the service alongside the event-sourced resolved/MTTR.
