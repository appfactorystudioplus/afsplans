# 13 — Naming and Separation

> Owner decision (2026-10-16): *"The term **billing** belongs to the appfactory studio ecosystem and should have nothing
> to do with Direct Payments or processors. Billing uses their implementation, but they should be separate — and that
> goes for anything labelled or termed **membership**, **members**, or using the `brain_memberships` table. That has
> nothing to do with Direct Payments and processors and should be fixed."*

This document turns that into a rule and lists every place in Direct Payments and processor code that breaks it. It
replaces the softer boundary [09](09-extension-points.md) first proposed. Phase **PA7** ([11](11-rollout-and-testing.md)).

## 13.1 The rule

**Direct Payments and processor code, configuration, routes, APIs, SDK, playground, tests and plan documents use only
Direct Payments vocabulary.** They contain no reference to:

- *billing*, *Billing*, `billing.*` config, `[Billing]` log tags, `plans/billing/…` plan references,
  `AFS_BILLING_MODIFIER_ADDITION`;
- *member*, *membership*, `member_ref`, `member_reference_num`, `Membership`, `brain_memberships`,
  `brain_membership_billing_state`, `brain_billing_settings`, any `brain_*` table;
- the appfactory sign-in system (`CheckpointIdentityResolver`) as a Direct Payments dependency.

The words Direct Payments uses instead:

| appfactory word | Direct Payments word |
|---|---|
| billing (the activity) | payments, charges, subscriptions, renewals |
| billing settings / billing state | Direct Payment settings; subscription status |
| member, membership | payer (the person paying), subscriber (a payer with a subscription), account owner (who owns a Connect account) |
| membership level / tier | tier (a Direct Payments tier and its `level`) |
| member reference | payer reference (`pay_…`), or the website's own user id where a website supplies one |

**What does not count as a breach:** words that belong to a provider's own API and must be sent exactly — PayPal's
`/v1/billing/subscriptions`, `billing_cycles`; Stripe's `billing_cycle_anchor`, `billing_reason`, `billing_details`;
card `billing_address`/postal code. These stay, and the grep gate excludes them by name (§13.6).

**Where appfactory code lives:** anything that needs to know about members — tier enforcement, member creation, the
member's dashboard, dunning-to-downgrade — lives in appfactory code (`App\Services\Billing`, `App\Http\Controllers\Brain`,
`App\Http\Controllers\Portal\Admin\MembershipController`, `plans/billing/`). It **uses** Direct Payments through its public
surface (API, events, interfaces) exactly like any tenant website would. Direct Payments never calls it, never reads its
tables, and does not know it exists. Every change on that side is marked **appfactory studio modification** and is planned
in [14](14-appfactory-studio-modifications.md), not here.

## 13.2 How appfactory plugs in without Direct Payments knowing

Direct Payments exposes **generic** extension points. It ships a default for each; the appfactory deployment registers its
own implementation from appfactory code. No interface name, method, parameter or default mentions members.

| Extension point (Direct Payments) | What it answers | Direct Payments default |
|---|---|---|
| `SubscriberIdentity` (chain) | "Which payer is making this request?" for `dp.subscription` and `…/me/*` | Site Users (signed-in user → payer link); payer / subscription-management token |
| `SubscriptionResponseDecorator` | Extra fields a host application wants on a subscription response | none |
| `PayerDisplayResolver` | How the Money ledger labels a payer | payer e-mail / name |
| `AdvancedConfigAuthority` | Who may write a Direct Payment's advanced (grace / retry) settings | any dashboard administrator |
| `RetryPolicyDefaults` (was "dunning defaults") | Default grace / reminder ladder when a DP has none | built-in defaults |
| Events (`SubscriptionPlanChanged`, `SubscriptionMigrated`, `SubscriptionEnded`, `ChargeSucceeded`, …) | Anything a host application wants to react to | no listeners |

Rule for events: a listener can **react**, it cannot **reply into** the Direct Payments response. Today
`SubscriptionPlanChanged` carries a `warning` the Billing listener sets (`membership_tier_write_failed`) and the DP API
returns it to the browser — that field is removed from the event and the response; appfactory logs and handles its own
failure.

Which implementations are registered is set in the appfactory deployment's own service provider, by configuration —
never by Direct Payments checking whether a `brain_*` table exists.

## 13.3 Inventory — identifiers to rename or remove (code, not comments)

Read 2026-10-16. Paths relative to `w/www/`.

### Classes, routes, commands, config

