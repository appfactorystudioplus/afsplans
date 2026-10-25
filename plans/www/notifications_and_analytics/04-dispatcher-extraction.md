# 04 — Extracting the Dispatcher from Direct Payments

Closes **M1, M4, M6, M7, M9** ([01](01-audit-findings.md) §1.2). The aim is a refactor, not a rewrite: the behaviour listed in §4.5 does not change,
and a test proves it before and after.

> **Built 2026-10-19** — see [`complete/04-dispatcher-extraction.complete.md`](complete/04-dispatcher-extraction.complete.md). Where the build differs from the text below
> (the Direct Payments `NotificationDispatcher` class stays as a thin entry point instead of being deleted; the generic one takes a module key, not a `ScheduledSource`; the
> old tables stay until step 08) the completion record lists each difference.

## 4.1 What `NotificationDispatcher` does today, method by method

File: `app/Externals/DirectPayments/Notifications/NotificationDispatcher.php` (402 lines). Constructor takes one `DirectPaymentMailer`.

| Method (lines) | What it does | Generic or Direct Payments | Goes to |
|---|---|---|---|
| `evaluateScheduled()` (38–118) | For each active scheduled rule: skip outside `send_window_hour`; query subscriptions whose `next_charge_at`/`trial_ends_at` falls within `max(days_before)`; for each, compute days out, match against `days_before`, honour a per-run send cap, dispatch | **Both.** The loop, window, day match and cap are generic. The query (joins `laravel_processor_subscriptions`, `cancel_at_period_end`, statuses) is Direct Payments | Loop → `NotificationDispatcher::evaluateScheduled(ScheduledSource, …)`; query → `DirectPaymentsScheduledSource` |
| `dispatchEvent()` (130–159) | Given a `ProcessorSubscription`: find the contract via `SubscriptionContracts::contractFor`; find the active rule for (Direct Payment, event); build variables; dispatch. Swallows every exception | **Both.** "Find the rule, dispatch, swallow" is generic. "Subscription → contract → Direct Payment" is Direct Payments | Generic: `NotificationDispatcher::dispatch(Activity)`; translation: a small `DirectPaymentsActivities::subscriptionEvent(…)` that builds the `Activity` |
| `dispatchToRecipients()` (168–217) | Resolve recipients; for each, insert a `pending` ledger row (skip on duplicate), send, update the row, log | **Generic** except the send call (`DirectPaymentMailer`) | `NotificationDispatcher` + `MailChannel` |
| `resolveRecipients()` (222–243) | `custom` → the rule's list; `handler` → the handler's own list (a label for the ledger), falling back to the customer when there is no handler; `customer` → the module's lookup | **Generic** modes; the `customer` lookup is the module's | `NotificationDispatcher` + `RecipientResolver` |
| `resolveCustomerEmail()` (249–266) | The payer's e-mail, then the provider customer's e-mail, else null | **Direct Payments** | `DirectPaymentsRecipientResolver` |
| `firstHandlerEmailLabel()` (268–279) | The first e-mail address in a mail handler's JSON, for the ledger | **Generic** (mail handler) | `MailChannel` |
| `insertPendingLedgerRow()` / `recordLedgerOutcome()` (284–329) | Insert with the unique key; on violation return null (the dedupe) | **Generic** | `NotificationSend` model + dispatcher |
| `buildScheduledVars()` / `buildActionVars()` / `buildVars()` (335–400) | The `[{name, value}]` list: first/last name, e-mail, amount, plan name, card, manage URL, site name/url, `access_ends_at`, plus overrides | **Direct Payments** (reads payer, customer, plan, Direct Payment) | `DirectPaymentsVariableBuilder` |

Everything Direct Payments-specific already sits behind four narrow questions — *who is the recipient*, *what are the variables*, *which subscriptions are
due*, *what is this subscription's Direct Payment* — which is why this is a refactor.

## 4.2 Where each class lands

| Today | After | Notes |
|---|---|---|
| `App\Externals\DirectPayments\Notifications\NotificationDispatcher` | **`App\Services\Notifications\NotificationDispatcher`** (generic) | The Direct Payments class is deleted once its three callers move |
| — | `App\Services\Notifications\Channels\MailChannel`, `InboxChannel` | `MailChannel` wraps `DirectPaymentMailer`'s logic generically (§4.7) |
| — | `App\Services\Activity\{Activity, ActivityPublisher, ActivityCatalog, EventDefinition}` and `…\Contracts\{ActivityModule, RecipientResolver, VariableBuilder, ScheduledSource}` | [02](02-target-model.md) |
| `App\Models\Externals\DirectPaymentNotification` | `App\Models\Notifications\NotificationRule` | Constants `EVENTS`, `ALWAYS_ON_EVENTS`, `SCHEDULED_EVENTS` move to the module's `events()` |
| `App\Models\Externals\DirectPaymentNotificationSend` | `App\Models\Notifications\NotificationSend` | |
| — | `App\Externals\DirectPayments\Notifications\{DirectPaymentsActivityModule, DirectPaymentsRecipientResolver, DirectPaymentsVariableBuilder, DirectPaymentsScheduledSource, DirectPaymentsActivities}` | **The only Direct Payments code left.** It lives where the dispatcher lived |
| `App\Externals\DirectPayments\Mail\DirectPaymentMailer` | stays, now used only for the default-or-handler decision, then folded into `MailChannel` (§4.7) | `DirectPaymentCommunicationService` (the receipt) keeps using it until [06](06-activity-fan-out.md) |
| `DirectPaymentDefaultEmail` | generalised to `App\Services\Notifications\DefaultEmail` reading `…/mjml/defaults/<folder>/defaults.json` | The folder name comes from `EventDefinition::$defaultTemplate`; Direct Payments' folder stays `dp` — **no template file moves** |
| `DirectPaymentsSubscriptionNotify` (command) | unchanged name, options and schedule; body calls the generic dispatcher | §4.6 |
| `DirectPaymentNotificationController` | the `email-handlers/list` endpoint moves to a generic controller at the same URL; `publicList`/`listSends` keep their URLs and read the new tables | §4.3 |

