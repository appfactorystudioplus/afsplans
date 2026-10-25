# Ecommerce ↔ Direct Payments — Coupling Audit & Decoupling Plan

**Date:** 2026-09-06 · remediation rewritten around the **façade order** model · **executed 2026-09-06**.
**Scope:** `w/www` (the Brain). Every point where the ecommerce module reads, writes, or
reasons about Direct Payments, and the work to reduce that to **three reference strings per
purchase**.

---

## Execution status (2026-09-06)

Landed as working-tree changes (uncommitted). Ecommerce test suite green
(58 targeted tests, full Unit suite green bar one pre-existing WIP failure
`FormsExternalSdkStaticTest`). Client build: see below.

| Phase | Status |
|---|---|
| **P3.4** gateway | ✅ `app/Externals/DirectPayments/{Contracts/PaymentGateway,DirectPaymentGateway,InvoiceSnapshot}.php` + `DirectPaymentsServiceProvider`, registered. `isPaid()` 3-step (row → live PaymentIntent → false), `snapshot()`, `snapshots()`, `paidInvoicesAwaiting()`. Verified against live DB. |
| **P1.4** `EcommerceCheckoutService` | ✅ `begin()` returns `{cart, direct_payment, shipping_item_ref, discount_item_ref, changes}` — no invoice. `confirm()` → `complete(cart, dpRef, procRef, invoiceRef, lines)` gated on `isPaid()`. No DP model import. |
| **P1.5** `EcommerceCartController` | ✅ `completeCheckout` (payload: 3 refs + lines); `/checkout/complete` route added, `/checkout/confirm` kept as alias. |
| **P1.6** `EcommerceTaxService` | ✅ `calculateViaProcessor()` + `DirectPayment`/`Processor` imports removed; zone-based estimate only. |
| **P2.1** `EcommerceDirectPaymentService` | ✅ gutted to admin-config validation + `assertCartLineCompatibility`. `EcommerceProduct::scopeStorefrontListed()` / `hasDirectPaymentItem()` carry visibility. |
| **P1.2** `EcommerceStorefrontController` | ✅ no DP service; returns `dp_item_reference_num`; `price_cents` is a hint; `getPrices()` + route removed. |
| **P2.2** pricing | ✅ `/prices` route, `publishPrices` endpoint/route, `EcommercePricePublication` reads, `commerce.prices()`/price-watch all removed. `EcommercePricePublication` model + table left for a later drop migration. |
| **P2.3** DP-item creation | ✅ `createProductDpItem` endpoint/route + `createDpItemForVariant` removed. `EcommercePromotionService::ensureDiscountItem` kept (admin-path exception — see below). |
| **P3.1** migration | ✅ **clean break** (no transitional cruft): `+direct_payment_reference_num` on orders + `+shipping_item_ref` on stores + backfill; **DROP** every order-level money/payment-status column, all order_line `_cents`, `stores.dp_item_mode`, `stores.processor_tax_enabled`, and the `laravel_ecommerce_price_publications` table. |
| **P3.2** `EcommerceOrderService` | ✅ `createFromCart(cart, InvoiceSnapshot)`; `serializeOrder()` resolves money live from the gateway; transitional `_cents` mirrors written from the snapshot. |
| **P3.3** purchase view | ✅ `commerce.purchase(invoiceRef)` + `PortalDirectPaymentsFactory.getInvoice()` (typed) + `directPaymentsApi.getInvoice` runtime + `attachPricing()` helper. |
| **P3.4** fulfilment gate | ✅ `EcommerceFulfilmentService::createFulfilment()` re-checks `isPaid()` → `EC_E_ORDER_UNPAID`. |
| **P3.5** reconcile | ✅ `EcommerceReconcileOrders` rewritten — scans `paidInvoicesAwaiting('ecommerce')`, recovers via `createFromCart`. |
| **P0.1–0.3** client SDK | ✅ `mountPayment` deleted; `completeCheckout`/`purchase`/`attachPricing` added; `commerce_component_script.ts` both checkout flows rewritten to `Portal.directPayments` directly — typed, no optional chaining, explicit SDK assert. |
| **P4** plan docs | ⏳ 06/07/08 need the superseded banners; this doc is the source of truth. |

**Smoke test fixes (2026-09-06, browser + tinker against `demo-store-test`)**
- `completeCheckout` could not find the cart once `begin()` set it to `checking_out`
  (`findActiveByToken` matches `active` only) → payload now carries `cart_ref` (from
  `begin()`'s `cart.reference_num`); `complete()` also falls back to
  `snapshot()->facadeRef` (invoice metadata) for the deprecated `/checkout/confirm` alias.
- `EcommerceOrder::$fillable` was missing `direct_payment_reference_num` → the ref was
  silently dropped on insert. Added.
- The DP SDK's `getDirectPayment()` fetched `?direct_payment=X` **without** `include_items=true`,
  so `_dpConfig.items` was `[]` and `Invoice.addItem()` threw *"Item reference … not found in
  Direct Payment configuration"*. Fixed in `direct_payments/api.ts` (pre-existing WIP bug the
  new `createInvoice`+`addItem` flow surfaced; the old `loadPreparedInvoice` path didn't
  validate item refs).
