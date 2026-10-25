# 02-payers-and-bindings — completion record

**Plan file:** [`../02-payers-and-bindings.md`](../02-payers-and-bindings.md)
**Rollout phases:** DP1 (§2.5), DP2 (§2.2–2.3), DP3 (§2.4)
**Repo:** `w/www`
**Date:** 2026-09-12/13
**Status:** ✅ Shipped and applied to the local database

## What shipped

### DP1 — §2.5, every money object snapshots its processor

- `laravel_direct_payment_subscriptions` gained `processor_reference_num` (char 7, indexed) and
  `payer_reference_num` (char 32, indexed) — folded into the existing merged migration
  (`2026_04_03_160000_create_laravel_direct_payment_tables.php`), not a separate ALTER, since
  that file is the one this plan actively owns and edits.
- `laravel_direct_payment_invoices` gained `payer_reference_num` the same way.
- **No backfill migration** — verified via `SELECT COUNT(*)` before writing the migration that
  both tables were empty on this deployment. `laravel_direct_payment_subscriptions.
  processor_reference_num` is populated going forward by
  `DirectPaymentSubscriptionController::store()` (resolved from the `ProcessorSubscription`
  row's own column, D2) and `BillingBackfillDpSubscriptions` (the orphan reconciler, same
  source).
- D9 (tier identity) fixed in the same pass: `saveDirectPayment()`'s tier loop changed from
  delete-and-recreate to `updateOrCreate` keyed on `reference_num`, with
  `DP_E_UNKNOWN_TIER_REFERENCE` refusing a client-invented reference. New
  `App\Externals\DirectPayments\Support\DirectPaymentReference::tier()`.

### DP2 — §2.2–2.3, payers and payer_customers

- `laravel_direct_payment_payers` and `laravel_direct_payment_payer_customers` created (same
  merged migration file).
- `App\Externals\DirectPayments\PayerService`: `resolveForCheckout()` (the three-rule resolution —
  already-known payer / guest-by-email / fresh-anonymous), `customerFor()` (FORWARD resolution,
  creates a provider customer via the extracted `ProcessorCustomerGateway`), `recordExistingCustomer()`
  (records a customer the existing checkout flow already created, added because DP2's own
  checkout wiring needed a "record, don't create" path the plan's `customerFor()` doesn't cover).
- `App\Externals\Processor\ProcessorCustomerGateway` extracted **verbatim** from
  `ProcessorCustomerController`'s private methods, so `PayerService` and the controller share one
  copy of the Stripe/Square wire format — no behaviour change to the extracted code.
- Wired into `DirectPaymentsController::saveInvoice()`: resolves and records a payer alongside
  every invoice save, best-effort, never overwriting an already-set `payer_reference_num` with
  null on a re-save. `customer_id` (D4) is untouched.

### DP3 — §2.4, processor bindings

- `laravel_direct_payment_processor_bindings` created (same merged migration file).
- `App\Externals\DirectPayments\BindingRegistry`: `active()`, `ensureActive()` (idempotent, throws
  `DP_E_ACTIVE_BINDING_MISMATCH` on a processor change — the guard that makes DP0's refusal
  meaningful at the data layer, not just the controller).
- Every "current processor" read in the codebase re-pointed at it, with a fallback to
  `laravel_direct_payments.processor_reference_num` for any DP the backfill command hadn't
  reached yet (a gap caught during testing, not anticipated from the plan text alone):
  `DirectPaymentsController::guardProcessorChange()`, `PayerService::customerFor()`,
  `DirectPaymentSubscriptionController::store()`'s fallback branch, the tier-binding write in
  `saveDirectPayment()`.
