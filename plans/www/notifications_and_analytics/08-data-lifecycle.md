# 08 — Data Lifecycle: Keeping Rapid Data From Growing Without Limit

Closes **D1–D8** ([01](01-audit-findings.md) §1.5). Notifications and analytics create rows continuously; so do several tables that already exist. This step
adds one scheduled job that bounds every such table, a quarterly analytics report that keeps the overall picture after the detail is gone, and a test that
stops a new unbounded table from slipping in. It is written to be the home for **all** retention in `w/www`, not only for the tables this plan adds.

## 8.1 How it works today

| Fact | Evidence |
|---|---|
| **Nothing prunes a tenant site.** `routes/console.php` schedules six commands (`queue:work`, charge-hook repair, subscription notify/migrate, scheduled changes, ecommerce hold expiry); none deletes old rows. No `queue:prune-failed`, `queue:prune-batches` or `model:prune` is scheduled, and no model uses `Prunable` | `routes/console.php:19–77`; `grep -rn "Prunable" app` and `grep -rn "prune" routes bootstrap config` are empty |
| **One prune command exists, for the Brain only.** `brain:storage-prune` deletes storage-device report history older than `report_retention_days` (30) and says why `brain_storage_device_events` is kept forever: it is the audit trail that explains a deletion months later | `app/Console/Commands/BrainStoragePrune.php`; it is scheduled from `members`, not from `www` (`routes/console.php:83–86`) |
| **The queue, cache and failed-job tables are in the database** (`QUEUE_CONNECTION=database`, `CACHE_STORE=database`) | `_env/dev/.env:53, 60` |
| **Several log tables have no index on their time column**, so a delete by age scans the whole table: `laravel_analytics_events` (single-column indexes on `module`, `event`, `resource_name`, `resource_reference` only), `laravel_processor_webhooks` (index on the processor reference only), `laravel_integration_logs`, `websocket_failure_events` (none), `progress_records` (`date` not indexed), `laravel_user_tokens` (`expires_at` not indexed). `laravel_processor_webhook_rejections` and `…_orphans` have composite indexes led by `ip` / `provider`, which a time-only delete cannot use | the create migrations cited in [01](01-audit-findings.md) D7 |
| **`laravel_processor_webhooks` is not only a log.** It stores the full JSON `payload` of every webhook, `squareSubscriptionForOrder()` reads a stored `invoice.payment_made` event to find a subscription, and the Stripe v2 path uses a stored row as its replay guard | `ProcessorWebhookController.php:1217–1229`, `:1692` |
| **The ledger's unique key is the duplicate guard**, so a `pending` row left by a dead job occupies the key forever | `NotificationDispatcher::insertPendingLedgerRow()` ([04](04-dispatcher-extraction.md) §4.1) |

## 8.2 Rules

1. **Every table that grows with activity is classified**, and a table that is not classified fails a test (§8.9).
2. **Bounded, chunked, resumable.** A run deletes in small chunks with a pause and a time budget; interrupting it loses nothing; the next night continues.
3. **Audit and money records are never auto-deleted.** They are listed as `keep` with the reason, so "why is this table not pruned" has a written answer.
4. **Detail before summary, never after.** Analytics detail is deleted only after the summary that replaces it exists (§8.5).
5. **A bad cutoff must not empty a table.** A circuit breaker stops a policy that would delete most of a large table, and the first run on every site is supervised (§8.4).
6. **Shipped disabled.** `DATA_LIFECYCLE_ENABLED` defaults to `false`; the owner reviews a dry run, then turns it on per environment.
7. **Observable.** Every run writes what it did to a table and the log; a weekly footprint report shows size and growth.
8. **No `app()`, no hidden registration.** The policies are an explicit list in one service provider.

## 8.3 What is kept, what is pruned, and for how long

Classes: **A** audit/money — keep; **B** business history — keep until the owner decides; **C** rapid logs — prune; **D** payload-heavy — compact then prune;
**E** expiring credentials — prune after expiry; **F** framework tables — use the framework's own command. Every number is a config value
(`config/data_lifecycle.php`) and an owner decision ([10](10-open-questions.md) OQ18).

