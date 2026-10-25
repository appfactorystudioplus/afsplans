# ecommerce — Planning Index

Turning a catalogue CRUD into a real store: variants, inventory, cart, tax, shipping,
discounts, orders and fulfilment — with **Direct Payments** as the payment engine and
**Site Users** as the optional identity layer.

> ### 🔴 Two things are true today and both gate everything
>
> **1. The API is unauthenticated, including destructive writes.**
> `/portal/api/ecommerce/*` sits behind `portal.cors`, which is an explicit no-op — not
> `ApiAuthMiddleware`. That group includes `POST /product`, `DELETE /product/{ref}`,
> `POST /store` and **`DELETE /store/{ref}`**, and the handlers delete by reference with no
> ownership check. **Anyone can delete a tenant's store.**
>
> **2. Nothing transacts.** `laravel_ecommerce_orders` has **no writer anywhere in the
> codebase**. `commerce.checkout()` POSTs to `/portal/api/v1/processors/checkout`, **a route
> that does not exist**. The builder's "Add to Cart" buttons are static markup with no
> handler. There is no cart, no order, no payment path.
>
> See [02-current-state-audit.md](02-current-state-audit.md). Phase **P0** closes the
> security hole and depends on nothing.

Read in this order:

| Step | Document | Contents |
|---|----------|----------|
| — | [README.md](README.md) | This index |
| 01 | [01-site-users-guard.md](01-site-users-guard.md) | Optional customer accounts via [Site Users](../site_users/README.md) — guest checkout stays the default. **Extended by [`../connections/`](../connections/README.md)**: the store↔module link, the guest-checkout switch, the checkout sign-in gate, and the shopper's Orders/Returns dashboard. That plan's [12](../connections/12-guest-continuity.md) also fixes a **guest-cart defect in this module** — one cookie for the whole site means a second store destroys the first store's cart |
| 02 | [02-current-state-audit.md](02-current-state-audit.md) | Everything that exists, and the 19 defects and gaps |
| 03 | [03-architecture.md](03-architecture.md) | The layering: catalogue → cart → Direct Payments → order. What owns money, what owns identity |
| 04 | [04-catalogue.md](04-catalogue.md) | Products, **variants**, options, media, inventory, collections, SEO |
| 05 | [05-pricing-tax-shipping.md](05-pricing-tax-shipping.md) | Price lists, compare-at, currency, tax classes and zones, shipping rates |
| 06 | [06-cart-and-checkout.md](06-cart-and-checkout.md) | The persistent cart, and why **a cart is a Direct Payments invoice** |
| 07 | [07-direct-payments-integration.md](07-direct-payments-integration.md) | **Which Direct Payment a store uses**, product↔item mapping, subscriptions, marketplace splits |
| 08 | [08-orders-and-fulfilment.md](08-orders-and-fulfilment.md) | The order lifecycle, line items, fulfilments, tracking, refunds, the event log |
| 09 | [09-promotions.md](09-promotions.md) | Discount codes, automatic promotions, gift cards |
| 10 | [10-storefront-and-sdk.md](10-storefront-and-sdk.md) | `window.Portal.commerce`, the builder components that actually work, `portal.d.ts` |
| 11 | [11-admin-dashboard.md](11-admin-dashboard.md) | The operator surface — catalogue, orders, fulfilment, settings |
| 12 | [12-rollout-and-testing.md](12-rollout-and-testing.md) | Phases, the security gate, the test matrix |

## Completion documents

Each numbered document gets a completion document in [`complete/`](complete/) — source
basename plus `.complete.md`. Include status, summary, files changed, verification steps and
follow-up work.

## Code documentation standard

Document all new and changed code thoroughly with block comments — PHPDoc for PHP, JSDoc for
TypeScript. Every file, exported symbol, public function, route handler, migration and
non-trivial type must explain purpose, parameters, return values, side effects and plan
references.

New code carries `AFS_ECOMMERCE_MODIFIER_ADDITION` (see [../../markers.md](../../markers.md))
with the plan reference. Site Users work carries `AFS_SITE_USERS_MODIFIER_ADDITION`. Where a
file already has a marker, **add alongside; never replace.**

## The four rules this plan is built on

**1. Direct Payments owns money; ecommerce owns commerce.**
Ecommerce never talks to Stripe, never holds a secret key, never creates a provider customer.
It builds a cart, hands it to Direct Payments, and records what came back
([03](03-architecture.md) §3.2). Every payment feature — subscriptions, saved cards,
marketplace splits, refunds — arrives through that seam rather than being rebuilt here.

**2. A cart is a Direct Payments invoice.**
`dp.createInvoice()`, `addItem({ item_ref, quantity, seller_ref })`, `lock()`, `getTotal()` —
the DP API already models exactly what a cart is. Building a parallel cart with its own
totalling is how the two drift and a customer is charged a different number than they saw
([06](06-cart-and-checkout.md) §6.3).

**3. Money is integer minor units, everywhere.**
The current `decimal(10,2)` price column is the single most consequential schema mistake to
fix ([04](04-catalogue.md) §4.2). Every amount in every new table is `*_cents`, integer,
matching what the processor layer already does.

**4. Identity is optional.**
Guest checkout is the default and stays fully supported. Accounts are opt-in per store
([01](01-site-users-guard.md) §1.5), and every feature here works without one.

## One-paragraph summary

The ecommerce module today is a catalogue CRUD with static storefront mockups: three tables,
nine unauthenticated admin endpoints, an orders table nothing writes, and a checkout method
that calls a route which does not exist. This plan closes the security hole first, then
builds the store that catalogue implies — **variants and inventory** so a size-medium can sell
out, **price lists** so a wholesale customer sees wholesale prices, **tax and shipping** so a
total is a real total, a **persistent cart** that survives a device change, **discount codes
and automatic promotions**, and a real **order lifecycle** with line items, partial
fulfilment, tracking and refunds. Payment is not rebuilt: the cart *is* a Direct Payments
invoice, so subscriptions, saved cards, marketplace splits and refunds all arrive through a
seam that already exists — and which Direct Payment a store uses becomes a first-class,
configurable choice rather than an unused column.
