# 02 — Target Model (complete — the model is built and tested; nothing uses it yet)

## Status

**Complete 2026-10-19.** Nothing is committed. Steps **00** (overview) and **01** (findings) are analysis documents with nothing to build, so work started here, at 02. This step builds
the **shapes** every later step stands on: the one input (`Activity`), a module's vocabulary (`EventDefinition`, `ActivityModule` and three contracts), the catalogue that holds
them, the two tables (rules and the send ledger) with their models, and the rule that decides what applies to an `Activity`. **No behaviour of the running application changes:** no
module is registered, nothing publishes an `Activity`, nothing reads or writes the new tables except their tests, and the new config flag defaults to today's behaviour.

Everything below is in `w/www`.

## What is in this step and what is not

The pieces of [02](../02-target-model.md) §2.1, with where each one lands:

| Piece | State | Where |
|---|---|---|
| `Activity` | **built** | `app/Services/Activity/Activity.php` |
| `EventDefinition` | **built** | `app/Services/Activity/EventDefinition.php` |
| `ActivityModule` + `RecipientResolver`, `VariableBuilder`, `ScheduledSource` | **built** (interfaces) | `app/Services/Activity/Contracts/` |
| `ActivityCatalog` | **built** | `app/Services/Activity/ActivityCatalog.php` |
| Registration of modules | **built**, list empty | `app/Providers/ActivityServiceProvider.php`, listed in `bootstrap/providers.php` |
| `NotificationRule` + table `laravel_notification_rules` | **built** | `app/Models/Notifications/NotificationRule.php`, migration `2026_10_19_000001_create_laravel_notification_tables.php` |
| `NotificationSend` + table `laravel_notification_sends` | **built** | `app/Models/Notifications/NotificationSend.php`, same migration |
| Resolution order (§2.4) and "nothing configured" (§2.6) | **built** | `app/Services/Notifications/RuleResolver.php`, `EffectiveRule.php`, `config/notifications.php` |
| The code marker | **added** | `plans/markers.md`: `AFS_NOTIFICATIONS_MODIFIER_ADDITION` |
| `ActivityPublisher` | step 06 | — |
| `NotificationDispatcher` (generic), `MailChannel`, moving the Direct Payments rows | step 04 | — |
| `InboxChannel`, the extended `laravel_notifications` | step 03 | — |
| `AnalyticsRecorder`, `AnalyticsRollup`, the analytics columns (§2.7) | step 05 | — |

## Phases

[09](../09-rollout-and-testing.md) divides the work into phases; this step covers part of two of them.

| Phase | Part done here | Left for |
|---|---|---|
| P1 — Namespaces and contracts | `App\Services\Activity` (value objects, contracts, catalogue, provider) | the generic dispatcher and the Direct Payments adapters (step 04) |
| P2 — Schema and data | the two new tables and their models | the copy of the Direct Payments rows and the `payment_receipt` merge (step 04), the save path switch |

## What was built, in detail

- **`Activity`** — immutable. `module` and `event` are lower-case keys; references are null or 1–128 characters; money is an integer `valueCents` and a lower-cased three-letter `currency`, allowed only with a value;
  `data` may hold scalars and arrays at any depth but **never an object** (so an `Activity` can be queued and logged); `occurredAt` defaults to now. `scopeKey()` returns `''` for a module-wide
  Activity. `Activity::dedupeKeyFor()` builds the `<module>.<event>.<subject>[.<bucket>]` key of 06 §6.7.
- **`EventDefinition`** — the fields of 02 §2.3 and 06 §6.2, validated on construction: key format, a non-empty label, an always-on event cannot default its e-mail off, delay 0–3600 s,
  recipient mode `customer` or `handler`, lists of non-empty strings. `appliesToVariant()` answers the editor's filter.
- **`ActivityCatalog`** — built once from the provider's list and checked then, so a duplicate module, a duplicate event or a malformed key fails at boot. `module()`, `event()`, `events()`,
  `has()` return null / empty / false for anything unknown, never an exception.