| Table | What it holds | Class | Action and default retention | Condition and safety |
|---|---|---|---|---|
| `laravel_analytics_events` | Raw browser and server events | C | **Delete after 90 days** (the owner's "3 months") | Only when that day has a rollup **and** the quarter containing it has a report (§8.5) |
| `laravel_analytics_daily_rollups`, `…_daily_dimensions` | One row per day, module, event (and breakdown) | C | Delete after **25 months** | Quarterly reports already hold the summary; 25 months keeps a full year-on-year comparison |
| `laravel_analytics_reports` | The quarterly reports (§8.6) | A | **Keep** | ~100–200 KB each |
| `laravel_notification_sends` (and, until P6, `laravel_direct_payment_notification_sends`) | The send ledger | C | **Delete `sent`/`skipped` after 90 days; `failed` after 180** | Longer than any replay window (a provider retries webhooks for days; scheduled reminders belong to a period that has ended). `pending` older than 2 hours is first marked `failed: stale_pending` (§8.7) |
| `laravel_notifications` | The admin bell | C | **Delete archived after 180 days, read after 365; never unread** | Replaces the `notifications:prune` named in [03](03-admin-inbox.md) §3.3 |
| `laravel_processor_webhooks` | Raw provider webhooks with full payload | D | **Compact at 90 days** (set `payload` to `NULL`, keep the row), **delete at 730 days** | Row kept for the replay guard and the audit trail; payload dropped once nothing re-reads it (§8.1). Compaction is its own action so the big column goes first |
| `laravel_processor_webhook_rejections` | One row per rejected webhook call, by IP | C | Delete after **30 days** | Fills under attack; the composite index is by IP, so a `created_at` index is added |
| `laravel_processor_webhook_orphans` | Webhooks matching no processor | C | Delete after **30 days** | |
| `laravel_integration_logs` | Per-integration event payloads | C | Delete after **90 days** | |
| `laravel_form_events` | Per-submission event history (`mail.sent`, `mail.failed`) | B | Delete after **365 days** | Owner decision ([10](10-open-questions.md) OQ23); the submission itself is untouched |
| `websocket_failure_events` | Chat socket failures | C | Delete after **30 days** | |
| `progress_records` | Progress of long operations | C | Delete finished (`status` not `pending`/`in_progress`) after **30 days** | |
| `laravel_site_user_sessions` | Site-user sessions | E | Delete **30 days after `expires_at`** or `revoked_at` | Never an active session |
| `laravel_site_user_verifications` | One-time verification tokens | E | Delete **7 days after `expires_at` or `consumed_at`** | |
| `laravel_user_tokens` | Portal tokens | E | Delete **30 days after `expires_at`** (rows with no expiry are untouched) | |
| `failed_jobs`, `job_batches` | Queue failures and batches | F | `queue:prune-failed --hours=720`, `queue:prune-batches --hours=48 --unfinished=72 --cancelled=72`, daily | Framework commands; no policy class |
| `laravel_data_prune_runs` | This job's own run log (§8.4) | C | Delete after **365 days** | |
| `laravel_direct_payment_audit_log`, `laravel_site_user_admin_actions`, `laravel_brain_alloc_audit`, invoices, orders, bookings, subscriptions, contracts | Audit and money | A | **Keep** | Listed in `keep` with the reason |
| `laravel_ecommerce_order_events`, `laravel_event_activity`, `laravel_direct_payment_migration_runs`, `laravel_processor_migration_events` | Business history | B | **Keep for now** | Small and meaningful; revisit with the owner ([10](10-open-questions.md) OQ23) |

**Brain scope** (`brain_*`, `members_*`): `brain_app_mail_log` / `brain_app_mail_events`, `brain_domain_events`, `brain_infra_history`,
`brain_storage_device_reports` (already pruned by `brain:storage-prune`) and similar are classified in P7a by the same method. They are **not decided
here**: audit trails stay (the `brain:storage-prune` rule), mail logs and reports are the candidates. They run under `data:prune --scope=brain`, scheduled
from `members` through the internal commands endpoint like every other Brain-only command ([08 §8.11](08-data-lifecycle.md)).

## 8.4 The mechanism

```
 config/data_lifecycle.php          enabled · chunk_rows 5000 · pause_ms 100 · time_budget_seconds 600 · max_rows_per_policy_per_run 500000
                                    retention.<key> days · breaker {max_fraction 0.5, min_rows 100000}
 App\Services\DataLifecycle\
    RetentionPolicy (interface)     key() · table() · scope() 'tenant'|'brain' · plan(): Plan · apply(Chunker): Result
    TimeWindowPolicy                a table, a time column, a retention key, an optional condition — most policies are this
    AnalyticsEventsPolicy           adds the two gates of §8.5
    NotificationSendsPolicy         marks stale pending rows, then deletes by status and age (§8.7)
    InboxPolicy                     archived/read rules, never unread
    ProcessorWebhooksPolicy         the compact action, then the delete
    Chunker                         deletes ≤ chunk_rows at a time, orders by the primary key, sleeps, stops at the time budget
    DataPruner                      runs the list, records a PruneRun per policy, applies the breaker
 App\Providers\DataLifecycleServiceProvider    the explicit list of policies — one line each, no discovery
 data:prune [--dry-run] [--policy=key] [--scope=tenant|brain] [--force]
 data:footprint                     table sizes and growth, flags the unmanaged (§8.9)
 laravel_data_prune_runs            id · ran_at · policy · action · rows · duration_ms · status · error · cutoff
```

**Scheduling** (in `routes/console.php`, which every site evaluates; the order matters):

```php
Schedule::command('analytics:rollup')->hourly()->withoutOverlapping(50)->onOneServer();            // 05 §5.5
Schedule::command('analytics:report')->dailyAt('02:30')->withoutOverlapping(60)->onOneServer();    // §8.6 — makes the report the day after a quarter ends
Schedule::command('data:prune')->dailyAt('03:15')->withoutOverlapping(120)->onOneServer()->runInBackground();
Schedule::command('queue:prune-failed --hours=720')->dailyAt('03:45');
Schedule::command('queue:prune-batches --hours=48 --unfinished=72 --cancelled=72')->dailyAt('03:50');
Schedule::command('data:footprint')->weeklyOn(1, '04:00');
```

**Safety rails**

| Rail | Behaviour |
|---|---|
| **Disabled by default** | With `DATA_LIFECYCLE_ENABLED` false, `data:prune` runs as `--dry-run`: it prints and logs what it would delete and deletes nothing |
| **Supervised first run** | If a policy would delete more than `breaker.max_fraction` of a table **and** more than `breaker.min_rows` rows, it stops that policy, logs an error and waits for `--force`. A first catch-up on a large old table trips it by design: the owner runs it once by hand with `--force` and sees the counts |
| **Bounded** | At most `max_rows_per_policy_per_run` and `time_budget_seconds` per policy; the next night carries on |
| **Chunked** | `DELETE … WHERE id IN (SELECT id … ORDER BY id LIMIT n)` in a loop with a pause, so locks are short and replication lag stays small |
| **Idempotent** | Re-running deletes only what is still past its cutoff |
| **Never throws to the scheduler** | One policy failing is recorded and the next policy runs |
| **The cutoff is logged** | Every run row stores the cutoff timestamp, so a clock fault is visible afterwards |
| **Scope guard** | `scope = brain` policies exit immediately unless `config('brain.is_brain')`, as `brain:storage-prune` does |

## 8.5 Analytics: detail, then summary, then deletion

Raw analytics events are the table that grows fastest (one row per page load and per tracked action). The lifecycle of a day of data:

```
 event recorded ──▶ analytics:rollup (hourly) folds the day into laravel_analytics_daily_rollups / _dimensions
                  ──▶ quarter ends ──▶ analytics:report (next 02:30) builds the quarter's report from the RAW rows ──▶ laravel_analytics_reports
                                                                                                                          │
                  data:prune (03:15) deletes a raw event only when ALL THREE hold:  ◀───────────────────────────────────────┘
                     1. it is older than 90 days
                     2. its day has a rollup row
                     3. the report for the quarter it belongs to exists
```

Effect: a raw event lives **90 to about 92 days** — an event from the first day of a quarter is 90 days old on the day after the quarter ends, exactly when its report
appears; an event from the last day of a quarter is deleted at 90 days. The third condition costs at most a couple of days and buys an **exact** quarterly
report, including distinct visitors, which cannot be rebuilt from daily totals.

If the scheduler is down for months, nothing is lost: the gates keep the raw rows, and `analytics:report` catches up the missing quarters oldest-first on the
next run. If a rollup or a report is missing for a day or quarter, those rows are simply not eligible.

**First deployment:** raw events older than 90 days may already exist with no rollup. The supervised first run is therefore ordered: `analytics:rollup --backfill`,
then `analytics:report --backfill` for every completed quarter present in the raw data (marked `exact` where the raw rows cover the whole quarter), then
`data:prune --force` after the owner reads the dry run.

## 8.6 The quarterly report

A **calendar quarter** (Jan–Mar, Apr–Jun, Jul–Sep, Oct–Dec; [10](10-open-questions.md) OQ19) in the application's timezone, stored once, viewable at any time.

**Table `laravel_analytics_reports`** — `id`, `reference_num` (unique), `period_type` (`quarter`; room for `month`), `year`, `quarter`, `period_start`, `period_end`,
`status` (`final` \| `partial` when the site began collecting mid-quarter), `exact` (bool — false when built from rollups because the raw rows were gone),
`generated_at`, `source_events` (int), `schema_version` (int), `summary` (JSON). Unique key `analytics_reports_period_uq (period_type, year, quarter)`.

**`summary` (schema version 1)** — figures only, no personal data:

| Block | Content |
|---|---|
| `totals` | page views; unique visitors (exact `COUNT(DISTINCT visitor_id)` over the quarter); sessions; bounce rate; average duration; form loads, completions and conversion; subscriber sign-ups |
| `daily` | one entry per day: page views and unique visitors |
| `top_pages` | the 20 most-viewed routes with views and average duration |
| `traffic_sources`, `devices`, `browsers`, `countries` | counts and shares |
| `activity` | server-recorded activity by `module.event`: count and, for money events, `value_cents` by currency (carrying the caveat of [05](05-analytics.md) §5.1) |
| `funnels` | forms (loaded → completed), subscribers (page → sign-up) |
| `data_quality` | days without a rollup, whether the quarter is partial, whether it is exact, the event count behind it |

Comparison to the previous quarter and to the same quarter a year earlier is computed **when viewed**, from the two stored reports — never stored.

**Generation.** `php artisan analytics:report [--quarter=2026Q3] [--backfill] [--force]`:

- With no option it generates the most recently ended quarter if it has no report, then any older completed quarter that has raw or rollup data and no report.
- It reads the raw rows (exact); if they are gone it falls back to the rollups and sets `exact = false`.
- It is idempotent: an existing report is left alone unless `--force`, and `--force` refuses while a pruned gap would make the new report less complete than the stored one.
- On success it publishes `Activity(module: 'analytics', event: 'report_ready', subjectRef: '2026Q3')` — an inbox notification *"Your Q3 2026 analytics report is ready"* — the hand-in-hand
  behaviour of [06](06-activity-fan-out.md) applied to analytics itself. Inbox on, e-mail off by default.

**Viewing** (dashboard authentication, added to `RoutePolicy`):

| Method and path | Purpose |
|---|---|
| `GET /portal/api/v1/analytics/reports` | The list: year, quarter, period, `exact`, `status`, and a headline (page views, unique visitors, payments count and value) |
| `GET /portal/api/v1/analytics/reports/{year}/{quarter}` | The full summary plus the two comparisons |
| `GET /portal/api/v1/analytics/reports/{year}/{quarter}/export.csv` | One flat CSV (UTF-8 with a BOM, as the Events plan specifies) of every block |

In the dashboard the Analytics screen gains a **Reports** tab: the list, then a detail view that reuses the existing chart components and a *Compare with previous
quarter* toggle. Printing uses the browser's print stylesheet; a generated PDF is not built. Reports are **kept forever** (class A).

## 8.7 The notification ledger and the inbox

- **Replay horizon.** The ledger is a duplicate guard, so it must outlive anything that can replay an event. Providers retry webhooks for days; scheduled reminders belong to
  a billing period that ends; `repair-charge-hooks` looks back a bounded window. 90 days is more than all three, and the replay case is asserted in a test
  (a send recorded, the row aged to 89 days, the same activity published again → still deduped).
- **Stale `pending`.** A job that died between the ledger insert and the send leaves a `pending` row that holds the unique key. `NotificationSendsPolicy` first
  converts `pending` rows older than 2 hours to `failed` with `error = 'stale_pending'`, which makes them visible in the sends panel ([07](07-admin-ui.md) §7.3)
  and then eligible for the normal 180-day `failed` retention.
- **Inbox.** `InboxPolicy` deletes `is_archived = 1` rows older than 180 days and `is_read = 1` rows older than 365 days; an unread row is never touched. The
  per-administrator read state of [03](03-admin-inbox.md) §3.7, if it is built, extends the rule to "read by everyone".

## 8.8 Disk space, and the partitioning option

`DELETE` frees pages for reuse but does **not** shrink an InnoDB file. For the largest table (`laravel_analytics_events`) the plan therefore adds:

- `data:prune --optimize`, which runs `OPTIMIZE TABLE` (an online rebuild on MySQL 8 / MariaDB) on a table only when this run deleted more than 30 % of its rows or
  more than 1,000,000 rows, never more than once a month, and only inside the scheduled window — with the table-copy size check that the free space on the data
  disk exceeds the table size first;
- the weekly `data:footprint` reports data length and `DATA_FREE` per managed table so reclaimable space is visible.

**Considered and not chosen now: monthly partitions** of `laravel_analytics_events` (drop a partition instead of deleting rows: instant, no bloat). MySQL requires
the partitioning column in every unique key, which conflicts with the unique `dedupe_key` ([05](05-analytics.md) §5.3), the Laravel schema builder cannot declare
partitions (raw SQL per environment), and tenant hosts differ. Revisit if `data:footprint` shows a site writing more than about 5 million events a quarter
([10](10-open-questions.md) OQ22).

## 8.9 Keeping it managed: footprint and the classification test

- **`data:footprint`** (weekly) reads `information_schema.tables` for the managed tables and for every other table, writes row estimates and sizes to the log, and
  warns for any **unmanaged** table above 50 MB or 500,000 rows. It writes a JSON report to `storage/app/data_footprint.json` the dashboard can show later.
- **`DataLifecycleClassificationTest`** parses `database/migrations` (the approach `MigrationOwnershipTest` uses — no database needed). A created table is *log-like* if
  its name matches `log|event|audit|history|attempt|sends|webhook|session|token|progress|queue|notif|report|rejection|orphan|failure` or it has a `created_at`
  and no `updated_at`. Every log-like table must appear in `config/data_lifecycle.php` as a policy key or under `keep` with a one-line reason, or the test fails
  with the table's name. The current tables are baselined in P7a, so the test only catches **new** unclassified tables.

## 8.10 Indexes the policies need

One additive migration, `…_index_log_tables_for_pruning.php`, guarded with `Schema::hasIndex`, every index named explicitly (≤ 64 characters), created while the
tables are still small (an online `ALGORITHM=INPLACE` build otherwise):

| Table | Index |
|---|---|
| `laravel_analytics_events` | `analytics_events_created_idx (created_at)` — the prune gate and the summary both range-scan it |
| `laravel_processor_webhooks` | `processor_webhooks_created_idx (created_at)` |
| `laravel_processor_webhook_rejections` | `proc_wh_rejections_created_idx (created_at)` |
| `laravel_processor_webhook_orphans` | `proc_wh_orphans_received_idx (received_at)` |
| `laravel_integration_logs` | `integration_logs_created_idx (created_at)` |
| `websocket_failure_events` | `ws_failures_created_idx (created_at)` |
| `progress_records` | `progress_records_date_idx (date)` |
| `laravel_user_tokens` | `user_tokens_expires_idx (expires_at)` |
| `laravel_notification_sends` | `notification_sends_created_idx (created_at)`, `notification_sends_status_idx (status, created_at)` (in the P2 create migration) |
| `laravel_notifications` | `notifications_created_idx (created_at)` (in the P3 migration) |

## 8.11 Scheduling path and scope

`routes/console.php` is evaluated on every site — the Brain and every tenant clone — so it schedules `data:prune` for **tenant** policies, exactly as it schedules
`direct_payments:subscription-notify`. Brain-only policies run as `data:prune --scope=brain`, scheduled in `members` and invoked through the internal commands endpoint
(`POST /api/internal/brain/commands/{name}`), so `data:prune` is added to that endpoint's whitelist and to `BrainCommandsEndpointTest`. This follows
`routes/console.php:83–86` and `plans/create_member_redo/09-brain-schedule-move.md`; it adds no new mechanism.

## 8.12 Acceptance

- With `DATA_LIFECYCLE_ENABLED` false, `data:prune` deletes nothing and prints the counts it would delete.
- A seeded `laravel_analytics_events` with rows at 10, 89, 91 and 120 days, rollups for all days and no report for the 120-day row's quarter: only rows with a rollup **and** a report
  and older than 90 days are deleted; the 120-day row survives until the report exists.
- A policy asked to delete 90 % of 200,000 rows stops, logs the error and deletes nothing until `--force`.
- Interrupting a run mid-way and re-running deletes the rest and nothing extra.
- `analytics:report` for a completed quarter writes one `final` report with an exact unique-visitor count; run twice it writes one; with the raw rows removed it writes `exact = false`.
- A ledger row aged to 89 days still deduplicates a replayed activity; at 91 days it is deleted and a stale `pending` row older than 2 hours has become `failed: stale_pending`.
- A new migration that creates `foo_events` without a policy or `keep` entry fails `DataLifecycleClassificationTest`.
- `data:footprint` lists every table above 50 MB that has no policy.
