# 08 — Orders and Fulfilment

> **REVISED (2026-09-06) — see [`AUDIT-direct-payments-decoupling.md`](AUDIT-direct-payments-decoupling.md) §P3.**
> The order carries **no money and no payment status**. It stores three references —
> `direct_payment_reference_num`, `processor_reference_num`, `direct_payment_invoice_ref` —
> and fulfilment/domain data. Amount, tax, refunds, paid/refunded status, per-line prices
> all resolve live from the Direct Payments invoice through
> `App\Externals\DirectPayments\Contracts\PaymentGateway` (`serializeOrder()` does this; the storefront
> client uses `commerce.purchase(invoiceRef)`). The `*_cents` / `payment_status` columns
> still exist as transitional mirrors and a later migration drops them. §8.2's idempotency
> unique index on `direct_payment_invoice_ref` stays. The refund join (§8.5) resolves
> through the invoice ref. Fulfilment re-checks `isPaid()` before a shipment.

## 8.1 What exists

`laravel_ecommerce_orders` — one flat table, **never written by anything** (**E5**):

```
id · reference_num · store_reference_num · status · total_amount_cents · currency
customer_email · items_snapshot JSON · processor_reference_num
website_domain · website_reference_num · timestamps
```

The shape is a reasonable start and is missing everything an order needs to be operated:
line items as rows, addresses, tax and shipping breakdown, a lifecycle, fulfilment, and any
record of what happened to it.

## 8.2 The order

`laravel_ecommerce_orders` gains:

| Column | Type | Notes |
|---|---|---|
| `order_number` | `string(32)` unique | **Human-facing** — `#1001`, sequential per store. `reference_num` stays the opaque internal id |
| `site_user_id` | null index | [01](01-site-users-guard.md) §1.4 |
| `cart_id` | null | Provenance |
| `direct_payment_invoice_ref` | `string(64)` **unique** | The idempotency key ([06](06-cart-and-checkout.md) §6.5) |
| `direct_payment_charge_id` | `string(255)` null index | Joins the Money ledger ([direct_payments/29](../direct_payments/29-money-and-refunds.md)) |
| `status` | `string(32)` | §8.4 |
| `payment_status` | `string(32)` | `pending` \| `paid` \| `partially_refunded` \| `refunded` \| `failed` |
| `fulfilment_status` | `string(32)` | `unfulfilled` \| `partial` \| `fulfilled` |
| `subtotal_cents` | | Lines before discount |
| `discount_cents` | | |
| `shipping_cents` | | |
| `tax_cents` | | |
| `total_cents` | | Charged |
| `refunded_cents` | default 0 | Derived; see below |
| `currency` | | Stamped at creation, never re-derived |
| `customer_email` / `customer_phone` | | |
| `billing_address` / `shipping_address` | json | **Snapshots**, not references (§8.3) |
| `shipping_method_name` / `shipping_carrier` | | Snapshots |
| `discount_code` | null | |
| `customer_note` | text null | What the shopper typed at checkout |
| `internal_note` | text null | Staff only. **Never returned to the storefront** |
| `cancelled_at` / `cancel_reason` | | |
| `placed_at` | | |
| `timestamps` | | |

```php
$table->unique('direct_payment_invoice_ref', 'ec_orders_invoice_uq');
$table->unique(['store_reference_num', 'order_number'], 'ec_orders_number_uq');
```

**Three status columns, not one.** A real order is paid-but-unfulfilled, or
fulfilled-then-refunded, or partially both. One column forces invented combinations like
`paid_partially_fulfilled_refunded` and cannot express the fourth case anyone thinks of.

`refunded_cents` is **derived** from the refund records, not maintained independently — the
same rule as [direct_payments/29](../direct_payments/29-money-and-refunds.md) §15.2. Cached on the row for
list queries, recomputed on every refund event, never written by hand.

## 8.3 Line items — rows, and snapshots

`items_snapshot` JSON becomes **`laravel_ecommerce_order_lines`**:

| Column | Notes |
|---|---|
| `order_id` index | |
| `variant_id` null | Nullable — the variant may be deleted later |
| `product_id` null | |
| **`product_name`** | **Snapshot** |
| **`variant_title`** | **Snapshot** — `Medium / Black` |
| **`sku`** | **Snapshot** |
| `unit_price_cents` | **Snapshot** |
| `quantity` | |
| `subtotal_cents` / `discount_cents` / `tax_cents` / `total_cents` | |
| `tax_rate` | The rate applied, for the audit |
| `custom_attributes` json | Engraving text, gift note |
| `vendor_id` null | [07](07-direct-payments-integration.md) §7.6 |
| `direct_payment_item_reference_num` | What was actually charged |
| `fulfilled_quantity` default 0 | §8.5 |
| `refunded_quantity` default 0 | |

**The snapshot columns are the point.** An order says what was bought, at what price, under
what name — *at the time it was bought*. Renaming a product or changing its price must not
rewrite history, and a shopper disputing a charge is disputing what they saw, not what the
catalogue says today.

`variant_id` is kept alongside for reporting ("best-selling variant"), nullable so a purge
cannot orphan the order. Rows, not JSON, so "how many of SKU X did we sell in March" is a
query rather than a full-table JSON scan.

## 8.4 The lifecycle