## 4.3 The data move

The tables are generalised ([02](02-target-model.md) §2.4–2.5). Because **every tenant website is a clone of `www` with its own database**, rows in
`laravel_direct_payment_notifications` may exist on live tenant sites. So the move is a **new migration with a copy step**, not an edit of the existing
create migration (the shortcut the Direct Payments audit used, valid only while nothing was deployed — [10](10-open-questions.md) OQ3).

1. **P2a — create** `laravel_notification_rules` and `laravel_notification_sends` (guarded with `Schema::hasTable`, indexes named explicitly; the two names
   that exceed 64 characters if derived are named by hand — the AGENTS.md rule).
2. **P2b — copy**, in the same migration, inside a transaction, idempotently (`insertOrIgnore` on the unique key):
   - `laravel_direct_payment_notifications` → rules: `module = 'direct_payments'`, `scope_ref = direct_payment_reference_num`, `mail_active = is_active`,
     `inbox_active = false`, everything else column for column;
   - `laravel_direct_payment_notification_sends` → sends: `subject_ref = provider_subscription_id`, `recipient_ref = payer_reference_num`,
     `bucket = period_end`, `channel = 'mail'`, `recipient = recipient_email`.
3. **P2c — switch** the Direct Payments code to the new tables in the same release: the save path (`DirectPaymentsController` ~1240–1300, which keeps its
   request shape — the `notifications` array of `{event, is_active, mail_handler_reference_num, days_before, recipient_mode, recipient_emails}`; `is_active`
   maps to `mail_active`, and an optional `inbox_active` is accepted), `publicList`, `listSends`, the command's guard, and the dispatcher.
4. **P6 — retire** the old tables only after a full release in which nothing reads them (a grep gate) and the row counts of old and new were compared.
   Until then the old tables stay, unread and unwritten.

Rollback until P6: switch the code back; the old tables are untouched because nothing wrote to them after P2c — **except** rows saved between the switch
and the rollback, which are reconciled by re-running the copy in reverse ([09](09-rollout-and-testing.md) §9.5).

