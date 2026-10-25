# Ecommerce implementation — completion summary

**Status:** Core P1–P10 implemented (2026-08-31). Promotions (09), tax/shipping (05), builder wiring, and fulfilment UI remain partial.

## What shipped

### P1 — Schema (`www/database/migrations/2026_04_03_133338_create_laravel_ecommerce_tables.php`)
- Stores, products (`price_cents`, variants), carts, cart lines, stock reservations, orders, order lines, order events
- Fresh-install guarded creates only; existing old tables are **not** auto-upgraded — run `migrate:fresh` in `www` on dev if old ecommerce schema exists

### P2–P4 — Catalogue
- Models: `EcommerceProduct`, `EcommerceVariant`, `EcommerceStore`, `EcommerceCategory`
- `EcommerceVariantService` — default variant on product save
- Admin product save maps `price` (dollars) → `price_cents`, stamps slug/status
- Storefront: active products only, product detail with variants

### P6 — Cart & checkout
- `EcommerceCartService`, `EcommerceCheckoutService`, `EcommerceCartController`
- Server-side cart with httpOnly `ec_cart_token` cookie
- Routes under `/portal/api/ecommerce/storefront/cart/*` and `/checkout/*`
- Checkout creates locked `DirectPaymentInvoice`; confirm verifies `paid`/`succeeded` and creates order idempotently

### P8 — Orders
- `EcommerceOrderService`, order lines + events
- Admin: `GET /orders`, `GET /orders/{ref}`, `PATCH /orders/{ref}/internal-note`
- Admin UI: `commerce-orders.ts` (list + detail + internal note)

### P10 — SDK
- `resources/js/external/commerce/api.ts` — `Portal.commerce.create(storeRef)` instance API
- Legacy `getProducts` / `getCategories` retained; broken `checkout()` removed
- `portal.d.ts` updated in `www` + 7 consumer copies

### P0 (prior) — Security
- Unchanged: admin auth + site scope; public storefront reads

## Not fully implemented (defer)

| Plan area | Gap |
|-----------|-----|
| 05 tax/shipping | Totals are subtotal-only; no zones/methods |
| 09 promotions | No discount codes |
| 07 DP item sync | Manual `direct_payment_item_reference_num` only |
| 08 fulfilment | No admin fulfilment actions; `fulfilments` table in migration but no UI |
| 10 builder | Templates not wired to SDK |
| 01 site users | `site_user_id` column exists; cart merge / my orders not wired |
| 11 admin | No overview dashboard, variant grid, collections, settings screen (store PATCH exists) |

## Verification

```bash
cd www && php artisan test --filter=Ecommerce
```

Real checkout requires a payable store (Direct Payment configured), active products with default variants, and processor payment flow — not run in CI here.

## Files touched (primary)

- `app/Externals/Ecommerce/**` — services + support
- `app/Http/Controllers/Externals/Ecommerce/*`
- `app/Http/Controllers/Externals/Ecommerce/EcommerceController.php`
- `app/Models/Ecommerce*.php`
- `routes/portal.php`, `bootstrap/app.php`
- `resources/js/external/commerce/api.ts`, `portal.d.ts`
- `resources/js/portal/admin/controllers/ecommerce/*`
- `tests/Feature/Ecommerce/*`
