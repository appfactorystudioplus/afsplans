# 03-the-swap — completion record

**Plan file:** [`../03-the-swap.md`](../03-the-swap.md)
**Rollout phases:** DP0 (§3.3 — the guard only), DP5 (the full swap, extending this record)
**Repo:** `w/www`
**Date:** 2026-09-12/13
**Status:** ✅ Shipped and applied to the local database (DP0's guard; DP5 needed no new migration — DP3's binding table already had everything)

## What shipped

### DP0 — §3.3, the guard

- `DirectPaymentsController::saveDirectPayment()` gained `guardProcessorChange()`: a live Direct
  Payment refuses a processor change with `409 DP_E_PROCESSOR_CHANGE_REQUIRES_SWAP`, naming the
  current processor. May still SET one on a new DP or one with no processor yet.
- **DP0-phase adaptation, not a deviation**: at DP0 time, `BindingRegistry` doesn't exist yet
  (DP3), so "the current processor" is read from `DirectPayment`'s own column. Re-pointed at
  `BindingRegistry::active()` once DP3 shipped, with a fallback to the column for any DP the
  backfill command hadn't reached yet — a real gap caught during DP3's own testing (see that
  phase's completion record), not anticipated in advance.

### DP5 — the full swap

- `App\Externals\DirectPayments\SwapService`: `preview()` (kind detection, impact counts, blocking rules,
  `impact_hash`) and `execute()` (re-checks blockers fresh, verifies the hash, calls
  `BindingRegistry::swap()`).
- **Kind detection is inferred, not asked** — a matching Stripe *publishable key* between the
  current and target processor (same provider) is treated as "same account, rotated keys" and
  blocked outright, pointed at editing credentials instead of swapping. Not explicitly specified
  by the plan's text, which only says the system "distinguishes" the three kinds without saying
  how; this is the concrete mechanism chosen.
- **`payers_with_saved_cards` is a documented upper bound**, not a real count — nothing in this
  codebase tracks whether a payer_customer has a saved payment method locally (that lives at the
  provider). Every active payer_customer on the retiring processor is counted, erring toward
  over- rather than under-warning. Recorded explicitly in `SwapService`'s own docblock.
- `BindingRegistry::swap()` (retires the old binding to `retiring`, creates the new `active` one,
  keeps `laravel_direct_payments.processor_reference_num` in sync as the derived cache),
  `canRetire()`/`retire()` (§3.7 — live subscriptions, open invoices, a 180-day refund window,
  each scoped to the specific binding's own `(direct_payment, processor)` pair, not the processor
  globally).
- Four endpoints: `POST .../swap/preview`, `POST .../swap`, `GET .../bindings`,
  `POST .../bindings/{id}/retire`.
- Two commands: `direct_payments:import-customer-map` (adopts a migrated Stripe vault's new
  customer ids via `PayerService::recordExistingCustomer()`, never a provider call) and
  `direct_payments:subscription-migrate` (lists subscriptions stuck on a retiring processor — a listing
  command, not an automation of the migration itself).
- **The payoff, verified rather than assumed**: acceptance items about returning payers,
  post-swap cancellations, and post-swap refunds needed **zero new code** for DP5 — they fall out
  of DP2's and DP4's work automatically once a binding actually changes. Confirmed by the full
  round-trip HTTP test creating a real second binding and checking nothing else needed touching.

## Not implemented

- **The actual "create new subscription, cancel old" migration flow** (§3.6's steps 2-4) — a
  human-guided process requiring a hosted payment-setup link this codebase has no mechanism to
  generate. `direct_payments:subscription-migrate` implements step 1 (the listing) only; this is stated
  explicitly in that command's own docblock, not a silent gap.
- **A currency-aware `pay_type: CONNECT` interaction beyond the existing `ConnectProfileService`
  check** — not asked for by the plan, not built.
- **Admin UI** — this entire plan, DP0 through DP6, is backend-only. No frontend/TypeScript work
  was done in any phase; every endpoint is API-only. If an admin page is wanted, that's new,
  unstarted work.

## Files

**New:** `app/Externals/DirectPayments/SwapService.php`,
`app/Http/Controllers/Externals/DirectPayments/DirectPaymentSwapController.php`,
`app/Console/Commands/DirectPaymentsImportCustomerMap.php`,
`app/Console/Commands/BillingSubscriptionMigrate.php`

**Modified:** `app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php` (DP0's guard),
`app/Externals/DirectPayments/BindingRegistry.php` (`swap()`, `canRetire()`, `retire()`, `history()`),
`routes/portal.php` (the four routes)

**Tests:** `tests/Feature/DirectPayments/SaveDirectPaymentTest.php` (DP0's guard, 3 tests),
`SwapServiceTest.php` (10 tests), `BindingRetirementTest.php` (7 tests),
`DirectPaymentSwapControllerTest.php` (4 tests), `DirectPaymentsImportCustomerMapTest.php`
(4 tests), `BillingSubscriptionMigrateTest.php` (3 tests)

## Acceptance

| §3.9 criterion | Status | Evidence |
|---|---|---|
| `saveDirectPayment()` with a different processor on a bound DP → 409 | ✅ | `SaveDirectPaymentTest::test_a_live_direct_payment_refuses_a_processor_change` |
| Preview writes nothing; counts match a hand-run query | ✅ | `SwapServiceTest::test_preview_writes_nothing`, `test_preview_counts_impact_correctly` (every count independently seeded and asserted) |
| A swap confirmed with a stale `impact_hash` → `DP_E_IMPACT_CHANGED` | ✅ | `SwapServiceTest::test_execute_rejects_a_stale_impact_hash`, `DirectPaymentSwapControllerTest::test_swap_with_a_stale_hash_returns_409` |
| After a swap: DP's `reference_num` unchanged; old binding `retiring`; exactly one `active` | ✅ | `SwapServiceTest::test_execute_creates_a_new_active_binding_and_retires_the_old_one` |
| A returning payer's next checkout creates one customer on the new processor, charges it | ✅ (no new code) | Falls out of DP2's `customerFor()` + DP3's `BindingRegistry::active()` automatically |
| A subscription taken on the old processor still cancels correctly after the swap | ✅ (no new code) | See `04-history-refunds-and-webhooks.complete.md` — already correct |
| A refund on a pre-swap charge succeeds against the old account | ✅ | See `04-history-refunds-and-webhooks.complete.md` |
| Retiring a binding with a live subscription → refused, naming the blocker | ✅ | `BindingRetirementTest::test_a_live_subscription_blocks_retirement`, `DirectPaymentSwapControllerTest::test_retiring_a_binding_with_a_live_subscription_is_refused` |
| `import-customer-map --dry-run` reports matched/unmatched/already-claimed, writes nothing | ✅ | `DirectPaymentsImportCustomerMapTest` — all four scenarios covered |
| A key rotation (same account) does not go through the swap path; preview says so | ✅ | `SwapServiceTest::test_preview_detects_same_account_via_matching_publishable_key_and_blocks_it` |

## Verification

```bash
cd w/www
php artisan test tests/Feature/DirectPayments/SwapServiceTest.php tests/Feature/DirectPayments/BindingRetirementTest.php tests/Feature/DirectPayments/DirectPaymentSwapControllerTest.php tests/Feature/DirectPayments/DirectPaymentsImportCustomerMapTest.php tests/Feature/DirectPayments/BillingSubscriptionMigrateTest.php
php artisan direct_payments:subscription-migrate {dpRef}
```

## Residue

- **No admin UI exists for any of this** — every DP0-DP6 endpoint and command is backend-only.
  Flagged here rather than silently, since a tenant cannot actually perform a swap without one.
- `ConnectProfileService`'s CONNECT-config blocker in `SwapService::blockersFor()` reuses the
  exact check `guardConnectPayType()` already had — confirmed consistent, not a new pattern.
