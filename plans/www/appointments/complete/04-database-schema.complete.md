# 04 — Database schema (complete)

**Plan:** `04-database-schema.md`  
**Rollout phase:** P1  
**Repo:** `www`  
**Date:** 2026-08-31  
**Status:** Core tables shipped; series/waitlist/credits deferred to steps 08–09.

## Shipped

| Table | Migration |
|---|---|
| `laravel_appointments` (extended) | `2026_08_31_230000_create_appointments_extended_schema.php` |
| `laravel_appointment_services` | same |
| `laravel_appointment_schedules` | same |
| `laravel_appointment_staff` | same |
| `laravel_appointment_holds` | same |
| `laravel_appointment_slot_counters` | same |
| `laravel_appointment_bookings` (extended) | same |
| `laravel_appointment_exceptions` | `2026_08_31_231000_create_appointments_step04_tables.php` |
| `laravel_appointment_resources` | same |
| `laravel_appointment_service_staff` (pivot) | same |
| `laravel_appointment_busy_blocks` | same |
| `laravel_appointment_activity` | same |

Legacy `laravel_appointment_slots` retained; data copied to services on migrate.

## Not shipped (later steps)

- `laravel_appointment_series`, `laravel_appointment_waitlist` (08)
- `laravel_appointment_credits` (09)
- `service_resource` pivot (07)
- Full §4.12 production migration runbook (14)

## Models

- `Appointment`, `AppointmentService`, `AppointmentSchedule`, `AppointmentStaff` (via staff table), `AppointmentHold`, `AppointmentBooking`, `AppointmentException`

## Verification

```bash
cd www && php artisan migrate --force
```

## Next step

**Step 05** — wire exceptions into availability (started), add admin exception CRUD, document in `05-availability-and-scheduling.complete.md`.
