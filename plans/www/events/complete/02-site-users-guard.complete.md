# 02 — Registration and guarding (complete — P1 scope)

**Plan:** `02-site-users-guard.md`  
**Rollout phase:** P1 (API split, registration model core)  
**Repo:** `www`  
**Date:** 2026-08-31  
**Status:** P1–P11 core shipped; see rollout doc `14-rollout-and-testing.complete.md`.

## Implemented (P1)

| Plan § | What shipped |
|---|---|
| §2.1 | `laravel_event_registrations` + orders; occurrence + ticket type refs; snapshots; `access_token`, `ticket_code` |
| §2.2 (partial) | **Admin** `/portal/api/v1/events/*` with `ApiAuthMiddleware` + `events.site.scope` |
| §2.2 (partial) | **Public read + register** `/portal/api/events/storefront/*` (throttled) — path differs from plan’s `/api/events/public/*` (www uses `/portal` prefix like commerce) |
| §2.3 | `SiteUsersGateway` sets `site_user_id` on register; never from body |
| §2.4 | Attendee name/email stored on registration row |
| §2.5 (partial) | `access_token` on registration; no attendee lookup routes yet |
| §2.6 (partial) | Atomic claim via `EventCapacityService` — holds/waitlist deferred to P3/P4 |

## Not implemented (later phases)

- `/api/events/attendee/*` — `myRegistrations()`, cancel, transfer (P4/P11)
- `/api/events/register/*` hold/order/pay split (P3/P5)
- Waitlist, approval, one-email-per-occurrence unique index
- Full `site_users_mode` enforcement on register

## Files

- `app/Http/Controllers/Externals/Events/EventController.php`
- `app/Http/Controllers/Externals/Events/EventStorefrontController.php`
- `app/Externals/Events/Services/EventRegistrationService.php`
- `app/Http/Middleware/EventsPublicThrottle.php`, `EventsSiteScope.php`
- `routes/portal.php` (events groups)

## Verification

```bash
cd www && php artisan test --filter=EventsRouteTest
```

## Follow-up

- P3 holds before paid checkout
- P4 attendee self-service routes + SDK
- P11 my-events when Site Users P8d complete
