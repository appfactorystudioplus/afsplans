# 02 — Guarding appointments with Site Users (complete)

**Plan:** `02-site-users-guard.md`  
**Rollout phase:** P0 (security gate)  
**Repo:** `www`  
**Date:** 2026-08-31  
**Status:** P0 security gate shipped for this step's scope. Site Users self-service and modes deferred to step 08 / P12.

## Implemented

| Plan § | What shipped |
|---|---|
| §2.2 split API | **Admin** `/portal/api/v1/appointments/*` — `ApiAuthMiddleware` + `appointments.site.scope` |
| §2.2 | **Storefront** `/portal/api/appointments/storefront/*` — throttled, no auth |
| §2.2 | Bulk-replace endpoints **deleted** (not authenticated) — closes A1, A2 |
| §2.2 | `POST /storefront/book` creates **one** booking |
| §2.3 | `site_user_id` on bookings; set via `SiteUsersGateway`, never from body |
| §2.5 | `access_token` on new bookings; lookup by token or owner on manage routes |
| §2.6 (partial) | `site_users_mode` column on appointments — enforcement not wired |

## Not implemented (later steps)

- Full `site_users_mode` required/off enforcement on book (08 / P12)
- Reschedule route (08)
- Attendee routes at plan's exact paths (`/api/appointments/manage/*` vs `/portal/api/appointments/storefront/*`)

## Files

- `app/Http/Middleware/AppointmentsSiteScope.php`
- `app/Http/Middleware/AppointmentsPublicThrottle.php`
- `app/Http/Controllers/Externals/Concerns/AppointmentsAuthorizesSites.php`
- `app/Http/Controllers/Externals/Appointments/AppointmentsController.php`
- `app/Http/Controllers/Externals/Appointments/AppointmentStorefrontController.php`
- `routes/portal.php`
- `bootstrap/app.php` — middleware aliases

## Verification

```bash
cd www && php artisan test --filter=AppointmentsRouteTest
# Gate: unauthenticated POST to removed bulk routes → route not registered
```

## Next step

**Step 03** — document architecture in `03-architecture.complete.md`, then implement **step 04** schema gaps before claiming step 05.
