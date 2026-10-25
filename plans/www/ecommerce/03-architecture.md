# 03 — Architecture

## 3.1 The layers

```
    ┌──────────────────────────────────────────────────────────┐
    │  Storefront — builder components + window.Portal.commerce │  10
    └───────────────────────────┬──────────────────────────────┘
                                │
    ┌───────────────────────────▼──────────────────────────────┐
    │  CATALOGUE   products · variants · options · media        │  04
    │              collections · inventory · price lists        │  05
    └───────────────────────────┬──────────────────────────────┘
                                │
    ┌───────────────────────────▼──────────────────────────────┐
    │  CART        lines · totals · promotions · tax · shipping │  06 · 09
    └───────────────────────────┬──────────────────────────────┘
                                │  lock + hand over
    ┌───────────────────────────▼──────────────────────────────┐
    │  DIRECT PAYMENTS   invoice · payment element · charge     │  07
    │  (processors: stripe · square · paypal)                   │
    └───────────────────────────┬──────────────────────────────┘
                                │  confirmed
    ┌───────────────────────────▼──────────────────────────────┐
    │  ORDER       line items · fulfilments · refunds · events  │  08
    └──────────────────────────────────────────────────────────┘

    identity (optional, any layer):  Site Users  →  ../site_users/
```

## 3.2 Direct Payments owns money; ecommerce owns commerce

The single most important boundary here, and the one that decides how much of this plan is
actually new work.

| Ecommerce owns | Direct Payments owns |
|---|---|
| What is for sale, at what price, in what quantity | Taking the money |
| Cart contents and totalling **inputs** | The payment element, the provider, the customer record |
| Tax and shipping **rules** | Saved cards, subscriptions, splits, refunds |
| The order, its lifecycle, its fulfilment | The charge and its status |

**Ecommerce never talks to a provider.** It does not import a Stripe SDK, never sees a secret
key, never creates a provider customer, never handles a webhook. It builds a cart, hands it to
Direct Payments, and records what came back.

That is not tidiness — it is what makes the following arrive for free rather than being
rebuilt:

| Capability | Where it already exists |
|---|---|
| Saved cards across visits | Direct Payments + [site_users/08](../site_users/08-direct-payments-link.md) |
| Subscription products | `dp.subscription.*` ([direct_payments/28](../direct_payments/28-subscription-actions.md)) |
| Marketplace splits | `invoice.addItem({ seller_ref })` ([direct_payments/29](../direct_payments/29-money-and-refunds.md) §15.4) |
| Refunds | [direct_payments/29](../direct_payments/29-money-and-refunds.md) §15.4 |
| Provider-agnostic checkout | The processors layer's normalisation |

An ecommerce module that built its own Stripe integration would have to rebuild every row of
that table, badly, and would diverge the moment a provider changed.

### The one thing that crosses the line

`stores.direct_payment` — which Direct Payment a store charges through
([07](07-direct-payments-integration.md) §7.2). Ecommerce points *down* at a DP reference; the
Direct Payments layer knows nothing about stores. Same direction as everywhere else in this
ecosystem.

## 3.3 The three identifiers, and not confusing them

| Identifier | Layer | Example |
|---|---|---|
| `product.reference_num` / `variant.reference_num` | **Catalogue** — what a store sells | `prd_a1b2c3`, `var_a1b2c3` |
| `direct_payment_item_reference_num` | **Direct Payments** — a priceable line on a DP | `itm_123` |
| `provider_price_id` | **Processor** — Stripe's price object | `price_1AbC…` |

A variant maps to a DP item; a DP item maps to a provider price. **Ecommerce only ever names
the first two**, and only the first in its own tables. Reaching past Direct Payments to a
provider price is the boundary violation to watch for in review
([07](07-direct-payments-integration.md) §7.4).

## 3.4 Money

**Integer minor units, everywhere, in every new table.** Column names end `_cents`.

`products.price DECIMAL(10,2)` (**E9**) is migrated to `price_cents INTEGER` before anything
reads it ([04](04-catalogue.md) §4.2). Mixing decimal and integer money in one system
guarantees a rounding disagreement between the number a customer sees and the number that is
charged — and that disagreement surfaces as a chargeback, not a test failure.

Currency is per store, stored on the store, and stamped onto every cart and order at creation.
**A total never mixes currencies**, and an order's currency is never re-derived from the store
later — the store's currency can change; a completed order's cannot.

## 3.5 Multi-store, multi-site

`website_domain` and `website_reference_num` are already on stores and orders (§2.6), so:

- A tenant may run several stores on one site.
- A store belongs to one site.
- **Every catalogue query is scoped by store**, and every admin query additionally by the
  caller's ownership (**E2**, **E4**).

The scoping rule is enforceable rather than remembered: a model-level scope plus a test that
no query against `laravel_ecommerce_products` executes without a `store_reference_num`
predicate.

## 3.6 Statelessness and the cart

The cart is the one piece of genuinely mutable, per-visitor state, and it has to work for a
guest with no account.

| Actor | Cart key |
|---|---|
| Guest | An opaque `cart_token` in an httpOnly cookie |
| Signed-in ([01](01-site-users-guard.md)) | The `site_user_id`; the guest cart is **merged** on sign-in |

Server-side, always. A cart in `localStorage` cannot be priced, cannot have stock reserved
against it, and cannot survive a device change — and a cart whose totals are computed on the
client is a cart whose totals can be edited by the client
([06](06-cart-and-checkout.md) §6.2).

## 3.7 Failure posture

| Situation | Behaviour |
|---|---|
| Store inactive or missing | Storefront endpoints `404` |
| Variant out of stock at add-to-cart | Refuse, with the available quantity in the error |
| Variant out of stock at **checkout** | Refuse the whole checkout, name the line, **do not** silently reduce quantity |
| Price changed between add-to-cart and checkout | Re-price, show the difference, require re-confirmation |
| Direct Payment unreachable | Fail the checkout; the cart survives, no order is created |
| Payment confirmed but order write fails | **Order is reconciled from the DP invoice** ([08](08-orders-and-fulfilment.md) §8.6) — money taken with no order is the one unacceptable outcome |
| Tax service unreachable | Fail the checkout. **Never charge with tax silently zeroed** |

The last two are the ones worth designing for rather than discovering. Charging a customer
and recording nothing is unrecoverable without a reconciler; charging without tax is a
liability that surfaces at an audit.

## 3.8 What this plan deliberately does not build

| Not building | Why |
|---|---|
| A payment integration | §3.2 |
| A tax **engine** (nexus, VAT MOSS, filing) | Rates and zones, yes; a compliance engine is a product. The processor tax endpoint covers the common case ([05](05-pricing-tax-shipping.md) §5.5) |
| Live carrier rates (UPS/FedEx APIs) | Flat, weight and zone rates cover most tenants; carrier APIs are a per-tenant credential problem worth its own decision |
| A warehouse/3PL integration | Fulfilment records and tracking numbers, yes; an integration is per-provider |
| Multi-currency **presentment** | One currency per store. Multi-currency needs FX sourcing and rounding policy, which is a plan of its own |
| Product reviews | Genuinely useful, genuinely separable. Would fit as its own module reusing Site Users for identity |

Each is a defensible product, and each is a reason this plan does not double in size.
