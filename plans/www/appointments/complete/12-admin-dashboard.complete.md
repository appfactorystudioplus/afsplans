# 12 — Admin dashboard (complete)

**Plan:** `12-admin-dashboard.md`  
**Repo:** `www`  
**Date:** 2026-08-31 (updated)  
**Status:** Calendar + day sheet UI shipped; drag-reschedule deferred.

## Shipped

| View | Implementation |
|---|---|
| **Calendar (week grid)** | `appointments-calendar` view — bookings + busy blocks |
| **Day sheet** | Same view — printable table per date/staff |
| **Calendar sync panel** | Connect ICS feed, list connections, sync all |
| Overview API | `/overview` |
| Staff admin | `/staff` CRUD |
| Booking lifecycle actions | confirm/reject |
| Configure / preview / response | existing |

## Files

- `resources/js/portal/admin/html/views/appointments-calendar.html`
- `resources/js/portal/admin/controllers/appointments/appointments-calendar.ts`
- `resources/js/portal/admin/views/appointmentsView.ts` — `appointments-calendar` route
- Admin API: `/calendar`, `/day-sheet`, `/calendar-connections`

## Navigation

Appointments list → **Calendar** dropdown action per appointment.

## Deferred

- Drag-reschedule on calendar
- Staff-column multi-practitioner layout
- Full §12.9 controller tree rewrite
- Schedule weekly grid editor

## Verification

Open portal → Appointments → Calendar on a published appointment with bookings.
