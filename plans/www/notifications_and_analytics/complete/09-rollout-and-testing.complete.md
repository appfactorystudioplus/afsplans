# 09 — Rollout and Testing (complete — every phase that can be built before a release has been built and tested; P6, the retirement of the old code, is deliberately a later release; nothing has run on a server)

## Status

**2026-10-19.** Nothing is committed. This step is the plan's own rollout and test plan, so its work is the closing of the phases it names: the remaining **grep gates** are tests, the **optional reconciliation** (05 §5.9) is built, the **whole PHP suite was run once at the end**, and this record states exactly what is built, what is
assumed, what is unverified and what must be done by a person on a server, in order.

## The phases (09 §9.1) and where each ended up

| Phase | What it was | State | Record |
|---|---|---|---|
| **P0** Proof and quick fixes | N1 route order, N2 escaping, N6 producers, N9 sample data, the connect provider (A3), the dead analytics functions (A8), characterization tests of the old dispatcher | **done** | [02](02-target-model.complete.md), [03](03-admin-inbox.complete.md), [04](04-dispatcher-extraction.complete.md), [05](05-analytics.complete.md) |
| **P1** Namespaces and contracts | `Activity`, `ActivityCatalog`, the contracts, the general dispatcher and `MailChannel`, Direct Payments as the first module; no behaviour change (the 17 characterization tests pass unedited) | **done** | [02](02-target-model.complete.md), [04](04-dispatcher-extraction.complete.md) |
| **P2** Schema and data | the rules and ledger tables, the idempotent copy, the `payment_receipt` / `payment_succeeded` merge | **done** (migrations `…000001`, `…000003`, `…000005`) | [04](04-dispatcher-extraction.complete.md), [06](06-activity-fan-out.complete.md) |
| **P3** The inbox | extended `laravel_notifications`, `InboxChannel`, the repaired API, the client | **done** | [03](03-admin-inbox.complete.md) |
| **P4** Activity and analytics | `ActivityPublisher`, the recorder, rollups, the new summary, the hardened endpoint, queued mail, `ChargeSucceeded` publishing | **done** | [05](05-analytics.complete.md), [06](06-activity-fan-out.complete.md) |
| **P5** Admin UI | the catalogue and rules API, `NotificationRulesEditor`, the Direct Payments form, the sends panel and *Allow resend*, translations | **done, never opened in a browser** | [07](07-admin-ui.complete.md) |
| **P6** Clean-up and verification | drop the old Direct Payments notification tables; delete `SendDirectPaymentMail` and the receipt service's rule logic; the grep gates as tests; the optional reconciliation | **gates and reconciliation done; the deletions are NOT done, on purpose** (below) | this record |
| **P7a / P7b** Data lifecycle | `data:prune` (disabled), policies, run log, footprint, the classification test; the analytics gates, the quarterly report, its API and tab | **done; ships disabled** | [08](08-data-lifecycle.complete.md) |

### Why P6's deletions wait

The plan gates them on "after one release in which nothing reads them" ([09](../09-rollout-and-testing.md) §9.1). Nothing has been released: no site runs this code. Deleting the old tables and the old receipt job now would remove the only rollback (09 §9.5: "point the code back at the old tables"). What is true today is enforced by tests so the later deletion is safe:
the old tables are read by nothing (G7), the old models are gone, and nothing queues the old receipt job. When a release has run: drop `laravel_direct_payment_notifications` and `…_sends`, delete `app/Jobs/SendDirectPaymentMail.php`, `DirectPaymentCommunicationService::paymentSucceeded()` and the `payment_receipt` lookups, the Direct Payments adapter's unused parts, and the `keep` lines that excuse them.

## What this step added

- **Grep gates as tests** (09 §9.4). G1 and G3 (the general layer names no module and uses no `app()`) cover `Activity`, `Notifications`, `Analytics`, `DataLifecycle` and the models; G2 (nobody calls the recorder or the dispatcher except the publisher and the named Direct Payments entry points), G4 (no event name in the form or the editor — a TypeScript test), G8 (one prune mechanism) already existed; **new:** G5 (no hand-written inbox row except the inbox channel, the model, the API and the two sponsorship producers still to be adopted — and a second test that fails when that
  allow-list goes stale), G7 (the old tables are read by nothing), the old models are gone, and nothing dispatches the old receipt job. *G6 (the old dispatcher class is gone) was not made a gate*: the Direct Payments class was kept as a thin adapter on purpose (04 deviation 1; 06 deviation 2), and G2 pins who may use it.
