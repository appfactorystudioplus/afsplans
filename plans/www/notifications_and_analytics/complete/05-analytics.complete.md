# 05 — Analytics (complete — the server can record an event, the numbers come from the database, the public endpoint takes only the defined vocabulary; proved by tests, not yet run on a server)

## Status

**Complete 2026-10-19.** Nothing is committed. Analytics stays its own system (`App\Services\Analytics`, its own tables and commands); it receives an `Activity` through `AnalyticsRecorder` and shares nothing else with the notification
classes. **Nothing calls the recorder yet:** the `ActivityPublisher` that calls it, with the notification dispatcher, is step 06, so no payment appears as a server event until then. Everything the recorder, the rollup, the summary and the endpoint do is
proved by tests with Activities built by hand.

Findings closed: **A1** (the public writer validates and bounds), **A2** (the rollup table is now written), **A3** (the afs-connect count), **A4/A5** (the summary is no longer O(events)), **A6** (one vocabulary), **A7** (definitions made true),
**A8** (dead code removed), **A9** (seed rows marked and left out). Not built, by plan: the optional reconciliation command (05 §5.9, phase P6) — see "Not done".

Everything below is in `w/www`.

## Phases

| Phase | Piece | State | Where |
|---|---|---|---|
| P4 | Schema: events gain `source`, `occurred_at`, `value_cents`, `currency`, `subject_ref`, `dedupe_key` (unique), the promoted grouping columns; rollups gain `resource_reference`, `source`, `currency`, `value_cents`, duration sums and a wider unique key; new dimensions table | **built** | `database/migrations/2026_10_19_000004_extend_laravel_analytics.php` |
| P4 | Models: event (fills `occurred_at` and the grouping columns when created), rollup, new dimension model | **built** | `app/Models/AnalyticsEvent.php`, `AnalyticsDailyRollup.php`, `AnalyticsDailyDimension.php` |
| P4 | `AnalyticsRecorder`: Activity → one `source = server` row, only the listed keys, no e-mail-like value, a replay records once, never throws | **built** | `app/Services/Analytics/AnalyticsRecorder.php` |
| P4 | `AnalyticsAggregator`: `COUNT(*)` / `GROUP BY` of a date range (totals, breakdowns, routes, sessions) — one definition used by the rollup and by the live read | **built** | `app/Services/Analytics/AnalyticsAggregator.php` |
| P4 | `AnalyticsRollupService` + `analytics:rollup` (`--date`, `--backfill`, `--dry-run`), scheduled hourly | **built** | `app/Services/Analytics/AnalyticsRollupService.php`, `app/Console/Commands/AnalyticsRollup.php`, `routes/console.php` |
| P4 | `analytics:promote-columns` (old rows: `occurred_at` and grouping values out of `metadata`, in chunks) | **built** | `AnalyticsColumnPromoter.php`, `PromotedColumns.php`, `app/Console/Commands/AnalyticsPromoteColumns.php` |
| P4 | `AnalyticsSummary` behind `GET …/analytics/summary` | **built** | `app/Services/Analytics/AnalyticsSummary.php`, `AnalyticsApiController::summary` |
| P4 | Hardened public endpoint: vocabulary, size, throttle per address and per site, source forced to `browser` | **built** | `AnalyticsApiController::create`, `BrowserVocabulary.php`, `AnalyticsServiceProvider.php`, `config/analytics.php`, `routes/portal.php` |
| P4 | Browser helper sends a session id and a visitor id; pure id module | **built** | `resources/js/external/api/analytics/api.ts`, `ids.ts` |
| P4 | Dashboard: a missing rate shows "—" (it used to throw), page routes escaped, an Activity card | **built** | `AnalyticsController.ts`, `services/analyticsSummary.ts`, `html/views/analytics.html` |
| P0/P4 | afs-connect provider (A3) | **built** | `app/AfsConnect/Providers/WwwAnalyticsProvider.php` |
| P0 | A8: `analytics_update()`, `save_analytics()`, `get_analytics()` removed | **built** | `FunctionsController.php` |
| P4 | A9: seeders mark rows `seed`; every figure leaves them out unless `?include_seed=1` | **built** | `database/factories/AnalyticsEventFactory.php`, `AnalyticsSummary`, the rollups, the provider |
| P6 | Reconciliation command (05 §5.9, optional) | **not done** | — |

## What was built, in detail

