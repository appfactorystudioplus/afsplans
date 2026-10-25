# 04 — Direct Payments integration (complete)

**Plan:** `plans/www/ecommerce/07-direct-payments-integration.md`  
**Repo:** `www`  
**Date:** 2026-08-31

## What was built

### Store → Direct Payment validation

- Primary `direct_payment` validated on create/update:
  - `EC_E_DP_NOT_FOUND` — missing or wrong tenant (`website_reference_num`)
  - `EC_E_DP_WRONG_TYPE` — must be `SINGLE_PAY` or `CONNECT` (not `SUBSCRIPTION`)
  - `EC_E_DP_PROCESSOR_INACTIVE`
  - `EC_E_CURRENCY_MISMATCH` — when processor `extra_config.currency` is set
- Subscription DP validated separately (`SUBSCRIPTION` only) on update
- Clearing primary DP with active products returns warning `EC_W_DP_CLEARED_WITH_ACTIVE_PRODUCTS` but saves

### Item mapping modes

- `dp_item_mode`: `explicit` (default) | `dynamic`
- Migration default changed from `dynamic` to `explicit`
- **Explicit:** checkout requires mapped DP item; invoice uses DP item price as charge source
- **Dynamic:** checkout requires a `DYNAMIC` item on the store DP; passes `amount` on invoice lines; bounds checked (`EC_E_DYNAMIC_AMOUNT_*`)

### Cart and checkout

- `EC_E_MIXED_CART` when adding subscription product to one-off cart (or vice versa)
- `EC_E_ITEM_UNMAPPED` at checkout begin in explicit mode
- `EC_E_STORE_NOT_PAYABLE` unchanged — catalogue stores browse but cannot check out

### Admin API

| Route | Purpose |
|---|---|
| `GET /portal/api/ecommerce/store/{ref}/payment-diagnostics` | Payable flag, mode, mismatches |
| `POST /portal/api/ecommerce/product/create-dp-item` | One-click DP item creation for unmapped variant |

### Admin UI

- Store form: optional DP (catalogue mode), filtered SINGLE_PAY/CONNECT dropdown, `dp_item_mode` select, PATCH on edit
- Store list: payable / catalogue-only badge
- Store dashboard + products: payment mapping banners; bulk “create DP items” for unmapped variants

### Service

`App\Externals\Ecommerce\Services\EcommerceDirectPaymentService` — validation, mapping resolution, diagnostics, auto-provision helper.

## Deferred (per plan)

- §7.6 Marketplace / vendor splits (`CONNECT` + `seller_ref`)
- §7.7 Saved cards / site-users customer link at checkout
- §7.8 Refunds UI and restock prompts
- Full subscription checkout routing to `subscription_direct_payment` (cart guard only; subscription payment surface not wired)

## Tests

- `tests/Unit/Ecommerce/EcommerceDirectPaymentServiceTest.php` — mixed cart, mapping precedence, explicit default, unmapped diagnosis

## Notes

- Processor has no first-class `currency` column; mismatch check uses `extra_config.currency` when present.
- Existing DBs with `dp_item_mode = dynamic` retain that value until an operator changes it in store settings.