- **The reconciliation** (05 §5.9, optional). `direct_payments:reconcile-analytics [--days=7]`, scheduled weekly: per Direct Payment and currency it compares the payments recorded in analytics with the invoices that succeeded in the same whole days, prints a table, logs a warning for each line that differs (`missing_events`, `extra_events`, `amount`) and **changes nothing**. A refund is a separate record and never alters an invoice, so it compares charges. 9 tests.
- **The whole PHP suite, once at the end** (below).

## Test matrix (09 §9.3) — what proves what

| Matrix row | Where it lives |
|---|---|
| P0 `NotificationApiTest`, `DispatcherCharacterizationTest`, `escapeHtml.test.ts`, `SponsorshipNotificationTest`, `AnalyticsProviderTest` | `Notifications/NotificationApiTest`, `DirectPayments/NotificationDispatchCharacterizationTest` (17), `admin/tests/notificationHelpers.test.ts`, `Notifications/SponsorshipNotificationTest`, `Analytics/AnalyticsConnectProviderTest` (ten events → 10) |
| P1 `LayeringTest`, `ActivityCatalogTest` | `Unit/Notifications/GeneralLayerTest`, `Unit/Activity/ActivityCatalogTest`, `DirectPayments/DefaultEmailsTest` |
| P2 `NotificationRuleMigrationTest`, `…SaveTest`, `PublicNotificationsContractTest` | `Notifications/CopyDirectPaymentNotificationsTest`, `MergePaymentReceiptRulesTest`, `DirectPayments/DirectPaymentsNotificationModuleTest` (save path, public list, identical rows through both save doors) |
| P3 `InboxChannelTest`, unread count, metadata cast, prune | `Notifications/InboxChannelTest`, `NotificationApiTest`, `DataLifecycle/RetentionPoliciesTest` (inbox) |
| P4 `ActivityPublisherTest`, `PaymentSucceededFanOutTest`, `NoProviderTest`, `AnalyticsRecorderTest`, `AnalyticsRollupTest`, `AnalyticsSummaryTest`, `AnalyticsEndpointHardeningTest`, `DeliverNotificationMailTest` | `Notifications/ActivityPublisherTest` (incl. queued mail, no provider), `DirectPayments/PaymentSucceededFoldTest`, `Analytics/*` |
| P5 `NotificationCatalogApiTest`, `NotificationRulesApiTest`, `notificationRulesEditor.test.ts` | `Notifications/NotificationRulesApiTest`, `admin/tests/notificationRulesModel.test.ts` |
| P7a `RetentionPolicyTest`, `DataPrunerSafetyTest`, `ProcessorWebhooksPolicyTest`, `DataLifecycleClassificationTest`, `DataFootprintTest` | `DataLifecycle/RetentionPoliciesTest`, `DataPrunerSafetyTest`, `LifecycleCommandsTest`, `Unit/DataLifecycle/DataLifecycleClassificationTest` |
| P7b `AnalyticsPruneGateTest`, `AnalyticsReportTest`, `AnalyticsReportApiTest`, `NotificationSendsPolicyTest`, `InboxPolicyTest` | `RetentionPoliciesTest` (the 10/89/91/120-day case, sends, inbox), `DataLifecycle/AnalyticsReportTest` |

## The whole suite

Run once at the end, one process at a time, on MariaDB 11.4.5, after the owner's answers of step 10 were built in:

