# 05 — Pricing, Tax and Shipping

The three things that turn a product price into a total a customer pays (**E17**).

## 5.1 What exists

Nothing. `orders.total_amount_cents` is the product price and nothing else — no tax, no
shipping, no discount. The processor layer *does* have a tax endpoint
(`POST /portal/api/v1/processors/tax`, used by signup), which is the one piece not being built
from scratch.

## 5.2 Price resolution — one function, one order

Every price a shopper sees comes from one resolver, in one order. Scattering this is how a
listing page and a cart show different numbers for the same item.

```
1  variant.price_cents          (null → product.price_cents)      04 §4.4
2  price list override          for the customer's group          §5.3
3  scheduled sale               active now                        §5.4
4  → unit price
   ─────────────────────────────────────────────
5  × quantity                   → line subtotal
6  − line discount              09 §9.4
7  + tax                        §5.5
   ─────────────────────────────────────────────
   cart: + shipping, + order-level discount, + order tax
```

**Lowest wins between 2 and 3** — a wholesale customer during a sale gets the better of the
two, not the sale price on top of the wholesale price. Stacking them is how a 40 % wholesale
rate plus a 30 % sale sells below cost.

`compare_at_price_cents` is display only ([04](04-catalogue.md) §4.3) and never enters a
calculation.

## 5.3 Price lists and customer groups

The wholesale case, and the reason the store-level auth mode is per store
([01](01-site-users-guard.md) §1.5.2).

**`laravel_ecommerce_customer_groups`** — `reference_num`, `store_reference_num`, `name`,
`is_default`.

**`laravel_ecommerce_price_lists`** — `reference_num`, `store_reference_num`,
`customer_group_id`, `name`, `type` (`fixed` | `percentage_off`), `is_active`,
`starts_at`/`ends_at`.

**`laravel_ecommerce_price_list_entries`** — `price_list_id`, `variant_id`, `price_cents`
(fixed) or `percent_off`, `min_quantity` (volume breaks).

Group membership hangs off Site Users: `site_user_id` → group. **A guest is always the default
group**, which is what makes wholesale pricing require an account without any extra mechanism
— the shopper must sign in to be in the wholesale group.

`min_quantity` gives volume pricing — "10+ at $8 each" — resolved as the highest qualifying
break at line-pricing time.

## 5.4 Scheduled sales

`laravel_ecommerce_sales` — `name`, `type` (`percentage` | `fixed_amount` | `fixed_price`),
`value`, `starts_at`, `ends_at`, `scope` (`store` | `collection` | `category` | `products`),
`scope_ids` json.

Evaluated at price-resolution time against the clock, not by writing prices into products.
A sale that mutates `price_cents` cannot end cleanly and loses the original.

Sales are **automatic** — no code needed. Codes are [09](09-promotions.md).

## 5.5 Tax

### Classes and zones

**`laravel_ecommerce_tax_classes`** — `standard`, `reduced`, `zero`, `digital`. A product
names one ([04](04-catalogue.md) §4.3).

**`laravel_ecommerce_tax_zones`** — `country_code`, `state_code` null, `postcode_pattern`
null, `priority`.

**`laravel_ecommerce_tax_rates`** — `tax_zone_id`, `tax_class`, `rate_percent`, `name`
(`VAT`, `GST`, `Sales Tax`), `compound`, `applies_to_shipping`.

Most-specific zone wins: postcode → state → country.

### Two modes

| Mode | Behaviour |
|---|---|
| `exclusive` **(default)** | Prices exclude tax; it is added at checkout. US convention |
| `inclusive` | Prices include tax; it is extracted for reporting. EU/UK convention |

Per store, and **it changes what `price_cents` means** — so switching it on a live store with
orders must warn hard, because every displayed price silently changes meaning.

### The processor tax endpoint

`POST /portal/api/v1/processors/tax` already exists. Where a store enables it, it is the
authority and the local tables are the fallback for when it is unreachable —
**except that "unreachable" fails the checkout** ([03](03-architecture.md) §3.7) rather than
falling back to a possibly-wrong local rate. Falling back silently is how a shop under-collects
for a month.

Tax is calculated once, at `checkout/begin`, against the shipping address, and **snapshotted
onto the order lines** with the rate ([08](08-orders-and-fulfilment.md) §8.3). A rate change
must never alter a historical order.

## 5.6 Shipping

**`laravel_ecommerce_shipping_zones`** — `store_reference_num`, `name`, `countries` json,
`states` json null, `postcodes` json null, `priority`.

**`laravel_ecommerce_shipping_methods`** — `shipping_zone_id`, `name`
(`Standard`, `Express`), `type`, `carrier`, `is_active`, `position`,
`min_delivery_days`/`max_delivery_days`.

| `type` | Rate from |
|---|---|
| `flat` | `rate_cents` |
| `weight` | Bands over the cart's total `weight_grams` ([04](04-catalogue.md) §4.4) |
| `price` | Bands over the cart subtotal |
| `free` | Zero, optionally above `free_over_cents` |
| `pickup` | Zero, no address required |

**`laravel_ecommerce_shipping_rates`** — `shipping_method_id`, `min_value`, `max_value`,
`rate_cents`. `min`/`max` are grams or cents depending on the method type.

### Rules that matter

- **A cart of only digital or service products skips shipping entirely** — no method, no
  address, no rate. Mixed carts ship the physical portion.
- **No matching zone → checkout is refused** with `EC_E_NO_SHIPPING_TO_ADDRESS`. Not a zero
  rate: shipping free to somewhere you do not ship is worse than saying so.
- Free-shipping thresholds are evaluated **after** discounts, so a code cannot drop a cart
  below the threshold while keeping free shipping.
- `applies_to_shipping` on a tax rate decides whether shipping is taxed — it varies by
  jurisdiction and getting it wrong is a compliance issue, not a rounding one.

Live carrier rates are out of scope ([03](03-architecture.md) §3.8) — they are a per-tenant
credential problem.

## 5.7 Migrations

| File | Contents |
|---|---|
| `..._create_ecommerce_customer_groups_table.php` | §5.3 |
| `..._create_ecommerce_price_lists_tables.php` | §5.3 |
| `..._create_ecommerce_sales_table.php` | §5.4 |
| `..._create_ecommerce_tax_tables.php` | §5.5 |
| `..._create_ecommerce_shipping_tables.php` | §5.6 |
| `..._alter_ecommerce_stores_add_tax_mode.php` | §5.5 |

All additive. **A store with nothing configured charges no tax and no shipping** — exactly
today's behaviour, so the phase is invisible until a tenant configures something.

## 5.8 Acceptance

- One resolver produces the price on the listing, the product page and the cart — asserted by
  comparing all three for the same variant (§5.2).
- A wholesale customer during a sale gets the **better** of the two, never both (§5.2).
- A guest is always in the default group; wholesale pricing requires signing in (§5.3).
- A volume break at 10+ applies at 10, not at 9.
- A sale ends on its own at `ends_at` with no write, and the original price returns (§5.4).
- Tax is snapshotted with its rate; changing the rate does not alter a historical order.
- Tax service unreachable → checkout **fails**; no fallback rate, no zeroed tax (§5.5).
- `inclusive` and `exclusive` on the same catalogue produce the same **charged** total for
  the same displayed price — verified in both modes.
- A digital-only cart requires no shipping address and shows no method (§5.6).
- An address in no zone → `EC_E_NO_SHIPPING_TO_ADDRESS`, not free shipping.
- A discount that drops the cart below a free-shipping threshold **removes** free shipping.
- A store with no tax or shipping configured behaves exactly as before the phase.
