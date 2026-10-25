# 09 — Rollout and Testing

## 9.1 Phases

Each phase ships on its own, leaves the system working, and gets a completion record in `complete/` when its plan file is finished. Nothing starts before the
owner approves it and has answered the open questions it depends on ([10](10-open-questions.md)). Phases are divisions of work **inside this file** — the
numbered plan files ([02](02-target-model.md)–[07](07-admin-ui.md)) are steps.

| Phase | Contents | Findings closed | Depends on | Owner decisions applied |
|---|---|---|---|---|
| **P0 — Proof and quick fixes** | **First, tests:** feature tests for `NotificationApiController` (list, create, update, delete, `mark-all-read`) that fail on N1; characterization tests of today's dispatcher through its public paths ([04](04-dispatcher-extraction.md) §4.8); a pure-function test for the client's HTML escaping. **Then fixes that need no new architecture:** route order and `whereNumber` (N1), `escapeHtml` on every interpolation in `NotificationService.ts` (N2), correct the two sponsorship producers and add `$casts` to `Notification` (N6), remove `addSampleData()` (N9), the connect provider (A3, P0 form), delete the dead `FunctionsController` analytics functions (A8). Register the marker in `plans/markers.md` | N1, N2, N6, N9, A3, A8 | — | OQ12 |
| **P1 — Namespaces and contracts** | `App\Services\Activity` (`Activity`, `ActivityCatalog`, `EventDefinition`, `ActivityPublisher` skeleton), the four contracts, `ActivityServiceProvider`; the generic `NotificationDispatcher` with `MailChannel`; the Direct Payments adapters (`DirectPaymentsActivityModule`, recipient resolver, variable builder, scheduled source, `DirectPaymentsActivities`); the three callers and the command move; the old class is deleted. **No schema change, no behaviour change** — the P0 characterization tests pass **unedited** | M1, M4 (mail context once per instance), M6, M9 | P0 | OQ1, OQ4 |
| **P2 — Schema and data** | `laravel_notification_rules` and `laravel_notification_sends`; the idempotent copy ([04](04-dispatcher-extraction.md) §4.3) including the `payment_receipt`/`payment_succeeded` merge ([06](06-activity-fan-out.md) §6.5); the Direct Payments save path, `publicList`, `listSends` and the command guard read the new tables; the old tables stay, unread and unwritten | M7 (server half) | P1 | OQ3, OQ7, OQ13 |
| **P3 — The inbox** | Extend `laravel_notifications`; `InboxChannel`; API: `unread-count`, `limit`/`page` use, `dedupe_key`, `severity`; client: severity, server unread count, module navigation registry, archive-all; the seven `portal.d.ts` copies | N3, N4, N5 (recorded, deferred), N7, N8, N10 | P0, P1 | OQ2, OQ8 |
| **P4 — Activity and analytics** | `ActivityPublisher` wired to `NotificationDispatcher` and `AnalyticsRecorder`; analytics schema, recorder, `analytics:rollup` and its schedule, the new `summary`, the hardened public endpoint, `source = seed`; `DeliverNotificationMail` (queued delivery); `ChargeSucceeded` and the webhook/subscription controllers publish Activities; the connect provider moves to rollups | A1, A2, A4, A5, A6, A7, A9, M2, M10, X2 | P1, P2 | OQ4, OQ5, OQ6, OQ13 |
| **P5 — Admin UI** | Catalogue and rules endpoints; `NotificationRulesEditor`; the Direct Payments form uses it; the sends panel and *Resend*; translations | M3 (visible), M5, M7 (client half) | P2, P4 | — |
| **P6 — Clean-up and verification** | After one release in which nothing reads them: drop the old Direct Payments notification tables; delete the old constants, `SendDirectPaymentMail` and the receipt service's rule logic; the grep gates become CI tests; the optional reconciliation command (05 §5.9); docs-site entries if the generated API reference covers these endpoints (`w/docs` — `openapi.json` mentions notifications; verify); completion records | — | P5 + one release | — |
| **P7 — Data lifecycle** ([08](08-data-lifecycle.md)) | **P7a (after P0, independent of P1–P6):** `config/data_lifecycle.php`; `RetentionPolicy`, `TimeWindowPolicy`, `Chunker`, `DataPruner`; `data:prune` (disabled by default) and `laravel_data_prune_runs`; the index migration (08 §8.10); the framework prunes scheduled; policies for webhooks (compact, then delete), webhook rejections and orphans, integration logs, websocket failures, progress records, expired sessions and tokens; `data:footprint`; `DataLifecycleClassificationTest` with its baseline. **P7b (after P3 and P4):** the analytics rollup retention; `AnalyticsEventsPolicy` with its two gates; `analytics:report`, `laravel_analytics_reports`, the three report endpoints, the Reports tab and the `report_ready` activity; `NotificationSendsPolicy` (stale `pending`); `InboxPolicy`; `--optimize`; the supervised first run (08 §8.5) | D1–D8, N10 (retention half) | P7a: P0 · P7b: P3, P4 | OQ18–OQ24 |

