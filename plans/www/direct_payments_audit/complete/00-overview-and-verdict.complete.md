# 00 — Overview and Verdict (complete)

## Status

**Complete — 2026-10-17.** This step is an analysis document: it holds no code of its own. Finishing it meant checking
every claim in it against `w/www` as the code stands today. All claims were re-checked; none had drifted. **No
application code was changed by this step.**

The ten problems it names are real and still open. They are fixed by other steps (column "Fixed in" below), none of
which has been started.

## Phases

| Phase | Work | Result |
|---|---|---|
| P0 | Re-check §0.2 "what works today" against the code | All 8 rows hold |
| P1 | Re-check §0.3 "ten that matter most" against the code | All 10 still present |
| P2 | Re-check §0.5 / §0.6 (the "no interruption" table and owner decisions) for consistency with 01 and 02 | Consistent; nothing contradicts a verified finding |

## §0.2 — what works today (re-checked)

| Area | Evidence found in the code |
|---|---|
| Stable handle | `BindingRegistry::swap()` (`app/Externals/DirectPayments/BindingRegistry.php:101`) rewrites the binding, not the DP reference |
| Processor history | Bindings table has `active_marker` with `unique('active_marker', 'dp_bind_active_uq')` (create migration line 237–238) |
| Payer identity | `PayerService` has `resolveForCheckout`, `resolveForSiteUser`, `customerFor`, `recordExistingCustomer`; `payer_customers` has `active_key` unique per payer (lines 188–191) |
| Tier identity and price | `TierBindingRegistry::upsertActive / priceFor / priceForExistingSubscription` |
| Backward resolution | Refund and subscription actions resolve the processor from the charge / subscription, as claimed |
| Swap preview | `SwapService` has `impact()`, `impactHash()`, and the hash comparison before a swap |
| Webhooks after a swap | `webhookCandidateProcessorIds()` includes any non-retired bound processor; orphans are recorded |
| Developer surface | Playground folders `single_charge`, `subscription`, `connect_setup`, `marketplace_split`, `server`, `dashbaord` exist |
| Plan provisioning | `ProcessorPlanAdminController::store()` exists with `trial_days` handled for PayPal |

## §0.3 — the ten that matter most (re-checked on 2026-10-16; **all ten are now fixed in code** — see the 'Fixed in step' column and `11-rollout-and-testing.complete.md`)

| # | Problem | Seen in code | Fixed in step |
|---|---|---|---|
| 1 | Processor can be hard-deleted, cascading | `ProcessorAdminController::destroy()` calls `$processor->delete()`; three `onDelete('cascade')` remain in `2026_05_21_000001_create_processor_tables.php` (lines 210, 244, 376) | 03, 16 |
| 2 | Account replaced in place | `update()` still fills `mode`, `publishable_key`; accepts `secret_key`, `webhook_secret`, `extra_config` | 03 |
| 3 | `orWhere('id')` precedence bug | Present at 10 sites (list in the 01 record, A4); only `ProcessorSquarePaymentController` is correct | 03, 16 |
| 4 | Browser chooses the processor | `processor_reference_num` read from the request on every charge route; SDK keeps it in `sessionStorage` and honours `processor_override` | 06 |
| 5 | Swap skips the Connect guards | `SwapService` only checks that Connect is enabled on the target (lines 205–207); `guardConnectPayType()` is on the save path only | 05, 06 |
| 6 | Live subscription cannot move | One `provider_subscription_id` per DP row (`dp_subscriptions_uq`); no leg table; `subscription-migrate` command only lists | 07 |
| 7 | Provider-ended subscription never ends locally | `ProcessorWebhookController` has no write of `canceled_at` / `access_ends_at` | 10, 16 |
| 8 | Provider parity gaps | PayPal customer is the string `paypal:{email}`; SDK shows Square/PayPal tier price 0 and `'usd'`; Square discount stub; PayPal ignores discounts | 05 |
| 9 | DP code depends on appfactory studio | `Membership` imported in the subscription controller, `NotificationDispatcher`, `MoneyController` | 09, 13 |
| 10 | Link endpoint accepts any tier and overwrites | `DirectPaymentSubscriptionController::store()` `updateOrCreate` writes `tier_reference_num` from the request and regenerates `reference_num` | 07, 16 |

## What this step leaves for the owner

- The ten problems above were open when this was verified. **Since then (2026-10-17) step 16 was built** (see
  [`../16-safety-rails-implementation.status.md`](../16-safety-rails-implementation.status.md)): problems 1, 3 and 7 are
  fixed in code and 5 and 10 are fixed on the save and link paths; 2, 4, 6, 8 and 9 remain for steps 03, 06, 07, 05, 09/13.
- The §0.5 table ("what no interruption can and cannot mean") is a statement of design, not of code; it is checked in
  step 11 (rollout and testing) on sandbox accounts and cannot be verified from code alone.
