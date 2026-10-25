# Step 13 — Market: cart and checkout on the Direct Payments API — completion report

**Date:** 2026-08-19
**Projects touched:** market (`apps/market`), brain (`apps/www`) — see "Decisions" for why this step
necessarily touches brain, not just market.
**Status:** ⚠️ complete with gaps — the full cart → checkout → server-to-server invoice → payment →
fulfilment pipeline is built and **live-verified end to end**, including the free path, a paid path
against a stub brain server, a two-seller split cart, double-submit idempotency, cart-mutation
invoice unlocking, and the stuck-checkout reconciliation job. No live Stripe was used anywhere (none
has been available in this session — consistent with every prior step). See "Known gaps" for the
honest list of what that leaves unverified.

## What shipped

`PurchaseController::processPayment()` — the fake checkout that accepted a raw card number and
marked every order `completed` without a processor — is deleted outright. In its place:

- **Cart** (`GET /cart`, `GET/POST /cart/items`, `PATCH/DELETE /cart/items/{id}`, `POST /cart/clear`):
  database-backed for a signed-in buyer, session-backed for a guest, merged on sign-in. Assets are
  quantity-1 and reject an already-owned item; services coalesce identical package+extras lines into
  a bumped quantity. A cart badge and "Add to cart" wiring run on every storefront page.
- **Checkout** (`GET /checkout`, `POST /checkout/prepare`, `POST /checkout/confirm`,
  `GET /checkout/status/{ref}`, `GET /checkout/return`): re-validates the cart against live prices and
  listing status before anything is charged; a required, IP+timestamp-recorded license-agreement
  checkbox; a free cart (§13.5) skips the payment flow entirely; a priced cart is built and locked as
  a Direct Payments invoice **server-to-server** (market's own PHP calling brain directly) — the
  browser never loops `addItem()` and never decides a price.
- **The browser side** (`resources/js/portal/checkout.ts`) mirrors `single_charge`'s flow but replays
  market's already-decided invoice into the SDK via a new `loadPreparedInvoice()` method (this step's
  own addition to the SDK — see "Decisions"), then mounts payment UI and confirms through Stripe
  exactly as the existing playground pages do.
- **Server confirmation never trusts the browser.** `POST /checkout/confirm` (and its polling twin,
  `GET /checkout/status/{ref}`) ask brain for the PaymentIntent's live Stripe status — not the
  freely-settable `DirectPaymentInvoice.status` column, which this step found has no request
  authentication at all (see "Decisions") — before `OrderFulfilmentService` ever runs.
- **Fulfilment is one transaction, keyed on the invoice's unique `dp_invoice_reference_num`.** A
  double-submit, a retried poll, and a stuck checkout the reconciliation command retries all converge
  on exactly one `Order`, checked before the transaction and backstopped by the unique constraint if a
  race gets past that check.
- **`market:reconcile-stuck-checkouts`** (scheduled every 5 minutes): finds a cart whose invoice
  locked and — per a live Stripe re-check — was actually paid, but whose `Order` never got created,
  and retries fulfilment. This is §13.6's highest-severity failure path, and it is live-verified, not
  just written (see "How this was verified").

## Files created

| File | Project | Tag placement | Purpose |
|---|---|---|---|
| `database/migrations/2026_08_19_000039_create_carts_table.php` | market | header | `carts` |
| `database/migrations/2026_08_19_000040_create_cart_items_table.php` | market | header | `cart_items` |
| `database/migrations/2026_08_19_000041_add_license_agreement_to_orders_table.php` | market | header | `orders.license_agreed_{at,ip}` |
| `app/Models/Cart.php`, `app/Models/CartItem.php` | market | header | Cart models |
| `app/Http/Controllers/CartController.php` | market | header | Cart JSON API + shell |
| `app/Http/Controllers/CheckoutController.php` | market | header | The whole checkout flow |
| `app/Services/Checkout/CartService.php` | market | header + per-method | Every cart rule |
| `app/Services/Checkout/InvoiceBuilder.php` | market | header + per-method | Builds/locks/unlocks the brain-side invoice |
| `app/Services/Checkout/OrderFulfilmentService.php` | market | header + per-method | The one atomic, idempotent fulfilment path |
| `app/Services/Checkout/CartLockedException.php` | market | header | Signals a locked-cart mutation |
| `app/Console/Commands/ReconcileStuckCheckouts.php` | market | header | §13.6's reconciliation job |
| `app/Http/Middleware/IdentifyCheckpointMember.php` | market | header | Optional identity for guest-friendly routes |
| `app/Services/Portal/CheckpointTokenVerifier.php` | market | header | Extracted from step 11's middleware — see Decisions |
| `app/Services/Internal/BrainClient.php` | market | header + per-method | Market → brain server-to-server calls |
| `resources/js/portal/cart.ts` | market | header | Cart badge, add-to-cart, cart page |
| `resources/js/portal/checkout.ts` | market | header | Checkout + return-page flow |
| `resources/views/cart.blade.php` | market | header | Cart shell |
| `resources/views/checkout/{index,return,pending}.blade.php` | market | header | Checkout shells |
| `lang/en/cart.php`, `lang/en/checkout.php` | market | header | New-page strings |

