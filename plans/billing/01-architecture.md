# 01 — Architecture

> **Status (2026-09-25).** `direct_payments:subscription-notify` (Part 1) runs in `www` on the Brain and
> every tenant and is documented in [`../www/direct_payments/`](../www/direct_payments/README.md).
> `billing:enforce-membership` (Part 2) is appfactory studio's own enforcement task: it is **scheduled in
> `members`** (decision D18), which triggers it every fifteen minutes on `www` over a signed
> server-to-server call; `www` runs it. Built, verified in the lab and off by default —
> [08](08-brain-enforcement.md) and [`complete/08-enforcement-in-members.complete.md`](complete/08-enforcement-in-members.complete.md).
> `members` needs its own working `schedule:run` cron.

## 1.1 The shape of it

```
                       operator saves a Direct Payments config
                                      │
        DirectPaymentsController::saveDirectPayment()  (www, portal admin)
                                      │
                  FunctionsController::RunCommandBG(
                      "--update-cron-task '{SystemUser}' '{Subdomain}'")
                                      │
                    core/scripts/bin/afs-website  (setuid root)
                                      │
                       core/scripts/commands.sh  → crontab -u {SystemUser}
                                      │
      * * * * * cd /home/{u}/websites/{subpath}/ && php artisan schedule:run
                                      │                (www + every tenant)
                                      ▼
                     direct_payments:subscription-notify
                     everyFifteenMinutes(), inert without config rows
                                      │
             ┌────────────────────────┼─────────────────────────┐
             ▼                        ▼                         ▼
          refresh                  evaluate                  send via
     next_charge_at            notification windows        mail handler
       from provider                                      (BillingMailer)


   members' own `schedule:run` cron  (platform only — not a tenant)
                                      │
                                      ▼
                        billing:enforce-membership
              everyFifteenMinutes(), inert unless both kill switches on
                                      │
                    members: signed POST → www /api/internal/brain/billing/enforce
                                      │
                                      ▼
                    www: runs the enforcement — one transaction per member:
                    brain_memberships.membership + state + event, sends notice
                                      │
                                        ┌─────────────────┼──────────────────┐
                                        ▼                 ▼                  ▼
                                  alloc_v2 tier    cloud-storage       afs-connect
                                  index (client)   QuotaService        entitlement
                                                   ::planFor()         layer 2
```

## 1.2 Where each command lives

Decided by **who has to run it**, not by where the tables are (they share one database):

| Command | Runs where | Why |
|---|---|---|
| `direct_payments:subscription-notify` | `www`, Brain **and every tenant clone** | It works on each site's own Direct Payments data and must ship to tenants. Registered in `www/routes/console.php`, which runs on every tenant. Documented in [`../www/direct_payments/24`](../www/direct_payments/24-notification-scheduler.md) |
| `billing:enforce-membership` | **`members`**, once | It acts on appfactory's own members, so it has no reason to exist on a tenant. Registered in `members/routes/console.php`; needs `members`' own `schedule:run` cron; it needs no "is this the Brain" test |

`members` is not the sole writer of `brain_*` and has no mail sender, so it schedules enforcement
and triggers it; the ladder, the tier write, the state and event rows and the notice all stay on `www`,
in one transaction per member ([08](08-brain-enforcement.md) §8.0–8.2). `members` is a **consumer** of
the resulting tier for everything else (step [10](10-members-dashboard.md)).

## 1.3 Push and pull, as elsewhere in the ecosystem

Same split the architecture doc describes for afs-connect ingest:

| Path | Trigger | Role |
|---|---|---|
| **Push** | `ProcessorWebhookController` — `customer.subscription.updated`, `invoice.payment_succeeded`, `invoice.payment_failed`, and the Square/PayPal equivalents | **Fast.** Updates `laravel_processor_subscriptions` within seconds. Also the trigger for action-based notifications (`payment_failed`, `subscription_canceled`, …) |
| **Pull** | `direct_payments:subscription-notify`'s sync phase | **Authoritative.** A subscription whose row is stale, or whose `next_charge_at` is null, or which is inside its notification window, is re-read from the provider |

The reason the pull path has to exist at all is in the audit
([20](../www/direct_payments/20-current-state-audit.md) §2.1): today's `current_period_end` is webhook-only, so
any subscription created before webhooks were configured has a `null` next charge and is
invisible to any notifier that trusts the database. A safety net that only runs when the
fast path failed is exactly the case that is never tested; running it every 15 minutes on
a bounded candidate set is.

## 1.4 The four phases of one `direct_payments:subscription-notify` tick