- Client stamps `metadata: { facade: 'ecommerce', cart_reference_num }` into
  `setupSinglePayPayment` — reaches the Stripe PaymentIntent today; reaches
  `DirectPaymentInvoice.metadata` only once the SDK's `saveInvoice` propagates `metadata`
  (see follow-ups).

**Verified end to end (tinker, real paid invoice fixture):** `complete()` writes the order
with all three refs, resolves amount/charge/status/line-prices from the invoice, is
idempotent. Storefront pages (`new-page-products`, `new-page-cart`) render on the new API.

**Invoice ↔ cart correlation — wired (2026-09-06, option b).**
- New `POST /checkout/attach { cart_ref, invoice_ref }` → `EcommerceCartController::attachCheckout`.
  The client calls it after `invoice.lock()`, before `setupSinglePayPayment()`, and the
  server writes `cart.direct_payment_invoice_ref`. Correlation lives entirely in ecommerce's
  own table — no DP invoice metadata, no SDK `saveInvoice` change.
- `complete()` resolves the cart by `cart_ref` → cookie → `direct_payment_invoice_ref` →
  `snapshot()->facadeRef`, in that order.
- `EcommerceReconcileOrders` scans `checking_out` carts (idle ≥15 min) that name an invoice,
  `gateway->isPaid()`, and writes the order from the cart. **Verified end to end via
  tinker** — a paid invoice on an attached cart with no order is recovered, cart → converted.
- `commerce.attachCheckout(cartRef, invoiceRef)` added to the SDK; the component script
  guards the call (`typeof shop.attachCheckout === 'function'`) so an old sandbox SDK
  degrades to the `cart_ref` happy path rather than throwing.

**"Payment not initialized" on the pay click — fixed.** The component script called
`Portal.directPayments.create()` again on the second click, getting a fresh instance whose
`_processorClient` was null. Now one `dp` instance is created on the first click and reused
for `confirmPayment()`.

**Clean break (2026-09-06) — no old-way code left in the repo**
- Migration drops the order/order_line money columns, `dp_item_mode`,
  `processor_tax_enabled`, and the price-publications table (not deprecate-then-remove — gone
  now). `EcommercePricePublication` model deleted.
- `EcommerceOrderService::createFromCart` writes no money; `serializeOrder` resolves it all
  from the invoice. Order `status` is `open`/`cancelled` only.
- `/checkout/confirm` route + `confirmCheckout()` (controller + SDK) removed — no deprecated
  alias.
- `EcommerceDirectPaymentService`: `isExplicitMode`, `resolveItemReference` gone.
- Admin `EcommerceController`: store settings drop `dp_item_mode`/`processor_tax_enabled`;
  `getStoreOverview` metrics use `cancelled_at IS NULL` and resolve revenue through
  `PaymentGateway::snapshots()`.

**Remaining / follow-ups**
- `EcommercePromotionService::ensureDiscountItem` still creates a `DirectPaymentItem` from
  the admin promotion screen (admin-config-time, not checkout/storefront path). Left as a
  deliberate exception; revisit if DP grows a first-class discount concept.
- `saveProduct()` still reads `DirectPaymentItem.price` to keep the `price_cents` hint fresh
  at save time (admin-path, read-only). Acceptable.
- 3DS/redirect-return: `confirmPayment()` can resolve `{url}` and redirect; there is still
  no return handler that resumes `completeCheckout` — a redirect card pays with no order
  until the reconcile sweep. Tracked, not yet built.

---

## Target architecture — the façade

Direct Payments is the payment engine. **Ecommerce is a façade over it** — and so are
events, appointments, and anything else that sells (donations, memberships, bookings). Each
façade owns its own domain and attaches domain metadata to a DP item; none of them track
money.

### The rule

> A façade stores the **references** that identify a purchase and **nothing about its
> money**. Payment amount, status, charge id, refunds — all of it is resolved from Direct
> Payments on demand, primarily in a client view.

### Terminology

**"Invoice" always means the Direct Payments invoice.** Ecommerce has no invoice concept and
never will — it has a cart and, after payment, an order. Anywhere this doc says "the invoice"
it means the DP invoice.

### How an order comes to exist

1. **Checkout, client-side, in one turn:** loop every line in the cart → `createInvoice()`
   → `addItem({ item_ref, quantity, amount? })` per line → `lock()`. The invoice is built
   and locked in that single synchronous pass.