- **The tables.** `laravel_analytics_events` keeps `metadata` as the overflow and gains typed columns so the database can group: `source` (`browser` \| `server` \| `seed`, default `browser`), `occurred_at`, `value_cents`, `currency`, `subject_ref`,
  `dedupe_key` (unique; many NULLs allowed), `visitor_id`, `session_id`, `source_label`, `device`, `browser`, `country`, `duration_seconds`; indexes `analytics_events_mod_evt_at_idx (module, event, occurred_at)`,
  `analytics_events_source_at_idx (source, occurred_at)`, `…_subject_idx`, `…_dedupe_uq`. The migration is guarded column by column, runs twice without harm, copies nothing, and has a working `down()`. The rollup table's old unique key
  `(rollup_date, module, event)` is replaced — the new key is created first, the old one dropped second.
- **A browser event** goes through the model, which sets `occurred_at` and copies `metadata.visitor_id / session_id / source / device / browser / country / duration` into their columns. **No deployed page script has to change**: an older script that
  sends only a route and metadata still works, and its events fill the same columns. An explicit column wins over the same key in `metadata`. A `server` event never has its metadata read for grouping.
- **The recorder.** `record(Activity, EventDefinition): bool` (the plan says `void`; the bool says "a new row was written" and lets tests tell a replay from a first record). It writes `module`, `event`, `resource_reference = scopeRef`, `subject_ref`,
  `value_cents`, `currency`, `occurred_at` (the time the thing happened, so a late publish lands on its real day) and `dedupe_key`. `metadata` holds only `EventDefinition::$analyticsKeys`, only plain values, text cut at 100 characters, and **a value that
  contains `@` is dropped even when its key is listed**. A reference longer than the 64-character column keeps its first 55 characters plus `~` and 8 hash characters, so two long references cannot merge. A duplicate `dedupe_key` returns `false` and logs nothing; any
  other failure is logged as a warning and swallowed.
- **The rollup.** `analytics:rollup` recounts a day with the aggregator and **replaces** that day's rows inside one transaction (a reader sees the old day or the new one). Three rules keep it safe: a day with **no raw events is never touched** (retention in
  step 08 may delete old events; a later `--date` run must not turn a kept rollup into zeros); a site whose rollup has **never run is backfilled from its first event on the first run**; and an hourly run also **repairs the days since the last rollup** (a stopped scheduler fixes
  itself, up to 400 days). `--backfill` first runs the column promotion, then recounts every day that has events. The schedule is hourly, `withoutOverlapping`, `onOneServer`, and a clone that has not migrated gets "not migrated yet" and exit 0.
- **The breakdowns.** `laravel_analytics_daily_dimensions` holds, per day and source and for **page loads of every module together** (module `*`): device, browser, country, source_label, route (the 100 most viewed per day and source — `analytics.route_dimension_limit`, so a flood of
  invented URLs cannot grow the table), and one `session` row carrying the day's unique visitors, sessions and single-page sessions. A route row carries the sessions that viewed it and how many of those had no other page: that is a per-page bounce rate.
- **The summary.** Finished days come from the rollup tables, today from the aggregator (same code, so a rolled-up day and a live day cannot disagree — a test proves the two give the identical JSON), and on a site whose rollup is empty the finished days are counted live so
  a fresh deploy still shows history. It returns the old keys with their shape and the plan's definitions: `totalVisitors` (distinct visitors per day, added up), `pageViews`, `bounceRate` (**`null`** when no event carried a session id), `avgDuration` (over events that have a duration),
  `topPages[].bounceRate` (**`null`** when no session), `activeUsers` (distinct visitors in the last 5 minutes). New keys: `activity` (server events by `module.event`), `activityValue` (money by currency), `funnels` (forms: loaded → completed; subscribers: page load → sign-up). Both
  empty maps are JSON objects, not lists. `?days` is clamped to 1–365; `?include_seed=1` includes seed rows.
- **The public endpoint.** Still anonymous (that is its purpose). `module`/`event` must be a pair in `BrowserVocabulary::PAIRS` (the six pairs the helper sends) or a pair in the `ActivityCatalog`; anything else is a **422** and writes nothing. `metadata` ≤ 20 keys and ≤ 4096 bytes encoded,
  `route` ≤ 255, ids ≤ 64. The caller cannot choose `source`, `dedupe_key`, value or time: the row is `browser`, stamped when it arrives. Throttle: a named limiter `analytics-events` — **120 a minute per address and 3000 a minute per site** (`ANALYTICS_THROTTLE_PER_IP`,
  `ANALYTICS_THROTTLE_PER_SITE`), replacing the old single `600,1` that was per address and domain. The origin rule is the existing externals CORS layer; a test now covers this route.
- **The helper.** Every event carries `session_id` (random, kept in `sessionStorage` per tab) and `visitor_id` (random, kept in `localStorage`); neither holds personal data; blocked or throwing storage falls back to an id kept for the page. The public method signatures and `portal.d.ts` are unchanged, so the seven
  copies did not need touching.
