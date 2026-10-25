# 01 — Audit Findings (complete)

## Status

**Complete — 2026-10-17.** This step is the list of findings A1–A61 plus E1–E5; it holds no code of its own. Finishing
it meant re-reading every finding in `w/www` a day after the audit and recording whether it is still true. Result: **every
finding re-checked is still present; none was fixed and none had drifted** (line numbers moved by at most a few lines).
**No application code was changed by this step.** Fixing the findings is the work of steps 03–16. *Update 2026-10-17 (end of the build): every finding A1–A61 and E1–E5 is now addressed in code by steps 03–16 and tested against fakes; the consolidated finding-to-step table is in `11-rollout-and-testing.complete.md`. What stays unverified is anything that needs a real provider, a real e-mail or a browser.*

Paths below are relative to `w/www/`.

## Phases

| Phase | Findings | Re-checked in code | Result |
|---|---|---|---|
| P0 | §1.1 claims about the first plan | Yes | Hold |
| P1 | §1.2 processor lifecycle A1–A9 | Yes | All present |
| P2 | §1.3 swap and DP config A10–A19 | Yes | All present |
| P3 | §1.4 routing and identity A20–A29 | Yes | All present |
| P4 | §1.5 subscriptions A30–A39 | Yes | All present |
| P5 | §1.6 provider parity A40–A49 | Yes | All present |
| P6 | §1.7 appfactory code in DP A50–A54, A61 | Yes | All present; A61 counts re-measured |
| P7 | §1.8 webhooks and integrity A55–A60 | Yes | All present |
| P8 | §1.9–§1.10 (E1–E5, carried TODOS) | Not re-read here | Owned by steps 15 and the first plan's TODOS |

## Evidence per finding

