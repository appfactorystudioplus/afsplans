# 07 — Direct Payments Integration

> **SUPERSEDED (2026-09-06) by [`AUDIT-direct-payments-decoupling.md`](AUDIT-direct-payments-decoupling.md).**
> The server-side pieces this step specified — `EcommerceDirectPaymentService` price
> resolution, the price-publish cache, storefront visibility via a DP join, server-built
> invoices, DP-item auto-creation — were built and then removed. The model now: a store
> names a Direct Payment; every variant carries `direct_payment_item_reference_num`; the
> **storefront client** joins DP item prices and **builds the invoice**; the façade order
> stores three references (`direct_payment_reference_num`, `processor_reference_num`,
> `direct_payment_invoice_ref`) and resolves all money through
> `App\Externals\DirectPayments\Contracts\PaymentGateway`. §7.2 (store picks a DP, validated) and
> §7.4's mapping precedence (variant wins over product) still hold. Read the AUDIT doc for
> the current design; the rest of this file is history.

Which Direct Payment a store charges through, how a variant maps to a priceable item, and how
subscriptions, saved cards and marketplace splits arrive without ecommerce building any of
them.

## 7.1 What exists today

Two columns and no mechanism (**E8**):

```php
laravel_ecommerce_stores.direct_payment                        // a DP reference_num
laravel_ecommerce_products.direct_payment_item_reference_num   // a DP item reference_num
```

`grep` finds **no reader** for either. The intent was right — a store should name the Direct
Payment it charges through — and it was never finished, because there is no checkout to
finish it into (**E5**, **E6**).

## 7.2 The store's Direct Payment — made real and configurable

A store charges through exactly one Direct Payment at a time. That is the configuration the
module has been missing.

`laravel_ecommerce_stores` gains:

| Column | Type | Notes |
|---|---|---|
| `direct_payment` | `string(64)` null | **Existing.** The DP `reference_num`. Null = the store cannot take payment |
| `currency` | `string(10)` default `usd` | Stamped onto carts and orders ([03](03-architecture.md) §3.4) |
| `subscription_direct_payment` | `string(64)` null | Optional second DP for subscription products (§7.5) |

### Where a tenant chooses it

In the **store settings**, beside the Site Users mode
([01](01-site-users-guard.md) §1.5.3) — one screen where a tenant decides how their shop
behaves:

```
Store settings — Main Store
──────────────────────────────────────────────
  Name              Main Store
  Currency          [ USD ▾ ]

  Payments
    Direct Payment  [ Store Checkout (eq4W10iF8) ▾ ]
                    Stripe · live · SINGLE_PAY

    ⓘ This Direct Payment's processor takes every payment
      for this store. Change it to switch processors without
      touching your products.

    Subscription payments (optional)
                    [ — none — ▾ ]
                    For products of type "subscription".

  Customer accounts
    ( • ) Off — anyone can buy as a guest
    ...
──────────────────────────────────────────────
```

The dropdown lists Direct Payments with `pay_type = SINGLE_PAY` (and `CONNECT` for
marketplaces — §7.6), showing each one's **processor, provider and mode**, because "which
Stripe account does this charge" is the question an operator actually has.

The subscription select lists `pay_type = SUBSCRIPTION` DPs and appears only when the store
has at least one subscription product.

### The payoff

**Switching processor is a dropdown change.** A tenant moving from Stripe to Square points
the store at a different Direct Payment; no product changes, no code changes, no re-keying.
That is precisely what the Direct Payments layer exists for, and it is why ecommerce must
never hold a provider reference itself ([03](03-architecture.md) §3.3).

> **That promise was not true when it was written, and
> [`../direct_payments/`](../direct_payments/README.md) (2026-09-12) is the plan that makes it
> true.** Today re-pointing a Direct Payment is one field write that orphans every customer,
> redirects every live subscription at an account it does not exist in, and leaves refunds on
> past charges unreachable
> ([`../direct_payments/01`](../direct_payments/01-current-state-audit.md) **D1**, **D2**,
> **D5**). Two corrections to the paragraph above: a swap **re-points the store's existing
> Direct Payment** rather than pointing the store at a different one — the DP reference is the
> stable handle ([`../direct_payments/03`](../direct_payments/03-the-swap.md) §3.2) — and "no
> re-keying" holds for code and history but **not for saved cards or live subscriptions**,
> which cannot follow a tenant to a new provider
> ([`../direct_payments/03`](../direct_payments/03-the-swap.md) §3.5, §3.6).

