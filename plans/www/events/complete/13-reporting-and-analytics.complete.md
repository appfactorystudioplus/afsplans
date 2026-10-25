# 13 — Reporting and analytics (complete)

**Plan:** `13-reporting-and-analytics.md`  
**Rollout phase:** P10  
**Repo:** `www`

## Shipped

- `EventReportingService` — summary + registration export
- Admin routes: `GET .../report`, `GET .../export/registrations`
- `WwwEventsProvider::isStub()` → false

## Deferred

- Dashboard charts, utilisation by session, afs-connect deep links

## Verification

`GET /portal/api/v1/events/{ref}/report` (authenticated)
