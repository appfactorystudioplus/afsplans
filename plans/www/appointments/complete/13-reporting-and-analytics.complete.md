# 13 — Reporting and analytics (complete)

**Plan:** `13-reporting-and-analytics.md`  
**Repo:** `www`  
**Date:** 2026-08-31  
**Status:** Summary + export API; utilisation/gap analysis deferred.

## Shipped

- `AppointmentReportingService` — 30d summary, booking export rows, customer aggregation
- Admin endpoints: `/report`, `/export/bookings`, `/customers`
- `WwwAppointmentsProvider` — real summary fields + booking change events

## Deferred

- Utilisation against available minutes (needs full availability cache)
- Gap analysis, revenue per available hour
- Queued CSV/XLSX export with BOM
- Retention / erasure / calendar purge (§13.7)
- Customer merge UI

## afs-connect summary shape

```php
appointments_count, services_count, staff_count,
bookings_upcoming, bookings_30d, no_show_rate_90d, revenue_cents_30d
```

(utilisation_30d null until availability reporting lands)