```
  phase 0  GUARD      any active laravel_direct_payment_notifications row?  no → exit
  phase 1  SYNC       refresh a bounded set of subscriptions from providers
  phase 2  EVALUATE   for each active config × each matching subscription,
                      compute the day-offset bucket and check the send ledger
  phase 3  SEND       render the mail handler, send, write the ledger row
```

Phases 1–3 each have their own per-run cap and their own failure isolation: a provider
outage in phase 1 must not stop phase 2 from notifying on data that is already fresh, and
one member's bad email address must not abort the rest of phase 3.

## 1.5 Fail-closed and fail-safe rules

Borrowed verbatim in spirit from afs-connect §11.4's `AllocationGateway` note, because the
same asymmetry applies:

| Situation | Behaviour | Why |
|---|---|---|
| `IS_BRAIN` absent or not `true` | `direct_payments:subscription-notify`'s Brain-only branch (manage-billing URL) treats the deployment as **not the Brain** | One env flag replaces the old `GetManagedDomain()`/`type.txt` domain test, which broke in any environment whose URL differs ([www_brain B10](../www_brain/00-overview.md)). *(Enforcement no longer needs it — it runs only in `members`.)* |
| Provider API unreachable during sync | Keep the stored `next_charge_at`, set `last_sync_error`, continue | A provider outage must not cancel a member's notification, and must **never** be read as "no next charge" |
| Provider says the subscription is gone (404) | Mark `status = 'canceled'`, do **not** delete the row | Deleting loses the audit trail the enforcement ladder needs |
| Mail handler row missing or unparseable | Skip that config, log, continue; write a `status = 'failed'` ledger row | One misconfigured DP must not silence every other DP's notifications |
| Enforcement cannot resolve a member's subscription at all | Do **nothing** to that member | Never downgrade on absence of evidence. Only an observed `past_due`/`canceled` starts the ladder |
| Enforcement finds the member already at or below the target tier | No-op, no event, no email | The task must be safe to run 96 times a day forever |

The one place the asymmetry inverts: **a send that cannot be proven not-yet-sent is not
sent.** The ledger's unique index is checked before the send, and the row is inserted in
the same transaction; a duplicate-key violation is treated as "already sent", not as an
error.

## 1.6 Concurrency

`schedule:run` fires every minute; `everyFifteenMinutes()` means the command starts at
:00/:15/:30/:45. A slow provider sync could still overlap the next window. *(The lock advice below applies to each
app separately: `www` for `direct_payments:subscription-notify`, and **`members`' own cache store and
`schedule:run` for enforcement** — confirm the cache-lock table on both.)*

```php
Schedule::command('direct_payments:subscription-notify')
    ->everyFifteenMinutes()
    ->withoutOverlapping(14)   // minutes; expires before the next window opens
    ->onOneServer()
    ->runInBackground();
```

Both `withoutOverlapping()` and `onOneServer()` need an atomic-lock-capable cache store.
`www/config/cache.php` defaults to `env('CACHE_STORE', 'database')` and the
`0001_01_01_000001_create_cache_table` migration is present, so the database store's lock
table is available — **this must be confirmed migrated on each deployment**, and is listed
as a prerequisite in [11](11-rollout-and-testing.md) §11.2. The ledger's unique index is
the real correctness guarantee; the locks are an efficiency measure.

## 1.7 Where every new artefact lands

