# 04 — Extracting the Dispatcher from Direct Payments (complete — Direct Payments now runs on the general system; proved by tests, not yet run on a server)

## Status

**Complete 2026-10-19.** Nothing is committed. The Direct Payments notification path — a webhook event and the 15-minute scheduled run — now goes through the general
`App\Services\Notifications\NotificationDispatcher`; Direct Payments is the first module registered in the system. **The behaviour the owner relies on did not change:** 17
characterization tests written against the **old** dispatcher (17 tests, 62 assertions, green on the old code) pass against the new one with their assertions unchanged.

One deliberate behaviour change, one defect found and fixed on the way: see "Deviations" 7 and 8.

Everything below is in `w/www`.

## Phases

| Phase | Piece | State | Where |
|---|---|---|---|
| P0 | Characterization tests of the old path | **built first, green on the old code** | `tests/Feature/DirectPayments/NotificationDispatchCharacterizationTest.php` |
| P1 | General dispatcher: rules → ledger → recipients → channels, the "N days before" loop, swallow-everything | **built** | `app/Services/Notifications/NotificationDispatcher.php` |
| P1 | `MailChannel` (handler, else built-in default; one recipient per call) | **built** | `app/Services/Notifications/Channels/MailChannel.php` |
| P1 | Generic built-in e-mail reader (`<folder>/<event>`) | **built** | `app/Services/Notifications/DefaultEmail.php` |
| P1 | Mail seam for the general layer + bridge to the Direct Payments seam | **built** | `Contracts/MailTransport.php`, `Mail/GeneralMailTransport.php`, `DirectPaymentsServiceProvider` |
| P1 | Direct Payments as a module: events, recipient resolver, variable builder, scheduled source, Activity builder | **built, registered** | `app/Externals/DirectPayments/Notifications/DirectPayments*.php`, `ActivityServiceProvider` |
| P1 | Direct Payments entry point kept as a thin adapter (same API) | **built** | `app/Externals/DirectPayments/Notifications/NotificationDispatcher.php` |
| P2a/b | Copy of settings and ledger into the general tables | **built** | migration `2026_10_19_000003_copy_direct_payment_notifications_to_rules.php` |
| P2c | Switch every reader and writer: save path, form read, public list, ledger endpoint, command guard, sync horizon, receipt, mailer | **built** | see below |
| P6 | Retire the old tables | **not done** — by design, a later release | — |

## What was built, in detail

