# 05 — Analytics

Closes **A1–A9** ([01](01-audit-findings.md) §1.3). Analytics stays its own system with its own classes. What changes is that the **server** can record
an event (so a payment, a sign-up or a booking appears), that the numbers are computed by the database instead of in PHP memory, and that the public
endpoint accepts only what the application defines.

## 5.1 Two kinds of analytics, and the rule that keeps them apart

| Kind | Source | Answers | Where it lives |
|---|---|---|---|
| **Traffic** | The visitor's browser (`page_load`, `form_loaded`, `form_completed`, `subscriber_signup`) | How many people came, from where, on what, and how far they got | `laravel_analytics_events`, `source = 'browser'` |
| **Activity** | The server, from an `Activity` ([06](06-activity-fan-out.md)) | What happened on my site this week: payments, registrations, bookings, sign-ups | the same table, `source = 'server'` |
| **Reports** | A module's own tables | Revenue, refunds, attendance, no-shows — figures a person acts on or reconciles | `EventReportingService`, `AppointmentReportingService`, the money ledger — **unchanged** |

**The rule:** analytics counts and charts; it is never the source of truth for money. Revenue and refunds are summed from invoices (events step 13 §13.2:
*"Money comes from orders, never from ticket prices… Reports read the rows"*). A server-recorded `payment_succeeded` carries `value_cents` so a dashboard
card can show "£1,240 this week", and the card says *"from recorded activity — see Reports for the reconciled figure"*. A scheduled check may compare the two
(§5.9); a mismatch is a bug to investigate, not a number to show.

## 5.2 How it works today

- **Write path:** the browser calls `POST /portal/api/v1/analytics/events` (`window.Portal.analytics`, `resources/js/external/api/analytics/api.ts`).
  The builder's script runner calls `initPageLoad(route)` on every published page (`resources/js/portal/builder/core/component_scripts/script_runner.ts:19–44`).
  The controller validates `module` and `event` as strings ≤ 64 characters and stores whatever `metadata` array it is given.
- **Read path:** `GET …/analytics/summary?days=N` (dashboard auth) loads every event in the window and computes stats, a per-day chart, traffic sources,
  devices, browsers, countries and top pages in PHP. `GET …/analytics/events` lists raw events.
- **afs-connect:** `WwwAnalyticsProvider` reports `page_loads_30d` for the connect index.
- **Rollups:** a table and a model exist; nothing writes them.
- **Data:** development seeders insert 500 page loads, 45 form loads, 15 completions and 50 sign-ups on any non-production database with an empty table.

## 5.3 Schema — additive, on tables this app owns

One migration, `…_extend_laravel_analytics.php`, guarded with `Schema::hasColumn` (AGENTS.md §5: an `ALTER` on a table your own app owns is the normal way).

`laravel_analytics_events` gains:

| Column | Type | Purpose |
|---|---|---|
| `source` | string(16), default `browser`, index | `browser` \| `server` \| `seed` (the seeders mark their rows `seed` — **A9**) |
| `occurred_at` | timestamp, nullable | When it happened (a server event can be recorded late); falls back to `created_at` |
| `value_cents` | bigint, nullable | Money, when there is some |
| `currency` | char(3), nullable | |
| `subject_ref` | string(64), nullable, index | The invoice, submission, registration |
| `dedupe_key` | string(128), nullable, **unique** | A replayed webhook records once |
| `visitor_id` | string(64), nullable | Promoted from `metadata.visitor_id` for browser events |
| `session_id` | string(64), nullable | **New** — lets bounce rate be real (§5.6) |
| `source_label`, `device`, `browser`, `country` | string(64/32/32/8), nullable | Promoted from `metadata` so the summary can `GROUP BY` |
| `duration_seconds` | int, nullable | Promoted from `metadata.duration` |

