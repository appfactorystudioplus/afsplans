# P3 — Pricing, tax, and shipping (plan 05)

**Status:** Implemented (2026-08-31). Price lists (§5.3) and scheduled sales (§5.4) deferred.

## What shipped

### Schema (`2026_08_31_120000_create_ecommerce_tax_and_shipping_tables.php`)
- Store: `tax_mode`, `processor_tax_enabled`
- Cart: `shipping_method_id`
- Variant: `weight_grams`
- Tables: `laravel_ecommerce_tax_zones`, `tax_rates`, `shipping_zones`, `shipping_methods`, `shipping_rates`

### Services
- `EcommercePricingService` — single unit-price resolver (variant → product)
- `EcommerceTaxService` — local zone/rate tables; optional processor tax via `/portal/api/v1/processors/tax` (fails checkout when enabled and unavailable)
- `EcommerceShippingService` — zone matching, flat/free/pickup/price/weight bands, digital-only carts skip shipping
- `EcommerceCartTotalsService` — subtotal + shipping + tax → cart total

### Integration
- Cart serialization includes real `shipping_cents`, `tax_cents`, `total_cents`
- Checkout `begin` validates shipping, computes totals strictly for tax, adds shipping line to DP invoice metadata
- Orders snapshot subtotal/shipping/tax from invoice metadata

### Storefront routes
- `GET /portal/api/ecommerce/storefront/cart/shipping-methods`
- `POST /portal/api/ecommerce/storefront/cart/shipping-method`

### Admin routes (auth + site scope)
- `GET/POST /portal/api/ecommerce/settings/tax-zones`
- `GET/POST /portal/api/ecommerce/settings/shipping-zones`
- Store PATCH accepts `tax_mode`, `processor_tax_enabled`

### SDK
- `shop.shippingMethods()`, `shop.setShippingMethod(id)`
- Cart type includes `shipping_method_id`, `requires_shipping`

## Behaviour when nothing is configured

Stores with no tax zones and no shipping zones behave exactly as before: zero tax, zero shipping, total = subtotal.

## Deferred

- Price lists and customer groups (§5.3)
- Scheduled sales (§5.4)
- Tax-inclusive mode calculations (§5.5 — `tax_mode` stored, exclusive math used today)
- Admin settings UI (API only; configure via POST or future settings screen)

## Verification

```bash
cd www && php artisan migrate --force
cd www && php artisan test --filter=Ecommerce
```
