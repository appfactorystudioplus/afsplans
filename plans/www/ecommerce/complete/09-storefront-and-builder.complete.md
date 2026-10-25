# 09 — Storefront & builder (complete)

**Plan:** `plans/www/ecommerce/10-storefront-and-sdk.md`  
**Rollout phase:** P8 — storefront  
**Repo:** `www`  
**Date:** 2026-08-31

## What was built

### SDK additions (§10.2)

| Method | Purpose |
|---|---|
| `collections()` | Stub — returns `[]` until catalogue §4.7 collections ship |
| `variantFor(productRef, options)` | Match variant by option_values map |
| `mountPayment(selector, opts)` | Wraps Direct Payments for checkout components |

`portal.d.ts` + 7 consumer copies updated.

### Builder components (§10.4)

Seven GrapesJS blocks under **Commerce** category:

- Product Grid · Product Detail · Cart · Cart Badge · Checkout · Order Confirmation · My Orders

Each uses `commerceComponentScript` — reads `data-commerce-kind`, `data-store-ref`, optional `data-dp-ref` / `data-product-slug`. Editor shows placeholder; published pages call `Portal.commerce.create()`.

Files:
- `builder/core/component_scripts/commerce_component_script.ts`
- `builder/core/component_scripts/portal_helpers.ts`
- `builder/core/commerce_components.ts`
- Registered from `builder/core/imp.ts`

### Playground

Interactive examples under `public/playground/externals/ecommerce/` (hub + per-topic pages with dedicated CSS).

## Acceptance notes

- Cart badge updates via `cart:updated` on published pages ✓
- Product detail disables OOS variants in select ✓
- Editor makes no storefront API calls (placeholder only) ✓
- `checkout()` legacy method remains removed ✓

## Deferred

- Full option-picker matrix (disable invalid combinations before select)
- `collections()` backend + collection-filtered grid
- Listing cache with customer-group key (§10.6)
- Refactor page templates in `ecommerceTemplates.ts` to embed component types
- Shared `portal_helpers` on published pages without in-script duplication (currently inlined in commerce script for GrapesJS export)

## Verification

Publish a page with Commerce blocks + store ref; ensure `/external/api/sdk.js` is on the page template.
