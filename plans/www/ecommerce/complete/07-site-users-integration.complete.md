# 07 — Site Users integration (complete)

**Plan:** `plans/www/ecommerce/01-site-users-guard.md`  
**Repo:** `www`  
**Date:** 2026-08-31

## What was built

### Site user resolution

- `App\SiteUsers\SiteUsersGateway` — reads `site_user` from request attributes only (never request body)
- `config/site_users_consumers.php` — ecommerce registry entry
- `EcommerceShopperAuth` — `off` / `guest` / `required` checkout enforcement

When Site Users P8d ships, its middleware sets `$request->attributes->get('site_user')` and ecommerce works without further changes.

### Cart merge (§6.2)

- `EcommerceCartMergeService` — union lines, sum quantities (stock-clamped), preserve discount/email
- `EcommerceCartService::resolveCartForShopper()` — guest cookie cart merges into signed-in user's cart on next request
- Active cart lookup by `site_user_id` + store

### Checkout attribution

- `site_user_id` written on cart at checkout begin from gateway (never from client body)
- Order inherits `cart.site_user_id` (already on order model)
- `required` mode → `EC_E_AUTH_REQUIRED` (401) from API

### My orders (storefront)

| Route | Auth | Behaviour |
|---|---|---|
| `GET /portal/api/ecommerce/storefront/orders?store=` | Signed in | Lists caller's orders only |
| `GET /portal/api/ecommerce/storefront/orders/{ref}` | Optional | Owned orders require match; guest orders reachable by reference (404 not 403) |

Admin order responses still include `internal_note`; storefront serialisation excludes it (`includeInternal: false`).

### Admin UI

Store form: **Customer accounts** mode select (`off` / `guest` / `required`).

### SDK

- `CommerceInstance.myOrders()`
- `CommerceInstance.order(referenceNum)`
- `portal.d.ts` updated (+ consumer copies)

## Depends on (not in www yet)

- Site Users session + `SiteUsersApiGuard` middleware (site_users plan P2/P8d)
- Storefront route/API guard for members-only shops (P4)
- Saved cards / DP customer link (site_users/08)
- `site_users_id` module select when modules exist

## Tests

- `tests/Unit/Ecommerce/EcommerceShopperAuthTest.php`

## Error codes

`EC_E_AUTH_REQUIRED`

## Default behaviour

`site_users_mode = off` — identical to pre-integration: guest checkout, no attribution, no extra queries on unsigned requests beyond optional merge when attribute is present.
