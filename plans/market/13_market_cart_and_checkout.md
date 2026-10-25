# Step 13 — Market: cart and checkout on the Direct Payments API

**On completion:** write `complete/13_market_cart_and_checkout_complete.md`.
**Depends on:** [08](08_stripe_connect_brain_api.md), [09](09_direct_payments_connect_example.md), [11](11_market_portal_auth.md), [03](03_market_database_schema.md).
**Blocks:** 16, 17, 19.

---

## 13.1 What is being replaced

`app/Http/Controllers/PurchaseController.php::processPayment()`:

```php
'card_number' => 'sometimes|required_if:payment_method,credit_card|regex:/^\d{13,19}$/',
…
'transaction_id' => 'TXN-' . strtoupper(bin2hex(random_bytes(8))),
'status' => 'completed',
```

It accepts a raw card number into the application, validates it with a regex, invents a transaction
id, and marks the order paid. It never contacts a processor. Deleted in full — not adapted.

The raw-card-number field is the part to be loudest about: card data must never reach market's PHP
process at all. In the new flow the card is entered into a Stripe element hosted by the SDK, and
market sees only a payment reference.

---

## 13.2 The integration contract

Three constraints from the brief, each with a consequence:

> ### ⚠️ No `addItem` loop in the browser — and why
>
> `Invoice.addItem()` validates each `item_ref` against the DP's **static configured item list**, so
> a marketplace using it would need a DP item row per listing, kept in sync forever. Step 08 §08.5.7
> resolves this with the `CONNECT` pay_type: dynamic line items, and the invoice built
> **server-to-server** from market's own `order_items`. The browser receives an invoice reference and
> pays it.
>
> That also keeps prices out of the client entirely, removing the tampering surface.

1. **Types come from `apps/market/resources/js/portal.d.ts`** — 1269 lines of ambient declarations,
   already present, including `PortalDirectPaymentsAPI`, `PortalDirectPaymentInvoice`,
   `PortalDirectPaymentsSetupSinglePayOptions`, `PortalConfirmPaymentOptions`. It declares; it does
   not implement.
2. **The runtime comes from the script tag** — `<script src="https://{{ $afssdk }}.appfactory.studio/sdk.js"></script>`,
   already in `layouts/app.blade.php:15`, fixed for every page by step 11's composer.
3. **`window.Portal.httpUrl` and the DP ref come from env**, rendered by the composer into
   `window.MarketConfig`. Step 09's playground hardcodes them; market must not.

So the checkout logic is written in TypeScript against the ambient types, compiled into the portal
bundle, and calls a global the script tag supplied. The types are compile-time only — there is no
import, and there must not be one.

**Guard against the SDK not loading.** A CDN failure, an ad blocker, or a wrong `$afssdk` value
leaves `window.Portal` undefined. The checkout must detect this and show *"Payment services could
not be loaded. Please refresh."* rather than throwing `Cannot read properties of undefined`.

---

## 13.3 The cart

Server-side, database-backed for signed-in members (survives devices), session-backed for guests and
merged on sign-in.

`carts` (`buyer_reference_num` nullable, `session_id`, `currency`, timestamps) and `cart_items`
(`purchasable` morph, `seller_reference_num`, `quantity`, `service_package_id`, `extras` json,
`unit_price_snapshot`).

Rules, each with a reason:

- **Assets: quantity is always 1.** You cannot own a downloadable twice. Adding an owned asset shows
  *"You already own this"* and links to the portal.
- **Services: one line per configured package + extras.** Two orders of the same gig with different
  packages are two distinct lines.
- **`unit_price_snapshot`** is captured at add-time and re-validated at checkout. A price change
  between adding and paying shows *"The price of X changed from $A to $B"* and requires
  acknowledgement. Silently charging the new price is a chargeback; silently charging the old one is
  a loss.
- **Removed listings** are flagged at checkout and must be removed before proceeding.
- **Seller count is displayed** when > 1, because it determines the split mode (step 08) and, if
  §08.9's deferral was taken, blocks checkout with *"Items from different sellers must be checked
  out separately."*

Cart routes: `GET /cart`, `POST /cart/items`, `PATCH /cart/items/{id}`, `DELETE /cart/items/{id}`,
`POST /cart/clear`. Cart badge in both layouts.

---

## 13.4 Checkout

`GET /checkout` (replacing `/checkout/service/{service}` and `/checkout/asset/{asset}` — a cart has
many items; a per-item checkout URL cannot express that; keep the old routes as redirects into the
cart so existing links do not 404).

`resources/views/checkout.blade.php` gets: order summary, buyer details, **the license agreement
block**, the payment element mount, and the pay button.

### The license block

Every asset line renders its EULA — expandable, full text, from the live `market_licenses` row
(pre-purchase it is the live text; at purchase it is snapshotted). A single required checkbox:
*"I have read and agree to the license terms for the items in this order."* Blocking; recorded with
timestamp and IP on the order.

This is not decoration. It is the enforcement point for step 06's promise that the buyer reviews the
license before purchase.

### The flow

Mirrors `single_charge/direct-payments-charge-example.js` and step 09's example, in
`resources/js/portal/checkout.ts`:

```ts
/** MARKET_MODIFIER_ADDITION */
const cfg = window.MarketConfig;                       // from the composer, never a literal
window.Portal.httpUrl = cfg.dp_http_url;               // set before any SDK call

const directPayments = window.Portal.directPayments.create(cfg.direct_payment_ref);
await directPayments.initializeDirectPayment(cfg.direct_payment_ref);

// CONNECT pay_type: no addItem loop in the browser. Market's SERVER built the
// invoice from its own order_items and posted it to brain over the signed
// internal channel — prices never pass through the client (step 08 §08.5.7).
const { invoice_reference_num } = await fetch('/checkout/prepare', { method: 'POST' }).then(r => r.json());

await directPayments.setupSinglePayPayment({
  invoice_reference_num, mountTarget: '#payment-element', ...
});
const result = await directPayments.confirmPayment({ returnUrl: cfg.market_url + '/checkout/return' });
```

`seller_ref` per item is what drives step 08's split. If §08.9's deferral was taken, it is still sent
and simply resolves to one destination.

**Any cart mutation destroys the payment session and unlocks the invoice** — the
`destroyPaymentSession()` / `modifyCartBtn` behaviour the playground already models. A locked
invoice paid against a changed cart charges the wrong amount, which is the worst class of bug this
page can have.

### Server confirmation

The browser result is a **hint**, never the authority. A user can close the tab between confirmation
and return, and a hostile one can POST a fabricated success.

```
POST /checkout/confirm   { invoice_reference_num }
   → market asks brain for the invoice's authoritative state
   → if paid:  create Order + OrderItems + Entitlements (+ ServiceOrders), ledger, clear cart
   → if not:   render pending; a webhook/poll completes it later
```

The whole fulfilment runs in **one database transaction** keyed on `dp_invoice_reference_num`, which
is `unique` — so a double-submit, a retried webhook, and a refreshed return page cannot produce two
orders or two entitlements. Idempotency is enforced by a constraint, not by a check-then-insert
race.

Order creation writes, atomically:
`orders` → `order_items` (with `license_body_snapshot`) → `entitlements` (assets) →
`service_orders` in `awaiting_requirements` (services) → `seller_ledger_entries` (step 16) → cart
cleared → seller callbacks queued (step 04) → receipt email.

Then redirect to `/portal/orders/{ref}`.

---

## 13.5 Free items

Price `0` skips the payment flow entirely: no invoice, no processor, no fee, no transfer. The order
is created directly with `total = 0` and status `paid`. A cart mixing free and paid items pays only
for the paid ones.

Worth implementing early — it is the cheapest end-to-end path through the whole system and makes
steps 12 and 18 testable before Connect is finished.

---

## 13.6 Failure paths

| Failure | Behaviour |
|---|---|
| SDK not loaded | Blocking message, pay button disabled, no exception in console |
| Card declined | Stripe's message inline; invoice stays locked; retry allowed |
| Invoice lock fails | Cart preserved, specific error, nothing charged |
| Network drop after confirm | Return page polls `/checkout/status/{invoiceRef}` for up to 60s, then tells the buyer to check `/portal/orders` — never says "failed" when it does not know |
| Paid, fulfilment throws | Transaction rolls back; a reconciliation job retries from the invoice ref; **the buyer is never left charged with no entitlement**. Alert immediately — this is the highest-severity failure in the system |
| Price changed mid-checkout | Blocked, explained, acknowledgement required |
| Seller cannot receive payment | Blocked before lock, naming the seller |

---

## 13.7 Files

> **Tagging (step 01 §01.4) — every file below.** Created files carry
> `MARKET_MODIFIER_ADDITION` in their top doc block; modified files carry it at each changed line or
> region. `grep -rn MARKET_MODIFIER_ADDITION` must return this list in full, or the change index is
> already wrong.


**Created:** `app/Http/Controllers/CartController.php`,
`app/Http/Controllers/CheckoutController.php`,
`app/Services/Checkout/{CartService,OrderFulfilmentService,InvoiceBuilder}.php`,
`app/Models/{Cart,CartItem}.php`, cart migrations,
`resources/views/cart.blade.php`, `resources/views/checkout/{index,return,pending}.blade.php`,
`resources/js/portal/{cart,checkout}.ts`,
the fulfilment-reconciliation console command.

**Modified:** `app/Http/Controllers/PurchaseController.php` (`processPayment` deleted; the two
checkout routes become cart redirects), `routes/web.php`, `resources/views/layouts/app.blade.php`
(cart badge), `resources/js/portal.d.ts` (if step 08 added declarations).

---

## 13.8 Verification

- [ ] `grep -n "card_number" apps/market/app` → zero.
- [ ] Buy a $0 asset: order + entitlement created, no processor call, visible in `/portal`.
- [ ] Buy a paid asset in Stripe test mode: order, entitlement, ledger entries, transfer, and the seller callback all land.
- [ ] Submit `/checkout/confirm` twice with the same invoice ref: one order, one entitlement. Verify by count, not by absence of an error.
- [ ] Fabricated invoice ref → rejected; nothing created.
- [ ] Change an asset's price between add-to-cart and checkout: blocked with the acknowledgement prompt.
- [ ] Block `sdk.js` in devtools: the blocking message appears, no console exception.
- [ ] Modify the cart with a locked invoice: session destroyed, invoice unlocked, no stale charge possible.
- [ ] Two-seller cart behaves per the §08.9 decision, and the completion report says which.
- [ ] License checkbox is required, and the agreement is recorded on the order.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/13_market_cart_and_checkout_complete.md` written.