2. **Charge:** `setupSinglePayPayment({ invoice, … })` then `confirmPayment({ email,
   invoice })` — the card is actually charged here, by the processor, not asserted.
3. **On success only:** the client constructs the order payload — the product lines, plus
   `direct_payment_reference_num`, `processor_reference_num`, `direct_payment_invoice_ref` —
   and `POST`s it to the ecommerce API. A failed charge builds no payload and writes nothing.
4. **Server:** one `DirectPaymentGateway->isPaid($invoiceRef)` check, then insert the order.
   That check is what *makes* "an order row exists ⟹ its invoice was paid" a guarantee
   rather than a hope. Idempotent on `direct_payment_invoice_ref`.

There is **no `pending` order state.** An order is written complete or not at all.

### What ecommerce stores per purchase (the order row)

| Field | Source | Why the façade keeps it |
|---|---|---|
| `direct_payment_reference_num` | the store's DP at purchase time | which DP took the money |
| `processor_reference_num` | the invoice/intent at purchase time | which processor took it — snapshot, because the store's DP can be re-pointed later and a completed order's charge cannot move |
| `direct_payment_invoice_ref` | the client after `confirmPayment()` | the one purchase. Also the idempotency key (`ec_orders_invoice_uq`) |

Those three resolve **everything else** — line amounts, subtotal, tax, total, paid/failed
status, charge id, refunded amount — by querying the Direct Payments invoice. Ecommerce
never copies any of it into its own tables.

### What ecommerce still owns

- **Catalogue** — products / variants + `dp_item_reference_num` (the attach point for
  "extra data about an item"). No price, no tax, no processor.
- **Cart** — lines, quantities, promo code, shipping selection, `direct_payment_invoice_ref`
  once checkout begins (correlation).
- **Order as a fulfilment record** — the three refs above, a line snapshot (item ref, name,
  quantity — descriptive facts, not money), shipping address, fulfilment state, internal
  notes, customer/site-user link.

### Where payment facts get resolved

| Consumer | How |
|---|---|
| Storefront product pricing | client: `dp.getDirectPayment().items[]` joined onto products by `reference_num` |
| Customer "my orders" / receipt | client: given the order's 3 refs, read the DP invoice → amount, status, line prices, refunds |
| Admin order list / detail | client: same read, richer projection |
| **Server money-gates** — allow a fulfilment, initiate a refund | server: one read-only call to a DP gateway ("is invoice X paid?") at the moment it matters. Not stored, not subscribed |

So "does ecommerce know if an order is paid?" — yes, but only by asking Direct Payments,
and only where a real decision hangs on it. Everywhere else it's a client-side view concern.

### Why it generalises

Every façade that sells needs a little server-side validation of its own domain (a ticket
has capacity; a product has stock; a donation has a campaign). That validation is why
`dp_item_reference_num` exists — a place to hang façade-specific data on a priceable line.
If each façade *also* tracked payment state, that's the same payment-sync code written three
times, kept in step by hand, drifting the first time one copy misses a webhook. The façade
holds references; Direct Payments holds the truth.

### The external contract does not change

From outside, an ecommerce store still has orders with a paid/unpaid status, a total, a
refund history. Nothing a storefront visitor or an admin sees is removed. Only the
**internal** representation changes: those facts are read through Direct Payments instead of
stored alongside a second copy.

---

## Part 1 — Audit

### 1.1 Data-model coupling

| Table | Column | Disposition | Notes |
|---|---|---|---|
| `laravel_ecommerce_stores` | `direct_payment` | **keep** | which DP the store charges through |
| `laravel_ecommerce_stores` | `subscription_direct_payment` | keep | same, for subscription products |
| `laravel_ecommerce_stores` | `dp_item_mode` (`explicit`/`dynamic`) | **remove** | only meaningful while the server resolves prices |
| `laravel_ecommerce_stores` | `discount_item_ref` | **remove** | cache of a server-auto-created `DirectPaymentItem` |
| `laravel_ecommerce_stores` | `processor_tax_enabled` | **remove** | drives `EcommerceTaxService::calculateViaProcessor()` reaching into `Processor` |
| `laravel_ecommerce_products` / `_variants` | `direct_payment_item_reference_num` | **keep** (opt. rename `dp_item_reference_num`) | the attach point |
| `laravel_ecommerce_products` / `_variants` | `price_cents` | **remove** (or keep clearly as `price_cents_hint`, first-paint only) | client reads price from the DP item |
| `laravel_ecommerce_orders` | `direct_payment_invoice_ref` | **keep** | ref 3 of 3 + idempotency index |
| `laravel_ecommerce_orders` | `processor_reference_num` | **keep** | ref 2 of 3 — snapshot at purchase |
| `laravel_ecommerce_orders` | `direct_payment_reference_num` | **add** | ref 1 of 3 — currently only on the invoice; store it on the order so the view needs no lookup chain |
| `laravel_ecommerce_orders` | `direct_payment_charge_id` | **remove** | resolve from the invoice |
| `laravel_ecommerce_orders` | `total_cents`, any tax/subtotal/discount `_cents` | **remove** | resolve from the invoice |
| `laravel_ecommerce_orders` | `payment_status` / `paid_at` (if present) | **remove** | resolve from the invoice |
| `laravel_ecommerce_carts` | `direct_payment_invoice_ref` | **keep** | checkout correlation |
| `laravel_ecommerce_order_items` | `direct_payment_item_reference_num` | keep / rename | descriptive snapshot |
| `laravel_ecommerce_order_items` | per-line `_cents` amounts | **remove** | the locked invoice `items_data` is the authoritative, immutable per-line record |
| *(own table)* | `EcommercePricePublication` + `revision` | **remove** | the server-side price-publish mechanism |