| # | Evidence seen today |
|---|---|
| A1 | `Http/Controllers/Externals/Processor/ProcessorAdminController.php:195–201` `destroy()` calls `->delete()` |
| A2 | `database/migrations/2026_05_21_000001_create_processor_tables.php` `onDelete('cascade')` at 210, 244, 376 |
| A3 | `ProcessorAdminController::update()` validates and fills `mode`, `publishable_key`, `extra_config`, rotates secrets with no account check (~lines 127–162) |
| A4 | `orWhere('id', …)` in `ProcessorIntentController.php` 56, 155, 343, 409, 488, 604; `ProcessorSubscriptionController.php:39`; `ProcessorClientController.php:52`. `ProcessorSquarePaymentController.php:44` uses the closure form (correct) |
| A5 | `Processor` model has no archive field or method |
| A6 | `store()` validates a client `reference_num` (`size:7`), used at lines 75–83 |
| A7 | `extra_config` accepted as a free array in `store()` / `update()` |
| A8 | `SwapService::kindOf()` (`app/Externals/DirectPayments/SwapService.php:112`) compares `publishable_key` only |
| A9 | `orWhere('id')` also in `ProcessorAdminController` 129, 176, 197 and `ProcessorSyncController` 22, 85 |
| A10 | `DirectPaymentsController::destroy()` (`Http/Controllers/Externals/DirectPayments/`, :308) deletes items, tiers, Connect, notifications and the DP; only `blockIfPublished` guards it |
| A11 | `DirectPaymentsController.php:382` writes `$procRef` unconditionally |
| A12 | `SwapService` checks only Connect-enabled (205–207); `guardConnectPayType()` (:895) is on the save path only |
| A13 | `BindingRegistry::swap()` retires the current binding before looking up the target; no `transaction` anywhere in `BindingRegistry` or `DirectPaymentSwapController` |
| A14 | No mode comparison in `SwapService::blockersFor()` |
| A15 | No webhook-registered check in the swap blockers |
| A16, A17, A18 | `DirectPaymentsController` deletes items (431/433, and recreates with `'reference_num' => $item['reference_num'] ?? ''`) and drops tiers with `->delete()` at 490. Tiers are now upserted by reference (an earlier fix), but tiers missing from the payload are still hard-deleted |
| A19 | `DirectPaymentSwapController.php:83` passes `$request->input('bound_by')` |
| A20 | Charge routes read `processor_reference_num` from the request (Intent, Subscription, Square controllers) |
| A21 | Nothing outside admin code reads the binding status before a new charge |
| A22 | `resources/js/external/api/direct_payments/api.ts` stores DP data, processor and key in `sessionStorage` (1030–1047) and honours `processor_override` (445, 1239, 1256, 1265) |
| A23 | `SESSION_KEY_CUSTOMER_ID = 'afs_customer_id'` (:56); routes `customers/create-anonymous`, `find-or-create`, `update-email` at `routes/portal.php:1033–1035` |
| A24 | `DirectPaymentsController.php:76` calls `PayerService::recordExistingCustomer()` with the request's customer id |
| A25 | `ProcessorSubscriptionController::recreateStripeCustomer()` at :624 |
| A26 | `Externals/Processor/ProcessorCustomerGateway.php:28` returns `"paypal:{$email}"` |
| A27 | Two registries still written separately (processor customers vs payer customers) |
| A28 | `PayerService.php:63` `DirectPaymentPayer::firstOrCreate(...)` keyed on e-mail |
| A29 | `api.ts:779` sends the resolved payer as `user_id = 'su:…'`, not as `payer_reference_num` |
| A30 | `dp_subscriptions_uq` = (DP, `provider_subscription_id`); one id per row |
| A31 | Unauthenticated `POST /{dpRef}/subscriptions` → `DirectPaymentSubscriptionController::store` (`routes/portal.php:1280`) |
| A32 | `DirectPaymentSubscriptionController.php:114` `Str::random(12)` inside the `updateOrCreate` update values |
| A33 | Same block (:108–118) writes `tier_reference_num` from the request on an existing link |
| A34 | `trial_end` only for plan trials (:223); no deferred start for Stripe, Square or PayPal |
| A35 | `Console/Commands/BillingSubscriptionMigrate.php`, `direct_payments:subscription-migrate`, listing only |
| A36 | No write of `canceled_at` / `access_ends_at` in `ProcessorWebhookController`; `BindingRegistry` counts `whereNull('canceled_at')` |
| A37 | Webhook and controller lookups by `provider_subscription_id` alone (webhook controller 623, 855, 903, 931) |
| A38 | `Services/Billing/MembershipTierResolver.php` uses `processor_tier_id` (:155). appfactory studio — set aside by the owner |
| A39 | `Http/Controllers/Externals/SiteUsers/SiteUsersPaymentsController.php:77–95` merges every `payer_customers` row, superseded included |
| A40 | Stripe mints a coupon per subscription (:185–207); Square discount stub and PayPal omission unchanged |
| A41 | Trial encoded only as a PayPal billing cycle in `ProcessorPlanAdminController` (:276–291) |
| A42, A43 | `SquareSubscriptionActions` and `PayPalSubscriptionActions` in `app/Externals/DirectPayments/Subscriptions/Providers/` |
| A44, A45 | `paypal_client_id` read from `extra_config` in `ProcessorPaymentMethodService` (202, 242, 447) and `ProcessorBillingController` (1152), while orders use `publishable_key` |
| A46 | `getTierInfoFromRecord()` returns `amountCents: 0, currency: 'usd'` for Square and PayPal (`api.ts:35–40`) |
| A47 | `'usd'` literals in `api.ts` (29, 36, 39, 45) and invoice save |
| A48 | `uniqid()` idempotency keys in `ProcessorSubscriptionController` (411, 429), `ProcessorIntentController` (559), refund and discount paths |
| A49 | `return_url`/`cancel_url` = `url('/')` (:536–537); `location_id` falls back to `env('SQUARE_LOCATION_ID')` (:430) |
| A50 | `use App\Models\Membership;` at `DirectPaymentSubscriptionController.php:12`, used at 205, 615, 693 |
| A51 | `brain_membership_billing_state` referenced at :675–681 |
| A52 | `NotificationDispatcher.php` 250, 349 use `Membership` |
| A53 | `MoneyController.php` imports `Membership` |
| A54 | `Http/Controllers/Portal/Admin/MembershipController.php:361` claims by `provider_subscription_id`. appfactory studio — set aside |
| A55 | `webhookCandidateProcessorIds()` at `ProcessorWebhookController.php:370–382`; a retired, inactive processor drops out |
| A56 | `recordRejectedWebhook()` rate-limits (401–404) before writing the orphan row |
| A57 | `ProcessorCharge` maps to `laravel_direct_payment_invoices`; `laravel_processor_charges` is still created and unused |
| A58 | `logStripeChargeback` (:807), `logSquareDispute` (:1285), `logPayPalDispute` (:2055); no disputes model or table |
| A59 | `routes/portal.php:944–946` payment-method delete/store/default have no `externals.dashboard` middleware, unlike the routes around them |
| A60 | `resources/js/external/api/processors/api.ts` `getProcessors()` (:41), `/charge` (:67), `/subscribe` (:80); no `processors/list` route |
| A61 | See count note below |

## A61 counts, re-measured

Step 13 states 182 marker lines and 143 `plans/billing` references. Re-measured today over the Direct Payments and
processor code (`app/Externals/DirectPayments`, `app/Externals/Processor`, the two Externals controller folders,
`app/Models/Externals`, `routes`): **136** `AFS_BILLING_MODIFIER_ADDITION` lines and **104** `plans/billing`
references. The difference is scope (step 13 also counts migrations and 17 test files); the finding stands. Step 13
remains the authority for the full inventory.

## What this step leaves for the owner

- All 61 findings remain open. The order of fixing is in step 11 (rollout and testing); the first step to build is 16.
- Critical findings: A1, A2, A3, A4, A12, A20, A33. A33 (any caller can re-point a subscription's tier) and A59
  (payment methods can be detached without authentication) are exposed today on the unauthenticated routes named above.
