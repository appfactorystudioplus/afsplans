# 07-tier-bindings — completion record

**Plan file:** [`../07-tier-bindings.md`](../07-tier-bindings.md)
**Rollout phases:** DP1 (§7.2 — D9), DP4 (§7.3, §7.5 — D10, the resolvers, the backfill)
**Repo:** `w/www`
**Date:** 2026-09-12/13; **checkout wiring fixed 2026-09-17**
**Status:** ✅ Shipped and applied to the local database. **Correction (2026-09-17):** this
document's own Acceptance table claimed "A new subscription after a swap uses the new
processor's price via `priceFor()`" — true of `priceFor()` in isolation
(`TierBindingResolversTest`), false of the actual checkout path: `priceFor()` had **zero
callers anywhere in this codebase**. The storefront still sent `tier.processor_tier_id` (07
§7.2's stale write-through column) straight to `ProcessorSubscriptionController::store()`,
which a live user hit as "Invalid or inactive plan" the first time a Direct Payment's processor
was swapped after its tiers were priced. Fixed below — see "Checkout wiring, fixed 2026-09-17."

Not listed in `complete/README.md`'s original "Expected files" table — added here because this
plan document has its own numbered file and its own real gap (below) that neither DP1's nor
DP4's other completion records fully cover.

## What shipped

### DP1 — §7.2, D9 (tier identity)

- `DirectPaymentsController::saveDirectPayment()`'s tier loop rewritten from delete-and-recreate
  to `DirectPaymentTier::updateOrCreate()` keyed on `reference_num`. A reference in the payload
  that doesn't belong to the DP is refused with `DP_E_UNKNOWN_TIER_REFERENCE` (422), never
  silently accepted under a client-chosen id.
- `laravel_direct_payment_tier_bindings` created in the same pass (§7.3's table), with
  `App\Externals\DirectPayments\TierBindingRegistry::upsertActive()` keeping it in sync with every tier
  saved from DP1 onward.

### DP4 — §7.3/§7.4, D10 (the resolvers) and the missing backfill

- `TierBindingRegistry::priceFor()` (FORWARD) and `priceForExistingSubscription()` (BACKWARD)
  added. The latter's signature deliberately extends the plan's literal one — it takes an
  optional `$tierReferenceNum` override, because `DirectPaymentSubscriptionController::update()`
  needs the price of the tier being moved **to**, not the subscription's current tier, and the
  plan's own signature (reading `$link->direct_payment_tier_reference_num`) only covers the
  latter.
- `update()` re-pointed at `priceForExistingSubscription($link, $tierRef)` instead of
  `$tier->processor_tier_id` directly — `DP_E_TIER_NOT_PRICED_ON_PROCESSOR` (422) when no
  binding exists for the target tier on the subscription's own processor.
- **A real gap found and fixed in the same pass, not anticipated by the plan**: DP1's own
  migration note claimed no backfill was needed because the source table was empty — true for
  `laravel_direct_payment_subscriptions`, **false** for `laravel_direct_payment_tiers`, which
  already had 4 real rows on this deployment. Without a backfill, D10's fix would have hard-failed
  every existing tier with `DP_E_TIER_NOT_PRICED_ON_PROCESSOR` the first time anyone tried to
  upgrade a pre-existing subscription. `php artisan direct_payments:backfill-tier-bindings` closes
  it — dry-run confirmed all 4 real tiers resolved cleanly, ran for real, verified live.

## Checkout wiring, fixed 2026-09-17

A real, live user hit `POST /portal/api/v1/processors/stripe/create-subscription` returning
400 "Invalid or inactive plan" after a completed processor swap. Root cause: the storefront's
`setupSubscriptionPayment()` (`external/api/direct_payments/api.ts`) resolved `plan_id` from
`tier.processor_tier_id` — the tier's own raw column, a write-through cache of whichever
processor was active the last time the tier was saved in the admin — while
`dp.processor_reference_num` (what the SAME call already uses to initialize the
`ProcessorClient`) had already moved to the new processor. `ProcessorSubscriptionController::
store()` correctly looks the plan up scoped to the CURRENT processor and correctly found
nothing, since the id it was handed belonged to the OLD one.

New: `GET /portal/api/v1/directpayments/{dpRef}/tiers/{tierRef}/checkout-price`
(`DirectPaymentSubscriptionController::checkoutPrice()`) — resolves the DP's active processor
(`BindingRegistry::active()`, falling back to the DP's own column, exactly `PayerService::
customerFor()`'s pattern) and calls `TierBindingRegistry::priceFor()` — finally giving that
FORWARD resolver its first real caller. Returns `{success, plan_id, processor_reference_num}`,
or 404/422 with the same `DP_E_TIER_NOT_FOUND`/`DP_E_NO_ACTIVE_BINDING`/
`DP_E_TIER_NOT_PRICED_ON_PROCESSOR` codes `update()` already uses for the equivalent failures.

The SDK's `_resolveCheckoutPlanId()` calls it before EITHER `createSubscription()` call site
(the Stripe/Square-Elements path and the Square tokenize-then-confirm path both had the same
bug) and uses its `plan_id`, never the tier's own cached column. A network-level failure (an
older server mid-deploy, or genuinely offline) degrades to the pre-fix cached value rather than
blocking checkout on an unrelated problem; a well-formed refusal from the server (e.g. the tier
genuinely has no price on the current processor) is thrown with its real, specific message
rather than silently retried into a second, more confusing "Invalid or inactive plan."

**Not touched:** `ProcessorSubscriptionController::store()` itself — it is deliberately
provider-agnostic (`ProcessorClient.createSubscription()` has non-Direct-Payments callers too),
so the resolution has to happen one layer up, in the DP-aware controller, exactly where
`update()` already does the equivalent BACKWARD resolution. `DirectPaymentSubscriptionController::
update()` was already correct — this fix only closes the gap on the FORWARD (new-subscription)
side.

## Not implemented

- `directpayments:tier-binding-drift` (§7.5's monitoring command) — not built. It exists in the
  plan to detect drift from an ongoing backfill process; with the backfill already complete and
  every tier save keeping the table in sync going forward, there is nothing for it to detect yet.
  A genuine gap if a future manual DB edit desyncs the two, but not one anything today produces.

## Files

**New:** `app/Externals/DirectPayments/TierBindingRegistry.php` (created DP1, extended DP4),
`app/Models/Externals/DirectPaymentTierBinding.php`, `app/Console/Commands/DirectPaymentsBackfillTierBindings.php`

**Modified:** `app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php` (tier loop),
`app/Http/Controllers/Externals/DirectPayments/DirectPaymentSubscriptionController.php` (`update()`;
`checkoutPrice()` added 2026-09-17), `routes/portal.php` (the new `checkout-price` route,
2026-09-17), `resources/js/external/api/direct_payments/api.ts` (`_resolveCheckoutPlanId()`
added and wired into both `createSubscription()` call sites, 2026-09-17)

**Tests:** `tests/Feature/DirectPayments/SaveDirectPaymentTest.php` (tier identity, D9),
`TierBindingResolversTest.php`, `DirectPaymentsBackfillTierBindingsTest.php`,
`DirectPaymentCheckoutPriceTest.php` (new, 2026-09-17 — HTTP-level proof the checkout path
itself now resolves through `priceFor()`, not just the resolver in isolation)

## Acceptance

| §7.6 criterion | Status | Evidence |
|---|---|---|
| Saving a tier list twice leaves every `reference_num` unchanged | ✅ | `SaveDirectPaymentTest::test_saving_a_tier_twice_keeps_its_reference_num` |
| An unknown tier reference → 422 `DP_E_UNKNOWN_TIER_REFERENCE`, nothing written | ✅ | `SaveDirectPaymentTest::test_an_unknown_tier_reference_is_refused` |
| Removing a tier deletes it; a pinned subscription's fallback still resolves | ⚠️ | Deletion tested (`test_removing_a_tier_from_the_payload_deletes_it_but_not_its_history`); `MembershipTierResolver`'s own fallback was read, not re-tested here — pre-existing, unchanged code |
| After a swap, `update()` on a pre-swap subscription sends the OLD processor's price | ✅ | `TierBindingResolversTest::test_price_for_existing_subscription_resolves_the_target_tier_on_the_subscriptions_own_processor` — the exact D10 scenario, old and new prices coexisting, old one returned |
| A new subscription after a swap uses the new processor's price via `priceFor()` | ✅ **(corrected 2026-09-17)** | Previously cited `TierBindingResolversTest::test_price_for_resolves_the_active_binding_on_the_named_processor` alone — proves `priceFor()` works, not that anything called it. Now: `DirectPaymentCheckoutPriceTest::test_resolves_the_price_for_the_dps_currently_active_processor_after_a_swap` — an HTTP request against `checkout-price`, the endpoint the storefront now actually calls before checkout |
| Unpriced tier refuses with `DP_E_TIER_NOT_PRICED_ON_PROCESSOR` | ✅ **(corrected 2026-09-17)** | Previously only the underlying `ModelNotFoundException` from `TierBindingResolversTest`. Now: `DirectPaymentCheckoutPriceTest::test_422s_when_the_tier_has_no_price_on_the_current_processor` — the actual HTTP 422 with the actual code |
| `tier-binding-drift` reports zero after backfill | ❌ | Command not built (see Not implemented) |

## Verification

```bash
cd w/www
php artisan test tests/Feature/DirectPayments/SaveDirectPaymentTest.php tests/Feature/DirectPayments/TierBindingResolversTest.php tests/Feature/DirectPayments/DirectPaymentsBackfillTierBindingsTest.php
php artisan direct_payments:backfill-tier-bindings --dry-run
```

**2026-09-17 (checkout wiring fix):**

```bash
cd w/www
php artisan test tests/Feature/DirectPayments/DirectPaymentCheckoutPriceTest.php   # 6 passed
php artisan test tests/Feature/DirectPayments/                                     # 87 passed
npx tsc --noEmit -p resources/js/external/api/tsconfig.json                        # exit 0
npm run build-external-webpack                                                     # exit 0
```

No live browser or live-Stripe verification was possible for this fix — MySQL/Apache were not
reachable from this session at the time (see Residue). Recommended before this is considered
fully closed: reproduce the original swap scenario against the running app (swap a Direct
Payment's processor, attempt a new subscription checkout) and confirm the 400 is gone.

## Residue

None found beyond the backfill gap already fixed in this same pass, until 2026-09-17's checkout
wiring gap (above) — found only because a live user hit it, not by this document's own review.

- **No live verification of the 2026-09-17 fix** — the local MySQL/Apache stack (Laragon) was
  not reachable from this session (`mysql.exe` connection refused, ports 443/3306 not
  listening) at the time the fix was written and tested. Everything above is backed by new
  PHP feature tests against a real HTTP request and a clean type-check/build, not a live
  click-through reproducing the original browser error. If Laragon was simply stopped between
  the bug report and this fix, restarting it and repeating the original checkout flow is the
  natural next step.
- **The second `createSubscription()` call site** (`_confirmSubscriptionPaymentInternal()`'s
  Square tokenize-then-confirm path) had the identical bug and received the identical fix, but
  was not the path the user's own error came from (theirs was the Stripe/Square-Elements path,
  the first call site) — flagged so a reviewer knows both were checked, not just the one in the
  stack trace.
- **This document's own prior Acceptance claims should be read as a caution**, not just a
  correction: a plan step that builds a resolver and unit-tests it in isolation is not the same
  claim as "the checkout flow now uses it," and this file asserted the stronger claim on the
  weaker evidence. Worth remembering for any other "✅ … via `X()`" row in this codebase's
  completion docs where the cited evidence is a unit test of `X()` alone.