```
        ┌─────────┐   payment confirmed   ┌──────┐
        │ pending │──────────────────────▶│ paid │
        └────┬────┘                       └───┬──┘
             │ payment failed / abandoned     │ fulfilment
             ▼                                ▼
        ┌───────────┐                   ┌───────────┐
        │ cancelled │◀──────────────────│ fulfilled │
        └───────────┘   cancel+refund   └─────┬─────┘
                                              │ all lines refunded
                                              ▼
                                        ┌──────────┐
                                        │ refunded │
                                        └──────────┘
```

| Transition | Effect |
|---|---|
| → `paid` | Reservations consumed, **stock decremented**, confirmation email, digital assets released |
| → `fulfilled` | Per-line ([08](08-orders-and-fulfilment.md) §8.5); shipping notification |
| → `cancelled` | **Stock restored** if it was decremented; refund is a **separate** operator action |
| → `refunded` | Set by the refund record, not directly |

**Cancelling does not refund.** They are separate operator decisions — cancelling an unpaid
order is routine, and coupling them means a mis-click moves money. The admin offers both
together where it makes sense, with the refund unchecked by default.

**`laravel_ecommerce_order_events`** — append-only: `order_id`, `event`, `actor`
(`system` | `customer` | `admin:{id}`), `payload` json, `created_at`.

`placed`, `paid`, `payment_failed`, `fulfilment_created`, `shipped`, `delivered`,
`cancelled`, `refunded`, `note_added`, `email_sent`.

This is the "what happened to this order" a support conversation needs, and it is the first
thing anyone asks for and the last thing anyone builds.

## 8.5 Fulfilment

Real orders ship in pieces — two items now, one when it arrives.

**`laravel_ecommerce_fulfilments`** — `order_id`, `reference_num`, `status`
(`pending` | `shipped` | `delivered` | `cancelled`), `carrier`, `tracking_number`,
`tracking_url`, `shipped_at`, `delivered_at`, `notify_customer`.

**`laravel_ecommerce_fulfilment_lines`** — `fulfilment_id`, `order_line_id`, `quantity`.

So one order → many fulfilments → each covering some quantity of some lines.

`fulfilment_status` on the order is **derived**: nothing fulfilled → `unfulfilled`; some →
`partial`; every line's `fulfilled_quantity` equal to `quantity` → `fulfilled`. Derived, not
set by hand, so it cannot contradict the lines.

`tracking_url` is built from carrier + number by a small per-carrier map, with a free-text
fallback — a tracking number nobody can click is a support ticket.

Digital lines are auto-fulfilled on payment ([04](04-catalogue.md) §4.8) and excluded from
shipping fulfilments.

## 8.6 The reconciler — money taken, no order

The one unacceptable outcome ([03](03-architecture.md) §3.7): payment confirmed, order write
failed.

`php artisan ecommerce:reconcile-orders` finds Direct Payment invoices that are **paid** with
no matching order, and creates the order from the invoice — which carries the lines, the
quantities and the total.

Scheduled every 15 minutes, and it logs loudly. **A non-zero count is an incident**, not
routine cleanup: it means the confirm path failed after the money moved, and the cause should
be found rather than papered over by a job that quietly fixes it forever.

The `unique(direct_payment_invoice_ref)` index (§8.2) makes the reconciler safe to run
against orders that already exist.

## 8.7 Notifications

Through the existing mail handler system — the same mechanism as
[direct_payments/24](../direct_payments/24-notification-scheduler.md) §6.6, not a second email path.

| Event | Default |
|---|---|
| Order confirmation | **On** |
| Payment failed | On |
| Shipped (per fulfilment) | On |
| Delivered | Off |
| Cancelled | On |
| Refunded | On |
| Abandoned cart | **Off** ([06](06-cart-and-checkout.md) §6.6) |
| Low stock *(to the operator)* | On |

Per store, each choosing a `laravel_mail_handlers` row from the same dropdown the billing plan
uses ([direct_payments/23](../direct_payments/23-notification-config.md) §5.5). Every send is written to a
ledger with the same uniqueness discipline — an order confirmation sent twice is a support
call.

Reusing that machinery rather than building a second one is worth the coupling: it is already
solved, including the provider-token and callback bugs the billing audit found
([direct_payments/20](../direct_payments/20-current-state-audit.md) §2.3).

## 8.8 Acceptance

- An order is created **only** after the charge is verified server-side
  ([06](06-cart-and-checkout.md) §6.4).
- Double-confirm creates **one** order — enforced by the unique index, under concurrency.
- Order lines carry name, variant title, SKU and price as **snapshots**; renaming the product
  afterwards does not change the order (§8.3).
- Deleting a product leaves its historical orders fully readable.
- Partial fulfilment sets `fulfilment_status = partial`; fulfilling the rest sets `fulfilled`
  — derived, never written directly.
- Cancelling restores stock; cancelling does **not** refund (§8.4).
- A refund in the Money ledger updates `refunded_cents` and `payment_status`, and appears in
  the order events.
- `ecommerce:reconcile-orders` creates a missing order from a paid invoice and is a **no-op**
  on the second run.
- `order_number` is sequential per store and unique; two stores may both have `#1001`.
- `internal_note` never appears in any storefront response — asserted.
- An order confirmation is sent once, even if `confirm` is retried.
