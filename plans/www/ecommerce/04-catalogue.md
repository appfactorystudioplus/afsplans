# 04 — The Catalogue

Products, variants, options, media, inventory and collections.

## 4.1 What changes

| Today | Becomes |
|---|---|
| `price DECIMAL(10,2)` | `price_cents INTEGER` (**E9**) — §4.2 |
| `images TEXT` (JSON blob) | `laravel_ecommerce_product_media` (**E10**) — §4.5 |
| `category1 … category5` | `laravel_ecommerce_product_categories` (**E11**) — §4.7 |
| `reference_num` nullable, non-unique | Unique, non-null (**E12**) — §4.3 |
| *(no status)* | `status: draft \| active \| archived` (**E13**) — §4.3 |
| *(no variants)* | `laravel_ecommerce_variants` (**E15**) — §4.4 |
| *(no stock)* | Inventory on the variant (**E16**) — §4.6 |

## 4.2 Money first (**E9**)

`products.price DECIMAL(10,2)` → `price_cents INTEGER`, and **this migration runs before
anything else in the plan reads a price**.

```php
// AFS_ECOMMERCE_MODIFIER_ADDITION — plans/www/ecommerce/04-catalogue.md §4.2
//
// DECIMAL → integer minor units. Every other money column in this codebase is
// already integer (laravel_processor_charges.amount_cents,
// laravel_ecommerce_orders.total_amount_cents), and mixing the two guarantees a
// rounding disagreement between the price a customer sees and the amount the
// processor charges.
//
// Converted with round(), not cast: (int)(19.99 * 100) is 1998 on IEEE-754
// doubles. That is not a hypothetical — it is the single most common way this
// migration is written wrong.
$table->unsignedInteger('price_cents')->default(0);
// UPDATE … SET price_cents = ROUND(price * 100)
```

The `round()` note is load-bearing. `(int)(19.99 * 100)` evaluates to **1998**, silently
under-charging by a cent on a large fraction of a catalogue.

`price` is dropped only after the backfill is verified — a `SELECT` proving
`ROUND(price*100) = price_cents` for every row, with zero exceptions, in the same migration.

## 4.3 `laravel_ecommerce_products` — reshaped

| Column | Type | Notes |
|---|---|---|
| `id` | `id` | |
| `reference_num` | `string(32)` **unique, not null** | `prd_` + 12. Fixes **E12**, and with it **E3** |
| `store_reference_num` | `string(32)` index, not null | |
| `name` | `string(255)` | |
| `slug` | `string(255)` | URL segment. Unique per store |
| `description` | `text` null | |
| `short_description` | `string(500)` null | Listing/card copy — the long one is for the product page |
| `status` | `string(20)` default `draft` | `draft` \| `active` \| `archived` (**E13**) |
| `product_type` | `string(20)` default `physical` | `physical` \| `digital` \| `service` \| `subscription`. Drives shipping ([05](05-pricing-tax-shipping.md) §5.6) and DP mapping ([07](07-direct-payments-integration.md) §7.5) |
| `price_cents` | `unsignedInteger` | The default; a variant may override (§4.4) |
| `compare_at_price_cents` | `unsignedInteger` null | Strikethrough "was" price |
| `cost_cents` | `unsignedInteger` null | For margin reporting. **Never exposed on the storefront** |
| `tax_class` | `string(32)` null | [05](05-pricing-tax-shipping.md) §5.5 |
| `direct_payment_item_reference_num` | `string(64)` null | [07](07-direct-payments-integration.md) §7.4 |
| `seo_title` / `seo_description` | `string` null | |
| `sort_order` | `integer` default 0 | |
| `published_at` | `timestamp` null | Scheduled publishing |
| `deleted_at` | `timestamp` null | **Soft delete** (**E14**) |
| `timestamps` | | |

```php
$table->unique(['store_reference_num', 'slug'], 'ec_products_store_slug_uq');
$table->index(['store_reference_num', 'status', 'published_at'], 'ec_products_listing_idx');
```

**Soft delete matters more than it looks.** An order's line items reference the product that
was bought; hard-deleting it destroys the meaning of every historical order containing it
(**E14**). Line items also snapshot name and price ([08](08-orders-and-fulfilment.md) §8.3),
so the order survives even a purge — belt and braces, because order history is the thing a
customer disputes.

`status = archived` is what "delete" means in the admin. Actual deletion is a separate,
confirmed action that refuses when orders reference the product.

