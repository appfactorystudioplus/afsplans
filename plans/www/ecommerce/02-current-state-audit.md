# 02 — Current State Audit

Read from the repository. Findings are numbered `E#` so later documents can cite them.

## 2.1 What exists

| Layer | File | LOC |
|---|---|---|
| Migration | `2026_04_03_133338_create_ecommerce_tables.php` — categories, products, stores | 68 |
| Migration | `2026_07_23_000002_create_laravel_ecommerce_orders_table.php` — orders | 33 |
| Models | `EcommerceCategory`, `EcommerceProduct`, `EcommerceStore`, `EcommerceOrder` | ~40 |
| Admin API | `Portal/Admin/EcommerceController.php` — 9 CRUD methods | 151 |
| Routes | `routes/portal.php:600` — `/portal/api/ecommerce/*` | 9 |
| Externals SDK | `resources/js/external/commerce/api.ts` — `getProducts`, `getCategories`, `checkout` | 86 |
| Builder | `portal/builder/core/ecommerceTemplates.ts` — storefront templates | 532 |
| Admin UI | `portal/admin/controllers/ecommerce/` — store, products, categories | — |
| afs-connect | `app/AfsConnect/Providers/WwwEcommerceProvider.php` | — |

### The schema, in full

```php
laravel_ecommerce_stores
    id · reference_num (unique) · name · direct_payment
    website_domain · website_reference_num · timestamps

laravel_ecommerce_categories
    id · store_reference_num · name · reference_num · description · timestamps

laravel_ecommerce_products
    id · store_reference_num · reference_num · name · description
    price DECIMAL(10,2) · images TEXT · direct_payment_item_reference_num
    category1 … category5 · timestamps

laravel_ecommerce_orders
    id · reference_num (unique) · store_reference_num · status
    total_amount_cents · currency · customer_email · items_snapshot JSON
    processor_reference_num · website_domain · website_reference_num · timestamps
```

That is the whole module.

## 2.2 Security — the gate

| # | Finding | Consequence |
|---|---|---|
| **E1** | `/portal/api/ecommerce/*` has **no authentication**. Its only wrapper is `Route::middleware('portal.cors')` (`routes/portal.php:75`), and `PortalCorsMiddleware::handle()` is `return $next($request)` with a docblock saying CORS is Nginx's job. Verified by walking brace depth 128→600; the two `ApiAuthMiddleware` groups above close at 155 and 202 | **Unauthenticated catalogue enumeration, product write, product delete, store create and store delete** on every deployment |
| **E2** | `getStores()` is `EcommerceStore::all()` | Every store on the deployment, to anyone |
| **E3** | `saveProduct()` is `firstOrNew(['reference_num' => …])` on a client-supplied reference | Any product overwritable by naming it |
| **E4** | `deleteProduct()` / `deleteStore()` delete by reference with no ownership check | Even after auth, one tenant could delete another's rows |

Detail and remediation: [01](01-site-users-guard.md) §1.1 and §1.6. **P0 ships first and
depends on nothing else in this plan.**

## 2.3 Nothing transacts

This is the finding that reframes the module.

| # | Finding | Consequence |
|---|---|---|
| **E5** | **`laravel_ecommerce_orders` has no writer.** `grep -rn "EcommerceOrder" app/ routes/` returns only the model file itself | The table has been migrated and is dead. No order has ever been recorded |
| **E6** | `commerce.checkout()` POSTs to `/portal/api/v1/processors/checkout` — **that route does not exist** in `routes/portal.php` | The SDK's only transactional method 404s |
| **E7** | The builder's storefront templates render `<button>Add to Cart</button>` with **no `onclick`, no `script`, no handler** (`ecommerceTemplates.ts`) | The buttons are decoration. There is no cart anywhere in the module |
| **E8** | `stores.direct_payment` and `products.direct_payment_item_reference_num` exist and are **read by nothing** | The Direct Payments linkage is columns without a mechanism — see [07](07-direct-payments-integration.md) |

So the module is a **catalogue CRUD with static mockups**. Every document after this one is
building the store the catalogue implies.

## 2.4 Schema defects