| Today | Where | Change |
|---|---|---|
| `ProcessorBillingController` | `Http/Controllers/Externals/Processor/ProcessorBillingController.php` | Split by what it does: refunds → `ProcessorRefundController`; subscription amount/date/discount adjustments → `ProcessorSubscriptionAdjustmentController`; saved payment methods → removed in favour of the payer-scoped endpoints ([04](04-payer-customer-identity.md) §4.6); `sync` → `ProcessorCustomerSyncController` |
| `updateBillingAmount()`, `updateBillingDate()`, request field `billing_date` | same file :302, :391, :403 | `updateSubscriptionAmount()`, `updateRenewalDate()`, field `renewal_date` |
| Routes `/api/v1/processors/billing/sync`, `/billing/refund`, `/billing/marketplace-refund`, `/billing/discount`, `/billing/billing-amount`, `/billing/billing-date`, `/billing/payment-methods*` | `routes/portal.php:912, 938–946` | `/processors/refunds`, `/processors/refunds/by-payment-intent`, `/processors/subscriptions/{discount,amount,renewal-date}`, `/processors/customers/sync`; old paths kept one release as aliases, then removed |
| SDK calls to `/billing/*` | `resources/js/external/api/processors/api.ts:94–150` | follow the new routes |
| Commands `BillingSubscriptionMigrate`, `BillingSubscriptionNotify`, `BillingBackfillDpSubscriptions` | `Console/Commands/` | `DirectPaymentsSubscriptionMigrate`, `DirectPaymentsSubscriptionNotify`, `DirectPaymentsBackfillSubscriptions` (signatures already `direct_payments:…`; only class names and descriptions change) |
| `config/billing.php` keys `sync_limit_per_run`, `default_notice_window_days`, `sends_per_run` read by Direct Payments | `Subscriptions/SubscriptionSyncService.php:186, 200`; `BillingSubscriptionNotify.php:55` | move to `config/direct_payments.php` (`DIRECT_PAYMENTS_SYNC_LIMIT_PER_RUN`, …), old env names read as fallbacks for one release. `enforcement_enabled` stays in `config/billing.php` — it is appfactory's |
| `Dunning\` namespace (`AdvancedConfigGate`, `DunningPolicy`, `DunningPolicyResolver`) | `app/Externals/DirectPayments/Dunning/` | `RetryPolicy\` (`AdvancedConfigAuthority` default, `RetryPolicy`, `RetryPolicyResolver`); `brain_billing_settings` read removed (§13.2) |

### Identity and fields

| Today | Where | Change |
|---|---|---|
| `CheckpointIdentityResolver` + `Membership` lookups in subscription self-service | `DirectPaymentSubscriptionController.php:205, 615, 693, 745` | `SubscriberIdentity` chain (§13.2) |
| `member_ref` request/SDK option (a "hint" checked against the caller) | `DirectPaymentSubscriptionController.php:192, 602`; `api.ts:502–555`; SDK README :117 | Removed. The caller is resolved by `SubscriberIdentity`; there is nothing to hint |
| `resolveBillingState()` reading `brain_membership_billing_state` | `DirectPaymentSubscriptionController.php:679–705` | Removed from Direct Payments; appfactory may add fields through `SubscriptionResponseDecorator` ([14](14-appfactory-studio-modifications.md)) |
| `NotificationDispatcher` reading `Membership` e-mail / record | `Notifications/NotificationDispatcher.php:250, 349` | payer e-mail ([09](09-extension-points.md) §9.3) |
| `MoneyController` mapping payers to members | `Http/Controllers/Externals/Processor/MoneyController.php:190` | `PayerDisplayResolver` |
| `AdvancedConfigGate` reading `Membership.level` | `Dunning/AdvancedConfigGate.php` | `AdvancedConfigAuthority` |
| Connect `member_reference_num` (who owns a connected account) | `laravel_processor_connected_accounts`, `ProcessorConnectedAccount.php:40`, `ProcessorConnectController.php:110, 140, 316–318`, `DirectPaymentConnectController.php:161` | The column already has a generic sibling, `connect_user`. Readers move to `connect_user` (the "account owner") and the `member_reference_num` column is removed from the create migration (development, no data). Message "does not belong to the requesting member" → "…requesting account owner" |
| `laravel_processor_migrations.created_by_member_reference_num`, `laravel_processor_migration_accounts.member_reference_num` | `ProcessorMigration.php:26`, `ProcessorMigrationAccount.php:20` (schema built, never wired) | `created_by` and `connect_user`; safe to rename now because nothing writes them |
| PayPal cancel reason `'Cancelled by member.'` | `Subscriptions/Providers/PayPalSubscriptionActions.php:32` | `'Cancelled by the subscriber.'` — this string is sent to PayPal and shown in the tenant's PayPal dashboard |
| `NotificationDispatcher` sends through `App\Mail\BillingMailer` | `Notifications/NotificationDispatcher.php:5, 27, 199` | `MailHandlerSender`, the sender every other module uses ([15](15-default-emails.md) §15.5); `BillingMailer` stays appfactory's |
| Notification variable `manage_billing_url`; `firstname`/`lastname`/`email` read from `Membership` | `NotificationDispatcher::buildVars()` :349–375 | `manage_subscription_url`; names and e-mail from the payer and the provider customer only |
| Log tags `[Billing][…]`, `[Billing][Sync]`, `[Billing][RefundService]`, `[Billing][DirectPaymentSubscription]` | `PayPalRetryConfig.php:149`, `SubscriptionSyncService.php:107, 116, 157`, `Stripe/Square/PayPalSubscriptionActions`, `Stripe/SquareSubscriptionReader`, `RefundService.php:150, 205, 269`, `DirectPaymentSubscriptionController` | `[DirectPayments][…]` |
| User-facing strings "subscriptions still billing", "keep billing on the current processor" | `BindingRegistry.php:147`, `SwapService.php:234` | "subscriptions still renewing", "keep renewing on the current processor" |
| SDK comment `level // the membership level`; playground "member reference number" input | `api.ts:444`; `dashbaord/direct-payments-dashboard.{html,js}` | "tier level"; the dashboard sample takes a payer token / signed-in user instead of a member reference |
| SDK README seller examples `member_ref_of_seller_a` | `resources/js/external/api/direct_payments/README.md:43–47` | `seller_ref_a` |
| SDK option `billing_period` on price formatting | `api.ts:1584, 1603`; subscription playground :169–177 | `show_interval` (old name accepted one release) |
| Playground headings "Billing Details" / "each billing period" | `subscription/direct-payments-charge.html:723–775` | "Payment details" / "each renewal period". (A card's billing address field keeps that name — it is the card network's term) |