## 4.4 Variants (**E15**)

The largest gap. A product today is one indivisible thing — no size, no colour, no per-option
price or stock.

### The model

```
Product  "Classic T-Shirt"
  Options:  Size [S, M, L]   Colour [Black, White]
  Variants: 6 = S/Black · S/White · M/Black · M/White · L/Black · L/White
              each with its own SKU, price, stock, image, DP item
```

**`laravel_ecommerce_product_options`** — the axes:

| Column | Notes |
|---|---|
| `product_id` index | |
| `name` | `Size` |
| `position` | Display order |

**`laravel_ecommerce_product_option_values`** — the choices:

| Column | Notes |
|---|---|
| `option_id` index | |
| `value` | `Medium` |
| `position` | |

**`laravel_ecommerce_variants`** — what is actually bought:

| Column | Type | Notes |
|---|---|---|
| `reference_num` | `string(32)` unique | `var_` + 12 |
| `product_id` | index | |
| `sku` | `string(64)` null | Unique per store when present |
| `barcode` | `string(64)` null | |
| `option_values` | `json` | `{"Size":"Medium","Colour":"Black"}` — denormalised for display and matching |
| `price_cents` | `unsignedInteger` null | **Null = inherit the product's** |
| `compare_at_price_cents` | `unsignedInteger` null | |
| `cost_cents` | `unsignedInteger` null | |
| `weight_grams` | `unsignedInteger` null | Shipping ([05](05-pricing-tax-shipping.md) §5.6) |
| `stock_quantity` | `integer` default 0 | §4.6 |
| `stock_policy` | `string(20)` default `deny` | `deny` \| `continue` (backorder) |
| `track_inventory` | `boolean` default `true` | |
| `media_id` | null | The variant's own image |
| `direct_payment_item_reference_num` | `string(64)` null | Overrides the product's ([07](07-direct-payments-integration.md) §7.4) |
| `position` | | |
| `deleted_at` | | |

```php
$table->unique(['product_id', 'option_values'], 'ec_variants_combo_uq');
```

### Every product has at least one variant

**Including products with no options.** A product with no options gets one *default variant*,
created automatically and hidden in the admin UI.

That is the decision that keeps the rest of the system simple: **the cart, inventory, orders
and DP items all reference a variant, never a product.** Without it, every one of those has
two code paths — "product with variants" and "product without" — and the second is the one
that gets the bugs.

`price_cents` on the variant is nullable and inherits: a shop where only the XL costs more
sets one variant's price and leaves five null, rather than restating the same number six
times and having them drift.

## 4.5 Media (**E10**)

`images TEXT` holding `json_encode([...])`, hand-decoded in three places, becomes
**`laravel_ecommerce_product_media`**:

| Column | Notes |
|---|---|
| `product_id` index | |
| `variant_id` null | Variant-specific imagery |
| `url` | |
| `alt_text` | **Accessibility, and it is not optional in a serious storefront** |
| `type` | `image` \| `video` |
| `width` / `height` | Lets the storefront reserve space and avoid layout shift |
| `position` | Explicit ordering — the JSON array had implicit ordering nobody could change |
| `is_primary` | The listing thumbnail |

Migration walks the existing JSON and creates one row per entry, `position` from array index,
`is_primary` on the first. `alt_text` starts null and the admin nags for it.

## 4.6 Inventory (**E16**)

Stock lives on the **variant**. Three concepts, and conflating them is how a shop oversells:

| Concept | Meaning |
|---|---|
| `stock_quantity` | Physically on hand |
| **Reserved** | In someone's in-flight checkout |
| **Available** | `stock_quantity − reserved` — what a shopper may add |

**`laravel_ecommerce_stock_reservations`**:

| Column | Notes |
|---|---|
| `variant_id` index | |
| `cart_id` index | |
| `quantity` | |
| `expires_at` index | **15 minutes**, refreshed on cart activity |

```
add to cart      → no reservation (browsing is not buying)
begin checkout   → reserve, 15 min
payment confirmed→ reservation consumed, stock_quantity decremented
abandoned/expired→ swept, stock returns
```

**Reserving at add-to-cart rather than at checkout is the common mistake.** A shopper who
adds an item and wanders off would hold the last unit for as long as their session lives, and
a busy shop would show sold-out to everyone while selling nothing.

The sweep is a scheduled command; expiry is also checked lazily on read, so an unswept
reservation never blocks a sale.

