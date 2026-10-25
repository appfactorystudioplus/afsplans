# 09 — Payments and policies (complete)

**Plan:** `09-payments-and-policies.md`  
**Repo:** `www`  
**Date:** 2026-08-31 (updated)  
**Status:** Live DP checkout wired for bookings.

## Shipped

| Item | Location |
|---|---|
| Invoice creation on paid book | `AppointmentPaymentService::createInvoiceForBooking` |
| Claim-before-pay ordering | booking row + invoice in same transaction |
| Storefront payment fields on book response | `invoice_ref`, `direct_payment_ref`, `amount_cents`, `requires_payment` |
| SDK payment mount | `ExternalAppointmentsAPI.beginPayment()` + `confirmPayment()` |
| Post-pay confirm | `POST …/bookings/{ref}/confirm-payment` |
| Reconcile paid invoices | `appointments:reconcile-payments` → `confirmPaid()` |
| Playground paid flow | `playground/externals/appointments/book/` |

## Flow

1. `book()` claims slot, creates booking `pending`, creates DP invoice if `chargeCents > 0`
2. Client calls `beginPayment()` → `setupSinglePayPayment` → `confirmPayment()` (Portal SDK)
3. Client calls `appointments.confirmPayment(bookingRef, token)` OR cron `appointments:reconcile-payments`

## Deferred

- Package/credits purchase (§9.6)
- Operator-initiated no-show/late fees (§9.5)
- Abandoned-payment expiry job (release slot)

## Verification

```bash
php artisan appointments:reconcile-payments --dry-run
```

Priced service requires `direct_payment_reference_num` on the appointment.
