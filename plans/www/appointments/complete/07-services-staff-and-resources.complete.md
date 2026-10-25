# 07 — Services, staff and resources (complete)

**Plan:** `07-services-staff-and-resources.md`  
**Repo:** `www`  
**Date:** 2026-08-31  
**Status:** Staff + services shipped; resource conflict loop partial.

## Shipped

- `laravel_appointment_services`, `laravel_appointment_staff`, `service_staff` pivot
- `AppointmentStaffService` — `any`, `specific`, `least_booked` assignment at book time
- Implicit staff on appointment create (`ensureImplicitStaff`)
- Admin staff CRUD routes
- Public staff list (name only)
- Service fields: `staff_mode`, `location_mode`, cancel/reschedule windows

## Deferred

- Resource assignment in booking transaction (§7.5)
- `round_robin` mode
- Multi-location (§7.8)
- Class fixed-schedule kind (§7.6)

## Files

- `app/Externals/Appointments/Services/AppointmentStaffService.php`
- `app/Models/Externals/AppointmentStaff.php`
- Admin routes: `GET/POST …/staff`
