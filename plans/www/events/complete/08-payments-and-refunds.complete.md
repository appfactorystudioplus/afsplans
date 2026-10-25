# 08 — Payments and refunds (complete)

**Plan:** `08-payments-and-refunds.md`  
**Rollout phase:** P5  
**Repo:** `www`

## Shipped

- `EventPaymentService` — creates `DirectPaymentInvoice` for pending orders
- `events:reconcile-payments` — confirms orders when invoice paid
- Integer cents on orders/registrations throughout

## Superseded — façade orders (2026-09-08)

The "integer cents on orders/registrations" line above no longer holds. Event
orders were decoupled from Direct Payments the same way carts were
(`w/www` `0aa2b4dfd`): see [`00_extras.md` E2](00_extras.md#e2--events--direct-payments-decoupling-facade-orders).

- `laravel_event_orders` money columns (`subtotal_cents` … `refunded_cents`) and
  `laravel_event_registrations.amount_cents` / `refunded_cents` are **dropped**
  (migration `2026_09_09_000000_events_facade_payment_refs`).
- The order keeps three references — `direct_payment_reference_num`,
  `processor_reference_num`, `direct_payment_invoice_ref` — and resolves every
  figure from the invoice through `App\Externals\DirectPayments\Contracts\PaymentGateway`.
- New `EventOrderService::finalizeFromSnapshot()` is the confirm write-path;
  `EventStorefrontController::confirmPayment()` gates on `PaymentGateway::isPaid()`
  (closes the webhook-lag hole `confirmPaid()` had).
- `events:reconcile-payments` rewritten: `{--minutes=15} {--dry-run}`.

## Deferred

- Refund flows, fee modes, tax lines, cancellation policy automation
- Season passes (`laravel_event_passes` schema only — P12)

## Verification

`php artisan events:reconcile-payments --dry-run`
