# 04-history-refunds-and-webhooks — completion record

**Plan file:** [`../04-history-refunds-and-webhooks.md`](../04-history-refunds-and-webhooks.md)
**Rollout phase:** DP4
**Repo:** `w/www`
**Date:** 2026-09-12
**Status:** ✅ Shipped and applied to the local database

## What shipped

### §4.3 — D5, refunds resolve from the charge

- `ProcessorBillingController::refund()` rewritten: the processor is resolved from
  `$charge->processor_reference_num`, never the request's `processor_reference_num`. A missing
  origin processor returns `409 DP_E_ORIGIN_PROCESSOR_MISSING` naming the reference, exactly per
  spec.
- **One deliberate, verified deviation from the plan's literal code**: the plan's own snippet
  drops the customer-ownership check entirely. Confirmed via `routes/portal.php` that this
  specific endpoint (`POST /portal/api/v1/processors/billing/refund`) carries **no auth
  middleware** — dropping the check would have let anyone refund any known `charge_id` with no
  ownership proof at all, worse than the bug being fixed. The ownership check (`customer_id` must
  own the charge) is kept, in its original shape; only the processor-resolution question changed.
  `MoneyController::refund()` (the authenticated, admin-facing path via `RefundService`) was
  already correct and untouched — confirmed by reading `RefundService::refund()` directly.

### §4.4 — D2 (confirmed, not fixed) and D10 (fixed)

- **D2's account-resolution part was already correct in this codebase** when read directly —
  `cancel()`/`update()`/`setPaymentMethod()` all resolve via `$subscription->processor_reference_num`
  (the `ProcessorSubscription`'s own snapshot), not via the Direct Payment's current processor.
  The plan's audit describes an older shape than what the code actually does today; this is
  recorded as a **confirmed-correct** finding, not a fix.
- D10 (the price half of the same bug) fixed — see `07-tier-bindings.complete.md`.

### §4.5 — D7, webhooks

- All four webhook candidate scans (Stripe v1, Square, PayPal, Stripe v2) expanded: a processor
  bound via any non-`retired` `DirectPaymentProcessorBinding` stays a candidate even with
  `is_active = false` — via one shared `webhookCandidateProcessorIds()`/`webhookCandidateProcessors()`
  helper pair, not four separate edits.
- New `laravel_processor_webhook_orphans` table, written from the single shared
  `recordRejectedWebhook()` call site whenever the rejection reason is `bad_signature`
  specifically (real candidates existed, none verified — the swap-orphan shape), never for
  `no_processor`/`no_secret` (which mean no real candidate was even tried).
- **A pre-existing mechanism found during the audit, not built by this pass**: the codebase
  already had `ProcessorWebhookRejection` (rate-limited, security-framed). The new orphan table is
  deliberately separate — orphans are an operator-facing "did we miss money" count and are never
  rate-limited, unlike rejections.

## Not implemented

- **Open disputes are not checked anywhere** (DP5's `canRetire()` inherits this gap) — no
  dispute-tracking table exists in this codebase; chargebacks are only logged
  (`ProcessorWebhookController::logStripeChargeback()`), never persisted. Flagged explicitly in
  `BindingRegistry::canRetire()`'s own docblock.

## Files

**New:** `app/Models/Externals/Processor/ProcessorWebhookOrphan.php`

**Modified:** `app/Http/Controllers/Externals/Processor/ProcessorBillingController.php` (`refund()`),
`app/Http/Controllers/Externals/Processor/ProcessorWebhookController.php` (candidate scans,
`recordRejectedWebhook()`), `database/migrations/2026_05_21_000001_create_processor_tables.php`
(the orphans table)

**Tests:** `tests/Feature/DirectPayments/RefundResolvesFromChargeTest.php`,
`WebhookOrphanRecordingTest.php`

## Acceptance

Per `complete/README.md`'s own note: "the fake provider asserting which account a refund and a
subscription cancellation were sent to" is the evidence bar for this document specifically. What
was actually built and verified:

| §4.7 criterion | Status | Evidence |
|---|---|---|
| A refund on a pre-swap charge succeeds against the old account regardless of what the request names | ✅ | `RefundResolvesFromChargeTest::test_a_request_naming_the_wrong_processor_still_resolves_from_the_charge` — the request names a processor **with a real, callable secret key** (would attempt a live Stripe call if reached); the origin processor has none. Reaching the origin's "not configured" 400 rather than attempting the other processor's real call **is** the provider-level proof: if resolution had used the request's processor, this test would have tried a genuine (fake-keyed) HTTP call instead of stopping at 400 |
| A refund on a charge whose origin processor no longer exists → `409 DP_E_ORIGIN_PROCESSOR_MISSING` | ✅ | `RefundResolvesFromChargeTest::test_a_charge_whose_origin_processor_no_longer_exists_is_refused_with_a_named_code` |
| Cancelling a subscription taken before a swap still cancels at the old provider | ✅ (pre-existing) | Confirmed correct by reading the code directly, not a new test — `cancel()` already resolved via `$subscription->processor_reference_num` before this pass touched anything |
| Upgrading a pre-swap subscription sends the old processor's price to the old processor | ✅ | See `07-tier-bindings.complete.md` |
| A webhook from a retiring binding's processor is accepted | ✅ | `WebhookOrphanRecordingTest::test_a_deactivated_but_still_bound_processor_still_authenticates` — a processor with `is_active=false` but a `retiring` binding authenticates a real, HMAC-signed webhook through the actual route |
| A webhook matching no candidate creates an orphan row, visible as a count | ✅ | `WebhookOrphanRecordingTest::test_a_webhook_matching_no_real_candidate_is_recorded_as_an_orphan` |
| The grep gate: no BACKWARD-question method calls `BindingRegistry::active()` | ⚠️ | Not run as an actual grep gate in CI; manually verified by reading every changed method during implementation. Worth adding as a real check in a follow-up |

## Verification

```bash
cd w/www
php artisan test tests/Feature/DirectPayments/RefundResolvesFromChargeTest.php tests/Feature/DirectPayments/WebhookOrphanRecordingTest.php
```

## Residue

- `refundByPaymentIntent()` (market's own refund caller, `ProcessorBillingController.php`) was
  **not** audited or touched in this pass — it resolves via PaymentIntent id directly, a
  different mechanism from `refund()`'s customer/charge lookup, and D5's audit only named
  `refund()`. Worth a follow-up pass to confirm it doesn't have the same shape of bug.
- The stale-vs-fresh distinction in `guardConnectPayType()`/`SwapService::blockersFor()`'s CONNECT
  check reuses `ConnectProfileService` exactly as `saveDirectPayment()` already did — no new
  finding, confirmed consistent.