### 1.2 Server-side code that touches Direct Payments

Ranked by depth of coupling.

#### A. `EcommerceCheckoutService::begin()` — writes a `DirectPaymentInvoice` row directly
`app/Externals/Ecommerce/Services/EcommerceCheckoutService.php:166`

- `DirectPaymentInvoice::query()->create([...])` — ecommerce owns a row in a DP-owned table,
  sets DP's status vocabulary (`'locked'`), hand-builds the `items_data` JSON the SDK's
  `Invoice` class exists to build.
- Bypasses `DirectPaymentsController::saveInvoice` and every DP service.
- Reimplements in PHP: line resolution, shipping as a pseudo-item (`item_ref => 'shipping'`),
  discount as a negative pseudo-item, per-line tax.
- ~130 lines. The single biggest violation.

#### B. `EcommerceDirectPaymentService` — the whole file (676 lines)
`app/Externals/Ecommerce/Services/EcommerceDirectPaymentService.php`

| Method(s) | What it does | Disposition |
|---|---|---|
| `validateDirectPayment()` + two public wrappers | reads `DirectPayment`, `Processor`, currency at admin-config time | keep the concept — move to an admin settings service or a DP validation call |
| `resolveCheckoutLine()`, `resolveItemReference()` | reads `DirectPaymentItem` for the charge price | **delete** — client resolves |
| `createDpItemForVariant()` | **creates `DirectPaymentItem` rows** from a catalogue screen | **delete** |
| `resolveVariantPriceCents()`, `resolveProductPriceCents()`, `livePriceRows()`, `publishPrices()`, `publishedPriceCatalog()`, `isStorefrontVisible()` | server-side pricing from `DirectPaymentItem.price` + publish/revision cache | **delete** |
| `storefrontListedQuery()` | SQL `EXISTS` against `laravel_direct_payment_items` | **delete** — visibility = "`dp_item_reference_num` non-null"; client hides unresolved |
| `diagnoseVariantMapping()`, `priceMismatches()` | price-reconciliation warning (already half-disabled by a code comment) | **delete** |
| `assertCartLineCompatibility()`, `cartLineIsSubscription()` | mixed subscription/one-off guard — pure catalogue logic | **keep**, move to `EcommerceCartService` |

#### C. `EcommerceOrderService::createFromCart(EcommerceCart $cart, DirectPaymentInvoice $invoice)`
`app/Externals/Ecommerce/Services/EcommerceOrderService.php:28`

- Takes a `DirectPaymentInvoice` model; reads `charge_id`, `processor_reference_num`,
  `amount_cents`, `metadata`, `customer_email` off it.
- Target: takes the cart + the three refs. Stores the refs, snapshots line descriptions, no
  money.

#### D. `EcommercePromotionService::ensureDiscountItem()`
`app/Externals/Ecommerce/Services/EcommercePromotionService.php:144` — creates/caches a "Discount"
`DirectPaymentItem`. Gone once the client builds the invoice.

#### E. `EcommerceTaxService::calculateViaProcessor()`
`app/Externals/Ecommerce/Services/EcommerceTaxService.php:130` — `DirectPayment` → `Processor` lookup
for processor-side tax. Delete; `processor_tax_enabled` with it.

#### F. Admin `EcommerceController`
`app/Http/Controllers/Externals/Ecommerce/EcommerceController.php`

- `saveProduct()` — reads `DirectPaymentItem` to copy its price into `price_cents` (167-168, 204).
- `createProductDpItem()` — endpoint whose only job is `createDpItemForVariant()`.
- `publishPrices()` — the price-publish cache endpoint.
- `getPaymentDiagnostics()` — surfaces `priceMismatches()`.
- `createStore()` / `updateStore()` — call `validatePrimaryDirectPayment()`.
- Order list / detail responses — build money fields from stored columns.

