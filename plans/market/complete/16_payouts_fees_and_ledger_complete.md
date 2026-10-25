# Step 16 — Payouts, the 10% fee, and the seller ledger — completion report

**Date:** 2026-08-20
**Projects touched:** market (`apps/market`), brain (`apps/www`), members (`apps/members`).
**Status:** ✅ complete, with genuine cross-project gaps documented below. The ledger, fee
arithmetic, and manual-payout/refund/chargeback round trip are all built and live-verified;
three of §16.7's six reconciliation checks need data brain does not expose yet and are
implemented as findable, documented gaps rather than faked.

## What shipped

**The one arithmetic rule** (`FeeCalculator`) — integer cents, `floor` on the fee, so
`fee + net == line_net` always. Verified over every price 1–100,000 cents × 7 fee percentages
(700,000 cases, zero failures) and live through a real $33.33-at-15% checkout (`$4.99` fee, not
`round()`'s `$5.00` — the exact fractional-cent case the plan's own §16.2 example describes).

**The ledger** (`LedgerService`, append-only, `SellerLedgerEntry`'s only writer) — `sale`+
`platform_fee` pairs share one `available_at`; every other type writes immediately-effective.
`BalanceCalculator` computes pending/available/total in integer cents via `SUM(ROUND(amount*100))`,
never a raw float aggregate. Three real callers, all live-verified:

- **Asset checkout** (`OrderFulfilmentService`) — ledgers at order creation, `available_at` =
  now + `clearing_days` (default 7).
- **Service completion** (`ServiceOrderStateMachine::transition()` → `completed`) — ledgers
  immediately, but **only when the order has no milestones** (checked via
  `ServiceOrderMilestone::where('service_order_id', ...)->exists()`) — avoids double-ledgering an
  order whose milestones already ledgered incrementally.
- **Milestone approval** (`MilestoneService::approve()`) — ledgers exactly that milestone's own
  amount, fee computed fresh against `service.platform_fee_percent`. Live-verified: a two-milestone
  ($80/$120) order approved milestone 1 only → ledger shows exactly `$80`/`$8` fee, nothing for
  milestone 2, and the order's later `completed` transition (via the milestone path) wrote **no**
  additional entry.

**Manual payouts** (`PayoutService`, `PayoutController`) — `POST /sellers/{ref}/payouts`
(members' `ConnectController::requestPayout()` — previously a 404 stub, now real) validates the
requested amount against available balance and the configurable minimum ($20 default), debits the
ledger **at request time** (not at settlement — Stripe reserves the connected account's balance
immediately), and calls brain synchronously to trigger the real Stripe payout.

**Refunds** (`market:refund` console command) — calls brain's new `refundByPaymentIntent()`
(reuses step 08's `reverse_transfer`/`refund_application_fee` correctness), then — only on that
call's success — ledgers `refund`/`fee_refund` (fee refunded proportionally, same `floor` rule) and
revokes the entitlement on a full refund.

**Chargebacks** — brain forwards a lost card dispute to market's new `routes/brain.php`; market
splits the chargeback proportionally across the order's items by gross share (remainder absorbed by
the last item, so the sum allocated always equals exactly what was reported) and ledgers a
`chargeback` debit per seller. Live-verified to drive a balance negative without erroring.

**A new brain↔market HMAC channel** (`BrainInternalSignature`, mirrored on both sides) — the exact
channel brain's own step-08 class explicitly left open for "members, market, or a future brain-side
seller portal." `MARKETPLACE_INTERNAL_API_KEY` (left blank since step 08, "fails closed while
empty") now has a real shared value on both sides.

**Reconciliation** (`market:reconcile-ledger`, daily) — two of §16.7's six checks, fully
implemented and live-verified against deliberately corrupted data: a paid order with no `sale`
entry, and a deleted `platform_fee` row producing a `balance_after` drift. See "Known gaps" for the
other four.

## Decisions

1. **`OrderFulfilmentService`'s pre-existing `round($x, 2)` fee computation was replaced with
   `FeeCalculator`, beyond the plan's own "ledger writes" framing for this file's change.** §16.1's
   own rule — "two systems computing the same fee is how a ledger drifts" — named exactly this risk:
   `round()` and `floor`-in-cents disagree on a fractional-cent boundary (found live: $33.33 at 15%
   gives `round()` → $5.00, `floor` → $4.99), and leaving the checkout-time computation on the old
   rule while the ledger used the new one would have been precisely the two-systems drift the plan
   warns about, not a single new implementation.

2. **`MilestoneService::createForOrder()`'s sum-check compared against `line_total` (tax-inclusive);
   fixed to compare against the tax-excluded base** (`platform_fee_amount + seller_net_amount`) —
   found while wiring the ledger, since a milestone's own fee is computed against that same
   tax-excluded base everywhere else. Never surfaced live because `tax_mode` defaults to `none`.

3. **`LedgerController`/`routes/brain.php` are mounted under a new `brain.internal` channel, not
   `routes/internal.php`'s `market.internal`** — a deliberate deviation from §16.9's literal
   "`app/Http/Controllers/Internal/{LedgerController,PayoutController}.php`" grouping, which reads
   as "same directory" not "same route group." A stolen `MARKET_INTERNAL_API_KEY` (members' secret)
   must not also unlock ledger-mutating calls brain alone should be able to make, and vice versa —
   two callers, two secrets, one shared directory for file-list fidelity.

4. **The manual-payout ledger debit is written at request time, not at Stripe settlement.** Stripe
   reserves the connected account's balance the instant `payouts.create` succeeds; if market waited
   for the `payout.paid` webhook to debit, a seller could request the same available balance twice
   before the first payout's webhook ever arrived. A failed trigger (brain unreachable) leaves the
   payout `pending` with no `connect_payout_id` for `ProcessScheduledPayouts` to retry — the debit
   stands either way, corrected only by a genuine `payout.failed` (`payout_reversal`), never by a
   mere retry-later case.

5. **`market:refund` is a console command, not a web UI or a webhook-triggered flow** — the same
   reasoning as step 15's `market:resolve-dispute`: market has no admin authentication system
   anywhere in this codebase (unchanged since that step's own finding). Deliberately synchronous
   (calls brain, gets an authoritative answer, ledgers immediately) rather than a webhook round trip
   — a webhook would need to correlate an arbitrary `charge.refunded` event back to one order among
   possibly several sharing a payment reference, which a direct, synchronous call avoids needing to
   do.

6. **§16.7's own admin-report requirement is satisfied as CLI output** (`market:reconcile-ledger`'s
   stdout + log lines), not a web view — repeating step 15's established precedent rather than
   introducing a fourth kind of un-gated "admin" surface. §16.9 lists "admin reporting views" as a
   created file; this is the resolution, documented consistently rather than silently built as a
   web page nobody could safely reach.

## Found bugs (fixed)

- **§16.2 fee-rounding drift** between checkout (`round()`) and the (not-yet-built) ledger — see
  Decision 1.
- **§16.2 milestone sum-check tax-inclusivity mismatch** — see Decision 2.
- **Members' three payout-request entry points hardcoded `min:10`** (`AssetStoreController`,
  `FreelanceController`, `ConnectController::requestPayout()`, plus `EarningsView.ts`'s own
  client-side `amount < 10`) — stale relative to the plan's stated $20 default and now redundant:
  market's real endpoint is the authority and reports the actual configured threshold in its error
  message. Relaxed to a `0.01` sanity floor in all four places rather than guessing a second number
  that could drift from market's own config independently.

## Known gaps (deliberate, documented — not silently assumed built)

- **Three of §16.7's six reconciliation checks are not implemented**: matching a ledger `sale`
  against brain's `ProcessorTransferIntent` rows (#2), re-checking `pending > 24h` transfer intents
  against Stripe (#4), and Stripe's connected-account balance vs. market's computed available
  balance (#6). All three need data that lives in brain's own database or a live Stripe call, and no
  read endpoint for any of it exists on either side yet — building one was out of this step's scope
  as written. `ReconcileLedger`'s own header comment documents this in full.
- **§16.7 check #5 ("market's platform_fee_percent equals brain's configured value") is not
  well-defined as literally written** — §16.1 and `config/market.php`'s own header are explicit that
  market keeps **no live copy** of the fee percent at all; every listing snapshots its own rate at
  publish time. There is no singular "market's platform_fee_percent" for this check to compare
  against. Read as in tension with §16.1 rather than as one coherent rule; resolving it would need a
  brain-side read endpoint this step did not build. Documented in `ReconcileLedger`'s own header.
- **`orders.refunded_at` can only be set accurately for a single-item order's full refund.**
  `order_items` has no per-item refund column (confirmed by inspection) — a partial refund, or a
  full refund of one item inside a multi-item order, has nowhere to record itself on the buyer's
  receipt (`Portal\OrderController::apiShow()`) today. `market:refund` sets `orders.refunded_at`
  only when the refunded item is the order's only item; documented in that command's own comment
  rather than silently leaving multi-item orders inconsistent.
- **`market:refund` and `triggerPayout()` do not pass a Stripe idempotency key** — `BrainPayoutClient`'s
  own header comment names this: the Stripe SDK calls this step reuses (`payouts->create`,
  `refunds->create`) accept an idempotency key parameter this step did not wire through yet. A
  genuine retry today is handled at the market layer instead (`ProcessScheduledPayouts` only retries
  a payout still `pending` with no `connect_payout_id`; `market:refund` has no automatic retry at
  all — a second manual invocation would create a second Stripe refund).
- **Live end-to-end HTTP verification against brain was not possible this session.** `apps/www`
  cannot boot at all in this environment — `php artisan route:list`/any request fails on a missing,
  unrelated legacy include (`public/_portal_/admin/core/php/includes/functions.php`, confirmed
  absent from this checkout, nothing this step touched). Brain-side changes were verified via
  `php -l` (clean on every file) and careful manual review against the existing, already-verified
  patterns (`ConnectAccountService::requestPayout()`, already fully built and unused since step 08;
  `refundStripe()`'s `reverse_transfer`/`refund_application_fee` logic, reused verbatim). Market's
  side of the same round trip was verified two ways instead: `Http::fake()` against
  `BrainPayoutClient`'s exact outbound shape (headers, path, body) for the market→brain calls, and
  real signed HTTP requests (self-signed with the same `BrainInternalSignature` algorithm) against
  `routes/brain.php` for the brain→market direction — both directions of the contract exercised,
  just never through an actually-running brain process.

## Files

| File | Project | Change | Notes |
|---|---|---|---|
| `app/Services/Ledger/{FeeCalculator,BalanceCalculator,LedgerService}.php` | market | created | The arithmetic, the reads, the writes |
| `app/Services/Payouts/{PayoutService,BrainPayoutClient,BrainInternalSignature}.php` | market | created | Manual payout flow + the new brain channel's market-side signer |
| `app/Http/Middleware/VerifyBrainSignature.php` | market | created | Guards `routes/brain.php` |
| `app/Http/Controllers/Internal/{PayoutController,LedgerController}.php` | market | created | `POST /sellers/{ref}/payouts`; brain's forwarded payout-state/chargeback receiver |
| `app/Console/Commands/{ReconcileLedger,ReleasePendingEarnings,ProcessScheduledPayouts}.php` | market | created | §16.7/§16.9 |
| `app/Console/Commands/RefundOrderItem.php` | market | created | Not in §16.9's own list — the admin refund trigger (Decision 5) |
| `routes/brain.php` | market | created | The new brain→market channel's two endpoints |
| `config/market.php` | market | modified | `commerce.clearing_days`, `payouts.minimum_amount`, `brain_internal.key`/`max_skew` |
| `app/Services/Checkout/OrderFulfilmentService.php` | market | modified | `FeeCalculator` (Decision 1) + asset-sale ledger write |
| `app/Services/ServiceOrders/ServiceOrderStateMachine.php` | market | modified | Completion ledger write (non-milestone orders) |
| `app/Services/ServiceOrders/MilestoneService.php` | market | modified | Sum-check fix (Decision 2) + approval ledger write |
| `routes/internal.php` | market | modified | `POST /sellers/{ref}/payouts` |
| `routes/console.php` | market | modified | 3 new scheduled commands |
| `bootstrap/app.php` | market | modified | `routes/brain.php` group + `brain.internal` alias |
| `.env` | market | modified | `MARKETPLACE_INTERNAL_API_KEY`/`_MAX_SKEW_SECONDS` (real value, was unset) |
| `app/Services/Marketplace/BrainInternalSignature.php` | brain | modified | Added `sign()` (outbound half — `verifyRequest()` already existed, step 08) |
| `app/Http/Controllers/Externals/Processor/ProcessorConnectController.php` | brain | modified | `triggerPayout()` — the first caller of `ConnectAccountService::requestPayout()` (built step 08, unused since) |
| `app/Http/Controllers/Externals/Processor/ProcessorBillingController.php` | brain | modified | `refundByPaymentIntent()` — the PaymentIntent-keyed sibling of the existing `refund()` |
| `routes/portal.php` | brain | modified | 2 new routes for the above |
| `.env` | brain | modified | `MARKETPLACE_INTERNAL_API_KEY` set (was blank since step 08) |
| `app/Http/Controllers/{AssetStoreController,FreelanceController}.php`, `app/Http/Controllers/Market/ConnectController.php` | members | modified | Stale "not built yet" comments landed; hardcoded `min:10` relaxed (Found bugs) |
| `resources/js/market/EarningsView.ts` | members | modified | Same `min:10` fix; deviation comment updated to reflect `LedgerService` landing |

`grep -rn MARKET_MODIFIER_ADDITION` across every file in this table, in all three projects,
reconciled against this list — no file in the list without a tag, no tagged file outside it.
`resources/js/templates/views/payouts.html` (named in §16.9) needed no change — it is already a
state-agnostic shell `EarningsView.ts` mounts into unchanged.

## Verification

All performed live against a scratch MySQL database (`market_scratch_test`), never the real dev
database — `.env` backed up before any change (including the permanent `MARKETPLACE_INTERNAL_API_KEY`
addition, so the restore target correctly kept it), restored and diffed identical to that backup
afterward, scratch database dropped, local mysql service stopped when done.

- **Fee invariant**: `FeeCalculator::compute()` over every price 1–100,000 cents × 7 fee percentages
  (700,000 cases) — `fee + net == line_net` and `fee == floor(...)` held every time.
- **`php artisan migrate:fresh --force`** — all 43 migrations (steps 03–16) applied cleanly; step 16
  needed **zero new migrations** (`SellerLedgerEntry`/`Payout` fully anticipated by step 03, the same
  build-ahead pattern step 15 found for its own tables).
- **Real checkout through `OrderFulfilmentService::fulfil()`** (a real `Cart`/`CartItem`, not a
  hand-built `Order`) — $33.33 at 15%: `platform_fee_amount = 4.99`, `seller_net_amount = 28.34`,
  ledger `sale +33.33`/`platform_fee -4.99`, `available_at` = now + 7 days, `pending = $28.34`,
  `available = $0`.
- **Service completion**: a `delivered` → `completed` transition (no milestones) ledgered `sale
  +100.00`/`platform_fee -10.00` with `available_at = now()` (immediate, correctly distinct from the
  asset's clearing period).
- **Milestones**: two milestones ($80/$120) on a $200 order; approving milestone 1 alone ledgered
  exactly `$80`/`$8` fee; the order's later full completion (via the milestone path) wrote **no**
  additional entry — confirms "milestone approval ledgers exactly that milestone's amount" and no
  double-ledgering.
- **Manual payout** (`Http::fake()` standing in for brain): available balance ($162, correctly
  excluding the still-pending asset sale) requested and paid out in full; a second request against
  $0 available correctly rejected with the real `$20` minimum named; a partial $30 request against a
  topped-up balance succeeded; a simulated brain failure left the payout `pending` with a
  `failure_reason`, the ledger debit **standing**; `ProcessScheduledPayouts` retried it and picked up
  a real `connect_payout_id` on the next attempt.
- **`LedgerController` over real signed HTTP** (`php artisan serve`, self-signed with the same
  `BrainInternalSignature` algorithm brain will use): `payout.paid` marked the payout `paid`;
  `payout.failed` on the retried payout wrote a `payout_reversal` crediting back exactly what was
  debited; **a wrong signature was rejected with 401**; a chargeback forward against a real order
  correctly split proportionally across that order's two items (an accidental multi-item test
  artifact from this session's own seeding, not a design choice — the split summed to exactly the
  reported chargeback amount either way, confirming the remainder-absorption logic).
- **`market:refund`** (`Http::fake()` standing in for brain): a full refund revoked the entitlement
  and set `orders.refunded_at` (single-item order); a partial $50-of-$200 refund at the item's 10%
  rate correctly refunded $5.00 in fees and left the entitlement active; an over-amount request was
  rejected naming the item's actual gross.
- **`market:reconcile-ledger`**: clean on correct data; deliberately deleting a `platform_fee` entry
  and seeding a paid order with no `sale` entry produced exactly two findings, both correctly
  describing the corruption, with a non-zero exit code.
- **Buyer receipts** (`Portal\OrderController::apiShow()`, unmodified by this step): confirmed by
  reading the method body — no `platform_fee_amount`/`seller_net_amount` anywhere in the response
  shape.
- **`php -l`** clean on every PHP file created or modified, across all three projects.
- **`npx tsc --noEmit --skipLibCheck`** on `EarningsView.ts` (members) — zero errors attributed to
  that file.
