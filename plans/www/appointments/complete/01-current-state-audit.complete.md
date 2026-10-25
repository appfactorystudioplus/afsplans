# 01 — Current state audit (complete)

**Plan:** `01-current-state-audit.md`  
**Repo:** `www`  
**Date:** 2026-08-31  
**Status:** Baseline recorded; findings tracked below as later steps land. **Do not add `14-rollout-and-testing.complete.md` until steps 02–13 are documented.**

## Finding status (A1–A35)

| Finding | Status | Closed in step |
|---|---|---|
| **A1** Bulk-replace bookings deletes calendar | **Closed** | 02 / 06 — route removed |
| **A2** Bulk-replace slots | **Closed** | 02 / 06 — route removed |
| **A3** Public booking list leaks PII | **Closed** | 02 / 11 — admin-only list; public gets availability projection |
| **A4** Unauthenticated DELETE cascades | **Partial** | 02 — admin DELETE requires auth; soft delete added (04) |
| **A5** No site scoping | **Partial** | 02 — `website_reference_num` written on create, filtered on admin |
| **A6** SDK create/delete on public surface | **Closed** | 11 — removed from SDK |
| **A7** No site_user on booking | **Partial** | 02 — column + set on book |
| **A8** No cancel/reschedule | **Partial** | 08 — cancel only |
| **A9** booking_id as credential | **Partial** | 02 / 04 — `access_token` on new bookings |
| **A10** max_bookings never enforced | **Partial** | 04 / 06 — `capacity` + claim on new bookings |
| **A11** No unique constraint | **Partial** | 04 / 06 — `active_slot_key` unique for capacity-1 |
| **A12** date + time string, no TZ | **Partial** | 04 / 05 — `starts_at` UTC on new bookings |
| **A13** buffer never applied server-side | **Partial** | 05 — split buffers on service; used in availability |
| **A14** Client-supplied appointment ref overwrite | **Closed** | 02 — server generates on create |
| **A15** Fire-and-forget admin saves | **Open** | 12 — DataStore still void-syncs in places |
| **A16** Admin slot save = bulk replace | **Closed** | 06 / 12 — per-service PUT/POST |
| **A17** Preview booking = bulk replace | **Closed** | 06 / 11 — single `POST /storefront/book` |
| **A18–A19** Client-generated ids | **Partial** | 02 / 04 — server refs for appointments/services/bookings |
| **A20–A25** Admin UI defects | **Open** | 12 |
| **A26–A29** Widget broken / leaks bookings | **Open** | 11 — `appointment-export.ts` not rewritten |
| **A30** No confirmation email | **Open** | 10 |
| **A31** Client capacity logic wrong | **Partial** | 05 — server availability with `remaining` |
| **A32–A34** Date/active filtering bugs | **Partial** | 05 / 11 — active services filtered server-side |
| **A35** No staff/resources/exceptions/etc. | **Open** | 07 / 05 / 04 |

## Files reviewed (2026-08-31)

- `routes/portal.php` — old bulk routes removed; admin + storefront groups added
- `app/Http/Controllers/Externals/Appointments/AppointmentsController.php` — rewritten
- `resources/js/external/appointments/api.ts` — public SDK only
- `database/migrations/2026_08_31_230000_create_appointments_extended_schema.php`

## Verification

```bash
cd www && php artisan test --filter=AppointmentsRouteTest
rg "whereNotIn" app/Http/Controllers/Externals/Appointments/AppointmentsController.php  # no matches
```

## Next step

**Step 02** — finish and document `02-site-users-guard.complete.md`. Then **03**, **04**, … in order. Step **14** last.