**The ordering gates that must not be relaxed**

1. **P0 before everything, and the characterization tests before any move in P1.** An extraction with no test on the old behaviour proves nothing.
2. **P1 changes no behaviour.** If a P0 test needs editing to pass in P1, the extraction is wrong, not the test.
3. **P2's copy is idempotent and non-destructive.** The old tables are not touched until P6.
4. **P4 wires `ChargeSucceeded` only after P2**, so the publisher reads the new tables; and the `payment_succeeded` change (one e-mail, every charge) ships with
   its merge data step, not before it.
5. **P3 and P2 may run in parallel after P1**; both need only the contracts.
6. **Nothing is enabled for other modules in this plan.** Adopting Forms, Events, Appointments, Ecommerce, Site Users, Sponsorships and Subscribers is a
   later plan each.
7. **P7a may start after P0 and ships disabled** (`DATA_LIFECYCLE_ENABLED` false). Nothing is deleted until the owner has read a dry run.
8. **Analytics detail is never deleted before its rollup and its quarterly report exist.** P7b therefore needs P4; the first prune on any site is a supervised
   `--force` run after `analytics:rollup --backfill` and `analytics:report --backfill` ([08](08-data-lifecycle.md) §8.5).

## 9.2 Environment

Mode is `vagrant` ([`../../DEVELOPMENT_MODE.md`](../../DEVELOPMENT_MODE.md)). Tests run on the portable MariaDB (`C:\Users\mrmit\mariadb`, port 3307,
`AFS_TEST_WIPE=1`) — **not SQLite**: the unique keys, the `JSON` columns and the `INSERT … ON DUPLICATE KEY UPDATE` rollup are MySQL behaviour. Run the
new test folders and the existing Direct Payments folder per phase; the full suite once at the end of a multi-phase block (it takes tens of minutes), with
progress output on, one heavy process at a time. TypeScript tests use the repo's Node test runner (`--experimental-strip-types`) on **pure functions
extracted from the DOM code**, so no package is installed; a `test:admin` script is added beside `test:builder`, `test:external-api` and `test:external-ui`.
Browser behaviour of the bell and the editor, a real e-mail send, and a real webhook delivery are verified by the owner on the dev server and recorded as
**unverified** in each completion record until then.

## 9.3 Test matrix

New tests under `tests/Feature/Notifications/`, `tests/Feature/Analytics/` and `tests/Unit/Notifications/`. The existing 764+ Direct Payments, Processor,
Site Users, Ecommerce, Events and Membership tests stay green throughout.

