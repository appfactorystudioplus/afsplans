# 06 — Cart and Checkout

## 6.1 What exists

Nothing (**E7**). The builder renders `<button>Add to Cart</button>` with no handler, and
`commerce.checkout()` calls a route that does not exist (**E6**).

So the cart is built from scratch — which is an opportunity, because the right design is not
the obvious one.

## 6.2 The cart is server-side

| Actor | Key |
|---|---|
| Guest | Opaque `cart_token`, httpOnly cookie, 30 days |
| Signed in ([01](01-site-users-guard.md)) | `site_user_id`; the guest cart **merges** on sign-in |

A `localStorage` cart cannot be priced authoritatively, cannot hold a stock reservation, and
cannot survive a device change. More sharply: **a cart whose totals are computed on the client
is a cart whose totals can be edited by the client**, and the first thing anyone does with a
storefront is open devtools.

The client holds a token. The server holds the cart.

**`laravel_ecommerce_carts`**

| Column | Notes |
|---|---|
| `reference_num` unique | `crt_` + 12 |
| `store_reference_num` index | |
| `cart_token` unique index | The guest key |
| `site_user_id` null index | Set on sign-in |
| `email` null | Captured at checkout, before payment — abandoned-cart recovery |
| `currency` | Stamped from the store at creation ([03](03-architecture.md) §3.4) |
| `status` | `active` \| `checking_out` \| `converted` \| `abandoned` |
| `discount_code` null | [09](09-promotions.md) |
| `shipping_address` / `billing_address` json null | |
| `shipping_method_id` null | |
| `direct_payment_invoice_ref` null | §6.3 |
| `expires_at` index | |
| `converted_order_id` null | |
| `timestamps` | |

**`laravel_ecommerce_cart_lines`** — `cart_id`, `variant_id`, `quantity`,
`unit_price_cents` (captured at add time), `line_subtotal_cents`, `custom_attributes` json
(engraving text, gift note), `unique(cart_id, variant_id, custom_attributes_hash)`.

Lines reference a **variant**, never a product ([04](04-catalogue.md) §4.4).

### Merging on sign-in

Guest adds three items, then signs in and already has a cart. Both are real.

**Merge, do not replace.** Same variant in both → sum quantities, clamped to available stock.
Different variants → union. Then delete the guest cart and re-point the token.

Replacing is what most implementations do, and it silently discards whatever the shopper just
spent five minutes choosing.

## 6.3 A cart is a Direct Payments invoice

The insight this document is built on.

The DP API already models a cart:

```js
const invoice = await dp.createInvoice();
invoice.addItem({ item_ref: 'itm_123', quantity: 2, seller_ref: 'member_x' });
invoice.getSubtotal(); invoice.getTaxTotal(); invoice.getTotal();
invoice.lock();          // generates inv_xxxxxxxx, freezes the item list
invoice.hasSplit();      // marketplace
```

Line items by reference, quantities, subtotal/tax/total, a lock that freezes it, and
per-line seller attribution for splits. **That is a cart.**

So ecommerce does **not** build a parallel totalling engine. It maintains the cart the shopper
manipulates — add, remove, change quantity, apply a discount, choose shipping — and at
checkout **projects it onto a DP invoice**, which becomes the authority for the amount
charged.

```
ecommerce cart              →  DP invoice
─────────────────────────      ────────────────────────────
cart_line (variant × qty)   →  addItem({ item_ref, quantity })
  variant.dp_item_ref       →       item_ref              (07 §7.4)
  vendor (marketplace)      →       seller_ref            (07 §7.6)
discount                    →  a negative-adjustment item (09 §9.5)
shipping                    →  a shipping item            (05 §5.6)
tax                         →  invoice.getTaxTotal()
────────────────────────────────────────────────────────────
                               invoice.lock()  →  the charged amount
```

**Why not total it ourselves and pass one number?** Because then two systems compute a total
from the same inputs and eventually disagree — over a rounding rule, a tax edge, a discount
applied before versus after tax. When they disagree, the customer sees one number and is
charged another. Making the DP invoice the single authority removes the class of bug rather
than testing for it.

Ecommerce still computes a **display** total for the cart page. But it is explicitly a
preview, and the checkout step re-derives from the locked invoice and shows the difference if
there is one (§6.5).

## 6.4 Checkout, step by step

> **REVISED (2026-09-06) — see [`AUDIT-direct-payments-decoupling.md`](AUDIT-direct-payments-decoupling.md) §P1.**
> The invoice is built by the **client**, not the server; `/checkout/confirm` became
> `/checkout/complete` and takes the three references the client collected; the server's
> one job on that call is to verify the invoice is paid (`PaymentGateway::isPaid()`) before
> writing the order. There is no order payment state — a row's existence is the proof.