### Validation

| Rule | Code |
|---|---|
| The DP exists and belongs to this tenant | `EC_E_DP_NOT_FOUND` |
| Its `pay_type` is `SINGLE_PAY` or `CONNECT` | `EC_E_DP_WRONG_TYPE` |
| Its processor is `is_active` | `EC_E_DP_PROCESSOR_INACTIVE` |
| Store currency matches the DP's processor currency | `EC_E_CURRENCY_MISMATCH` |
| Clearing it while the store has `active` products | **Warn**, do not refuse — a tenant may be mid-migration |

## 7.3 Charging without a Direct Payment

A store with `direct_payment = null` is a **catalogue**, not a shop.

- The storefront renders, products are browsable.
- Add-to-cart works.
- `POST /checkout/begin` returns `EC_E_STORE_NOT_PAYABLE`.
- The admin shows a persistent banner naming the missing setting and linking to it.

That is deliberately not an error state. Browsing a catalogue before payments are configured
is a legitimate stage of setting up a shop, and blocking the storefront until a DP exists
makes the setup order rigid for no reason.

## 7.4 Variant → Direct Payment item

`invoice.addItem({ item_ref, quantity })` needs a DP **item reference**. So every sellable
variant must map to one.

| Level | Column | Precedence |
|---|---|---|
| Variant | `direct_payment_item_reference_num` | **Wins** |
| Product | `direct_payment_item_reference_num` | Fallback |

Two mapping modes, and the choice matters:

### Mode A — explicit items *(default)*

Each variant maps to a DP item configured on the Direct Payment. Prices live in the DP's item
list; ecommerce's `price_cents` is display only, and the two are **reconciled**.

Correct, auditable, and the DP's own configuration remains the source of truth for what things
cost. The cost is that adding a product means adding a DP item.

**A price mismatch is surfaced, never silently resolved.** The admin shows a warning listing
every variant whose `price_cents` differs from its DP item, because the alternative — showing
one price and charging another — is the failure this whole design exists to avoid
([06](06-cart-and-checkout.md) §6.3).

### Mode B — dynamic items

