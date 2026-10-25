# 01 — Current state audit (complete)

**Plan:** `01-current-state-audit.md`  
**Rollout:** §14.2 pre-P1 verification + baseline for closing findings  
**Repo:** `www`  
**Date:** 2026-08-31  
**Status:** Audit re-run after partial P1 work — findings tracked below; not all V# closed.

## §14.2 verification (required before P1)

| Check | Result | Notes |
|---|---|---|
| **V7** — does `events.ts` throw `ReferenceError` on load? | **Closed** | Admin no longer runs `new ExternalEventsAPI()` at module scope. Uses `eventsAdminFetch` to `/portal/api/v1/events` |
| **V8** — is anything in `localStorage`? | **Open** | `DataStore` still caches events locally after server load. Create/detail still read/write cache. Importer decision deferred until tenant check on `.test` |

## Finding status (V1–V26)

| Finding | Status | Where / follow-up |
|---|---|---|
| **V1** No controller/routes | **Closed** | `EventController`, `EventStorefrontController`, routes in `portal.php` |
| **V2** Legacy PHP SDK URL | **Closed** | `resources/js/external/events/api.ts` uses `/portal/api/events/storefront` |
| **V3** No relational schema | **Partial** | P1 migration adds occurrences, ticket types, orders, registrations, promo codes — not sessions/venues/holds |
| **V4** DP ref unused server-side | **Open** | P5 — paid checkout |
| **V5** No builder component | **Open** | P8 |
| **V6** Admin writes on public SDK | **Closed** | Public SDK: `events()`, `event()`, `register()` only |
| **V7** ExternalEventsAPI not global | **Closed** | See §14.2 |
| **V8** localStorage is source of truth | **Partial** | List/delete/save hit API; cache remains for UI |
| **V9** Browser reference generation | **Partial** | Server generates `evt_*` when ref omitted; create form still pre-generates client ref |
| **V10** Browser price-level ids | **Open** | P3 ticket type sync |
| **V11** Delete never calls server | **Closed** | Delete uses `DELETE /portal/api/v1/events/{ref}` |
| **V12–V21** Admin UI defects | **Open** | P6 admin rewrite |
| **V22–V26** Email, discounts, SDK copies | **Open** | P6–P8, P7 |

## Files reviewed

- `www/routes/portal.php` — admin + storefront route groups
- `www/resources/js/portal/admin/controllers/events/events.ts`
- `www/resources/js/external/events/api.ts`
- `www/database/migrations/2026_08_31_200000_create_events_module_tables.php`

## Verification (local)

```bash
cd www && php artisan test --filter=EventsRouteTest
# Confirm no legacy URL in events SDK:
rg "request\\.php" resources/js/external/events/
```

## Next step

**P1 completion** — finish and document in `02-site-users-guard.complete.md`, `04-database-schema.complete.md`, `11-public-surface-and-sdk.complete.md`. Do **not** add `14-rollout-and-testing.complete.md` until §14.9 definition of done is met.
