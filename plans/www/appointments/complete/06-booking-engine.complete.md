# 06 — Booking engine (complete)

**Plan:** `06-booking-engine.md`  
**Repo:** `www`  
**Date:** 2026-08-31  
**Status:** Core engine shipped; full concurrency suite deferred.

## Shipped

| Item | Location |
|---|---|
| Bulk replace removed | routes + admin controller |
| Single `POST …/storefront/book` | `AppointmentBookingService`, `AppointmentStorefrontController` |
| Capacity claim (unique index + counters) | `AppointmentCapacityService` |
| Holds | `AppointmentHoldService` |
| Idempotency column | migration `2026_08_31_232000`, `idempotency_key` on bookings |
| 409 + alternatives | booking service returns `alternatives` via `nextAvailable()` |
| Activity log on book/cancel | `AppointmentActivityService` |
| Reconcile counters command | `appointments:reconcile-counters` |
| Expire holds command | `appointments:expire-holds` |

## Deferred

- 50-way concurrency CI suite (§14.3)
- Honeypot / origin enforcement
- Full duplicate-policy (`one_active_per_email`)

## Tests

- `tests/Unit/Appointments/AppointmentCapacityServiceTest.php`
- `tests/Feature/Appointments/AppointmentsSecurityTest.php` (no `whereNotIn` delete)

## Verification

```bash
cd www && php artisan test --filter=Appointments
php artisan appointments:reconcile-counters
```
