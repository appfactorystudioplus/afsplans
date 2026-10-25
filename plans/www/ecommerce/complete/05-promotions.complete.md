# 05 — Promotions and discounts (complete)

**Plan:** `plans/www/ecommerce/09-promotions.md`  
**Repo:** `www`  
**Date:** 2026-08-31

## What was built

### Schema

- `laravel_ecommerce_promotions` — code and automatic promotions
- `laravel_ecommerce_promotion_redemptions` — per-order redemption audit
- `laravel_ecommerce_gift_cards` + `laravel_ecommerce_gift_card_transactions` — schema only (checkout deferred)
- `laravel_ecommerce_stores.discount_item_ref` — DP adjustment item for invoice discount lines
- `laravel_ecommerce_carts.applied_promotion_id`

Migration: `2026_08_31_140000_create_ecommerce_promotions_tables.php`

### Promotion engine

`App\Externals\Ecommerce\Services\EcommercePromotionService`:

| Type | Behaviour |
|---|---|
| `percentage` | Off qualifying subtotal |
| `fixed_amount` | Cents off, capped at subtotal |
| `free_shipping` | Shipping zeroed in totals |
| `buy_x_get_y` | Discounts cheapest qualifying units; `max_applications` cap |

Stacking: default best single promotion wins; stackable promotions sum percentages (cap 100%) and fixed amounts. When a entered code loses to a better automatic promo, cart returns a message.

Usage limits enforced at **order creation** via conditional `usage_count` update + redemption row — not at cart apply.

### Cart / checkout / orders

- Totals service evaluates promotions before tax (discounted subtotal)
- Storefront: `POST /cart/discount`, `DELETE /cart/discount`
- Checkout adds negative DP discount line via `ensureDiscountItem()` (auto-creates `EC_DISCOUNT` DYNAMIC item)
- Orders record `discount_code` + `discount_cents`; redemption inserted in order transaction

### Admin API

| Route | Purpose |
|---|---|
| `GET /portal/api/ecommerce/promotions?store=` | List |
| `POST /portal/api/ecommerce/promotion` | Create/update |
| `DELETE /portal/api/ecommerce/promotion/{ref}` | Delete |

First promotion save provisions store discount DP item.

### SDK

- `CommerceInstance.applyDiscount(code)`
- `CommerceInstance.removeDiscount()`
- Cart fields: `promotion_code`, `promotion_messages`, `free_shipping`
- `portal.d.ts` updated (+ consumer copies)

## Deferred

- Gift card checkout as payment method (§9.7) — tables exist, no apply flow
- Admin promotions UI (API only)
- `customer_group_id` restriction
- `collection` applies_to scope

## Tests

- `tests/Unit/Ecommerce/EcommercePromotionServiceTest.php` — floor at zero, stackable %, buy-x-get-y cheapest item

## Error codes

`EC_E_PROMO_INVALID`, `EC_E_PROMO_INACTIVE`, `EC_E_PROMO_EXPIRED`, `EC_E_PROMO_NOT_STARTED`, `EC_E_PROMO_MIN_SUBTOTAL`, `EC_E_PROMO_MIN_QUANTITY`, `EC_E_PROMO_ACCOUNT_REQUIRED`, `EC_E_PROMO_USAGE_EXHAUSTED`, `EC_E_PROMO_CUSTOMER_LIMIT`