Decrement happens **once, on payment confirmation**, inside the same transaction that creates
the order ([08](08-orders-and-fulfilment.md) §8.2). `stock_policy = continue` allows the
variant to go negative — that is what backorder means, and the negative number is the
backlog.

Low-stock threshold per variant drives an admin alert. Not a notification system of its own —
it reuses the mail handler path ([08](08-orders-and-fulfilment.md) §8.7).

## 4.7 Categories and collections (**E11**)

`category1 … category5` become a join table:

**`laravel_ecommerce_product_categories`** — `product_id`, `category_id`, `position`,
`unique(product_id, category_id)`.

And `laravel_ecommerce_categories` gains what a real category needs: `parent_id` (nesting),
`slug`, `description`, `image_url`, `seo_title`, `seo_description`, `position`,
`is_visible`.

**Collections** are the separate concept most catalogues eventually need — "Summer Sale",
"New Arrivals", "Under $50":

**`laravel_ecommerce_collections`** — `reference_num`, `store_reference_num`, `name`, `slug`,
`type` (`manual` | `automatic`), `rules` json, `sort_order`, `is_visible`.

| Type | Membership |
|---|---|
| `manual` | An explicit join table, hand-curated |
| `automatic` | Evaluated from `rules` — `price_cents < 5000`, `tag = summer`, `created_at > …` |

Categories are **taxonomy** (where a product lives); collections are **merchandising** (how it
is grouped for a campaign). Trying to serve both with one concept is why so many catalogues
end up with a "Sale" category that is also a navigation entry.

Automatic collections are evaluated on write and cached, not on every storefront read — a
rule engine in the hot path is how a listing page gets slow.

Plus **tags**: `laravel_ecommerce_product_tags`, free-form, the input most automatic rules
actually key on.

## 4.8 Digital products

`product_type = digital` unlocks:

**`laravel_ecommerce_digital_assets`** — `variant_id`, `file_path`, `file_size_bytes`,
`download_limit`, `expires_after_days`.

Delivered by a signed, expiring URL after payment confirmation — **never a direct file path**,
and never before the charge is confirmed. Download counts are recorded per order line, so
"they say it never arrived" is answerable.

Digital variants set `track_inventory = false` and are excluded from shipping
([05](05-pricing-tax-shipping.md) §5.6).

## 4.9 Migrations

| File | Contents |
|---|---|
| `..._alter_ecommerce_products_price_cents.php` | §4.2 — **first**, with the verification |
| `..._alter_ecommerce_products_add_fields.php` | §4.3 |
| `..._create_ecommerce_product_options_tables.php` | §4.4 |
| `..._create_ecommerce_variants_table.php` | §4.4 + default-variant backfill |
| `..._create_ecommerce_product_media_table.php` | §4.5 + JSON walk |
| `..._create_ecommerce_stock_reservations_table.php` | §4.6 |
| `..._create_ecommerce_product_categories_table.php` | §4.7 + `category1..5` backfill |
| `..._alter_ecommerce_categories_add_fields.php` | §4.7 |
| `..._create_ecommerce_collections_tables.php` | §4.7 |
| `..._create_ecommerce_product_tags_table.php` | §4.7 |
| `..._create_ecommerce_digital_assets_table.php` | §4.8 |

The `category1..5` backfill matches on **name** within the store, creating categories that do
not exist. Unmatched values are reported rather than dropped — they are the tenant's data and
a silent loss is not the migration's call.

## 4.10 Acceptance

- `ROUND(price * 100) = price_cents` for **every** row, asserted in the migration (**E9**).
- A product saved at `19.99` charges **1999**, not 1998 (§4.2).
- Every product has ≥ 1 variant, including options-less ones (§4.4).
- A variant with null `price_cents` sells at the product's price; setting it overrides.
- Two variants cannot share an option combination (`ec_variants_combo_uq`).
- Adding to cart does **not** reserve stock; beginning checkout does (§4.6).
- An expired reservation does not block a sale, swept or not.
- Buying the last unit takes stock to 0 and the next add-to-cart is refused with the
  available quantity.
- `stock_policy = continue` permits negative stock.
- Archiving a product removes it from the storefront and leaves historical orders intact.
- Deleting a product referenced by an order is **refused**.
- A category with 3000 products lists in one indexed query, not a five-column `OR` (**E11**).
- An automatic collection re-evaluates on product write, not on storefront read.
- A digital purchase yields a signed expiring URL, and never before payment confirmation.
