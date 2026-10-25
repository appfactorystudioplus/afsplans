# 01 — Site Users Guard — Complete

## Status

Partial — **P0 / P0b shipped** (security gate and query scoping). Site Users attribution (P1+) not started.

## Summary

Closed findings **E1–E4** from the audit:

- Split admin writes behind `ApiAuthMiddleware` + `ecommerce.site.scope`
- Added public storefront reads at `/portal/api/ecommerce/storefront/*`
- Kept deprecated unauthenticated GET aliases on legacy paths for published pages
- Scoped `getStores()` and all save/delete paths to the caller's owned sites
- Stamped `website_domain` / `website_reference_num` on store create (reuses `FormSiteResolver`)
- Wired portal admin fetches with `credentials: 'include'` and `initEcommerceData()` hydration
- Moved external SDK reads to the storefront prefix; updated `portal.d.ts`

## Files changed

**Backend**

- `app/Http/Middleware/EcommerceSiteScope.php`
- `app/Http/Controllers/Externals/Ecommerce/EcommerceController.php`
- `app/Http/Controllers/Externals/Concerns/EcommerceAuthorizesSites.php`
- `app/Http/Controllers/Externals/Ecommerce/EcommerceStorefrontController.php`
- `routes/portal.php`
- `bootstrap/app.php`

**Admin UI**

- `resources/js/portal/admin/controllers/ecommerce/ecommerceApi.ts`
- `resources/js/portal/admin/controllers/ecommerce/commerce-store.ts`
- `resources/js/portal/admin/controllers/ecommerce/commerce-products.ts`
- `resources/js/portal/admin/controllers/ecommerce/commerce-categories.ts`
- `resources/js/portal/admin/views/ecommerceView.ts`
- `resources/js/portal/admin/store/DataStore.ts`

**Externals**

- `resources/js/external/commerce/api.ts`
- `resources/js/external/portal.d.ts`

**Tests**

- `tests/Feature/Ecommerce/EcommerceSecurityRouteTest.php`

## Verification

```bash
cd www
php artisan test --filter=Ecommerce
```

Manual:

1. Unauthenticated `DELETE /portal/api/ecommerce/store/{ref}` → **401**
2. Logged-in portal admin can list/create stores for the current site
3. Published page can still `GET /portal/api/ecommerce/products?store=…` (deprecated alias)
4. `window.Portal.commerce.getProducts({ store: '…' })` hits `/portal/api/ecommerce/storefront/products`

## Follow-up

- P1: `site_user_id` on orders, checkout attribution ([01-site-users-guard.md](../01-site-users-guard.md) §1.4)
- P1 schema: `price_cents`, unique refs ([04-catalogue.md](../04-catalogue.md))
- P3+: cart, Direct Payments invoice seam, orders ([06](../06-cart-and-checkout.md), [08](../08-orders-and-fulfilment.md))
- Copy updated `portal.d.ts` commerce section to consumer apps' `checkpointHelper/static/portal.d.ts`
- Backfill `website_reference_num` on legacy store rows without scoping columns
