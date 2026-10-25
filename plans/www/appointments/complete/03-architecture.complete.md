# 03 — Architecture (complete)

**Plan:** `03-architecture.md`  
**Repo:** `www`  
**Date:** 2026-08-31  
**Status:** Layer split implemented in code layout; not all layers fully built yet.

## Layers as implemented today

| Layer | Plan § | Code location | Status |
|---|---|---|---|
| Public / SDK | 11 | `AppointmentStorefrontController`, `external/appointments/api.ts`, playground | Partial — SDK + API; widget export not rewritten |
| Availability | 05 | `AppointmentAvailabilityService` | Partial — schedules + bookings + holds; no exceptions/busy/staff loop |
| Booking engine | 06 | `AppointmentBookingService`, `AppointmentCapacityService`, `AppointmentHoldService` | Partial — single book, hold, capacity claim |
| Direct Payments | 09 | — | Not started |
| Booking lifecycle | 08 | cancel on storefront | Partial |
| Effects (mail/sync) | 10 | — | Not started |
| Resources | 07 | models stub only | Not started |

## Key decisions (from 00 §0.5) reflected in code

- **D1** Bulk replace deleted — `AppointmentsController` has no `whereNotIn` delete paths
- **D2** Availability computed — `AppointmentAvailabilityService::availability()`; not materialised
- **D3** DB-backed claim — `active_slot_key` + `laravel_appointment_slot_counters`
- **D4** Staff/resources first-class in schema — tables planned in 04; staff table exists, resources deferred
- **D5** Calendar sync — `laravel_appointment_busy_blocks` not created yet (step 10)

## Vocabulary split (§3.3)

- `laravel_appointment_services` replaces slot-as-service
- `laravel_appointment_schedules` holds weekly hours (migrated from legacy slot fields)
- Legacy `laravel_appointment_slots` retained read-only; not written by new admin paths

## Next step

**Step 04** — finish schema per plan (exceptions, resources, pivots, activity, …), document in `04-database-schema.complete.md`.
