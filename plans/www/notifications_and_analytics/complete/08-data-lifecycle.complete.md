# 08 — Data Lifecycle (complete — the retention job ships DISABLED; the quarterly report, its API and Reports tab are built; proved by tests, not yet run on a server or seen in a browser)

## Status

**Built 2026-10-19, nothing committed.** One scheduled job, `data:prune`, now bounds the log-style tables of `w/www`; a quarterly analytics report is saved *before* the raw analytics events it was built from may be deleted, so the overall picture can be viewed at any time; and a test stops a
new unbounded table from slipping in unnoticed. **The job is shipped disabled** (`DATA_LIFECYCLE_ENABLED` false): until the owner turns it on every run is a dry run that says what it *would* delete and deletes nothing. **Nothing has been deleted anywhere by this step.**

Closes **D1–D8** (the tables that grow without limit), **N10** (inbox retention) and the open half of **A2**/**D2** (analytics growth). Everything is in `w/www`.

## Phases

| Phase | Piece | State | Where |
|---|---|---|---|
| P7a | `config/data_lifecycle.php`: the switch, the limits, every retention number, and the `keep` list with a reason for each log-like table that stays | **built** | `config/data_lifecycle.php` |
| P7a | `RetentionPolicy`, `TimeWindowPolicy`, `Chunker`, `DataPruner`, run log `laravel_data_prune_runs` | **built** | `app/Services/DataLifecycle/`, `DataPruneRun` |
| P7a | `DataLifecycleServiceProvider`: the explicit list of 18 policies, one per table and action | **built** | `app/Providers/DataLifecycleServiceProvider.php` |
| P7a | `data:prune` (disabled by default), `data:footprint`, the schedule | **built** | `app/Console/Commands/DataPrune.php`, `DataFootprint.php`, `routes/console.php` |
| P7a | The pruning indexes migration | **built** | `2026_10_19_000006_index_log_tables_for_pruning.php` |
| P7a | `DataLifecycleClassificationTest` with the baseline, and gate G8 | **built** | `tests/Unit/DataLifecycle/` |
| P7b | `AnalyticsEventsPolicy`: a raw event goes only when old enough **and** its day is rolled up **and** its quarter has a report | **built** | `app/Services/DataLifecycle/AnalyticsEventsPolicy.php` |
| P7b | `analytics:report`, `laravel_analytics_reports`, `AnalyticsReportService`, `report_ready` on the bell | **built** | `AnalyticsReportService.php`, `AnalyticsReportCommand.php`, `AnalyticsActivityModule.php` |
| P7b | The three report endpoints and the Reports tab on the Analytics screen | **built** | `AnalyticsReportsApiController.php`, `analyticsReports.ts`, `analytics.html` |
| P7b | Stale-`pending` ledger rows, the inbox rules, `--optimize` | **built** | provider policies, `DataPruner::optimize()` |
| — | Brain-scope policies (`brain_*`, `members_*`) and the `data:prune --scope=brain` whitelist in the internal commands endpoint | **not built — not decided with the owner** (08 §8.3) | — |

## What was built

- **The rails** (08 §8.4). *Disabled by default*; *dry run* on request; the **breaker** — a policy that would remove more than half of a table **and** more than 100,000 rows stops and waits for `--force` (the supervised first run); **bounded** by a row budget (500,000) and a time budget (600 s) per policy and **chunked**
  (5,000 rows, oldest first, a pause between); **idempotent** and resumable (an interrupted run loses nothing, the next one carries on); one policy failing is recorded and the next still runs; nothing is thrown to the scheduler; a policy for a missing table is skipped; a Brain-scope policy does nothing unless this is the Brain.
  **Every action is recorded** in `laravel_data_prune_runs` — policy, action, rows, status (`done`, `partial`, `dry_run`, `blocked`, `skipped`, `failed`), duration, **the cutoff it used** (so a clock fault is visible afterwards) — and logged.
- **The policies** (all numbers in the config; days unless stated): raw analytics events 90, gated (below); rollups and their breakdowns 760 (about 25 months); notification send ledger — `sent`/`skipped` 90, `failed` 180, `pending` older than 2 **hours** is first marked `failed: stale_pending`; the admin bell — archived 180, read 365, **an unread, unarchived
  notification is never touched**; provider webhooks — the **payload is dropped at 90** (the row, its type and time stay: the replay guard and the audit trail) and the **row at 730**; rejected webhook calls 30; orphan webhook calls 30; integration logs 90; form events 365; chat socket failures 30; finished progress records 30 (a running one never); site-user sessions 30 days after expiry or
  revocation (never an active one); verification tokens 7 days after expiry or use; portal tokens 30 days after expiry (one with no expiry never); the job's own run log 365.
- **Analytics: detail, then summary, then deletion** (08 §8.5). A raw event is deleted only when **all three** hold: its day is before the 90-day cutoff, its day has a rollup row, and the report of the calendar quarter it belongs to exists. A day that fails any is simply not eligible, so a scheduler that was down for months loses nothing — the rows wait. A raw event therefore lives about 90–92 days. The
  schedule makes the order true: `analytics:rollup` hourly, `analytics:report` at 02:30, `data:prune` at 03:15.
- **The quarterly report** (08 §8.6). `analytics:report` builds, oldest first, every **completed** calendar quarter that has data and no report; it is idempotent. It reads the **raw rows** (so unique visitors are an exact `COUNT(DISTINCT)` over the whole quarter — the daily totals would double-count someone who came on two days) and, if the raw rows are gone, the rollups (`exact = false`, said on the
  screen). A quarter whose first data day is mid-quarter is `partial`. `--force` rebuilds but **never replaces an exact report by a less exact one**. The summary (schema version 1) is figures only: totals (page views, unique visitors, sessions, bounce rate, average duration, form loads/completions/conversion, sign-ups), a daily series, the 20 top pages, traffic sources, devices, browsers, countries, server-recorded activity by
  `module.event` with money by currency, the two funnels, and a data-quality block. Development seed rows are never counted. When a report is made, `analytics` (now a module of the notification system) announces `report_ready` — *"Your Q3 2026 analytics report is ready"* on the bell (where event defaults apply); a first catch-up over several old quarters announces only the newest.
- **Viewing.** `GET /portal/api/v1/analytics/reports` (the list with a headline each), `…/reports/{year}/{quarter}` (the full summary plus the comparison with the previous quarter and the same quarter a year earlier, **worked out when viewed**, never stored), `…/export.csv` (one flat CSV with a byte-order mark). All behind the dashboard login and in `RoutePolicy`. The Analytics screen gains a **Reports** card: the list, a click opens the detail, a *Compare with previous quarter* switch, a CSV link per
  row; everything drawn from a report is escaped.
- **Keeping it managed** (08 §8.9). `data:footprint` (weekly) reads the database's table sizes, marks every table `managed` / `kept` / `unmanaged`, warns for each unmanaged table over 50 MB or 500,000 rows, shows reclaimable space, and writes `storage/app/data_footprint.json`. **`DataLifecycleClassificationTest`** reads every migration (no database): a created table is log-like if its name matches the pattern or it has `created_at` and no `updated_at`; each one must be pruned by a policy or listed under
  `keep` with a reason, or the test fails naming it. The 77 tables (of 252) that matched today were classified (18 prune policies on 16 tables, the rest kept with a one-line reason each); the test now fails for a **new** unclassified table. Gate G8: no second prune mechanism in `routes/console.php` or the commands.
- **Disk space** (08 §8.8). `data:prune --optimize` rebuilds a table only after a run removed at least 30 % of it (or a million rows), at most once in 30 days, and only when free disk space can be shown to exceed the table; otherwise it is recorded as skipped with the reason.
- **Indexes** (08 §8.10): one guarded migration adds named time-column indexes to the eleven tables the policies delete by age.
- **Shared sums.** The sums behind the dashboard summary moved into one class (`AnalyticsFigures`) that the report also uses, so the summary and the report cannot disagree about what a figure means (the 17 summary tests still pass unchanged).

## Tests

PHP on MariaDB 11.4.5, port 3307.

| File | Tests | Proves |
|---|---|---|
| `Feature/DataLifecycle/RetentionPoliciesTest` | 17 | **rows at 10, 89, 91 and 120 days: only the 91-day row goes (rolled up and its quarter reported); the 120-day row waits for its Q2 report**; no rollup = kept; a late scheduler loses nothing; rollups kept ~25 months; ledger 89/91 and 179/181 days; pending → `failed: stale_pending`; **a replay at 89 days is still deduplicated, at 91 it is not**; inbox rules; webhook payload dropped at 90, row at 730; every other window at its edges; running progress, active sessions and no-expiry tokens never touched |
| `Feature/DataLifecycle/DataPrunerSafetyTest` | 16 | ships disabled; a disabled or dry run deletes nothing; **a policy asked to delete 90 % of 200,000 rows stops, logs the error and deletes nothing until `--force`**; the breaker leaves small tables alone; a run stops at its row budget and the next finishes without deleting anything extra; a time budget; a failing policy does not stop the next; missing table skipped; Brain scope; every run recorded with its cutoff; the optimize decision and a real rebuild |
| `Feature/DataLifecycle/AnalyticsReportTest` | 17 | one final exact report with the exact unique-visitor count (3 where the daily sum says 4); every block; no personal data; idempotent; not-ended / no-data; partial; **raw rows gone → `exact = false`**; a forced rebuild never loses exactness; the bell once; catch-up oldest first announcing only the newest; the list, the detail with comparison, 404, the CSV with its BOM, the login |
| `Feature/DataLifecycle/LifecycleCommandsTest` | 12 | the commands' behaviour and refusals; **the nightly order** (report 02:30, prune 03:15, framework prunes, weekly footprint); the footprint finds the big table nobody manages; the indexes exist, re-run and undo |
| `Unit/DataLifecycle/DataLifecycleClassificationTest` | 7 | the scan finds the tables; every log-like table is pruned or kept with a reason; nothing listed is misspelled; not both; G8 |
| `admin/tests/analyticsReports.test.ts` | 10 | the Reports list and detail as HTML: dashes, comparison, labels, escaping |
| existing `GeneralLayerTest` | — | gates G1/G3 now cover `app/Services/DataLifecycle` |

## Final run

Run once at the end, one process at a time, against MariaDB 11.4.5: `tests/Unit`, `Feature/Notifications`, `Feature/Analytics`, `Feature/Externals`, `Feature/DataLifecycle` and the notification-related Direct Payments files — **782 tests, 2364 assertions; the 18 errors are the known Windows `LaravelCronScriptTest` setUp problem, and one failure was a test of mine that expected the catalogue to hold only Direct Payments (it now also holds `analytics`, which announces its own report) — corrected, and `tests/Unit/Activity` re-run alone: green.** TypeScript: `test:admin` 79 pass; `tsc --noEmit` clean. Not run: the rest of `Feature/DirectPayments` and `Feature/Processor` (step 06 ran them; this step changed nothing in them), and the other Feature folders.

## Deviations from the plan text

1. **A webhook payload is replaced by `{"compacted":true}`, not `NULL`.** The column is `NOT NULL JSON`; the marker avoids altering a payment table, and a compacted row is recognisable (and not compacted again).
2. **The inbox rule reads "archived after 180 days, read after 365, never an unread notification that is not archived".** The plan's §8.3 and §8.7 disagree about an archived-but-unread row; "Archive all" archives without marking read, so such rows exist and archiving is an explicit dismissal.
3. **Brain-scope policies:** at the time of writing none (the plan says "not decided here"). *Updated the same day after the owner's OQ23 answer:* one policy, `brain_mail_log` (`brain_app_mail_log`, 90 days), run by the new command `data:prune-brain`, scheduled from `members` (03:15) over the signed Brain surface and allow-listed in `BrainCommandsController`. It is its own command name because www's schedule already runs `data:prune` on every site, and an existing test forbids the Brain allowlist from naming anything www schedules. `brain_app_mail_events` is a catalogue, not a log, and stays.
4. **The old `laravel_direct_payment_notification_sends` table is listed as `keep`** ("frozen copy source, dropped in P6") rather than pruned, since nothing has written to it since step 04.
5. **Analytics rows with no `occurred_at`** (written before step 05 and not yet through `analytics:promote-columns`) are never eligible; the promotion gives them a time first.
6. **Report headline "payments"** reads the `direct_payments.payment_succeeded` count in the controller (the one place Analytics names a module); the report itself is module-agnostic.

## Not verified

- **Nothing was run on a server.** The job has never deleted a row anywhere outside the test database, and it will not until the owner sets `DATA_LIFECYCLE_ENABLED=true`.
- The Reports card was type-checked and its HTML builders tested; it was **not opened in a browser**.
- The weekly footprint's size figures and `OPTIMIZE TABLE` were exercised on MariaDB only; MySQL 8 behaves the same by design but was not run. The free-space check reads the database's `datadir`; where the database is on another machine it cannot, and says "skipped".
- `onOneServer` relies on the cache lock the other scheduled commands already use.

## The first supervised run on a real site (do in this order)

```bash
php artisan migrate --force
php artisan analytics:rollup --backfill
php artisan analytics:report            # builds every completed quarter that has data (the bell announces the newest)
php artisan data:prune --dry-run        # read the table: rows per policy, the cutoff of each
DATA_LIFECYCLE_ENABLED=true php artisan data:prune --force     # once the counts look right; --force is needed on a big old table because the breaker stops it by design
```
Then set `DATA_LIFECYCLE_ENABLED=true` in that site's `.env` for the nightly run. Deleted rows cannot be restored; the reports, the rollups and `laravel_data_prune_runs` stay.

## Next

Step **09** is the rollout and test plan (its phases are what these steps have been built from; the remaining items there are the optional reconciliation command, the grep gates as CI tests, and the retirement of the old Direct Payments notification tables and receipt job in P6). The Brain-scope retention needs an owner decision first.