- **The migration** creates both tables behind `Schema::hasTable` guards with every index named by hand: `notification_rules_ref_uq`, `notification_rules_scope_event_uq` (module, scope, event),
  `notification_rules_active_idx` (the scheduler's "is anything on" guard), `notification_sends_uq`, `notification_sends_scope_idx`, `notification_sends_created_idx`,
  `notification_sends_status_idx`. The last two are the indexes the data-cleanup job ([08](../08-data-lifecycle.md) §8.10) needs, so they exist from the start.
- **Empty strings, not NULL**, stand for "no scope" (a module-wide rule) and "no stored rule" (a send from defaults) — otherwise the unique keys would treat each as different. `NotificationRule`
  turns a null scope into `''`; `NotificationSend::claim()` fills the same defaults.
- **`NotificationSend::claim()`** inserts a send `pending` and returns `null` when the unique key already exists (a replay). It recognises **only** MySQL error 1062 (duplicate entry); any other
  integrity error, a missing required column for instance, still surfaces — swallowing those would hide bugs.
- **`RuleResolver::resolve(Activity, EventDefinition): EffectiveRule`** — the rule for the scope, else the module-wide rule, else the event's defaults **if** `notifications.use_event_defaults` is on.
  With it off (the default), "no rule" keeps meaning "no notification" except for an always-on event and an event that sends the item's attached handlers (`attachedHandlers`), so no site starts
  sending anything because of this change. A rule can switch things off or choose wording and handler; an always-on event ignores `mail_active = false`. The e-mail switch and the dashboard switch are independent.

## Tests

All on MariaDB 11.4.5 (port 3307) with the real create migration; run once at the end with progress output.

| File | Tests | Proves |
|---|---|---|
| `tests/Unit/Activity/ActivityTest.php` | 16 | defaults, module-wide scope, money and currency rules, key format (6 bad keys), empty reference refused, no object in `data` (also deep), dedupe-key convention, immutability |
| `tests/Unit/Activity/EventDefinitionTest.php` | 5 | quiet defaults, always-on rule, key/label/delay/mode/list validation, `appliesTo` |
| `tests/Unit/Activity/ActivityCatalogTest.php` | 8 | lookups, unknowns, empty catalogue, duplicate module, duplicate event, same event key in two modules, bad key / non-module, the container builds it once (singleton, empty) |
| `tests/Unit/Notifications/GeneralLayerTest.php` | 3 | **gates G1 and G3**: no `DirectPayment`, `Processor`, `payer` or `subscription` and no `app()` in the general layer; the check is not vacuous |
| `tests/Feature/Notifications/NotificationTablesTest.php` | 13 | migration creates, re-runs and rolls back; rule defaults; JSON round trip; scope `''`; one rule per (module, scope, event) including two module-wide rules; ledger claim, replay not re-claimed, failed row keeps its key, other recipient/channel/day/bucket/subject are new sends, "no rule" sends still dedupe, non-duplicate errors surface, index names |
| `tests/Feature/Notifications/RuleResolverTest.php` | 13 | scope rule beats module-wide; module-wide applies; no scope sees only module-wide; independent switches; always-on; defaults off = silence; defaults on; always-on without a rule; attached handlers; settings come through; empty handler = built-in default; bad mode falls back; other events/modules never used |
| `tests/Support/FakeActivityModule.php` | — | a generic module for tests; knows no real module |

Result: **72 tests, 204 assertions, OK** (in 11 seconds) — the **58 new tests** above plus **14 existing guards** run alongside them (`MigrationOwnershipTest`, 4, and `tests/Unit/DirectPayments`, 10, which includes the layering and
vocabulary gates). Then `ExtensionPointsTest` + `BrainCommandsEndpointTest`: **27 tests, 120 assertions, OK** (the new provider is loaded at boot by every test, so a heavier existing test that rebinds providers was run too).
The full suite was not run (only new, additive code was added). `php -l` is clean on every new file. MariaDB was shut down cleanly afterwards; no `php` or `mariadbd` process was left running.

## Deviations from the plan text

1. **`appliesTo` is a list of module-defined tokens, not `single_pay | subscription | both`.** The plan's own gate G1 forbids the word "subscription" in the general layer, and the plan's snippet contained it.
   Direct Payments will supply `single_pay` and `subscription` as its own tokens in step 04; an empty list means every variant. [02](../02-target-model.md), [04](../04-dispatcher-extraction.md) §4.4 and [06](../06-activity-fan-out.md) §6.2 were corrected.
2. **`EventDefinition` gained `recipientMode`** (`customer` or `handler`) — 06 §6.2 listed "recipientMode default" in prose; 02 now carries it in the definition.
3. **`rule_reference_num` is `''`, not NULL, for "no stored rule"** in the ledger (02 §2.5 now says so) — for the same unique-key reason the plan already gave for `scope_ref`.
4. **Contract method names were chosen here:** `RecipientResolver::customerEmail(Activity)`, `VariableBuilder::build(Activity, EventDefinition)`, `ScheduledSource::due(EventDefinition, ?scopeRef, maxDaysOut)`. Step 04 may refine them when the Direct Payments adapters are written.
5. **`NotificationSend::claim()` and `EffectiveRule`/`RuleResolver`** are small additions the plan implied (the ledger's insert-or-skip and the resolution order) but did not name.

## Built on assumptions the owner has not yet confirmed

The open questions in [10](../10-open-questions.md) are still open. This step was built on the **recommended** answer to each one it touches. Each is cheap to change now, because nothing uses the model yet:

| Question | Assumed | If it goes the other way |
|---|---|---|
| OQ1 — the general dispatcher's name | keep `NotificationDispatcher` (not built here; only namespaces `App\Services\Activity` / `…\Notifications` chosen) | a rename touches no file in this step |
| OQ3 — how the tables arrive | **new tables in a new migration** (this migration), copy step later | if the owner prefers editing the Direct Payments create migration, only the *copy* in step 04 changes; these two tables are new either way |
| OQ7 — what a site with no rules does | **defaults off**, behind `notifications.use_event_defaults` (an `.env` key `NOTIFICATIONS_USE_EVENT_DEFAULTS`, default false) | where the flag is stored (`.env`, or a settings row the clean-install SQL writes) is still undecided; the resolver reads `config()` so either works |
| OQ9 — module-wide rules | allowed (`scope_ref = ''`) | drop the second lookup in `RuleResolver::find()` |
| OQ12 — the marker | new `AFS_NOTIFICATIONS_MODIFIER_ADDITION`, added to `plans/markers.md` | replace the string in this step's files |

## Not verified

- Nothing was run on a server; the migration was run only on the local test database. It will be applied on the dev server by the next `php artisan migrate --force` (or a fresh install) — **not done**.
- No module is registered and nothing publishes, so the catalogue and resolver have not been exercised by real traffic, only by fakes and fixtures.
- The tables' behaviour with very large row counts and the `notification_sends_uq` key length (≈ 1.7 KB of 3 KB allowed per index) were reasoned, not measured; MariaDB accepted the migration.

## Next

Step **03** (the admin inbox) is next in order and needs only this model. Step **04** (the dispatcher extraction) is where `DirectPaymentsActivityModule` registers in `ActivityServiceProvider` and the Direct Payments rows are copied. The phase **P0** work
(tests and quick fixes for N1, N2, N6, N9, A3, A8) is independent of this step and can be done at any point.
