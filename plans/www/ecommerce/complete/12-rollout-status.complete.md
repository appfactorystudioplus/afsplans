# 12 — Rollout status (2026-08-31)

**Plan:** `plans/www/ecommerce/12-rollout-and-testing.md`  
**Repo:** `www`

## Phase checklist

| Phase | Status | Complete doc |
|---|---|---|
| P0 Security | ✓ | `01-site-users-guard.complete.md` (route split) |
| P1 Money/identifiers | ✓ | `02-core-implementation.complete.md` |
| P2 Catalogue core | ✓ partial | variants exist; collections/media deferred |
| P3 Tax/shipping | ✓ | `03-pricing-tax-shipping.complete.md` |
| P4 Cart | ✓ | in `02` / `06` |
| P5 DP + checkout | ✓ | `04-direct-payments-integration.complete.md` |
| P6 Orders | ✓ | `06-orders-and-fulfilment.complete.md` |
| P7 Promotions | ✓ | `05-promotions.complete.md` |
| P8 Storefront/builder | ✓ partial | `09-storefront-and-builder.complete.md` |
| P9 Site users | ✓ | `07-site-users-integration.complete.md` |
| P10 Admin | ✓ partial | `08-admin-dashboard.complete.md` |

## Automated tests

```bash
cd www && php artisan test --filter=Ecommerce
cd www && php artisan test tests/Unit/Database/MigrationOwnershipTest.php
```

## Manual verification still recommended

- End-to-end checkout on `.test` with Laragon + local Parse (`build.sh --local`)
- Builder: drag Commerce blocks, publish, add to cart on live page
- Admin: create promotion, tax zone, shipping zone, fulfil an order
- Settings route: `#/ecommerce/settings?store_reference_num=…` from store dashboard

## Out of scope / future

- Collections schema (plan §4.7)
- Product variant grid editor (plan §11.5)
- Gift card checkout
- Site Users middleware (auth repo)
- Members-only store guard (plan P4)
- Money ledger refunds from order admin
- Customer-group pricing cache
