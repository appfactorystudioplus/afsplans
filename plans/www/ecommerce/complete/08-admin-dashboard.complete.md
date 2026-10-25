# 08 — Admin dashboard (partial complete)

**Plan:** `plans/www/ecommerce/11-admin-dashboard.md`  
**Rollout phase:** P10 — admin  
**Repo:** `www`  
**Date:** 2026-08-31

## What was built

### Store overview (§11.3)

**API:** `GET /portal/api/ecommerce/store/{ref}/overview`

Returns:
- Revenue today / 7d / 30d
- Units sold (30d)
- Orders awaiting fulfilment (paid + unfulfilled/partial)
- Failed payment count
- Low stock variants (track inventory, qty ≤ 5)
- Setup warnings: no Direct Payment, no shipping zones, no tax zones, price mismatches

**UI:** Overview card on store dashboard (`commerce-store-dashboard.html` + `commerce-store.ts`) with refresh and link to orders.

### Promotions admin (§11.7)

**UI:** `commerce-promotions.html` + `commerce-promotions.ts`

- List promotions with usage count and status
- Create/edit form: percentage, fixed amount, free shipping, buy-X-get-Y
- Plain-language preview (plan §11.7)
- Delete promotion
- Route: `ecommerce:promotions` subview

Store dashboard adds **Manage Promotions** card.

### Tax & shipping settings (§11.8)

**UI:** `commerce-settings.html` + `commerce-settings.ts`

- Lists tax and shipping zones
- Create basic tax zone (country + rate %)
- Create shipping zone (countries + flat rate method)
- Route: `ecommerce:settings`

## Already existed (prior phases)

- Orders list + detail + fulfilment + cancel (`commerce-orders.ts`)
- Products, categories, store settings (incl. site_users_mode, DP)
- Payment diagnostics banner on dashboard

## Deferred

- Full product editor tabs (variants grid, media, inventory) — §11.5
- Collections admin
- Customers screen (Site Users) — §11.6
- Gift cards UI — §11.7
- Order timeline panel, refund into Money ledger
- Bulk product actions

## Verification

```bash
cd www && php artisan test --filter=Ecommerce
```

Manual: open store dashboard → overview loads; Promotions → create code → storefront `applyDiscount`.

## Files

- `app/Http/Controllers/Externals/Ecommerce/EcommerceController.php` — `getStoreOverview()`
- `routes/portal.php` — overview route
- `resources/js/portal/admin/html/views/commerce-store-dashboard.html`
- `resources/js/portal/admin/html/views/commerce-promotions.html`
- `resources/js/portal/admin/controllers/ecommerce/commerce-store.ts`
- `resources/js/portal/admin/controllers/ecommerce/commerce-promotions.ts`
- `resources/js/portal/admin/views/ecommerceView.ts`
- `resources/js/portal/admin/utils/templates.ts`
