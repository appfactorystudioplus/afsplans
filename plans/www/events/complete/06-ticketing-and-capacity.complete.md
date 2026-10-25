# 06 — Ticketing and capacity (complete)

**Plan:** `06-ticketing-and-capacity.md`  
**Rollout phase:** P3  
**Repo:** `www`

## Shipped

- Atomic `EventCapacityService` (registered + reserved counters, rollback, hold conversion)
- `EventHoldService` + `laravel_event_holds` + `events:expire-holds`
- `EventTierResolver` + `laravel_event_ticket_tiers`
- `EventPromoService` + promo validation/redemption
- `events:reconcile-counters`

## Deferred

- Seating allocations (§6.9), access-code ticket visibility filter
- Full concurrency load suite (§14.3)

## Verification

`php artisan test --filter=EventCapacityServiceTest`