- `php artisan direct_payments:backfill-processor-bindings` — needed for real this time (unlike
  DP1/DP2's empty tables): the local database had 4 existing Direct Payments. Ran against real
  data; all 4 bound cleanly to processor `2uDJGCr`, none skipped.

## Not implemented

- **Per-binding configurable refund window** (§2.4 doesn't ask for this, but DP5's
  `canRetire()` uses a fixed `BindingRegistry::DEFAULT_REFUND_WINDOW_DAYS = 180` constant, not a
  per-binding column) — DP5's own completion record, not this one.
- **`laravel_ecommerce_carts`/orders' own `payer_reference_num` writes** — DP6, not DP1-3.

## Files

**New:**
`app/Externals/DirectPayments/PayerService.php`, `app/Externals/DirectPayments/BindingRegistry.php`,
`app/Externals/DirectPayments/DirectPaymentException.php`, `app/Externals/DirectPayments/Support/DirectPaymentReference.php`,
`app/Externals/Processor/ProcessorCustomerGateway.php`, `app/Models/Externals/DirectPaymentPayer.php`,
`app/Models/Externals/DirectPaymentPayerCustomer.php`, `app/Models/Externals/DirectPaymentProcessorBinding.php`,
`app/Console/Commands/DirectPaymentsBackfillProcessorBindings.php`

**Modified:**
`database/migrations/2026_04_03_160000_create_laravel_direct_payment_tables.php`,
`app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php`,
`app/Http/Controllers/Externals/DirectPayments/DirectPaymentSubscriptionController.php`,
`app/Http/Controllers/Externals/Processor/ProcessorCustomerController.php`,
`app/Console/Commands/BillingBackfillDpSubscriptions.php`

**Tests:** `tests/Feature/DirectPayments/PayerServiceTest.php`,
`SaveInvoiceRecordsPayerTest.php`, `BindingRegistryTest.php`,
`DirectPaymentsBackfillProcessorBindingsTest.php`, plus extensions to `SaveDirectPaymentTest.php`.

## Acceptance

| §2.5 / §2.2-2.3 / §2.4 criterion | Status | Evidence |
|---|---|---|
| Two of four money tables already snapshot processor; plan finishes the set | ✅ | `laravel_direct_payment_subscriptions`/`invoices` both carry the column; `PayerServiceTest`, `SaveInvoiceRecordsPayerTest` |
| D2's backfill safe exactly once, done before swap exists | ✅ | No backfill needed (table was empty); write-side population instead, verified in `SaveInvoiceRecordsPayerTest` |
| A guest with an email reuses their payer; two anonymous guests never collapse | ✅ | `PayerServiceTest::test_a_guest_with_an_email_reuses_their_payer_on_the_same_website`, `test_two_anonymous_guests_never_collapse_into_one_payer` |
| Same email on two different websites → two payers | ✅ | `PayerServiceTest::test_the_same_email_on_two_different_websites_gets_two_payers` |
| `customerFor()` creates at the provider with email, or anonymous with none | ✅ | `PayerServiceTest::test_customer_for_creates_a_provider_customer_using_the_payers_email`, `test_customer_for_creates_an_anonymous_customer_when_the_payer_has_no_email` |
| A swap does not rewrite payer_customers, adds a row and marks the old superseded | ✅ | `PayerServiceTest::test_a_changed_provider_customer_supersedes_rather_than_overwrites` |
| `BindingRegistry::active()` is the only way code learns the current processor | ✅ | Every call site re-pointed, listed above; `guardProcessorChange()` fallback tested in `SaveDirectPaymentTest::test_a_live_direct_payment_refuses_a_processor_change` |
| Existing DPs get exactly one active binding after backfill | ✅ | Ran for real against 4 local DPs — `DirectPaymentsBackfillProcessorBindingsTest`, verified live with `direct_payments:backfill-processor-bindings --dry-run` then for real |

## Verification

```bash
cd w/www
php artisan test tests/Feature/DirectPayments/PayerServiceTest.php tests/Feature/DirectPayments/SaveInvoiceRecordsPayerTest.php tests/Feature/DirectPayments/BindingRegistryTest.php tests/Feature/DirectPayments/DirectPaymentsBackfillProcessorBindingsTest.php
php artisan direct_payments:backfill-processor-bindings --dry-run
```

## Residue

- **BM5/BM6-shaped findings did not recur here** — no duplicate-source-of-truth issues found in
  this pass (unlike `brain_membership_identity`'s audit of `resources/js/afs/`).
- **The DP1 tier_bindings backfill gap** (found during DP4, fixed there via
  `direct_payments:backfill-tier-bindings`) belongs to `07-tier-bindings.complete.md`, not here —
  noted so a reader of this file isn't surprised it's absent.
