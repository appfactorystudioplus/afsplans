# 08 — Booking lifecycle (complete)

**Plan:** `08-booking-lifecycle.md`  
**Repo:** `www`  
**Date:** 2026-08-31  
**Status:** Cancel, reschedule, approval paths exist; series/recurring partial.

## Shipped

| Transition | Implementation |
|---|---|
| Book → `confirmed` / `pending` | `AppointmentBookingService` |
| Cancel (customer token/owner) | storefront + capacity release + waitlist promote |
| Reschedule (claim-then-release) | `AppointmentLifecycleService` |
| Approve / reject | admin endpoints |
| Complete past bookings | `appointments:complete-past-bookings` |
| Mark no-show | `AppointmentLifecycleService::markNoShow` |
| Waitlist join + promote on cancel | `AppointmentWaitlistService` + tables |
| Series / credits tables | migration `2026_08_31_232000` |

## Deferred

- Custom questions via forms module (§8.3)
- Recurring series creation UX (§8.7)
- Policy fee charging (§8.6) — see step 09
- Customer self-service booking page UI

## Files

- `app/Externals/Appointments/Services/AppointmentLifecycleService.php`
- `app/Externals/Appointments/Services/AppointmentWaitlistService.php`