| Phase | Test | Proves |
|---|---|---|
| P0 | `NotificationApiTest` | list/get/update/delete; **`PATCH /notifications/mark-all-read` marks every unread row read** (red before the route fix); an unknown id is 404 |
| P0 | `DispatcherCharacterizationTest` | webhook `payment_failed` sends once to the payer; `upcoming_charge` at 7/3/1 days sends once per day across 96 ticks and re-arms when `next_charge_at` rolls; `custom` mode with three addresses = three ledger rows and three sends (B25); `customer` mode with no address = `skipped: no_recipient` and no retry; a `failed` row is not retried (D7); no active rule = one query and no output (D2); `cancel_at_period_end` = nothing |
| P0 | `escapeHtml.test.ts` | `<img src=x onerror=…>` in title/message/link becomes text |
| P0 | `SponsorshipNotificationTest` | an application and a display request each create one notification with its body |
| P0 | `AnalyticsProviderTest` | ten `page_load` events → `page_loads_30d = 10` |
| P1 | `LayeringTest` (extended) | no `DirectPayment`, `Processor`, `payer` or `subscription` in `app/Services/Activity`, `…/Notifications`, `…/Analytics` |
| P1 | `ActivityCatalogTest` | every Direct Payments event has a label, defaults and either a template or `defaultTemplate = null` on purpose; `defaults.json` `always_on` equals `EventDefinition::$alwaysOn` |
| P2 | `NotificationRuleMigrationTest` | the copy maps `is_active → mail_active`, `inbox_active = false`; a second run adds nothing; the merge rules of 06 §6.5 on all three row combinations |
| P2 | `DirectPaymentNotificationSaveTest` | the existing payload (no `inbox_active`) saves; removed rows are deactivated, not deleted; `alwaysOn` cannot be switched off; ≤3 days |
| P2 | `PublicNotificationsContractTest` | `{event, days_before, active}` and nothing else |
| P3 | `InboxChannelTest` | row has module/event/severity/subject/dedupe; a second publish with the same key writes nothing |
| P3 | `NotificationUnreadCountTest` | 150 unread → 150; archived excluded |
| P3 | `NotificationPruneTest` | archived > N days and read > 365 days go; unread never |
| P3 | `NotificationMetadataCastTest` | old rows (JSON string) read as arrays; new rows round-trip |
| P4 | `ActivityPublisherTest` | both consumers are called; one throwing does not stop the other or reach the caller; unknown pair = one warning |
| P4 | `PaymentSucceededFanOutTest` | one charge → one inbox row (if on), one mail per recipient (if on), one analytics event, one ledger row per channel/recipient — also on a duplicated webhook and with `charge.succeeded` before `invoice.payment_succeeded` |
| P4 | `NoProviderTest` | no mail provider: charge completes, webhook 2xx, inbox and analytics written, mail ledger `failed: no_provider_token` |
| P4 | `AnalyticsRecorderTest` | server event stored; dedupe; **no name or e-mail in any stored `metadata`** (a test greps every server event the suite creates) |
| P4 | `AnalyticsRollupTest` | idempotent (run twice = same rows); today and yesterday; value summed; retention only deletes rolled-up days |
| P4 | `AnalyticsSummaryTest` | same JSON keys as today; query count and peak memory bounded on 100,000 seeded rows; bounce rate `null` without a session id |
| P4 | `AnalyticsEndpointHardeningTest` | unknown pair 422; oversized metadata 422; throttle |
| P4 | `DeliverNotificationMailTest` | queued with the event's delay; `tries = 1`; a send failure records `failed` and does not re-send |
| P5 | `NotificationCatalogApiTest` | the catalogue equals the module's events; no hard-coded event list in the client (grep gate) |
| P5 | `NotificationRulesApiTest` | `PUT` upserts, omitted = deactivated; auth; `alwaysOn`; the resend clears one failed row and audits it |
| P5 | `notificationRulesEditor.test.ts` | effective-state rendering ("Using default"), `collect()` payload equals the old shape |
| P7a | `RetentionPolicyTest` | each policy deletes only rows past its cutoff and honours its condition (unread inbox rows, active sessions and unexpired tokens are never touched); chunking stops at the time budget and the next run resumes |
| P7a | `DataPrunerSafetyTest` | disabled = a dry run that deletes nothing; the breaker stops a 90 % delete of 200,000 rows until `--force`; a failing policy does not stop the next; the run row records the cutoff and counts |
| P7a | `ProcessorWebhooksPolicyTest` | payload set to `NULL` at 90 days with the row kept; row deleted at 730; a stored `invoice.payment_made` younger than 90 days is still found by `squareSubscriptionForOrder()` |
| P7a | `DataLifecycleClassificationTest` | a log-like table in a new migration with no policy and no `keep` entry fails, naming the table |
| P7a | `DataFootprintTest` | an unmanaged table over the threshold is listed |
| P7b | `AnalyticsPruneGateTest` | rows at 10, 89, 91 and 120 days: only rows older than 90 days **with a rollup and a quarterly report** are deleted; the 120-day row survives until its report exists |
| P7b | `AnalyticsReportTest` | one `final` report for a completed quarter with an exact distinct-visitor count; idempotent; `exact = false` when only rollups remain; a partial first quarter is marked; catch-up goes oldest first; `report_ready` is published once |
| P7b | `AnalyticsReportApiTest` | the list, the detail with both comparisons, the CSV with a BOM; authentication |
| P7b | `NotificationSendsPolicyTest` | a `pending` row older than 2 hours becomes `failed: stale_pending`; a row aged to 89 days still deduplicates a replayed activity; at 91 days it is deleted |
| P7b | `InboxPolicyTest` | archived after 180 days, read after 365, unread never |

## 9.4 Grep gates

Run as a CI script / unit test; each must be empty.