| Artefact | Path |
|---|---|
| Crontab installer block | `www/core/scripts/commands.sh` (new `--update-cron-task` block) |
| Bootstrap call | `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php` |
| Schedule registration | Part 1: `www/routes/console.php`. Part 2: **`members/routes/console.php`** |
| Part 1 command | `www/app/Console/Commands/BillingSubscriptionNotify.php` (signature `direct_payments:subscription-notify`; settings in `www/config/billing.php`) |
| Part 2 command | **`members/app/Console/Commands/BillingEnforceMembership.php`** |
| Provider sync | `www/app/Externals/DirectPayments/Subscriptions/SubscriptionSyncService.php` (+ `Providers/{Stripe,Square,PayPal}SubscriptionReader.php`) |
| Notification dispatch | `www/app/Externals/DirectPayments/Notifications/NotificationDispatcher.php` |
| Mail send seam | `www/app/Mail/BillingMailer.php` |
| Enforcement schedule and trigger | `members/routes/console.php`; `members/app/Console/Commands/BillingEnforceMembership.php`; `members/app/Services/Www/BrainSurfaceClient.php`, `BrainSurfaceSignature.php`; `members/config/brain_surface.php` |
| Enforcement surface and logic (Brain) | `www/routes/api.php` (group `internal/brain`); `www/app/Http/Controllers/Internal/BrainBillingEnforcementController.php`; `www/app/Http/Middleware/{BrainOnly,VerifyBrainSurfaceSignature}.php`; `www/app/Services/Brain/BrainSurfaceSignature.php`; `www/config/brain.php`; ladder in `www/app/Services/Billing/DunningService.php`, `MembershipTierResolver.php`, policy in `www/app/Externals/DirectPayments/Dunning/` ([08](08-brain-enforcement.md) §8.0–8.2) |
| Dunning policy schema | `laravel_direct_payment_advanced` ([26](../www/direct_payments/26-advanced-policy.md) §12.6) — read by the `members` resolver |
| DP advanced config | `www/app/Models/Externals/DirectPaymentAdvanced.php`, `.../DirectPayments/DirectPaymentAdvancedController.php`, `app/Http/Middleware/DirectPaymentAdvancedWriteMiddleware.php` |
| Money + refunds | `www/app/Models/Externals/Processor/ProcessorRefund.php`, `.../Processor/MoneyLedgerController.php`, `.../Processor/RefundController.php`, `www/resources/js/portal/admin/Processors/MoneyView.ts` ([29](../www/direct_payments/29-money-and-refunds.md)) |
| afs-connect money source | `www/app/AfsConnect/Providers/WwwMoneyProvider.php` + one entry in coordinator's `config/afs_connect_sources.php` ([29](../www/direct_payments/29-money-and-refunds.md) §15.6) |
| Subscription actions | `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentSubscriptionController.php` — cancel / update / setPaymentMethod ([28](../www/direct_payments/28-subscription-actions.md)) |
| DP advanced gate | `www/app/Externals/DirectPayments/Dunning/AdvancedConfigGate.php` ([26](../www/direct_payments/26-advanced-policy.md) §12.8) |
| Provider retry policy | `www/app/Externals/DirectPayments/Subscriptions/Providers/ProviderRetryProfile.php`, `{Stripe,PayPal,Square}RetryConfig.php`, `www/app/Models/Externals/Processor/ProcessorRetrySetting.php`, `.../Processor/ProcessorRetryPolicyController.php` ([27](../www/direct_payments/27-provider-retry-policy.md)) |
| Provider retry admin UI | `www/resources/js/portal/admin/controllers/processors/ProcessorDetailView.ts` + `.../processors/processor-details.html` ([27](../www/direct_payments/27-provider-retry-policy.md) §13.6) |
| "Is this the Brain?" | `config('brain.is_brain')` (`www/config/brain.php`), from the `IS_BRAIN` env flag plus a production URL check ([www_brain](../www_brain/08-security-and-safety.md) §8.3) |
| Plan-change tier hook | `www/app/Externals/DirectPayments/Events/SubscriptionPlanChanged.php` (raised by the Direct Payments layer) and `www/app/Services/Billing/ApplyTierOnPlanChange.php` (appfactory's listener, registered in `AppServiceProvider`) — [17](17-self-service-tier-changes.md) §17.3 |
| Models | `www/app/Models/Externals/DirectPaymentSubscription.php`, `DirectPaymentNotification.php`, `DirectPaymentNotificationSend.php`, `Brain/MembershipBillingState.php`, `Brain/MembershipEvent.php` — all Direct-Payments- or Brain-layer. **No new model under `app/Models/Externals/Processor/`** |
| DP↔subscription link | `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentSubscriptionController.php` ([22](../www/direct_payments/22-subscription-state.md) §4.2.3) |
| Migrations | `www/database/migrations/2026_08_25_*` — **twelve** (000001–000005, 000007–000013), listed per step in [22](../www/direct_payments/22-subscription-state.md) §4.8, [23](../www/direct_payments/23-notification-config.md) §5.8, [08](08-brain-enforcement.md) §8.9, [26](../www/direct_payments/26-advanced-policy.md) §12.9, [27](../www/direct_payments/27-provider-retry-policy.md) §13.9 and [29](../www/direct_payments/29-money-and-refunds.md) §15.7 |
| Admin API | `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentNotificationController.php`, routes in `www/routes/portal.php` |
| Admin UI | `www/resources/js/portal/admin/html/views/directpayments-form.html`, `.../controllers/direct_payments/directpayments.ts` |
| External SDK | `www/resources/js/external/direct_payments/api.ts` (+ README section) |
| Members UI | `members/resources/js/controllers/BillingApp.ts` |

Nothing in `packages/afsserver`, `auth`, `coordinator` or the four creative apps changes.
That is the point of D11.