## Files modified

| File | Project | Lines / regions | Tag placement | Change |
|---|---|---|---|---|
| `routes/web.php` | market | rewritten | line-level | Cart/checkout routes under `checkpoint.identify`; old checkout routes now redirect |
| `routes/console.php` | market | appended | line-level | Reconciliation command scheduled every 5 minutes |
| `bootstrap/app.php` | market | +1 alias | line-level | `checkpoint.identify` middleware alias |
| `vite.config.ts` | market | 1 line | line-level | `checkout.ts` added as its own entry |
| `resources/js/app.ts`, `resources/js/portal.ts` | market | +1 import each | line-level | Cart badge wired into both layouts |
| `resources/views/layouts/app.blade.php`, `resources/views/portal/layouts/portal.blade.php` | market | nav markup | line-level | Cart badge |
| `app/Http/Controllers/PurchaseController.php` | market | rewritten | header | `processPayment()` deleted; old routes redirect into the cart |
| `app/Http/Middleware/VerifyCheckpointToken.php` | market | verification logic moved out | line-level | Now delegates to `CheckpointTokenVerifier` |
| `app/Models/Order.php` | market | `$fillable`/`$casts` | line-level | `license_agreed_{at,ip}` — **a real bug fixed live, see Decisions** |
| `resources/js/portal.d.ts` (market's copy) | market | +1 method | line-level | `loadPreparedInvoice()` declaration |
| `resources/js/external/direct_payments/api.ts` | brain | `Invoice` + `ExternalDirectPaymentsAPI` | line-level | `Invoice.fromPrepared()` static factory + `loadPreparedInvoice()` |
| `resources/js/external/portal.d.ts` | brain | +1 method | line-level | Mirrors market's copy — `diff` confirmed identical except the cross-reference comment |
| `app/Http/Controllers/Externals/Processor/ProcessorIntentController.php` | brain | `createIntent()` + new method | line-level | Invoice-amount cross-check; new `getPaymentIntentStatus()` |
| `app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php` | brain | +1 method | line-level | New `getInvoice()` (single-invoice-by-ref lookup) |
| `routes/portal.php` | brain | +2 routes | line-level | The two new endpoints above |

## Files deleted

| File | Reason |
|---|---|
| `resources/views/confirmation.blade.php` | Dead — referenced `$order->order_number`, a column step 03 already removed. Superseded by `/portal/orders/{ref}` (step 12). |
| `resources/views/checkout.blade.php` (old) | Superseded by `resources/views/checkout/index.blade.php`. |

## Database changes

Three migrations (`000039`–`000041`, listed above). Verified with `php artisan migrate:fresh --force`
against a scratch MySQL database (`market_scratch_test`, created and dropped for this verification
only — the real dev database was never touched) — all 41 migrations (steps 03–13's combined set)
apply cleanly together.

## Environment / config keys added

None new. `InvoiceBuilder`/`BrainClient` read the existing `market.direct_payments.{ref,http_url}`
config (step 02/05).

## Decisions made during execution that diverge from the plan

1. **This step necessarily touches brain, not only market**, despite the plan's own header framing
   this as a market step. §13.2's "no `addItem()` loop in the browser" requirement means an invoice
   must be built server-to-server — but investigation (a dedicated research pass into brain's actual
   HTTP API, not assumption) found that **no server-to-server invoice-prepare mechanism existed
   anywhere in this codebase**, on either side. Step 08's own `charge()` method literally throws
   `"...use the server-to-server invoice flow (step 13), not yet implemented in this SDK"` — this
   step is where that promise had to be kept. What was found and used instead:
   - Brain's real invoice-persistence endpoint is `POST /portal/api/v1/directpayments/invoice`
     (`DirectPaymentsController::saveInvoice`) — the same one the browser SDK's own `saveInvoice()`
     calls. Market's `InvoiceBuilder` calls it directly, with its own market-generated
     `reference_num`, before the browser ever sees the invoice.
   - The SDK's `Invoice` class had no way to represent an already-decided, server-built invoice —
     `addItem()` only accepts items from the DP's static configured catalog, which a `CONNECT` DP has
     none of by design. Added `Invoice.fromPrepared()` (a static factory that populates `_items`
     directly, bypassing catalog validation — safe because every number in it was already computed by
     market's trusted backend, not decided by the browser) and `loadPreparedInvoice()` on
     `ExternalDirectPaymentsAPI`, both new, both mirrored into both `portal.d.ts` copies.
   - **A real, load-bearing security gap was found and fixed while building this**: `createIntent()`
     trusted its `amount` parameter entirely from the request body — the five split invariants (step
     08) only ever checked that a split's *pieces* summed correctly within whatever `amount` was
     supplied, never that `amount` itself matched the persisted invoice. §13.2's whole premise
     ("prices out of the client entirely, removing the tampering surface") was not actually true until
     this was closed. Fixed with a cross-check against `DirectPaymentInvoice.amount_cents` (an
     already-maintained canonical field, not re-derived) whenever `metadata.invoice_reference_num` is
     present — a request naming a real invoice with a mismatched amount now gets `422`, before any
     Stripe call.
   - A second, matching gap: nothing let market ask "was this invoice actually paid" without trusting
     `DirectPaymentInvoice.status`, which `updateInvoiceStatus()` lets **any unauthenticated caller**
     set directly — a `POST {reference_num, status: 'paid'}` from anyone, no card ever charged, would
     have looked identical to a real payment. Added `GET .../stripe/payment-intent/{id}/status`
     (`ProcessorIntentController::getPaymentIntentStatus()`), which reads Stripe live with the
     processor's secret key — the one thing in this flow a client cannot fabricate — and is what
     `CheckoutController::confirm()`/`status()` actually gate fulfilment on.
   - A third small addition, `GET .../directpayments/invoice/{reference_num}` — `getInvoices()`
     requires a `user_id`, which a guest checkout does not have; this is the single-invoice lookup
     `confirm()`/`status()` need instead.
   - **None of brain's existing Direct Payments/processor routes carry any request authentication** —
     confirmed by reading every controller in this call chain directly, not assumed. This step did not
     add a signed channel for its own two new calls (`saveInvoice`, `create-intent` is unchanged and
     still called only by the browser) — doing so unilaterally from market's side would accomplish
     nothing, since brain checks nothing. Flagged here, matching step 08's own precedent of reporting
     (not silently fixing) a pre-existing, wider authentication gap outside this step's scope.
2. **A guest cannot complete checkout — only build a cart.** Found live, not assumed:
   `entitlements.buyer_reference_num` is `NOT NULL` (step 03's schema), and step 01 D2 is explicit that
   `member_reference_num` is the *only* identity a marketplace row can carry — there is no anonymous-
   purchase model this schema supports. `CheckoutController::prepare()` now rejects with
   `401 SIGN_IN_REQUIRED` before building any invoice or moving any money when no member is identified;
   the cart itself stays fully guest-usable, exactly as §13.3 asks.
3. **`CheckpointTokenVerifier` extracted out of `VerifyCheckpointToken`** (step 11), not named in
   §13.7's file list. `IdentifyCheckpointMember` (this step's own middleware — optional identity,
   never rejects, needed because a cart must work for a guest) needs the identical cached,
   fail-closed-on-network-error verification `VerifyCheckpointToken` already has; extracting it was
   cheaper and safer than a second copy of the caching/HTTP logic. `VerifyCheckpointToken`'s own
   behavior is unchanged.
4. **`market:reconcile-stuck-checkouts` (not literally named in §13.7, which says only "the
   fulfilment-reconciliation console command")** — implemented and, unusually for this plan's prior
   steps, **actually exercised live**: a cart was locked, its invoice marked paid on a stub brain
   server, left unconfirmed, backdated past the staleness window, and the command correctly found and
   fulfilled it. See "How this was verified."
5. **Tax computation is a deliberate simplification**: `tax_mode === 'exclusive'` adds `tax_rate` (a
   fraction) on top of price; `inclusive`/`none` add nothing extra. No jurisdiction/nexus logic — out
   of this step's scope, and not otherwise specified anywhere in the plan.
6. **`seller_ledger_entries` is deliberately not written by `OrderFulfilmentService`.** That model's
   own header comment (step 03/16) states `LedgerService` (step 16) is the only writer it should ever
   have. `order_items.platform_fee_amount`/`seller_net_amount` are computed and stored here — a
   seller's own sales views already read them — but the append-only ledger itself is step 16's to
   build on top of these numbers, not to have them invented here.
7. **A service with no packages** (`Service.price` as fallback, per that model's own docblock) is a
   real, supported cart line — `CartService::addService()`, not named in §13.7, added alongside
   `addServicePackage()` since the storefront's current "Order Now" button (unchanged — step 14's
   territory) has no package picker yet and needs *something* correct to do when a service has no
   packages at all.

## Known gaps / deferred

- **No live Stripe test-mode account was available this session** (the standing gap every step since
  02 has recorded). The paid path was verified end-to-end against a **hand-written stub** implementing
  brain's exact two-endpoint contract (`saveInvoice`, `getPaymentIntentStatus`) — this proves market's
  side of the contract precisely (request shapes, field names, the full transaction/idempotency
  behavior) but is not a substitute for a real Stripe charge, a real webhook, or a real transfer.
  Specifically unverified: an actual card being charged, 3D Secure / redirect-based confirmation, a
  declined card's exact error surface reaching the UI, and a `separate_transfers` payout actually
  landing (steps 08/09's own reports record the same gap for the split machinery itself).
- **The browser side (`checkout.ts`, `Invoice.fromPrepared()`/`loadPreparedInvoice()`) was never
  loaded in a real browser against a compiled `sdk.js`.** `apps/www/public/external/api/sdk.js` needs
  rebuilding from `resources/js/external/**` before any of this step's SDK changes are live — the
  established **build handoff** pattern from steps 08/09. Verified instead: `tsc --noEmit` clean on
  every touched `.ts` file, `npm run build` clean in market (emits `checkout-*.js` as its own chunk),
  and the exact request/response shapes the browser code expects were proven correct against the
  real controller code and the stub server.
- **A narrow, real idempotency gap in the free path (§13.5), found and left open, not silently
  fixed**: two genuinely *concurrent* `POST /checkout/prepare` calls on the same free cart could both
  pass validation before either clears the cart, creating two orders — `dp_invoice_reference_num` is
  `null` for both (a free order has no invoice), so the unique-constraint backstop that protects the
  paid path does not apply here. The *sequential* case (a retry after the first call already
  succeeded) is correctly rejected — verified live — by the empty-cart guard this step added; only a
  true race window remains. Judged acceptable for a free-item edge case rather than adding a
  distributed lock for it, but recorded honestly rather than left undiscovered.
- **Two-seller-cart *checkout blocking* per §08.9's deferral was not needed** — step 08 shipped full
  multi-seller support (`separate_transfers`, not the "reject multi-seller carts" fallback §08.9
  offered as an option). This step's own cart/invoice pipeline threads `seller_ref` per line straight
  through to `getSplitItems()` with zero special-casing, and brain's `SplitCalculator::buildSplit()`
  picks `separate_transfers` automatically from the distinct seller count — verified live up through
  invoice preparation and full fulfilment (two entitlements, two distinct sellers, one order); the
  actual Stripe-side transfer split itself is unverified (no live Stripe, per above).
- **`resources/views/services/show.blade.php`/`assets/show.blade.php` were not redesigned** — step
  14's territory. The existing single-price "Order Now" button on the services page now adds the
  cheapest active package (or the fallback price) rather than a full tier picker; correct today, but a
  service with genuinely different-priced tiers deserves better UI once step 14 builds it.
- **No admin/seller-facing surface for a `cancelled`-status invoice or an abandoned cart** — the
  `carts` table accumulates a row per guest session and per buyer, most of which are legitimately
  ephemeral; no cleanup command exists for old empty/abandoned carts. Not asked for by the plan, noted
  as a reasonable future addition.

## How this was verified

Everything below is a real, live exercise — a scratch MySQL database (`market_scratch_test`, via a
locally-started `brew services start mysql`, dropped and the service stopped afterward — the real dev
database was never touched), a running `php artisan serve`, and (for the paid path) a hand-written PHP
stub server implementing brain's exact two-endpoint contract — not a reading of the source.

- **`php -l`** on every PHP file created or modified in both projects — clean.
- **`grep -n "card_number" apps/market/app`** → zero.
- **`php artisan migrate:fresh --force`** — all 41 migrations (steps 03–13 combined) apply cleanly.
- **Cart mutations, live**: add an asset (200, correct row), add the same asset again (already-owned
  rejected with `ALREADY_OWNED` once purchased), add a service package, attempt to set an asset's
  quantity to 3 (`422`, "Assets cannot have a quantity other than 1"), remove an item, clear a cart —
  all confirmed against the actual JSON response and, where relevant, a direct database read.
- **The free path, end to end**: `/checkout/prepare` on a $0 cart with no bearer token → `401
  SIGN_IN_REQUIRED`, nothing created (a real bug this exposed and fixed — see Decisions #2); the same
  call with a valid member identity → `200 {free:true, order_reference}`, and the database confirms
  exactly one `Order` (status `paid`, `license_agreed_at`/`license_agreed_ip` both populated — a second
  real bug found and fixed live, see Decisions/`Order.php`'s own note) with exactly one `OrderItem` and
  one `Entitlement`. A second `/checkout/prepare` call on the now-empty cart correctly rejects with
  "Your cart is empty" (a third real bug found live: before this fix, an empty cart's trivially-zero
  total fell into the free path and minted an empty order on every repeated call).
- **The paid path, end to end, against the stub brain server**: `/checkout/prepare` on a $24.99 asset
  with 8% exclusive tax → invoice built with `subtotal 24.99, tax 2.00, total 26.99` (exact); the stub
  confirms market's server-to-server `saveInvoice()` call landed with the precise field shape brain's
  real `DirectPaymentsController::saveInvoice()` validates against, including
  `processor_reference_num` correctly resolved from the DP's own config. After the stub simulates a
  succeeded PaymentIntent, `/checkout/confirm` correctly creates the `Order`
  (`subtotal 24.99, tax 2.00, total 26.99, platform_fee_total 2.50, seller_net_total 22.49` — exact
  10% fee arithmetic) plus one `OrderItem` and one `Entitlement`, and the cart is confirmed cleared and
  unlocked in the database.
- **Idempotency, by count, not by absence of error**: `/checkout/confirm` called twice with the same
  invoice reference → both calls return the identical `order_reference`, and a direct count confirms
  exactly one `Order` and one `Entitlement` row exist. A fabricated invoice reference →
  `{success:false, message:"Unknown invoice."}`, and the order count is unchanged.
- **Cart-mutation-destroys-the-session, live**: locked a cart via `/checkout/prepare`, confirmed
  `carts.locked_at`/`dp_invoice_reference_num` were set, then deleted a cart item — confirmed both
  fields cleared in the database *and* the stub's own invoice status flipped to `cancelled` via
  `InvoiceBuilder::destroySession()`'s call to `updateInvoiceStatus()`.
- **Price-change acknowledgement, live**: added an item, changed its live price directly in the
  database (simulating a seller edit), called `/checkout/prepare` → `422 CART_CHANGED` with the exact
  previous/current prices; retried with `acknowledge_price_changes: true` → succeeded, invoice built at
  the new price.
- **A genuine two-seller cart, live, full pipeline**: two assets from two different sellers added to
  one cart (`seller_count: 2`), prepared (both `seller_ref`s correctly attached per line in the invoice
  sent to brain), paid, and confirmed — the resulting order has exactly two `Entitlement` rows, each
  correctly attributed to its own seller.
- **The reconciliation command, live, not just written**: locked a cart, told the stub the payment
  succeeded, deliberately never called `/checkout/confirm`, backdated `carts.locked_at` past the
  staleness window, ran `market:reconcile-stuck-checkouts --dry-run` (correctly identified the stuck
  cart, changed nothing), then ran it for real — it correctly re-verified payment against the stub and
  fulfilled the order (confirmed: a real `ServiceOrder` in `awaiting_requirements`, for the service-
  package test case used here).
- **The seller callback (`MemberEventDispatcher::sale()`)**: confirmed queued for every order line
  created across every scenario above (5 jobs for 5 order-item creations, counts matched exactly), and
  one payload was decoded directly to confirm its exact shape
  (`seller_reference_num`, `title`, `gross`, `net`, `order_reference`, `event_id`) matches what
  members' `MarketEventController::sale()` (step 04) actually reads.
- **`tsc --noEmit --skipLibCheck`**, scoped to this step's own files in both projects — zero errors in
  `resources/js/portal/{cart,checkout}.ts`, `resources/js/app.ts`, `resources/js/portal.ts` (market)
  and `resources/js/external/direct_payments/api.ts` (brain).
- **`npm run build`** (market) — clean, emits `cart-*.js` and `checkout-*.js` as their own chunks
  alongside the unchanged `portal-*.js`.
- **Tag reconciliation**: `grep -q MARKET_MODIFIER_ADDITION` run against every file in the "Files
  created"/"Files modified" tables above, in both projects — all present, none missing.

## Follow-ups spawned

- **The wider, pre-existing lack of any request authentication on brain's Direct Payments/processor
  routes** (not created by this step, but exercised heavily by it) should get its own step —
  `saveInvoice`, `updateInvoiceStatus`, `getInvoices`, and `create-intent` (base amount aside — now
  cross-checked when an invoice is named) are all still callable by anyone.
- **`future_requirements`/live-dashboard-configuration/full live-Stripe verification** — unchanged,
  standing gaps from steps 08/09/10, still needing a real test-mode Stripe account.
- **The free-path concurrent-double-submit race** (Known gaps) — a small, well-scoped fix (a
  short-lived lock or an `INSERT ... ON DUPLICATE`-style guard on the cart during fulfilment) whenever
  it's worth the complexity for a $0-item edge case.
- **Step 14** should give the storefront a real package/tier picker for services — this step's
  "cheapest active package" fallback on the existing single-button UI is correct but minimal.
- **Step 16** owns `LedgerService`, the actual writer of `seller_ledger_entries` — this step computed
  and stored the fee/net numbers on `order_items` that step 16 will read, per that model's own
  established convention.
- **Step 17's seed data** should include a mix of free/paid/multi-seller carts so the dashboard/assets
  grid (step 12) has richer real fixtures than this step's own torn-down scratch data.

---

## Exit criteria (§13.8)

- [x] `grep -n "card_number" apps/market/app` → zero.
- [x] Buy a $0 asset: order + entitlement created, no processor call, visible via the same
      `Order`/`Entitlement` rows `/portal` (step 12) reads — verified live, including the
      sign-in-required guard and the empty-cart-resubmit guard this exposed.
- [x] Buy a paid asset "in Stripe test mode": order, entitlement, and the seller callback all land —
      verified against a stub implementing brain's real contract, **not live Stripe** (none available
      this session, consistent with every prior step). Ledger entries are deliberately not written by
      this step (see Decisions #6); the transfer itself needs live Stripe.
- [x] Submit `/checkout/confirm` twice with the same invoice ref: one order, one entitlement — verified
      by count.
- [x] Fabricated invoice ref → rejected; nothing created — verified.
- [x] Change an asset's price between add-to-cart and checkout: blocked with the acknowledgement
      prompt — verified live, including the successful retry after acknowledgement.
- [ ] Block `sdk.js` in devtools: the blocking message appears, no console exception — code-reviewed
      (`sdkAvailable()`'s guard in `checkout.ts`), not observed in a real browser; no live browser
      session against a real `sdk.js` was available this session (same class of gap steps 08/09
      recorded for their own playground pages).
- [x] Modify the cart with a locked invoice: session destroyed, invoice unlocked, no stale charge
      possible — verified live, including the brain-side invoice status flip.
- [x] Two-seller cart behaves per the §08.9 decision, and the completion report says which — **step 08
      shipped full multi-seller support (`separate_transfers`)**; this step's pipeline requires no
      special-casing for it, verified live through fulfilment (transfer execution itself needs live
      Stripe).
- [x] License checkbox is required, and the agreement is recorded on the order — verified live
      (`license_agreed_at`/`license_agreed_ip`), including the `$fillable` bug this exposed and fixed.
- [x] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4) — verified
      by `grep -q` against all files in this report's own tables, in both projects.
- [x] This file written.