| Gate | Pattern | Scope |
|---|---|---|
| G1 — the general layer names no module | `DirectPayment\|Processor\|payer\|subscription` | `app/Services/Activity`, `app/Services/Notifications`, `app/Services/Analytics` |
| G2 — nobody calls a consumer directly | `NotificationDispatcher\|AnalyticsRecorder` | everywhere except `app/Services/Activity/ActivityPublisher.php`, the dispatcher's own namespace, the Direct Payments scheduled command, and tests |
| G3 — no service location | `\bapp\(` | the three new namespaces and `ActivityServiceProvider` (the provider uses `$this->app->singleton/bind` only) |
| G4 — the vocabulary has one home | the fourteen event strings | `resources/js/portal/admin/controllers/direct_payments/directpayments.ts`, `…/directpayments-form.html` |
| G5 — no hand-written inbox row | `Notification::create\|laravel_notifications` | everywhere except `InboxChannel`, the model, the API controller, seeders, and (until Forms/Sponsorships adopt) the three named producers |
| G6 — the old class is gone | `Externals\\DirectPayments\\Notifications\\NotificationDispatcher` | P1 onwards |
| G7 — the old tables are unread | `laravel_direct_payment_notification` | P2 onwards, except the copy migration and the retirement migration |
| G8 — one prune mechanism | `prune` | `routes/console.php` and `app/Console/Commands`, except `data:prune`, `data:footprint`, the two framework `queue:prune-*` entries and the existing `brain:storage-prune` |

## 9.5 Rollback

| Phase | Rollback |
|---|---|
| P0 | Revert the commit; the fixes are independent edits |
| P1 | Revert the commit; no schema or data changed |
| P2 | Point the Direct Payments code back at the old tables (revert the commit); rows saved since the switch are copied back by `notifications:copy-back` (written in P2, tested in `NotificationRuleMigrationTest`); the new tables stay, harmless |
| P3 | Revert the code; the added columns are nullable and unused by old code; the cast is reverted with the model |
| P4 | Disable publishing with `config('activity.enabled')` (default true; false makes `publish()` a no-op) and revert; analytics rows already written stay; rollups can be rebuilt with `analytics:rollup --backfill` |
| P5 | Revert the UI commit; the Direct Payments form falls back to the old payload, which the server still accepts |
| P6 | Not rolled back — it is gated on a full release with nothing reading the old tables |
| P7 | Set `DATA_LIFECYCLE_ENABLED=false` (the default). **Deleted rows cannot be restored** — which is why it ships disabled, the first run is supervised, the breaker exists and the analytics gates hold the raw rows until the report is made. Reports and rollups already written stay |

## 9.6 Risks

| Risk | Mitigation |
|---|---|
| The extraction changes behaviour without anyone noticing | The P0 characterization tests pass unedited across P1–P2; `--dry-run` output compared on the same data before and after |
| Tenant sites hold rows in the old tables | The copy is a migration, idempotent, and runs on every clone with `php artisan migrate --force`; counts are logged |
| A cycle or an early-resolved dependency at boot | The catalogue is a singleton built from named module classes; the module classes take no database-touching constructor work; a boot test builds the container |
| Two e-mails, or none, during the `payment_succeeded` merge | The merge is logged per Direct Payment; the fan-out test covers all three row combinations; P4 ships behind the merge step |
| The queue worker is not running on a tenant | Mail would sit pending. `DeliverNotificationMail` failures and age are visible in the ledger (`pending` older than 15 minutes is shown in the sends panel); the worker is already part of the scheduler on every site |
| Analytics grows until retention exists | Rollups ship with the recorder (P4); retention ships with the report (P7b), which follows P4 closely. Until then the raw table is bounded only by traffic |
| The bell polls the server every 15 seconds on every open dashboard | The poll is the cheap `unread-count`; the list is fetched when the dropdown opens; hidden tabs back off to 60 seconds |
| A bad cutoff or a clock fault deletes data that cannot be recovered | Disabled by default; the circuit breaker; a supervised first run; the cutoff is logged on every run; the analytics gates; the replay case is tested |
| A prune locks a busy table | Chunked with a pause and a time budget, run at 03:15, indexes added first ([08](08-data-lifecycle.md) §8.10) |

## 9.7 Completion records

Each finished step gets `complete/NN-<name>.complete.md` (H1 `# NN — Title (status)`, opening `## Status`, a phase table, and an explicit **Unverified**
section naming what needed the server: a real e-mail send, a real webhook, browser checks of the bell and the editor). Off-plan work goes in
`complete/00_extras.md` as dated `E<n>` sections. No `README.md` inside `complete/`.