Indexes (named explicitly): `analytics_events_mod_evt_at_idx (module, event, occurred_at)`, `analytics_events_source_at_idx (source, occurred_at)`.
`metadata` stays as the overflow. The browser endpoint keeps accepting everything it accepts today and fills the promoted columns from `metadata` when
present, so **no deployed page script changes**. A backfill command copies the promoted values out of existing `metadata` rows in chunks.

`laravel_analytics_daily_rollups` gains `resource_reference` (string(64), default `''`), `value_cents` (bigint, default 0) and `source`, and its unique key
changes from `(rollup_date, module, event)` to `(rollup_date, module, event, resource_reference, source)` — named `analytics_rollups_day_key_uq`. The empty
string, not `NULL`, means "no resource", because a MySQL unique index treats each `NULL` as distinct.

A second small table, `laravel_analytics_daily_dimensions (rollup_date, module, event, dimension, value, event_count)` with a unique key over those five,
holds the breakdowns the screen shows (`device`, `browser`, `country`, `source_label`, `route`) so the dashboard never scans raw events for them.

## 5.4 `AnalyticsRecorder` — the server side

```php
final class AnalyticsRecorder
{
    public function record(Activity $activity, EventDefinition $event): void;   // never throws; logs a warning
}
```

- Writes one `laravel_analytics_events` row: `source = 'server'`, `module`, `event`, `resource_reference = scopeRef`, `subject_ref`, `value_cents`,
  `currency`, `occurred_at`, `dedupe_key`.
- `metadata` is built **only** from the keys `EventDefinition::$analyticsKeys` lists. Everything else in `Activity::$data` is dropped. **No names, e-mail
  addresses or free text** — references, amounts and enumerations only (rule 7, [00](00-overview-and-verdict.md) §0.6).
- On a duplicate `dedupe_key` it does nothing and returns.
- It is called by `ActivityPublisher`, never by a module directly ([06](06-activity-fan-out.md)).

## 5.5 Rollups and retention — `analytics:rollup`

A scheduled command (`routes/console.php`, hourly, `withoutOverlapping`, `onOneServer`) recounts a day from raw events and **upserts** the result:

- For `D` = today and yesterday: `INSERT … SELECT module, event, resource_reference, source, COUNT(*), COALESCE(SUM(value_cents), 0) … GROUP BY …
  ON DUPLICATE KEY UPDATE event_count = VALUES(event_count), value_cents = VALUES(value_cents)`; the same for the dimensions table. A recount (not an
  increment) is idempotent, so a repeated or overlapping run cannot double-count.
- Raw events (`browser` and `server`) older than **90 days** are deleted by the data-lifecycle job ([08](08-data-lifecycle.md) §8.5) only when their day has a rollup **and** the report for
  their quarter exists, so the quarterly report can be exact. Rollups are kept for 25 months; the quarterly reports ([08](08-data-lifecycle.md) §8.6) are kept forever.
- `--date=YYYY-MM-DD` and `--backfill` recompute history; `--dry-run` prints the counts.

## 5.6 The summary that scales (A4, A5)

`AnalyticsApiController::summary()` is rewritten to read:

- **Completed days** from the rollup and dimension tables (a handful of rows per day);
- **Today** from raw events with `COUNT(*)` / `GROUP BY` queries on the indexed columns — never `->get()` of the whole window.

It returns the same JSON keys the dashboard reads today (`stats`, `visitors`, `trafficSources`, `devices`, `browsers`, `geographic`, `topPages`,
`activeUsers`) so the screen keeps working, with these definitions made true:

| Key | Today | After |
|---|---|---|
| `totalVisitors` | count of `page_load` (same as page views) | `COUNT(DISTINCT visitor_id)` over the window |
| `pageViews` | count of `page_load` | unchanged |
| `bounceRate` | derived from form loads/completions | share of sessions with exactly one `page_load`, from `session_id`; `null` (shown as "—") when the browser did not send one |
| `avgDuration` | mean of whatever `duration` was sent | mean of `duration_seconds` over events that have one |
| `topPages[].bounceRate` | constant 0 | per-route single-page-session share, or `null` |
| `activeUsers` | distinct visitor ids in the window | distinct `visitor_id` in the last 5 minutes (what "active" means) |

