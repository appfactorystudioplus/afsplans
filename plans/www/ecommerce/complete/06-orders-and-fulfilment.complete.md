# 06 — Orders and fulfilment (complete)

**Plan:** `plans/www/ecommerce/08-orders-and-fulfilment.md`  
**Repo:** `www`  
**Date:** 2026-08-31

## What was built (this pass)

Core order schema, line items, and order creation were already in place from P1/P6. This pass completes **operational fulfilment** and **reconciliation**.

### Fulfilment service

`App\Externals\Ecommerce\Services\EcommerceFulfilmentService`:

- Partial shipments with per-line quantities
- Derived `fulfilment_status` (`unfulfilled` / `partial` / `fulfilled`)
- Carrier tracking URL templates (USPS, UPS, FedEx, DHL) + custom URL override
- Auto-fulfil **digital** and **service** lines on order creation
- **Cancel order** — restores inventory, does **not** refund (separate operator action per plan)

### Models

- `EcommerceFulfilment`, `EcommerceFulfilmentLine`
- Order relations: `fulfilments()`

### Admin API

| Route | Purpose |
|---|---|
| `POST /portal/api/ecommerce/orders/{ref}/fulfilments` | Create shipment |
| `POST /portal/api/ecommerce/orders/{ref}/cancel` | Cancel + optional stock restore |

Order detail responses now include `fulfilments`, `remaining_to_fulfil` per line, cancel fields.

### Admin UI

`commerce-orders.ts` — order detail with:
- Fulfilment progress per line
- Ship form (carrier, tracking, partial qty)
- Shipment list with tracking links
- Cancel order (with reason; no auto-refund)

### Reconciler

`php artisan ecommerce:reconcile-orders [--dry-run]` — creates orders from paid ecommerce invoices missing an order row. Scheduled every 15 minutes in `routes/console.php`. Logs loudly when work is found.

## Deferred

- Refund UI / Money ledger integration (§8.4, direct_payments/29)
- Shipped/delivered notification emails (§8.7 — mail handlers)
- Storefront "my orders" (plan 01 / P9)
- `shipping_method_name` / `shipping_carrier` snapshots on order row

## Tests

- `tests/Unit/Ecommerce/EcommerceFulfilmentServiceTest.php` — tracking URLs, derived fulfilment status

## Error codes

`EC_E_FULFILMENT_LINES_REQUIRED`, `EC_E_FULFILMENT_INVALID`, `EC_E_ORDER_CANCELLED`, `EC_E_ORDER_ALREADY_CANCELLED`
