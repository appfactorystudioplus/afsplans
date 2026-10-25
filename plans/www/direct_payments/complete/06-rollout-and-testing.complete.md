# 06-rollout-and-testing — completion record

**Plan file:** [`../06-rollout-and-testing.md`](../06-rollout-and-testing.md)
**Rollout phases:** DP0 through DP6, all shipped
**Repo:** `w/www`
**Date:** 2026-09-12/13
**Status:** ✅ The whole plan is implemented, tested, and applied to the local database.

The index the other completion records hang off, per `complete/README.md`. Read the phase-specific
files for the full detail; this one is the checklist and the test-matrix state.

## Phase checklist

| Phase | What it shipped | Completion record | Status |
|---|---|---|---|
| DP0 | `saveDirectPayment()` refuses a processor change with a live binding | [`03-the-swap.complete.md`](03-the-swap.complete.md) | ✅ |
| DP1 | Processor/payer snapshot columns; D9 (tier identity) fix | [`02-payers-and-bindings.complete.md`](02-payers-and-bindings.complete.md), [`07-tier-bindings.complete.md`](07-tier-bindings.complete.md) | ✅ |
| DP2 | Payers, payer_customers, `PayerService`, checkout wiring | [`02-payers-and-bindings.complete.md`](02-payers-and-bindings.complete.md) | ✅ |
| DP3 | Processor bindings, `BindingRegistry`, every forward read re-pointed | [`02-payers-and-bindings.complete.md`](02-payers-and-bindings.complete.md) | ✅ |
| DP4 | D5 (refunds), D10 (tier pricing), D7 (webhooks); D2 confirmed already correct | [`04-history-refunds-and-webhooks.complete.md`](04-history-refunds-and-webhooks.complete.md), [`07-tier-bindings.complete.md`](07-tier-bindings.complete.md) | ✅ |
| DP5 | The swap itself: preview, execute, retirement, import-customer-map | [`03-the-swap.complete.md`](03-the-swap.complete.md) | ✅ |
| DP6 | ecommerce/events/appointments payer columns; Site Users payer fold | [`05-implementer-contract.complete.md`](05-implementer-contract.complete.md) | ✅ backend; ⚠️ SDK/D11 out of scope |

## The hard gate — did it hold?

> "DP1 must ship before DP5, and that now covers two backfills, not one."

Both backfills ran **before** DP5 was implemented, in the order the plan required, and both were
verified against real local data (not assumed empty):

- `direct_payments:backfill-processor-bindings` — 4 existing Direct Payments, all bound cleanly.
- `direct_payments:backfill-tier-bindings` — 4 existing tiers, all bound cleanly. **This backfill
  did not exist when DP1 shipped** — DP1's own migration incorrectly assumed
  `laravel_direct_payment_tiers` was empty (it had 4 rows). The gap was found and closed during
  DP4, before DP5 (the swap) shipped — the gate held, but only because DP4 happened to audit that
  table again. Recorded as a real process lesson: "no backfill needed" claims should be verified
  against the actual database, not inferred from one table's emptiness generalizing to a sibling
  table's.

## Cross-cutting patterns confirmed across every phase

- **The FORWARD/BACKWARD invariant** (§4.1) held everywhere it was tested: `guardProcessorChange()`,
  `customerFor()`, `refund()`, `cancel()`/`update()`, `priceForExistingSubscription()`, webhook
  candidate resolution. No BACKWARD-question call site was found reading `BindingRegistry::active()`.
- **The binding-vs-column fallback pattern** (DP0's guard, `PayerService::customerFor()`,
  `DirectPaymentSubscriptionController`'s store fallback) — every phase that introduced a new
  authoritative source (`BindingRegistry`) kept the old column readable as a fallback for
  not-yet-backfilled data, rather than assuming the backfill had already run everywhere.
- **"No production data to migrate" needs verifying, not assuming.** Checked explicitly with
  `SELECT COUNT(*)` before every "no backfill needed" migration in DP1/DP2; the one time this
  wasn't done carefully enough (tiers, DP1) produced the gap DP4 had to close.

## What DP0-DP6 did not touch

- **No frontend/TypeScript code** in any phase except reading it for the DP6 audit. Every
  endpoint is API-only.
- **No admin UI** for the swap, bindings, or payer identity — flagged in
  `03-the-swap.complete.md`'s Residue.
- **`brain_membership_identity`** (AFS's own membership signup flow) — explicitly out of scope
  for the whole `direct_payments` plan; it's a separate, sibling plan that depends on this one
  having shipped.
- **`connections/`, `site_users/13`'s email change, D11's guest-continuity** — all remain
  plan-only, never implemented in code. DP6's audit found this repeatedly (§5.3's SDK resolver,
  the email-change endpoint, the cart-cookie reassignment) and built what it could without them,
  documenting the boundary each time rather than silently building around a missing dependency.

## Full test count

| Phase | New test files | Tests |
|---|---|---|
| DP0/DP1 | `SaveDirectPaymentTest.php` | 7 |
| DP2 | `PayerServiceTest.php`, `SaveInvoiceRecordsPayerTest.php` | 13 |
| DP3 | `BindingRegistryTest.php`, `DirectPaymentsBackfillProcessorBindingsTest.php`, `SaveDirectPaymentTest.php` (+1) | 16 |
| DP4 | `RefundResolvesFromChargeTest.php`, `WebhookOrphanRecordingTest.php`, `TierBindingResolversTest.php`, `DirectPaymentsBackfillTierBindingsTest.php` | 17 |
| DP5 | `SwapServiceTest.php`, `BindingRetirementTest.php`, `DirectPaymentSwapControllerTest.php`, `DirectPaymentsImportCustomerMapTest.php`, `BillingSubscriptionMigrateTest.php` | 28 |
| DP6 | `PayerServiceUpdateEmailTest.php`, `SiteUsersMyPayerTest.php`, `SiteUsersLinkPayersReconcilerTest.php`, `EventOrderPayerTest.php`, `EcommerceOrderPayerTest.php` | 23 |
| **Total** | | **104 new tests**, plus regression runs against every pre-existing test in `tests/Feature/DirectPayments/`, `tests/Feature/SiteUsers/`, `tests/Unit/Ecommerce/`, `tests/Unit/Events/`, and `tests/Unit/Database/MigrationOwnershipTest.php` — all green at every phase boundary |

## Verification

```bash
cd w/www
php artisan test tests/Feature/DirectPayments/ tests/Feature/SiteUsers/ tests/Feature/Events/ tests/Feature/Ecommerce/ tests/Unit/Events/ tests/Unit/Ecommerce/ tests/Unit/Database/MigrationOwnershipTest.php
php artisan migrate:status
```

## Residue carried forward from every phase

- No admin UI (`03-the-swap.complete.md`).
- `refundByPaymentIntent()` unaudited for D5's shape (`04-history-refunds-and-webhooks.complete.md`).
- `directpayments:tier-binding-drift` not built (`07-tier-bindings.complete.md`).
- The TS SDK resolver, `/me/email`, D11, and `AppointmentBookingService`'s own integration test
  (`05-implementer-contract.complete.md`).