The Direct Payment declares a `DYNAMIC` item; ecommerce passes the amount at add time
(`invoice.addItem({ item_ref, quantity, amount })`, which the DP API already supports for
dynamic items, bounded by the item's `min_amount`/`max_amount`).

Right for a large or fast-changing catalogue where maintaining a DP item per variant is
absurd. The trade is that the amount comes from ecommerce, so ecommerce's price column becomes
authoritative and the DP's bounds are the only guard.

Per store, `dp_item_mode: explicit | dynamic`, defaulting to `explicit`.

### Auto-provisioning

In `explicit` mode, saving a variant with no mapping **offers** to create the DP item —
name, price and SKU are all already known. One click instead of a context switch, and it is
an offer rather than an automatic write, because silently mutating a payment configuration
from a catalogue screen is the wrong default.

## 7.5 Subscription products

`product_type = subscription` ([04](04-catalogue.md) §4.3) routes checkout to the store's
`subscription_direct_payment` rather than its `direct_payment`, and to the subscription
surface rather than single-pay:

```js
// single-pay line
dp.setupSinglePayPayment({ invoice, mountTarget, onSuccess, onError });

// subscription line
dp.subscription.create({ tier_ref, … });   // direct_payments/28
```

**A cart may not mix subscription and one-off lines.** The two require different DP flows and
different providers' semantics; allowing the mix means one checkout with two payment elements
and two failure modes.

So: a subscription product goes into its **own** cart, and adding one to a cart containing
one-off items returns `EC_E_MIXED_CART` with a clear message. Restrictive, and it is the
restriction every platform that allowed the mix later regretted.

Everything downstream — renewal, dunning, cancellation, the member's subscription view —
already exists in the [billing plan](../../billing/README.md). Ecommerce contributes the
product and the tier reference and nothing more.

## 7.6 Marketplace splits

`invoice.addItem({ item_ref, quantity, seller_ref })` already carries per-line seller
attribution, and `invoice.hasSplit()`, `getSellerRefs()` and `getSplitItems()` already exist —
so multi-vendor is a mapping problem, not a payments problem.

Ecommerce adds a **vendor** to the catalogue:

**`laravel_ecommerce_vendors`** — `reference_num`, `store_reference_num`, `name`,
`connect_account_ref` (a DP Connect account), `commission_percent`, `is_active`.

`products.vendor_id` nullable. At checkout, a line whose product has a vendor passes that
vendor's `connect_account_ref` as `seller_ref`; the DP layer handles the split, the transfer
and the platform fee — including the `reverse_transfer` correctness a refund needs
([direct_payments/29](../direct_payments/29-money-and-refunds.md) §15.4).

Requires the store's DP to be `pay_type = CONNECT`. Validated at save with a message naming
the requirement rather than failing at checkout.

**Off unless a store adds a vendor.** A single-merchant shop never encounters any of it.

## 7.7 Saved cards and the customer link

A signed-in shopper's provider customer is reused rather than re-created, so saved cards
follow the account across visits and devices. That is
[site_users/08](../site_users/08-direct-payments-link.md) in full; ecommerce's part is
one thing:

**Pass the signed-in user's linked customer to the DP layer at checkout, when one exists.**

Guests get an anonymous customer, exactly as today. The signed-in path fails **loudly in
development and quietly in production** — logging and proceeding anonymously **without**
writing a link, so a lookup failure is recoverable rather than producing a wrong link and
attaching one shopper's card to another's account.

## 7.8 Refunds

Ecommerce does not implement refunds. [direct_payments/29](../direct_payments/29-money-and-refunds.md)
does, at the charge level, from the Money ledger.

Ecommerce's part is the **join**: an order records `direct_payment_invoice_ref` and the
resulting charge id, so the Money ledger's Customer and Order columns resolve, and a refund
issued there is visible on the order ([08](08-orders-and-fulfilment.md) §8.5).

**A refund does not automatically restock.** Whether returned goods are resellable is a
judgement — damaged, opened, never shipped — and an automatic restock on refund silently
inflates inventory. The admin offers "restock these items?" alongside the refund, defaulted to
**on** for unfulfilled orders and **off** for fulfilled ones, which is right far more often
than either default alone.

## 7.9 Acceptance

- A store with no `direct_payment` browses but cannot check out (`EC_E_STORE_NOT_PAYABLE`),
  with an admin banner (§7.3).
- Pointing a store at a different Direct Payment switches processor with **no product
  changes** (§7.2).
- Selecting a `SUBSCRIPTION` DP as the primary is refused (`EC_E_DP_WRONG_TYPE`).
- In `explicit` mode, a variant whose `price_cents` differs from its DP item is **flagged in
  the admin**, and the charge matches the invoice (§7.4).
- Saving an unmapped variant **offers** DP item creation; it does not create one silently.
- In `dynamic` mode, an amount outside the DP item's `min`/`max` is refused by the DP layer.
- Adding a subscription product to a cart with one-off items returns `EC_E_MIXED_CART`
  (§7.5).
- A vendor product charges with `seller_ref`, and `invoice.hasSplit()` is true (§7.6).
- Adding a vendor to a store whose DP is not `CONNECT` is refused at save.
- A signed-in shopper's second purchase reuses their provider customer; saved cards appear
  on another device (§7.7).
- A customer-link failure in production logs, proceeds anonymously, and writes **no** link.
- A refund in the Money ledger appears on the order, and does **not** restock without the
  operator choosing it (§7.8).
- `grep -rn "stripe\|square\|paypal\|Stripe" app/Http/Controllers/**/Ecommerce*` is
  **empty** — ecommerce names no provider ([03](03-architecture.md) §3.2).
