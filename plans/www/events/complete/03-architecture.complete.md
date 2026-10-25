# 03 — Architecture (complete — P1 scope)

**Plan:** `03-architecture.md`  
**Rollout phase:** P1  
**Repo:** `www`  
**Date:** 2026-08-31  
**Status:** Partial — layers established; money and communications boundaries not wired.

## Implemented

- **Occurrence as registration target** — registrations reference `occurrence_reference_num` + `ticket_type_reference_num` (§3.2 principle)
- **Admin vs public split** — separate controllers; public projection excludes raw counters where serializer applies `availabilityState()`
- **Site Users boundary** — events reads `site_user_id` from gateway only
- **Reference generation** — `App\Externals\Events\Support\EventReference` (server-side)

## Not implemented

- Direct Payments as order invoice (§3.3) — P5
- Forms module attachment for custom questions (§3.4 / D4) — P4
- Scanner / offline manifest layer (§3.5) — P9

## Files

- `app/Externals/Events/Support/EventReference.php`
- `app/Externals/Events/Services/EventCapacityService.php`
- `app/Externals/Events/Services/EventOccurrenceMaterialisationService.php`

## Follow-up

- P5 payment boundary
- P4 forms attachment on ticket types
