# P12 — Season passes (complete)

**Rollout phase:** P12  
**Repo:** `www`

## Shipped

- `laravel_event_passes` + `laravel_event_pass_redemptions` (migration `2026_08_31_220000`)
- `EventPass` / `EventPassRedemption` models
- `EventPassService` — list for site, redeem against included events
- Storefront: `GET /passes?site=`, `POST /passes/{ref}/redeem`
- SDK: `passes()`, `redeemPass()`

## Deferred

- Pass purchase/checkout flow (DP invoice)
- Admin UI for pass CRUD

## Verification

Create a pass row for a site; call redeem with valid event/occurrence refs after publishing.