| # | Finding | Consequence |
|---|---|---|
| **E9** | `products.price` is `DECIMAL(10,2)` | Every other money column in the codebase is integer minor units (`laravel_processor_charges.amount_cents`, `orders.total_amount_cents`). Mixing the two guarantees a rounding disagreement between what a customer sees and what is charged. **Fix before anything reads it** — [04](04-catalogue.md) §4.2 |
| **E10** | `products.images` is `TEXT` holding `json_encode([...])`, hand-decoded in three places | No ordering, no alt text, no dimensions, no primary flag, and a JSON blob that cannot be queried or validated |
| **E11** | `category1 … category5` — five nullable string columns | A product has at most five categories, they are unordered, they are strings rather than references, and "products in category X" is a five-column `OR`. `laravel_ecommerce_categories` exists but nothing joins to it |
| **E12** | `products.reference_num` is `nullable()` and **not unique**; same for `categories.reference_num` | The public identifier can be null or duplicated. **E3** is a direct consequence |
| **E13** | No `status` on products | No draft, no archive. A product is live the moment it is saved, and deleting is the only way to unpublish |
| **E14** | No timestamps beyond `created_at`/`updated_at`; no `deleted_at` | Deleting a product referenced by a historical order destroys the order's meaning |

## 2.5 Missing commerce capability

Not defects — absences. Listed because the plan's scope is defined by them.

| # | Absent | Where it lands |
|---|---|---|
| **E15** | **Variants.** No size/colour, no per-variant price, SKU or stock. A product is one indivisible thing | [04](04-catalogue.md) §4.4 |
| **E16** | **Inventory.** No stock level, no reservation, no oversell protection, no backorder | [04](04-catalogue.md) §4.6 |
| **E17** | **Tax and shipping.** No rates, no zones, no classes. `total_amount_cents` is the product price and nothing else | [05](05-pricing-tax-shipping.md) |
| **E18** | **Discounts and promotions.** None | [09](09-promotions.md) |
| **E19** | **Orders have no owner.** No `site_user_id`; the only identity is `customer_email`, free text | "My orders" is impossible, order history cannot survive a device change, and a returning shopper cannot be shown their own purchases — [01](01-site-users-guard.md) §1.4 |

Also absent, and covered in the documents named: order line items and fulfilment
([08](08-orders-and-fulfilment.md)), customer addresses ([01](01-site-users-guard.md)),
order notifications ([08](08-orders-and-fulfilment.md) §8.7), product collections and SEO
([04](04-catalogue.md)), price lists ([05](05-pricing-tax-shipping.md) §5.3).

## 2.6 What is genuinely good

Worth stating, because the plan builds on all of it:

- **The Direct Payments API is the right payment engine and already does the hard parts.**
  `Invoice` with `addItem({ item_ref, quantity, seller_ref })`, `lock()`, `getSubtotal()`,
  `getTaxTotal()`, `getTotal()`, `hasSplit()` — that is a cart's data model, already built and
  already provider-agnostic ([06](06-cart-and-checkout.md) §6.3).
- **The processor layer already handles tax** — `POST /portal/api/v1/processors/tax` exists
  and is used by signup ([05](05-pricing-tax-shipping.md) §5.5).
- **`stores.direct_payment` is the right idea**, unfinished — a store *should* name the
  Direct Payment it charges through ([07](07-direct-payments-integration.md) §7.2).
- **The builder templates are good markup.** They need behaviour, not redesign
  ([10](10-storefront-and-sdk.md) §10.4).
- **`website_domain` / `website_reference_num`** are already on stores and orders, so
  multi-site scoping was anticipated.

## 2.7 Summary

| Group | Findings | Phase |
|---|---|---|
| Security | **E1**–**E4** | **P0** — ships immediately, no dependencies |
| Schema correctness | **E9**–**E14** | P1 — before anything reads the data |
| Nothing transacts | **E5**–**E8** | P3–P5 — cart, Direct Payments, orders |
| Missing capability | **E15**–**E19** | P2, P6–P7, P9 |

The order matters: **fix the money column before building a cart on it**, and fix the
identifiers before building foreign keys to them.