- **`NotificationDispatcher::dispatch(Activity)`** resolves the rule (step 02's `RuleResolver`), builds the module's variables, and for each switched-on channel delivers: e-mail to one recipient at a time after
  claiming a ledger row (`NotificationSend::claim`), the dashboard notification through step 03's `InboxChannel`. It never throws. A mail transport that throws is recorded as a **failed** send (the ledger row says why) instead
  of leaving a `pending` row behind.
- **`evaluateScheduled(moduleKey, scopeRef, dryRun, cap)`** is the old loop made generic: for every active rule of a scheduled event it skips outside `send_window_hour`, asks the module's `ScheduledSource` what is due, keeps what is
  exactly N days out for one of the rule's days, then dry-runs, caps, or delivers. A module-wide rule does not also handle an item that has a rule of its own.
- **Recipients** keep the three modes: `custom` (the rule's list), `handler` (the handler's own list, untouched; the ledger gets its first address) and `customer` (the module's answer). A customer with no address is recorded once as
  `skipped: no_recipient`.
- **Ledger identity.** A periodic send is identified by the subject and the `bucket` the module puts in `Activity::$data` (a charge date, the minute of a webhook); a one-off send by the dedupe key. Direct Payments' webhook events use the
  minute, exactly as before, so a retry in the same minute is one e-mail and a second failure five minutes later is another.
- **Direct Payments module.** `DirectPaymentsActivityModule::events()` is now the one list of the fourteen events (labels and variables read from the template manifest, `alwaysOn` for the four processor-change e-mails, `scheduled`
  for `upcoming_charge` and `trial_ending`, `appliesTo` tokens `single_pay` / `subscription`). Nothing is on by default except the compulsory events, so a site with no settings still sends nothing. A test checks the module against
  the manifest so the two cannot drift.
- **Template folder.** `DefaultEmail` reads `mjml/defaults/<folder>/`; Direct Payments' folder stays `dp` and **no template file moved**. The two values only those templates read (`dp_greeting`, `dp_card`) are added by the module through the
  optional `DefaultEmailVariables` contract — only when a built-in template is sent, so a chosen mail handler still receives exactly the variables it always did.
- **The switch (P2c).** `DirectPaymentsNotificationRules` is the one place that reads and writes Direct Payments' rows of `laravel_notification_rules`; the admin form still receives `is_active` (it is `mail_active`), the public SDK list still
  returns `{event, days_before, active}` only (`active` = e-mail **or** dashboard), and the ledger endpoint still returns its old field names. The save path keeps its request shape and accepts an optional `inbox_active`; a form that does
  not send it leaves it as it was, and an event left out of a save switches **e-mail** off but never deletes the row or touches the dashboard switch. Validation (unknown event, 1–3 days in 1..7, always-on cannot be switched off,
  dangling handler) is unchanged.
- **The data copy** is its own migration: guarded, one transaction, `INSERT IGNORE` on the unique keys (re-runnable, adds only what is missing, never overwrites a row already in the new table), and it **aborts and rolls back if fewer rows
  arrive than left**. The old tables are not touched.
- **Callers unchanged.** The webhook controller, the subscriber controller and `direct_payments:subscription-notify` (name, options, schedule, dry-run table) still call the Direct Payments `NotificationDispatcher`, which now only
  translates to an Activity and calls the general one.

## Tests

PHP on MariaDB 11.4.5, port 3307. Progress shown.

| File | Tests | Proves |
|---|---|---|
| `Feature/DirectPayments/NotificationDispatchCharacterizationTest` | 17 | the old behaviour, unchanged: webhook send, nothing configured = nothing written, switched off, one event does not switch on another, same-minute retry once / later failure again, custom = one call each, handler mode, no address = skipped once, failed is not retried, no contract ignored, reminder days 7/3/1, forty 15-minute ticks = one e-mail, dry run, cap, send-window hour, another item, cancel-at-period-end |
| `Feature/Notifications/NotificationDispatcherTest` | 19 | the general dispatcher with a module that is not Direct Payments: rules decide, once-only ledger, the two switches independent, both channels, custom recipients, no address, failed held, a throwing transport = failed row, unknown module/event, periods, scheduled days, dry run and cap, **duplicates do not use up the cap**, scoped rule vs module-wide rule |
| `Feature/Notifications/CopyDirectPaymentNotificationsTest` | 7 | every field mapped, old tables untouched, re-run adds nothing / only what is missing / never overwrites, empty or missing table, long subscription id shortened as the dispatcher does |
| `Feature/DirectPayments/DirectPaymentsNotificationModuleTest` | 15 | the fourteen events once, single-payment tokens, quiet defaults, a template for every event, the save path (rules created with the dashboard switch off, reference kept, left-out event off not deleted, inbox switch kept when not sent, validation), the form's shape, the public list's shape (and that no mail setting leaks), the ledger endpoint's field names, the command guard, deleting a Direct Payment |
| `DefaultEmailsTest`, `ExtensionPointsTest`, `SaveDirectPaymentTest`, `ActivityCatalogTest` | edited | fixtures and the two reflection tests moved to the module's public classes; `DefaultEmailsTest` also checks the module against the manifest |
| existing `GeneralLayerTest` | — | gates G1 and G3 hold for every new class in the general layer |

## Final run

Run once at the end, below-normal priority, one process at a time, against MariaDB 11.4.5; MariaDB was shut down cleanly afterwards and no `mariadbd`, `php`, `node` or Electron process was left.

| Pass | Suites | Result |
|---|---|---|
| A | `tests/Unit`, `tests/Feature/Notifications`, `tests/Feature/Externals`, `BrainCommandsEndpointTest` | 516 tests, 1289 assertions: everything passes **except** 18 errors in `Tests\Unit\Scripts\LaravelCronScriptTest` ("typed property `$dir` must not be accessed before initialization", `LaravelCronScriptTest.php:72` — that test's own setup on this Windows machine; it does not touch notification code, but I did **not** run it on the code from before this step to prove it was already failing) and one test-side mistake of mine in `ActivityCatalogTest` (fixed; `tests/Unit/Activity` re-run: 30 tests, 73 assertions OK) |
| B | `tests/Feature/DirectPayments`, `tests/Feature/Processor` | **OK — 518 tests, 2744 assertions** (includes the 17 characterization tests, the 15 module tests, `DefaultEmailsTest`, `ExtensionPointsTest`, `SaveDirectPaymentTest` and all webhook / subscription / migration suites) |

Not run: the rest of `tests/Feature` (Admin, Appointments, Builder, Connections, Ecommerce, Events, Forms, Members, Membership, Observers, SiteUsers) — nothing in this step touches them, but they were not run.

An earlier pass found 6 errors in older Direct Payments tests that build only part of the schema (the rules table did not exist there). They exposed a real deploy risk — code reaching a site before its migrations — so `DirectPaymentsNotificationRules` now answers "no settings" when the table is missing instead of throwing (Deviation 11).

## Deviations from the plan text

1. **The Direct Payments `NotificationDispatcher` class was kept**, as a thin adapter with its old public API (`dispatchEvent`, `evaluateScheduled(?dpRef, …)`), instead of being deleted after its callers move. Moving ten call sites
   changes nothing a user sees and is what step 06 (`ActivityPublisher`) replaces anyway; keeping the entry point is also what lets the characterization tests run **unedited** against both versions.
2. **`evaluateScheduled` takes a module key**, not a `ScheduledSource` (the dispatcher asks the catalogue for the module's source), and returns the same array shape as before (`evaluated/sent/skipped/failed/deduped/rows`), not a `ScheduledStats` object, so the command is untouched. The dry-run row has a `subject` key (the command maps it to its old column).
3. **The characterization tests' fixture helpers were edited** (three of them: where configuration and ledger live); their assertions were not.
4. **A single mail seam, bridged.** The plan said `MailChannel` folds `DirectPaymentMailer`; `DirectPaymentMailer` stays as the Direct Payments sender for the receipt and the processor-change e-mails and delegates to `MailChannel`. The general layer gets its
   own `MailTransport` contract; `GeneralMailTransport` hands every send to the Direct Payments transport, so one binding (or one test stand-in) serves both.
5. **"Resolve the Brain configuration once per instance" (04 §4.7) was not changed.** It belongs to `MailHandlerSender` / `MailHandlerTransport`, which this step did not touch.
6. **Validation reads the module's constants** (`DirectPaymentsActivityModule::EVENTS`, `ALWAYS_ON_EVENTS`, `SCHEDULED_EVENTS`), the same single definition the catalogue is built from, not `ActivityCatalog` injected into the controller. The admin form's
   own drop-down and `SINGLE_PAY_NOTIFICATION_EVENTS` list in `directpayments.ts` are **not** yet built from the catalogue — that is step 07.
7. **One deliberate behaviour change: duplicates no longer count against the per-run cap.** The old dispatcher counted every attempt, including ones the ledger turned away. With the default cap of 100 and more than 100 already-sent
   items inside the reminder window, new items could never be reached. Now the cap limits real deliveries. (Covered by `test_items_already_sent_do_not_use_up_the_cap_so_new_ones_are_not_starved`.)
8. **A throwing transport is recorded as `failed`** (with the message) instead of leaving a `pending` row that blocks the key silently.
9. **The old models were deleted** (`DirectPaymentNotification`, `DirectPaymentNotificationSend`) along with their constants; the tables stay.
11. **A missing rules table reads as "no settings".** Every Direct Payments reader (`DirectPaymentsNotificationRules`, the public list) returns "none" when `laravel_notification_rules` does not exist yet, so a deploy that reaches
    the code before `migrate` cannot break a payment receipt or a webhook. (The save path still needs the table.) This does not remove the rule "migrate first"; it limits the damage if it is forgotten.
10. **Not done from 04 §4.3:** adding "the two new tables exist and are empty" to the clean-install SQL-import verification (the data-only SQL holds no notification rows; the check belongs with the install scripts), and the reverse copy for rollback
    (`09` §9.5) — rollback before P6 is "switch the code back"; rows saved after the switch would need re-copying by hand.

## Built on assumptions the owner has not yet confirmed

OQ3 (new tables + copy, as built), OQ7 (defaults off: Direct Payments declares every non-compulsory event off, so nothing starts sending), OQ9 (module-wide rules allowed — the loop handles them), OQ1 (the general class is named
`NotificationDispatcher`, in `App\Services\Notifications`). All still open in [10](../10-open-questions.md).

## Not verified

- **Nothing was run on a server, and no real e-mail was sent.** The tests use a recording transport; the real send path (MJML transform + the mail provider) is exactly what it was.
- **The copy migration has only run on the local test database** with seeded rows. On the dev server it will run with the next `php artisan migrate --force`; tenant sites that have rows will copy them. After it runs, compare
  `SELECT COUNT(*) FROM laravel_direct_payment_notifications` with `… FROM laravel_notification_rules WHERE module='direct_payments'` (the migration aborts if the second is smaller).
- **Deploy order matters:** the code reads the new tables, so the migrations must run first (as for step 03). A site where they have not run has no Direct Payments notification settings until they do.
- The admin form, the Direct Payments editor and the SDK were not exercised in a browser; their request and response shapes are asserted by tests.
- `routes/console.php` / the 15-minute cron was not run; the command's guard and its dry-run table are covered through the same classes it calls, not by invoking the artisan command.

## Next

Step **05** (analytics) and step **06** (the `ActivityPublisher` fan-out, which replaces the ten Direct Payments call sites) are next in order; step **07** (the admin UI) builds the event editor from the catalogue this step filled.
