# 05 — Availability and scheduling (complete)

**Plan:** `05-availability-and-scheduling.md`  
**Repo:** `www`  
**Date:** 2026-08-31  
**Status:** Server-side availability exists; many §5.1 conditions still unchecked.

## Implemented

| Item | Location | Notes |
|---|---|---|
| `availability()` function | `AppointmentAvailabilityService` | Schedules + bookings + holds + closed exceptions |
| Lead / horizon | same | Uses `min_lead_minutes`, `booking_horizon_days` on service |
| Active service filter | storefront controller | Inactive services rejected |
| Public API | `GET …/storefront/availability` | Returns computed slots |
| Holds subtracted | availability service | Active, unexpired holds block slots |

## Not implemented (deferred)

| §5.1 # | Gap |
|---|---|
| 3 | Busy blocks not subtracted |
| 5 | Buffer-after not applied in server loop |
| 6–7 | Staff / resource loops (§7.4–7.5) |
| 8 | Capacity > 1 uses counter at book time only; availability shows binary |
| 13 | Staff daily cap |
| §5.2 | Cache + `inputs_version` |
| §5.4 | `custom_hours` / `extra_hours` exceptions — only `closed` handled |
| DST edge tests | — |

## Audit items

- **A12** — partial (server-side, not full algorithm)
- **A13** — partial (buffer_before on service; after-buffer incomplete)
- **A32–A35** — scheduling half partial

## Next step

**Step 06** — booking engine: concurrency tests, reconcile command, document in `06-booking-engine.complete.md`.