- **The dashboard.** `bounceRate: null` used to throw (`null.toFixed`) and send the whole screen to its demo data — now "—". Page routes are visitor-supplied text and are escaped. A new **Activity** card lists what the server recorded, with the money line labelled *from
  recorded activity — see Reports for the reconciled figure* (05 §5.1).
- **afs-connect (A3).** `page_loads_30d` is page loads of **every module** for the last 30 days (20 Sep–today on 19 Oct): finished days from the rollup table when it holds any row in the window, today from events; with no rollup the whole window from events; seed rows left out where the
  tenant's schema can tell; a tenant that has not migrated still answers.

## Tests

PHP on MariaDB 11.4.5, port 3307, progress shown. TypeScript with the repo's Node runner.

| File | Tests | Proves |
|---|---|---|
| `Feature/Analytics/AnalyticsSchemaTest` | 10 | every column and index; old rows survive and read back as browser events; runs twice; the rollup key is widened (two sources or currencies share a day, a true duplicate fails); `down()`; the model fills `occurred_at` and grouping columns; explicit wins; server rows are not read |
| `Feature/Analytics/AnalyticsRecorderTest` | 13 | every column; late event keeps its time; only listed keys; plain values only; e-mail-like value dropped; **no stored server event contains a name or an e-mail address**; replay = one row; long reference; failure swallowed and logged |
| `Feature/Analytics/AnalyticsRollupTest` | 25 | totals per module/event/resource/source/currency; breakdowns; route views, durations and single-page sessions; session row; day edges; route limit; **running twice leaves identical rows**; recount replaces; a day with deleted events is never zeroed; dry run; first-run backfill; hourly recount and repair; metadata promoted before counting; chunked promotion; the commands; the schedule |
| `Feature/Analytics/AnalyticsSummaryTest` | 17 | same keys plus new; definitions; chart; shares; top pages and `null` bounce; active users; server activity and money; empty maps are objects; funnels; seed left out/included; **rolled-up and live give identical JSON**; day clamp; empty table; **100,000 events: ≤ 20 queries and < 8 MB peak** |
| `Feature/Analytics/AnalyticsEndpointHardeningTest` | 14 | every helper pair accepted; unknown pair 422 and nothing written; catalogue pairs; size limits; ids bounded; a forged `source`/`dedupe_key`/value is ignored; old-style script still works; throttle per address and per site; read routes keep the dashboard login |
| `Feature/Analytics/AnalyticsConnectProviderTest` | 9 | **ten `page_load` events → 10**; every module; 30-day edge; seed left out; rollup + today; unmigrated tenant; no table |
| `Feature/Analytics/AnalyticsCleanupTest` | 4 | A8 methods gone; seeder writes 610 rows all `seed`; summary and rollups leave them out |
| `Unit/Analytics/AnalyticsGatesTest` | 3 | gate G2 (only the publisher may call the recorder); no catalogue event lists a personal-looking key as analytics-safe; every pair the helper sends is accepted by the endpoint (A6) |
| `Feature/Externals/ExternalsCorsTest` | +1 | the analytics endpoint refuses an unlisted website and lets a listed one through to its own validation |
| `external/api/tests/analytics_ids.test.ts` | 5 | ids created once, kept, bounded, safe when storage is missing or throws |
| `admin/tests/analyticsSummary.test.ts` | 9 | "—" for a missing rate, the Activity card, escaping |
| existing `GeneralLayerTest` | — | gates G1/G3 now cover `app/Services/Analytics` as well (it was already listed) |

## Final run

Run once at the end, one process at a time, against MariaDB 11.4.5; MariaDB was shut down cleanly afterwards and no `mariadbd`, `php`, `node` or Electron process was left.

| Pass | Suites | Result |
|---|---|---|
| A | `tests/Unit`, `tests/Feature/Notifications`, `tests/Feature/Analytics`, `tests/Feature/Externals` | 603 tests, 1601 assertions: everything passes **except** the 18 errors of `Tests\Unit\Scripts\LaravelCronScriptTest` (the same known Windows setUp problem as in step 04) and two **expectations of mine in `AnalyticsSummaryTest`** (a browser share and a page duration I had miscounted) — corrected, and that file re-run alone: **OK, 17 tests, 78 assertions** |
| TS | `npm run test:external-api` 22 tests, `npm run test:admin` 46 tests, `npx tsc --noEmit` | all pass; type check clean |

**Not run:** `tests/Feature/DirectPayments`, `Processor` and the other Feature folders (Admin, Appointments, Builder, Connections, Ecommerce, Events, Forms, Members, Membership, Observers, SiteUsers). Nothing in this step touches them except the new service provider
(booted in every test that ran) and the deleted dead methods in `FunctionsController`; but they were not run. `npm run build` (Vite) was not run.