#### G. `EcommerceReconcileOrders` cron
`app/Console/Commands/EcommerceReconcileOrders.php` — scans `DirectPaymentInvoice` for paid
rows and calls `checkoutService->confirm()`. Re-purposed under the façade model (§P3.5) as a
rare recovery: catch a paid invoice whose cart metadata names an ecommerce cart with no
order — i.e. the charge succeeded but the client crashed between `confirmPayment()` and the
order `POST` — and write the order from the invoice's own `items_data` + the cart. Goes
through the DP gateway, never the model.

#### H. Client — `CommerceInstance.mountPayment()` + `commerce_component_script.ts`
- `resources/js/external/api/commerce/api.ts:241` — commerce SDK method wrapping
  `(window as any).Portal.directPayments`, `any`-typed, also owns `confirmCheckout`.
- `resources/js/portal/builder/core/component_scripts/commerce_component_script.ts` —
  `shop: any`, two overlapping checkout paths, the `confirmPayment`-skipping bug behind the
  checkout 422.

### 1.3 The flow: as built vs plan 06 §6.4

| Step | Plan 06 §6.4 | As built | Gap |
|---|---|---|---|
| begin | "create the DP invoice, addItem() per line, lock()" (server) | `begin()` hand-writes `DirectPaymentInvoice::create()` + `items_data` | reimplements the SDK `Invoice` in PHP against the DP schema |
| pay | `dp.setupSinglePayPayment({ invoice, mountTarget, onSuccess, onError })` | `shop.mountPayment()` wrapper → `dp` via `(window as any)` | off-spec wrapper; lost types; the 422 bug |
| confirm | server verifies charge, then creates order | `confirm()` — **client-triggered only** | webhook flips the invoice to `succeeded` and does nothing; order relies on the client POST + reconcile cron |

Plan 06 §6.4 also put invoice-building on the server. **This decision overrides that** — and
06/07/08 get rewritten (§P4).

### 1.4 Why moving invoice-building to the client is safe

Plan 06 §6.4 step 3: *"a client that can assert payment succeeded is a client that can order
free goods."* Under the façade model that risk is neutralised twice over:

