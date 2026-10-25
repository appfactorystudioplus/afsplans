# 09 — Check-in and on-site (complete)

**Plan:** `09-checkin-and-onsite.md`  
**Rollout phase:** P9  
**Repo:** `www`

## Shipped

- `ticket_code` on registrations
- `EventCheckinService` + `laravel_event_checkins`
- Storefront `POST /checkin` by ticket code
- Scanner manifest at `GET /scanner/manifest.json`
- Playground scanner at `public/playground/externals/events/scanner/`

## Deferred

- Offline service worker, manifest signing, badge printing, walk-in flow

## Verification

POST checkin with a confirmed registration's `ticket_code`; open scanner playground.
