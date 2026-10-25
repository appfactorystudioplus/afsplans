# 12 — Admin dashboard (complete)

**Plan:** `12-admin-dashboard.md`  
**Rollout phase:** P6  
**Repo:** `www`

## Shipped

- Admin API CRUD + registrations list + report + export
- Promo admin API: `GET/POST/DELETE /portal/api/v1/events/promos*`
- `events.ts` loads/saves/deletes via `/portal/api/v1/events`
- Detail/create/edit fetch event from `GET /events/{ref}` before localStorage fallback
- Server-generated references on create

## Deferred

- Full removal of `DataStore` cache for list view
- Promo admin UI (API only)
- Recurrence builder UI, session agenda editor

## Verification

Portal admin → Events: list loads from API; delete persists after reload.
