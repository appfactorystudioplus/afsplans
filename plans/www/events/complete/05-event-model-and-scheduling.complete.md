# 05 — Event model and scheduling (complete)

**Plan:** `05-event-model-and-scheduling.md`  
**Rollout phase:** P2  
**Repo:** `www`  
**Date:** 2026-08-31

## Shipped

- Occurrence-as-registration-target (`EventOccurrenceMaterialisationService` on publish)
- `recurrence_rule` JSON on `laravel_events` + `EventRecurrenceService` (daily/weekly/monthly/yearly horizon materialisation)
- UTC storage via Carbon timezone parse in materialisation
- `laravel_event_sessions`, `laravel_venues` tables + models

## Deferred / partial

- Full RRULE editor UI, BYDAY ordinal monthly, DST edge-case authoring UI (§5.5 cases 2–3)
- Session registration flow and clash detection (§5.4)
- Series edit modes (this / following / all) with divergence protection UI

## Files

- `app/Externals/Events/Services/EventRecurrenceService.php`
- `app/Externals/Events/Services/EventOccurrenceMaterialisationService.php`
- `database/migrations/2026_08_31_210000_create_events_extended_tables.php`

## Verification

Publish an event with `recurrence_rule` JSON; confirm multiple `laravel_event_occurrences` rows.
