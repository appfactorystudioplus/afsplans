# 14 — Rollout and testing (complete)

**Plan:** `14-rollout-and-testing.md`  
**Repo:** `www` + `packages/afsserver`  
**Date:** 2026-08-31  
**Status:** P0–P6 foundation shipped; P7–P12 partial; §14.3/§14.5 not fully met.

## Phases delivered

| Phase | Status |
|---|---|
| P0 Security | **Done** — routes split, bulk replace gone, auth + scope |
| P1 Correctness | **Done** — schema, unique index, counters |
| P2 Availability | **Partial** — computed projection; cache/tests incomplete |
| P3 Staff/resources | **Partial** — staff assignment; resources incomplete |
| P4 Lifecycle | **Partial** — cancel/reschedule/approval; series UI incomplete |
| P5 Communications | **Partial** — log confirmation + ICS; no reminder jobs |
| P6 Public surface | **Done** — SDK, playground, widget repair |
| P7 Admin | **Partial** — calendar/day sheet/sync UI; drag-reschedule not built |
| P8 Money | **Done** — invoice + SDK checkout + reconcile |
| P9 Calendar sync | **Done** — ICS import/outbound; OAuth deferred |
| P10 Waitlist/recurring | **Partial** — waitlist table + promote; recurring UI not built |
| P11 Reporting | **Partial** — summary/export API |
| P12 Accounts | **Partial** — site_user_id on bookings, myBookings |

## Security gates (§14.2)

| Gate | Status |
|---|---|
| No bulk replace routes | ✅ tested |
| No `whereNotIn` delete | ✅ grep test |
| Public no PII | ✅ structural (availability not bookings) |
| SDK no admin write | ✅ |
| Server references | ✅ |

## Tests in repo

```
tests/Feature/Appointments/AppointmentsRouteTest.php      (3)
tests/Feature/Appointments/AppointmentsSecurityTest.php (1)
tests/Unit/Appointments/AppointmentCapacityServiceTest.php (2)
```

**6 tests pass.** Concurrency suite, e2e journeys, cache equivalence — **not run**.

## Commands

```bash
php artisan appointments:reconcile-counters [--fix]
php artisan appointments:expire-holds
php artisan appointments:complete-past-bookings
php artisan appointments:reconcile-payments [--dry-run]
php artisan appointments:sync-calendars [--staff=ref]
```

## Completion doc set

Steps **01–14** all have `complete/` documents. Deferred items are listed honestly in each.

## Follow-ups (TODOS.md)

See `packages/afsserver/plans/TODOS.md` → **www/appointments** for deferred work: calendar sync, full admin calendar, DP checkout, concurrency CI, builder blocks, utilisation reporting.