New keys, additive: `activity` (counts by `module.event` for `source = 'server'`), `activityValue` (sum of `value_cents` by currency for events that carry
money, with the §5.1 caveat), and `funnels` (`forms`: `form_loaded` → `form_completed`; `subscribers`: `page_load` → `subscriber_signup`).

The browser helper gains `sessionId` (a random id kept in `sessionStorage` for the tab) and sends it with every event; the field is optional, so an
old cached script still works.

## 5.7 Hardening the public endpoint (A1, A6)

The endpoint must stay callable by anonymous visitors — that is its purpose — so authentication is not the fix. Instead:

1. **Vocabulary.** `module` and `event` must be a pair the application defines: the browser vocabulary below, or a `ActivityCatalog` pair. Unknown pairs return
   `422`. This also ends the `page`/`load` versus `page_load` mismatch (A6).
2. **Size.** `metadata` is limited to 4 KB and 20 keys; `route` to 255 characters.
3. **Throttle.** Per IP **and** per site, not only the global `600,1`.
4. **Origin.** Where `EXTERNALS_CORS_MODE` is not `off`, the existing CORS layer already restricts origins; the endpoint is added to its test.
5. **Not a trust boundary.** Browser events are labelled `source = 'browser'` and can be wrong or forged; nothing — not billing, not entitlement, not a
   report of record — reads them for a decision.

The **browser vocabulary** (the one the shipped helper already sends):

| module | events |
|---|---|
| `system` | `page_load` |
| `forms` | `page_load`, `form_loaded`, `form_completed` |
| `subscribers` | `page_load`, `subscriber_signup` |

Adding a browser event means adding it here and in `analytics/api.ts`; that is deliberate.

## 5.8 The afs-connect provider (A3)

`WwwAnalyticsProvider::summary()` is wrong today. Two changes:

- **P0 (no schema needed):** count `laravel_analytics_events` where `event = 'page_load'` for the last 30 days, and use the rollup table only when it holds
  at least one row in the window. Add a test that seeds ten `page_load` events and expects `page_loads_30d = 10`. (Seed rows are not yet separable — that
  arrives with the `source` column in P4.)
- **P4:** once `analytics:rollup` runs, read the rollup rows for `event = 'page_load'` (all modules) — the right keys, because the vocabulary is now fixed.

## 5.9 Reconciliation (optional, P6)

A weekly command compares, per Direct Payment, `SUM(value_cents)` of `server` `payment_succeeded` events with the sum of succeeded invoices for the same
period and logs a warning when they differ by more than the refunds explain. It changes nothing; it exists so a missed publish is noticed. Not required for the
system to work.

## 5.10 Cleanup

- **A8:** delete `analytics_update()`, `save_analytics()`, `get_analytics()` from `FunctionsController` (lines 1174–1215) after a repo-wide `grep` confirms no
  caller, including `resources/js` and `public/`.
- **A9:** the seeders mark rows `source = 'seed'`; the summary and rollups exclude `seed` unless `?include_seed=1`.

## 5.11 Acceptance

- `summary` on a table of 1,000,000 events returns in time proportional to the number of days, not events (measured in P4 with a seeded table, with the
  query count and the peak memory asserted, not just the wall time).
- A replayed `payment_succeeded` records one event and one rollup count.
- An unknown `module`/`event` posted to the public endpoint returns `422` and writes nothing.
- The connect index shows the real 30-day page-load count.
- `analytics:rollup` run twice in a row leaves identical rollup rows.
- No analytics row written by the server contains a name or an e-mail address (a test greps the stored `metadata` of every server event the suite creates).