## Deviations from the plan text

1. **Rollup = recount and replace, not `INSERT … SELECT … ON DUPLICATE KEY UPDATE`.** The same aggregation has to serve the live read for today, so it returns rows to PHP (a few per day) and the day is replaced in one transaction. The result is the same idempotent recount; it also removes a row that no longer has events.
2. **Wider keys.** The rollup key includes `currency` (two currencies must not be summed into one figure), and the dimension key includes `source` (so seed rows stay separable, A9). The plan's keys had neither.
3. **Extra rollup columns** `duration_sum`, `duration_count`, and the dimension columns `unique_visitors`, `sessions`, `single_page_sessions`, `duration_sum`, `duration_count`: the plan's definitions (distinct visitors, bounce rate, mean duration) cannot be computed from a bare count.
4. **`totalVisitors` = distinct visitors per day, added up**, not `COUNT(DISTINCT visitor_id)` over the whole window. A distinct count cannot be added up from daily rollups, and the plan also requires the cost to follow the number of days. A person who returns on two days counts twice. The exact figure per quarter is step 08's report.
5. **`country` is 64 characters**, not 8: senders (and the seeders) use names, not only codes.
6. **The browser helper also sends `visitor_id`.** The plan names only the session id, but `totalVisitors` and `activeUsers` need a visitor id and the shipped helper sent none. It is a random id with no personal data.
7. **Dimensions cover page loads of all modules together** (module `*`), because sessions are not per module; `forms.page_load` and `subscribers.page_load` therefore count as page views, exactly as before.
8. **`record()` returns `bool`**, not `void`.
9. **The Activity card text is English only**, with no `data-i18n` keys: a script edit of `locales.json` is a known trap here, and the dashboard screens are step 07's.
10. **A catalogue pair is accepted from a browser** (as the plan says), so an anonymous visitor can post `direct_payments.payment_succeeded` as a `browser` event. It is labelled `browser`, never appears in `activity`/`activityValue` (server only), and nothing may use it for a decision (05 §5.7 item 5).

## Built on assumptions the owner has not yet confirmed

OQ5 (a server-recorded event is never a source of truth: counts and dashboard figures only — the summary and the card say so), OQ6 (a vocabulary allow-list, size limit and per-address-and-site throttle, no signed site key), OQ10 (no names or e-mail addresses in analytics), OQ17 (the Activity card
lives on the existing Analytics screen). All still open in [10](../10-open-questions.md).

## Not verified

- **Nothing was run on a server and no browser was opened.** The Analytics screen, the new card and the helper's ids in a real page were not exercised; their request/response shapes and pure logic are asserted by tests.
- **The migration has run only on the local test database.** On a real site it needs `php artisan migrate --force` **before** the new code serves traffic (the public endpoint writes the new columns). After it, run `php artisan analytics:rollup --backfill` once — or simply let the first hourly run do it — and compare `SELECT COUNT(*) FROM laravel_analytics_events` with
  `SELECT SUM(event_count) FROM laravel_analytics_daily_rollups`. On a big table the column promotion walks every browser row in chunks of 1,000: run it off-peak the first time.
- **Query speed on MySQL/MariaDB at 1,000,000 events** was not measured; the test uses 100,000 and asserts the query count and peak memory, not time.
- **`onOneServer` on the Brain** relies on the cache lock the other scheduled commands already use; not exercised.
- **`routes/console.php`** was checked through the schedule list, not by waiting an hour.
- **Legacy files are untouched:** `public/core/php/request.php` and `core/scripts/remote/builder/php/functions2.php` still contain their own old `analytics_update`/`save_analytics`/`get_analytics` (the pre-Laravel stack, global functions, a different table). They are not part of the Laravel app that A8 named.

## Not done

- **05 §5.9, the weekly reconciliation of server `payment_succeeded` events against succeeded invoices.** The plan marks it optional and phase P6, and it has nothing to compare until step 06 publishes payments. It changes nothing when it runs and is not needed for the system to work.
- **The data-lifecycle side** (deleting raw events after 90 days, keeping rollups 25 months, quarterly reports): step 08. Until it exists the raw events table grows with traffic; the rollups mean the dashboard no longer depends on it.
- **The `created_at` index** on `laravel_analytics_events` is part of step 08's index migration; this step's queries use `occurred_at`.

## Next

Step **06** (`ActivityPublisher`) is next in order: it calls `NotificationDispatcher` and `AnalyticsRecorder` from one `publish()`, replaces the ten Direct Payments call sites, and is what makes a payment appear in the Activity card. Step **07** builds the notification rule editor, and step **08** the data lifecycle (raw analytics retention gated on the rollup and the quarterly report).
