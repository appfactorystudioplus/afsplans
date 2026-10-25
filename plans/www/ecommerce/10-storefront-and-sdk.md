# 10 — The Storefront and the SDK

`window.Portal.commerce`, and the builder components that currently do nothing.

## 10.1 What exists

**SDK** — `resources/js/external/commerce/api.ts`, 86 lines, three methods:

| Method | State |
|---|---|
| `getProducts(query?)` | Works |
| `getCategories()` | Works |
| `checkout(options)` | **POSTs to a route that does not exist** (**E6**) |

**Builder** — `ecommerceTemplates.ts`, 532 lines of storefront markup: product grids, product
detail, cart overview, checkout. Good markup with **no behaviour at all** (**E7**) — the
"Add to Cart" buttons have no `onclick`, no `script`, no handler.

The templates need wiring, not redesign ([02](02-current-state-audit.md) §2.6).

## 10.2 The SDK surface

Instance-based per store, matching the pattern `directPayments` and `siteUsers` already use:

```js
const shop = window.Portal.commerce.create('store_ref');
await shop.init();          // store config, currency, tax mode, enabled features
```

### Catalogue

| Method | |
|---|---|
| `shop.products({ category?, collection?, tag?, search?, sort?, page? })` | Paginated, priced for the caller |
| `shop.product(slugOrRef)` | With variants, options and media |
| `shop.categories()` / `shop.collections()` | |
| `shop.variantFor(productRef, { Size: 'M', Colour: 'Black' })` | Option combination → variant |

### Cart

| Method | |
|---|---|
| `shop.cart()` | Current cart, priced, with totals |
| `shop.addToCart({ variant_ref, quantity, custom_attributes? })` | |
| `shop.updateLine(lineId, { quantity })` / `shop.removeLine(lineId)` | |
| `shop.applyDiscount(code)` / `shop.removeDiscount()` | |
| `shop.setShippingAddress(addr)` | Re-derives tax and shipping |
| `shop.shippingMethods()` / `shop.setShippingMethod(id)` | |

### Checkout

| Method | |
|---|---|
| `shop.beginCheckout()` | → `{ invoice_ref, total_cents, changes[] }` ([06](06-cart-and-checkout.md) §6.4) |
| `shop.mountPayment(el, { onSuccess, onError })` | Delegates to `dp.setupSinglePayPayment()` |
| `shop.confirmCheckout()` | → `{ order_reference_num }` |

### Account *(requires Site Users)*

`shop.orders()`, `shop.order(ref)` — [01](01-site-users-guard.md) §1.4.

### Events

```js
shop.on('cart:updated', c => updateBadge(c.line_count));
shop.on('checkout:changed', changes => showRepriceNotice(changes));
shop.on('order:placed', o => …);
```

`cart:updated` is what makes a header cart badge work without every component polling.

### What the SDK never does

**No method accepts a price, total, tax or discount amount** ([06](06-cart-and-checkout.md)
§6.7). `shop.cart()` returns totals; it never sends them. An SDK that could set a price is a
shop that sells for a penny.

## 10.3 `checkout()` is replaced, not fixed

The existing `checkout()` posts a single `product_id` to a nonexistent route. Even given that
route, it encodes a model this plan does not have — one product, one quantity, no cart, no
discount, no shipping.

It is **removed** and replaced by the cart flow. Because it has never worked (**E6**), there
is nothing to deprecate: no caller can be relying on a 404.

`getProducts` and `getCategories` **do** work and get a deprecation window
([site_users/02](../site_users/02-the-rename.md) §2.3's pattern) — published pages call them
today.

## 10.4 Builder components that work

The templates become real components using the builder's proven three-layer model
([site_users/12](../site_users/12-builder-integration.md) §12.1): block → component type →
`script` that runs on the published page.

| Block | Renders |
|---|---|
| Product Grid | Paginated listing, filters, sort |
| Product Detail | Media gallery, **option pickers**, price, add-to-cart |
| Cart | Lines, quantity controls, discount field, totals |
| Checkout | Address, shipping method, payment element |
| Cart Badge | Line count, header-mounted, live via `cart:updated` |
| Order Confirmation | Post-purchase, reads `?order=` |
| My Orders | History — requires Site Users |

Each carries the two conventions every component script in the builder follows:

```ts
if (isEditorMode()) return;                 // canvas shows a placeholder
waitForPortal('commerce', init);            // sdk.js is a separate <script>
```

Both from the shared helper that
[site_users/12](../site_users/12-builder-integration.md) §12.10 lifts out — this plan uses it
rather than adding a fourth copy.

**Product Detail is the component with real logic**: selecting `Size: M` and `Colour: Black`
must resolve to a variant, update the price, swap the image, and disable combinations that do
not exist or are out of stock. Rendering a picker that lets a shopper choose an unbuyable
combination and then fail at add-to-cart is the most common storefront bug there is.

### The editor canvas

Components render **sample data** in the canvas, not live products — a tenant designing a grid
should see a grid, and the canvas must not hit the API on every keystroke. Where a store is
selected, the first few real products are fetched once and cached for the session.

## 10.5 `portal.d.ts`

Per the externals contract ([site_users/07](../site_users/07-externals-api.md) §7.6),
consumers never import the externals source; they use the copied declaration. It is
hand-maintained, so **`portal.d.ts` is updated in the same pass as the SDK** — an undeclared
method is invisible to every consumer's build.

| Declared | Replaces |
|---|---|
| `PortalCommerceNamespace` with `create()` | The current single `CommerceAPI` shape |
| `PortalCommerceInstance` — every §10.2 method | Three methods |
| `PortalCommerceProduct`, `…Variant`, `…Cart`, `…CartLine`, `…Order` | *(nothing today)* |
| `PortalCommerceError` with `EC_E_*` codes | *(nothing)* |
| `PortalCommerceEventMap` | *(nothing)* |

Copies in consuming apps are refreshed for the ones that declare the commerce namespace.

## 10.6 Storefront performance

A listing page is the most-hit page in the module and the easiest to make slow.

- **Product listings are cached** per (store, filter, page, customer group), invalidated on
  catalogue write. Customer group is in the key because price lists change the prices
  ([05](05-pricing-tax-shipping.md) §5.3) — omitting it serves wholesale prices to the public.
- **Automatic collections are evaluated on write**, never on read
  ([04](04-catalogue.md) §4.7).
- **The cart is never cached.** It is per-visitor and mutable.
- **Media carries dimensions** ([04](04-catalogue.md) §4.5) so the grid reserves space and
  does not shift as images load.
- **Stock is read at add-to-cart and re-read at `begin`** — never cached, because a stale
  stock read is an oversell.

The customer-group cache key is the one to get right. It is a correctness bug disguised as a
performance detail.

## 10.7 Acceptance

- A published storefront lists products, adds to cart, and completes a purchase — end to end,
  with no code from the tenant.
- Selecting an option combination that does not exist is **not possible** in the picker
  (§10.4).
- An out-of-stock combination is visibly disabled, not merely rejected on submit.
- The cart badge updates without a reload, via `cart:updated`.
- The editor canvas makes **no** API calls beyond one cached sample fetch.
- Every §10.2 method is declared in `portal.d.ts`; a consumer type-checks each without
  `(window as any)`.
- `grep -rn "from '.*external/commerce" <consumer>/resources/js/` is **empty**.
- The old `getProducts` / `getCategories` paths keep working through the deprecation window.
- The old `checkout()` is **gone**, and its removal breaks nothing (**E6**).
- A wholesale shopper and a guest hitting the same listing get **different prices** — proving
  the cache key includes the customer group (§10.6).