- **Price integrity** — `Invoice.addItem()` for a FIXED DP item derives the price from DP
  config server-side (`direct_payments/api.ts` `evaluatePrice()`: *"FIXED items: returns
  item.price + item.tax (server-authoritative)"*). DYNAMIC items are `min_amount`/`max_amount`
  bounded. `ProcessorIntentController::createIntent()` rejects `amount` ≠ persisted invoice.
  The client picks `item_ref` + `quantity`, never the price.
- **Order integrity** — the order is only written after the server confirms the invoice is
  paid (`DirectPaymentGateway->isPaid()` on the `POST`). A client that fakes
  `confirmPayment()` and posts a payload for an unpaid or fabricated `invoice_ref` gets a
  422 and no row. "An order row exists ⟹ its invoice was paid" holds because the insert is
  gated, not because the client was trusted.

So as long as every sellable variant maps to a FIXED or bounded-DYNAMIC DP item
(plan 07 §7.4 Mode A/B — already the design), the client builds the invoice and the façade
stays honest.

### 1.5 Client SDK — what exists, what's missing

Already typed in `portal.d.ts`:
- `directPayments.create(dpRef)`, `.initializeDirectPayment()`, `.getDirectPayment()` →
  `.items[]` (price, tax, min/max, price_type)
- `.getItems()`, `.getItem(ref)`, `.isItemDynamic()`, `.isSinglePay()`
- `.createInvoice()`, `Invoice.addItem()/lock()/…`, `.setupSinglePayPayment()`,
  `.confirmPayment()`, `.charge()`
- `.getInvoices(userId)` (factory, returns `any[]`)
- `getStore()` already ships `direct_payment` to the client

**Missing — needed for the purchase view (§P3.3):**
- a typed `directPayments.getInvoice(ref)` (the endpoint
  `GET /portal/api/v1/directpayments/invoice/{ref}` exists; no typed client method) returning
  status, `amount_cents`, `charge_id`, `items_data`, refund state.
- a commerce-level `commerce.purchase(invoiceRef)` that resolves the order's payment view
  from those three refs.

---

## Part 2 — Remediation plan

Vocabulary: **step = one file**, **phase = the work inside it**.
`P0` unblock + keep types · `P1` move the seam to the client · `P2` delete the server
machinery · `P3` the façade order model · `P4` plan docs.

### Coding standards for all TypeScript in this plan

- **No optional chaining.** `Portal.directPayments.create(...)` is obtained once, asserted
  explicitly (`if (!x) throw`), and reused. A missing or stale SDK throws — it never
  silently no-ops.
- **No `any`.** `directPayments.create()` is typed (`PortalDirectPaymentsAPI`) and returns a
  typed handle; invoice, items and results are all typed. `any` only at a genuine
  third-party boundary, never for Direct Payments objects.
- Reference pattern: `requireDirectPayments()` in
  `public/playground/externals/direct-payments/server/checkout-and-post.js`.

### P0 — Stop the bleeding, keep types

**P0.1 — `resources/js/external/api/commerce/api.ts`**
- Delete `CommerceInstance.mountPayment()`.
- Keep `confirmCheckout()` for now (renamed to `completeCheckout()` in P1); retype, no `any`.
- Mark the price-watch poller (`startPriceWatch`, `prices()`, `prices:updated`)
  `@deprecated`; hard-delete in P2.

**P0.2 — `resources/js/portal/builder/core/component_scripts/commerce_component_script.ts`**
- Replace both checkout branches with one flow calling `window.Portal.directPayments`
  directly, typed against `portal.d.ts`.
- `shop` typed as the commerce instance, not `any`.
- Sequence: `beginCheckout()` → `dp.initializeDirectPayment(store.direct_payment)` →
  build invoice (P1.1) → `setupSinglePayPayment` → `confirmPayment` →
  `POST /checkout/complete`.
- Add the 3DS / redirect-return path (today a redirect card pays with no order).

**P0.3 — `resources/js/external/api/portal.d.ts`**
- Verify `PortalDirectPaymentItem` carries everything the storefront join needs.
- Add `getInvoice(ref)` to `PortalDirectPaymentsFactory` (or the instance API).
- Re-copy `portal.d.ts` per the standing contract note — never import the externals source.

### P1 — Move the seam to the client

**P1.1 — Decision: client builds the invoice (`createInvoice` + `addItem` + `lock`).**
- `/checkout/begin` returns `{ cart, direct_payment, changes[] }` — no invoice row.
- **Shipping** — a DYNAMIC DP item on the store's DP (`store.shipping_item_ref`), client
  `addItem({ item_ref, quantity: 1, amount: shippingCents / 100 })`.
- **Discount** — client `addItem` with a negative `amount` (today's PHP already does this);
  if `Invoice` rejects negatives, raise a discount concept with the DP plan.

**P1.2 — `app/Http/Controllers/Externals/Ecommerce/EcommerceStorefrontController.php`**
- `getProduct()` / `getProducts()` — return `dp_item_reference_num` on every product and
  variant. Drop server-resolved price (or relabel `price_cents_hint`).
- Delete `getPrices()` and its route.

**P1.3 — `resources/js/external/api/commerce/api.ts`**
- `products()` returns `dp_item_reference_num`.
- Pure helper `attachPricing(products, dpItems)` — join by `reference_num` →
  `{ price_cents, tax_cents, dynamic: {min,max}|null, purchasable }`.
- Storefront wiring: `const dp = Portal.directPayments.create(store.direct_payment); await
  dp.initializeDirectPayment(); const { items } = await dp.getDirectPayment();` then
  `attachPricing(...)`.

**P1.4 — `app/Externals/Ecommerce/Services/EcommerceCheckoutService.php`**
- `begin()` — keep: payable check, empty-cart check, `repriceLines` → `changes[]`, stock
  reserve, shipping-availability check. Drop everything from line 100 down. Return
  `{ cart, direct_payment, changes }`.
- Replace `confirm(string $invoiceRef)` with
  `complete(EcommerceCart $cart, string $directPaymentRef, string $processorRef, string $invoiceRef, array $lines)`:
  - idempotent on `direct_payment_invoice_ref` (return the existing order if present)
  - `DirectPaymentGateway->isPaid($invoiceRef)` → if false, `EC_E_ORDER_UNPAID`, **write
    nothing**
  - insert the order (complete — no status column) with the three refs, snapshot the line
    descriptions from `$lines`, consume the stock reservation, convert the cart
  - this is the only DP call on the write path, and it is what makes "row exists ⟹ paid" true

**P1.5 — `app/Http/Controllers/Externals/Ecommerce/EcommerceCartController.php`**
- `confirmCheckout` → `completeCheckout`. Body is the client-built payload:
  `{ invoice_ref, direct_payment_ref, processor_ref, lines: [{ item_ref, name, quantity }] }`.
  Resolve the cart from cookie/site-user, call `checkoutService->complete()`.
- Route `POST /checkout/confirm` → `POST /checkout/complete` (keep the old path as an alias
  for one release for in-flight published pages).

**P1.6 — `app/Externals/Ecommerce/Services/EcommerceTaxService.php`**
- Delete `calculateViaProcessor()` and the `DirectPayment` / `Processor` imports. Keep only
  zone-based tax if that remains a feature.

### P2 — Delete the server machinery

**P2.1 — `app/Externals/Ecommerce/Services/EcommerceDirectPaymentService.php` — gut it**
- Delete: `resolveCheckoutLine`, `resolveItemReference`, `createDpItemForVariant`,
  `resolveVariantPriceCents`, `resolveProductPriceCents`, `isStorefrontVisible`,
  `livePriceRows`, `publishPrices`, `publishedPriceCatalog`, `storefrontListedQuery`,
  `priceMismatches`, `diagnoseVariantMapping`, `findDynamicItem`, `itemFor`,
  `validateDynamicAmount`.
- Keep as `EcommerceStorePaymentConfig` (or fold into admin settings):
  `validatePrimaryDirectPayment`, `validateSubscriptionDirectPayment`,
  `warningsForStoreUpdate`.
- Move `cartLineIsSubscription` / `assertCartLineCompatibility` to `EcommerceCartService`.

**P2.2 — Remove server-side pricing**
- `EcommercePricePublication` model + a guarded drop migration.
- `prices_revision` / `prices_published_at` out of `getStore()`.
- Admin `publishPrices()` endpoint + route + UI button.
- `commerce/api.ts` price-watch poller.

**P2.3 — Remove server-created DP items**
- `EcommercePromotionService::ensureDiscountItem()` + `store.discount_item_ref`.
- `EcommerceController::createProductDpItem()` + route; replace the admin affordance with a
  link to the DP item screen.
- `saveProduct()` — stop copying `DirectPaymentItem.price` into `price_cents`.

**P2.4 — `EcommerceCheckoutService` / `EcommerceOrderService` — drop the model import**
- Neither file imports `DirectPaymentInvoice` after this phase.

### P3 — The façade order model

**P3.1 — Migration (`add→migrate→deprecate→remove`, one file)**
- **Add** `direct_payment_reference_num` to `laravel_ecommerce_orders`.
- **Deprecate then remove** `direct_payment_charge_id`, `total_cents`, any
  tax/subtotal/discount `_cents` columns, and any `payment_status` / `paid_at` columns on
  orders and order_items. There is no order payment state — a row's existence is the state.
- **Remove** `discount_item_ref`, `processor_tax_enabled`, `dp_item_mode`,
  `EcommercePricePublication`, product/variant `price_cents` (or keep as `price_cents_hint`).
- Backfill `direct_payment_reference_num` on existing orders from their invoice.

**P3.2 — `app/Externals/Ecommerce/Services/EcommerceOrderService.php`**
- `serializeOrder()` returns the domain record + the three refs. **No money fields.**
- A separate resolver is not the server's job for display — the client view does it (P3.3).

**P3.3 — `resources/js/external/api/commerce/api.ts` — the purchase view**
- `commerce.purchase(invoiceRef): Promise<CommercePurchaseView>` — reads the DP invoice via
  `Portal.directPayments.getInvoice(ref)` and returns
  `{ status, amount_cents, currency, charge_id, lines: [{ item_ref, name, quantity,
  unit_cents, tax_cents }], refunded_cents, paid_at }`.
- Customer "my orders" and admin order screens call `commerce.purchase()` per order (or a
  batched variant) and merge it with `commerce.order()` (the domain record).

**P3.4 — Server money-gates (the only server→DP reads that remain)**
- **Ownership: the Direct Payments module owns the gateway.** A plain PHP service
  `App\Externals\DirectPayments\DirectPaymentGateway` (no HTTP — this is one Laravel app), exposing
  `isPaid(string $ref): bool` and `snapshot(string $ref): ?InvoiceSnapshot` (a small DTO:
  status, amount_cents, currency, charge_id, processor_reference_num, refunded_cents,
  paid_at). Backed by the DP models internally; ecommerce injects the service and depends on
  those two method signatures, never on a table, column name, or status string. DP can
  restructure its schema freely as long as the two methods still answer. This is the
  server-side equivalent of the client's `Portal.directPayments` front door — every other
  façade (events, appointments) uses the same service.
- Callers of the gateway, and the only server→DP reads in the system:
  1. `EcommerceCheckoutService::complete()` — gates the order insert (P1.4).
  2. `EcommerceFulfilmentService` — a second `isPaid()` before a shipment, cheap insurance
     against a refund between purchase and fulfilment (`EC_E_ORDER_UNPAID`).
  3. `EcommerceReconcileOrders` — the recovery sweep (P3.5).
  4. the batched purchase-view projection for admin/customer order screens (P3.3).
- Refund initiation stays in direct_payments/29 at the charge level; the ecommerce order view
  links to it through the invoice ref.

**Reference implementation.** A working `DirectPaymentGateway` + `InvoiceSnapshot` +
`PaymentGateway` interface, plus runnable scratch validators, live at
`public/playground/externals/direct-payments/server/` — verified against the local DB
(`validate-order.php` accepts a real paid `inv_…`, rejects an unpaid one). Move those three
classes to `app/Externals/DirectPayments/` when building this phase.

**`isPaid()` and the webhook-lag gap.** The gateway is not a separate service — it runs
in-process against the same database ecommerce already uses, so there is nothing to "be
down". The only real gap is lag: the processor confirmed the charge but the
`laravel_direct_payment_invoices` row is not updated yet (its webhook has not landed —
common in local dev with no forwarding). The gateway closes that itself:

```
isPaid($invoiceRef):
  1. read the invoice row — status in {paid, succeeded}?           → true   (webhook landed: the common case)
  2. else: retrieve the PaymentIntent named by invoice.event_identifier
     (set during setupSinglePayPayment) from the processor;
     status === 'succeeded' and amount matches?                    → true   (+ update the row opportunistically)
  3. else                                                          → false
```

- Step 2 is the one external call, made only inside the lag window. Processor credentials
  already live in the DP module, so this stays entirely within DP.
- The proof is a real `succeeded` PaymentIntent — unforgeable, and needs no client input:
  the invoice ↔ PaymentIntent link already exists server-side.
- The recovery sweep (P3.5) covers "client paid, then crashed before the `POST`".

**Rejected alternative — a client-signed payload.** A signature the browser applies proves
origin and integrity, never truth of payment; the browser is the untrusted party and will
sign a fabricated payload just as readily. A *DP-signed* paid-receipt token (DP signs
`{invoice_ref, amount, charge_id}`, ecommerce verifies with a public key, no network call)
is sound and is the same pattern as the processor's own signed webhooks — but it does not
remove the lag gap (the token is only minted once DP knows the invoice is paid) and it adds
key distribution and rotation. While DP and ecommerce are one Laravel app, the
`isPaid()`-with-PaymentIntent-fallback above is simpler and strictly as safe. Revisit the
signed receipt only if DP becomes a genuinely separate service.

**P3.5 — `app/Console/Commands/EcommerceReconcileOrders.php` — recovery only**
- Ask the gateway for paid invoices whose metadata names an ecommerce cart
  (`DirectPaymentGateway->paidInvoicesForFacade('ecommerce', since)` or similar) that have no
  matching order.
- For each: build the order from the invoice's `items_data` + the cart, exactly as
  `complete()` would. Release the stale reservation on carts whose invoice never paid past a
  timeout.
- This only fires when the charge succeeded but the client died before the order `POST` —
  rare. It is not a lifecycle stage; there are no `pending` orders for it to chase.

### P4 — Plan docs

- **`07-direct-payments-integration.md`** — full rewrite. The store names a DP; every variant
  carries `dp_item_reference_num`; the client joins items and builds the invoice; the façade
  stores three refs and resolves money from DP. Delete the server-side mapping/pricing/
  reconciliation sections.
- **`06-cart-and-checkout.md` §6.4** — the client builds the invoice and charges it, then
  posts the order payload; `/checkout/complete` replaces `/checkout/confirm`; the server
  gates the insert on `isPaid`; there is no order payment state.
- **`08-orders-and-fulfilment.md`** — the order carries no money; §8.5 refund join and the
  fulfilment gate resolve through the invoice ref; §8.2 idempotency index stays.
- **A short shared note** (`03-architecture.md` §3.3 or a new §3.7) — the façade contract, so
  events / appointments / future sellers follow the same three-ref rule.

### Suggested order

1. **P0.1–P0.3** — types + kill the wrapper + fix the 422 properly. Unblocks builder checkout now.
2. **P3.4** — the `DirectPaymentGateway` read interface. P1/P3 depend on it.
3. **P1.1 decision**, then **P1.2–P1.6**.
4. **P3.1–P3.3, P3.5** — the façade order + the purchase view.
5. **P2** cleanup once nothing calls the old paths.
6. **P4** doc rewrites alongside each phase, not after.

### Open questions

- **Local dev webhooks** — handled by the `isPaid()` PaymentIntent fallback (P3.4), so no
  `stripe listen` requirement. Still worth documenting that step 2 of `isPaid()` is what
  makes local checkout work without forwarding.
- **Subscriptions** (`product_type = subscription`) — same façade rule, but the flow is
  `dp.subscription.create()` (direct_payments/28) and the "purchase" is a subscription, not an
  invoice. Fold in or separate plan?
- **Marketplace splits** (07 §7.6) — already client-side via `Invoice.addItem({ seller_ref })`;
  the vendor → `connect_account_ref` map stays server-side catalogue data. Minimal change.
- **Batched purchase view** — an admin order list of 50 rows should not fire 50
  `getInvoice()` calls. Needs a `getInvoices(refs[])` or a server projection endpoint that
  itself goes through `DirectPaymentGateway`.
- **`price_cents` hint** — keep a denormalised first-paint price to avoid a blank catalogue
  before the DP item join resolves, or accept the flash? Decide in P1.2.
- **Column rename** `direct_payment_item_reference_num` → `dp_item_reference_num` — cosmetic;
  do it in P3.1 or skip.