### Markers and plan references (comments)

| Today | Count | Change |
|---|---|---|
| `AFS_BILLING_MODIFIER_ADDITION` in Direct Payments / processor code, migrations, routes and tests | 182 lines in `app/`, `routes/`, plus migrations and 17 test files | Replaced by a new marker, **`AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION`**, added to `plans/markers.md`. `AFS_BILLING_MODIFIER_ADDITION` stays reserved for appfactory billing code |
| `plans/billing/NN-….md` references in Direct Payments code | 143 lines | Re-pointed to where those documents now live: billing 02→20, 03→21, 04→22, 05→23, 06→24, 07→25, 12→26, 13→27, 14→28, 15→29 in `plans/www/direct_payments/`. References to billing 08–10, 16–17 (genuinely appfactory) are removed from Direct Payments code — if the code depends on them, that dependency is a §13.3 finding |
| `plans/create_member_redo/…` references in processor code | `ProcessorWebhookController.php:455, 1101, 1750`, `ProcessorIntentController.php:684`, `DirectPaymentsRepairChargeHooks.php:20`, others | Kept as history only where the reference explains *why* code is shaped as it is; reworded to "charge-details parser (create_member_redo/07)" style without implying a member dependency. Decided case by case in PA7 |

**This replaces markers, which the estate's marker rule normally forbids ("add alongside; never replace").** It is done on
the owner's explicit instruction of 2026-10-16 (decision **OQ15**: re-tag once) and recorded in `plans/markers.md` as a one-time re-tag, so a future reader
does not "restore" the old marker.

## 13.4 Plan documents

`plans/www/direct_payments/` (the first plan) still describes appfactory behaviour in places — e.g. README's "billing"
history, 20's B-findings about the members dashboard, 26's level-99 rule, 28's "member" wording. Those documents are
records of what was done and are not rewritten. A short note is added at the top of `plans/www/direct_payments/README.md`
pointing here, and every new document in this folder follows §13.1.

## 13.5 Database names

No Direct Payments or processor **table** contains *billing* or *member* in its name. Column-level breaches are the
Connect `member_reference_num` columns (§13.3) and none else. Two processor-subscription columns use provider vocabulary
(`next_charge_at`, `current_period_*`) and are fine.

## 13.6 Gate

```bash
cd w/www
S="app/Externals/DirectPayments app/Externals/Processor app/Http/Controllers/Externals/DirectPayments \
   app/Http/Controllers/Externals/Processor app/Models/Externals/Processor resources/js/external/api/direct_payments \
   resources/js/external/api/processors public/playground/externals/direct-payments config/direct_payments.php"
grep -rniE "billing|member|brain_|Checkpoint" $S \
  | grep -viE "billing_cycle|billing_reason|billing_details|billing_address|billingZip|v1/billing/|BILLING\.SUBSCRIPTION|billing_agreement"
# expected: no output (models outside Models/Externals/Processor are checked by file name in CI)
```