The seed data matters too: `core/config/testdb_appfactorystudio.data-only.sql` is the clean-install import. It holds no notification rows today (the dev
server's `laravel_direct_payment_notifications` count was 0 after the last install), so it needs no change, but P2 adds a check to the SQL-import
verification that the two new tables exist and are empty.

## 4.4 The vocabulary — one registration

`DirectPaymentsActivityModule::events()` returns the fourteen events (`upcoming_charge`, `payment_succeeded`, `payment_failed`, `subscription_canceled`,
`trial_ending`, `subscription_cancel_scheduled`, `subscription_access_ended`, `subscription_updated`, `payment_method_changed`, `payment_receipt`,
`payment_details_required`, `payment_details_updated`, `subscription_provider_changed`, `subscription_ending_payment_details_required`) with their
labels, switches' defaults, `alwaysOn` (the last four), `scheduled` (`upcoming_charge`, `trial_ending`), default-template key and variable list. The six
copies listed in [01](01-audit-findings.md) M7 become one:

- `DirectPaymentNotification::EVENTS` etc. → deleted; validation in the save path reads `ActivityCatalog`.
- The `<select>` in `directpayments-form.html` and `SINGLE_PAY_NOTIFICATION_EVENTS` in `directpayments.ts` → built from the catalogue endpoint
  ([07](07-admin-ui.md) §7.2); `SINGLE_PAY_NOTIFICATION_EVENTS` becomes the `appliesTo` field on `EventDefinition`: a list of tokens the **module** defines (Direct Payments uses `single_pay` and `subscription`; an empty list means every variant). The general layer cannot name those words itself — gate G1.
- `defaults.json` → remains the template manifest (labels, subjects, variable lists) and is read by `DefaultEmail`; its `always_on` flag is checked
  against the module's `EventDefinition::$alwaysOn` in a test so the two cannot drift.

## 4.5 What must not change

These are decisions already made, and bugs already fixed, in `plans/www/direct_payments/23`–`24` and `plans/billing`. Each has a test in [09](09-rollout-and-testing.md) §9.3.

| Keep | Source | How it survives |
|---|---|---|
| **D2** — zero work when nothing is configured: one indexed query, no provider calls, no log line | 24 §6.2 | The guard becomes `NotificationRule::where('module','direct_payments')->where(fn ($q) => $q->where('mail_active', true)->orWhere('inbox_active', true))->exists()` on `(mail_active, inbox_active, event)` |
| **D7** — a unique ledger; a `failed` row holds the key and is **not** retried | 24 §6.5, §6.10 | The unique key in §2.5; the same `insert … catch unique violation` |
| **D8** — at most three reminder days, in 1..7, stored descending | 23 §5.2 | Validation moves to the generic save endpoint, keyed on `EventDefinition::$scheduled` |
| **B25** — one recipient per send, never one call with three addresses | 24 §6.6.3 | `MailChannel::deliver()` takes one recipient |
| **B24** — an unreachable Brain makes the configuration null; record `no_provider_token`, exit 0, never fatal | 24 §6.9 | `MailChannel` resolves once and returns `SendResult::failed('no_provider_token')` |
| **B9** — use the build-integration send, not the bare handler send | `BillingMailer` header | Same call as `MailHandlerSender` makes today |
| Swallow everything in `dispatch` | 24 §6.7 | `ActivityPublisher` and `NotificationDispatcher::dispatch` catch `Throwable`, log a warning |
| Always-on events cannot be switched off | `ALWAYS_ON_EVENTS` | `EventDefinition::$alwaysOn`; the save path rejects `mail_active = false` for them (today's validation) |
| The cron command's name, options (`--dry-run`, `--dp`, `--force-sync`) and 15-minute schedule | `routes/console.php:45`; `BrainCommandsEndpointTest` | Untouched |
| `GET /directpayments/{dpRef}/notifications` public shape: `{event, days_before, active}` | `portal.d.ts`, SDK | `active` = `mail_active OR inbox_active`; no other field is added |
| `GET /email-handlers/list` summary-only response | `DirectPaymentNotificationController::listMailHandlers()` | Moved verbatim |

## 4.6 The scheduled path

Only Direct Payments has scheduled events, so a generic `notifications:run-scheduled` is **not built** now. The Direct Payments command stays the entry
point and the generic dispatcher gains one method:

```php
public function evaluateScheduled(ScheduledSource $source, ?string $scopeRef, bool $dryRun, int $sendsPerRunCap): ScheduledStats;
```

`DirectPaymentsScheduledSource::due()` is the old query (the join on `laravel_processor_subscriptions`, the status list, `cancel_at_period_end = false`,
the `[now, now + max(days_before) days]` window), returning `Activity` objects with `data` carrying the days out and target date. The dispatcher does
the window check, day match, cap and delivery. The command still runs phase 1 (`SubscriptionSyncService`) before it — syncing the provider is Direct
Payments' job, not the dispatcher's. When a second module needs a scheduled event, a generic command is added then, iterating `ActivityCatalog` modules
whose `scheduled()` is non-null.

## 4.7 One mail path (M4)

`MailChannel` replaces three behaviours with one:

- It resolves `__get_main_configurations()` and the Brain configuration **once per instance** (as `BillingMailer::resolveOnce()` does), not once per send.
- It sends through `MailHandlerSender`, which gains one instance-level entry point taking the prebuilt context; the existing `MailHandlerSender::send()`
  static stays as a thin wrapper that builds a context and calls it, so **no other module's mailer changes**.
- It returns `SendResult` exactly as today.

`BillingMailer` is left alone (the dunning ladder and the Brain's per-app mail use it, and `SendEmailBuildIntegration` is shared). Folding `BillingMailer` into
`MailChannel` is a possible later step, not part of this plan.

## 4.8 Tests before and after (M6)

Phase P0 adds **characterization tests** that drive the *public* path of today's dispatcher end to end with a fake `MailTransport` (the seam
`DefaultEmailsTest` already uses): a webhook `payment_failed`, a scheduled `upcoming_charge` at 7/3/1 days, the dedupe across 96 ticks, `custom` mode with
three recipients producing three sends, a `customer` mode with no resolvable address recording `skipped: no_recipient`, a `failed` send not being
retried. They pass against the old class in P0 and against the new one in P1–P2 **without being edited** — that is the proof the extraction changed
nothing. The two reflection tests in `ExtensionPointsTest` (lines 285–312) move to the recipient resolver and variable builder, whose methods are public.

## 4.9 Acceptance

- `grep -rn "DirectPayment\|Processor\|payer\|subscription" app/Services/Activity app/Services/Notifications app/Services/Analytics` is empty (excluding comments that cite a plan).
- The characterization tests of §4.8 pass unchanged before and after.
- `php artisan direct_payments:subscription-notify --dry-run` prints the same table on the same data.
- A save from the existing Direct Payments form (no `inbox_active` key) creates rules with `inbox_active = false` and behaves as before.
- Row counts of old and new tables match after the copy; the copy can be re-run without duplicates.