```
1  POST /api/ecommerce/checkout/begin
     ├─ re-price every line against current catalogue prices
     ├─ verify stock, reserve it (15 min — 04 §4.6)
     ├─ shipping availability check
     └─ → { cart, direct_payment, shipping_item_ref, discount_item_ref, changes[] }

2  the storefront builds and charges the invoice itself, via window.Portal.directPayments:
     dp.createInvoice() → addItem({item_ref, quantity}) per cart line → lock()
     dp.setupSinglePayPayment({ invoice, mountTarget, onSuccess, onError })
     dp.confirmPayment({ email, invoice })            ← the card is charged here

3  POST /api/ecommerce/checkout/complete    { invoice_ref, direct_payment_ref, processor_ref, lines[] }
     ├─ PaymentGateway::isPaid(invoice_ref)   ← the gate; false ⇒ EC_E_ORDER_UNPAID, write nothing
     ├─ create the order + line items         (08 §8.2) — three refs, no money
     ├─ consume reservations, decrement stock
     ├─ mark the cart converted
     └─ → { order_reference_num, order_number }
```

### Step 1 returns `changes[]`, and that matters

Between adding to a cart and checking out, prices change, stock runs out, discounts expire.
Silently charging the new price is how a customer disputes a charge; silently keeping the old
one is how a shop loses money on a mispriced item.

So `begin` returns what changed — `price_increased`, `price_decreased`, `out_of_stock`,
`quantity_reduced`, `discount_expired` — and a non-empty `changes[]` **requires
re-confirmation** before the payment element mounts.

### Step 3 verifies server-side

`confirm` does **not** trust the client's "it worked". It checks the charge with Direct
Payments before writing an order. A client that can assert payment succeeded is a client that
can order free goods, and it takes one fetch replay to find that out.

## 6.5 Idempotency

Checkout is where double-submission is normal — impatient clicks, retried requests, a browser
back button.

- `begin` is idempotent per cart: called twice, it returns the **same** invoice unless the
  cart changed.
- `confirm` is idempotent per invoice: called twice, the second returns the **existing**
  order rather than creating a second.

```php
$table->unique('direct_payment_invoice_ref', 'ec_orders_invoice_uq');   // 08 §8.2
```

**That unique index is the real mechanism**, not the application check — under concurrency the
check-then-insert races, and the index does not.

## 6.6 Abandoned carts

`status = active`, has lines, has an email, untouched for 1 hour → abandoned.

A scheduled sweep marks them and can send a recovery email through the mail handler system
([08](08-orders-and-fulfilment.md) §8.7), with a link that restores the cart by token.

Two rules that keep this from becoming spam: **at most one recovery email per cart**, and
**never** for a cart whose email was never captured — an address from a previous order is not
consent to email about this one.

Off by default, per store.

## 6.7 The endpoints

`/api/ecommerce/cart/*` and `/api/ecommerce/checkout/*` — public, rate-limited, guardable
([site_users/12](../site_users/12-builder-integration.md) §12.8):

| Method | Path | |
|---|---|---|
| `GET` | `/cart` | Current cart, priced |
| `POST` | `/cart/lines` | Add — `{ variant_ref, quantity, custom_attributes? }` |
| `PATCH` | `/cart/lines/{id}` | Change quantity |
| `DELETE` | `/cart/lines/{id}` | Remove |
| `POST` | `/cart/discount` | Apply a code ([09](09-promotions.md)) |
| `DELETE` | `/cart/discount` | Remove |
| `POST` | `/cart/shipping-address` | Sets the address; **re-derives tax and shipping** |
| `POST` | `/cart/shipping-method` | |
| `POST` | `/checkout/begin` | §6.4 |
| `POST` | `/checkout/confirm` | §6.4 |

**No endpoint accepts a price, a total, a tax amount or a discount amount.** They are all
derived server-side from the variant, the rules and the catalogue. An endpoint that accepts a
price is a shop that sells for a penny.

## 6.8 Acceptance

- A cart survives a browser restart (cookie) and a device change (signed in).
- Guest cart + existing user cart **merge**; nothing is discarded (§6.2).
- Quantity clamps to available stock, with the available number in the error.
- `POST /cart/lines` with a `price` field in the body: the field is **ignored** (§6.7).
- Price change between add and `begin` → `changes[]`, and the payment element does **not**
  mount until re-confirmed.
- Stock exhausted between add and `begin` → checkout refused, the line named; quantity is
  **not** silently reduced.
- Charged total equals `invoice.getTotal()` exactly — asserted against the processor charge,
  not against our own calculation (§6.3).
- Double-clicking Pay produces **one** order (§6.5), verified under concurrent requests.
- Forging a client-side "payment succeeded" creates **no** order (§6.4).
- Abandoning a checkout returns the stock within the sweep window.
- Tax service unreachable → checkout fails; **no** charge with zeroed tax
  ([03](03-architecture.md) §3.7).