- **Everything except the Brain folder** (`tests/Unit` and every `tests/Feature` folder): **1,610 tests, 6,257 assertions; the only errors are the 18 of the known Windows `LaravelCronScriptTest` setUp problem.**
- **`tests/Feature/Brain`, file by file** (the folder as one run exceeds PHP's time limit): every file passes except `BrainDeviceTokenCommandTest` (4 errors, 1 failure — the known Windows problem that its printed token line is not captured; unrelated to this plan) and two deprecation notices in `BrainInfrastructureAuthGateTest`.
- After that run, the owner chose that **payment failed and subscription canceled notify by default** (bell and e-mail); that change was made once the big run was already under way, so the 216 notification-related tests (`Feature/Notifications` and the Direct Payments notification files) were **re-run against the changed code: green**, with 3 new tests for the defaults. The rest of the suite was not re-run after that one change, which touches only the Direct Payments module's two event definitions.
- TypeScript: `test:admin` 79, `test:external-api` 22, `test:builder` 481 pass; `tsc --noEmit` clean.

## Rollback (09 §9.5) — what is true now

| Phase | Rollback |
|---|---|
| P2 | Point Direct Payments back at the old tables (revert the commit): they were never touched. Rows saved since are not copied back (no `notifications:copy-back` was written) — re-enter them, or write it before relying on this path. The `payment_receipt` merge is not undone (its settings live on in `payment_succeeded`) |
| P4 | `ACTIVITY_ENABLED=false` makes `publish()` do nothing (no bell, no e-mail, no analytics event); analytics rows already written stay; rollups can be rebuilt with `analytics:rollup --backfill` |
| P5 | Revert the UI commit; the form falls back to the old payload, which the server still accepts (a form that sends `payment_receipt` is understood) |
| P7 | `DATA_LIFECYCLE_ENABLED` is false by default. **Deleted rows cannot be restored.** Reports, rollups and the run log stay |

## Assumptions the owner has not confirmed

Recorded in [10](../10-open-questions.md) and in each step's record: the new-tables-plus-copy approach (OQ3), no defaults on existing sites (OQ7 (c): `notifications.use_event_defaults` off), module-wide rules (OQ9), the class name (OQ1), the marker `AFS_NOTIFICATIONS_MODIFIER_ADDITION` (OQ12, already in `plans/markers.md`), the fold of `payment_receipt` into `payment_succeeded` (OQ13 — **confirmed by the owner** during step 06), the retention numbers (OQ18 — confirmed) and shipping the job disabled (OQ21 — confirmed). Open: OQ1–OQ6 and OQ8–OQ17 as listed there; OQ23 (Brain-scope retention and business-history tables) must be answered before any of those are pruned.

## What has NOT been verified (all of it needs a person and a server)

1. **Nothing has run on a server or in a browser.** The bell, the Direct Payments form with its new switches, the sends drawer, the Analytics Activity card and Reports tab were type-checked and their logic tested; none was looked at.
2. **No real payment, webhook or e-mail.** The sandbox-card → webhook → receipt walk-through in the handoff (§7.9) is still the check that matters most; the receipt is now sent by a queued job, so the queue worker (the existing per-minute `queue:work` schedule line) must be running on the site.
3. **MySQL 8** (production) was not run; every test ran on MariaDB 11.4.5. The SQL used is portable by design (`GROUP BY` aliases, `JSON_EXTRACT`, `OPTIMIZE TABLE`), but `ONLY_FULL_GROUP_BY` behaves differently between them and the first MySQL run should be read carefully.
4. **Scale:** the summary was measured at 100,000 events (≤ 20 queries, < 8 MB); 1,000,000 was not.
5. **Deploy order:** `migrate --force` before the code serves traffic (the migrations are `…000001` to `…000007`); then the supervised sequence in [08](08-data-lifecycle.complete.md).

## Next (all need the owner)

1. Commit, per repo, when told: `w/www` (everything above), `packages/afsserver` (the plans), and the seven `portal.d.ts` copies changed in step 03.
2. Deploy to the dev server (`migrate --force`, then the supervised first run) and walk one sandbox payment end to end; open the Direct Payments form and the Analytics screen.
3. Answer OQ23 (Brain-scope retention), then add those policies.
4. After one real release: P6's deletions, listed above.
5. Adopt the system module by module (Forms, Events, Appointments, Ecommerce, Site Users, Sponsorships, Subscribers — each a small plan, [06](../06-activity-fan-out.md) §6.6); each adoption removes one allow-list line from gate G5 or G2.
