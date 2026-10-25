# 11 — Public surface and widget (complete)

**Plan:** `11-public-surface-and-widget.md`  
**Repo:** `www`  
**Date:** 2026-08-31  
**Status:** SDK + widget repaired; builder blocks deferred.

## Shipped

| Surface | Status |
|---|---|
| Storefront API | `AppointmentStorefrontController` — no booking PII on public reads |
| SDK `external/appointments/api.ts` | No admin writes; hold/book/cancel/reschedule/nextAvailable |
| Playground | `public/playground/externals/appointments/` |
| Widget | `appointment-export.ts` — requires `apiEndpoint` + `appointmentReferenceNum`; uses availability + book; no localStorage |
| Holds in widget | createHold on time select |

## Deferred

- Preview/widget single shared React component (§11.5)
- Builder blocks (§11.6)
- Hosted `/book/{slug}` pages + schema.org (§11.7)
- `renderBooking()` instance API with `destroy()`

## Audit

- **A3, A6, A26, A27, A28, A29** — addressed structurally
