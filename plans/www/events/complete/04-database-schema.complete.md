# 04 — Database schema (complete — P1 core)

**Plan:** `04-database-schema.md`  
**Rollout phase:** P1  
**Repo:** `www`  
**Migration:** `www/database/migrations/2026_08_31_200000_create_events_module_tables.php`  
**Date:** 2026-08-31  
**Status:** Partial — P1 core tables; sessions, venues, holds, tiers, check-ins not migrated.

## Tables created / extended (P1)

| Table | Purpose |
|---|---|
| `laravel_events` | Extended: slug, timezone, currency, site_users_mode, settings, soft deletes, etc. |
| `laravel_event_occurrences` | One row per instance; capacity counters |
| `laravel_event_ticket_types` | Priced/free types per occurrence |
| `laravel_event_orders` | Registration checkout unit |
| `laravel_event_registrations` | Attendee place + snapshots |
| `laravel_event_promo_codes` | Schema only — redemption not implemented |

## Not in migration yet

- Sessions, venues, holds, tier prices, check-ins, waitlist entries (plan §4.6–4.15)

## Models

- `App\Models\Externals\Event`, `EventOccurrence`, `EventTicketType`, `EventOrder`, `EventRegistration`, `EventPromoCode`

## Verification

```bash
cd www && php artisan migrate --force
# Guard test (no DB): MigrationOwnershipTest still owned by www
```

## Follow-up

- P2 recurrence/session/venue tables
- P3 hold + tier tables
