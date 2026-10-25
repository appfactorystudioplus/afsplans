# 14 — Rollout and testing (complete)

**Plan:** `14-rollout-and-testing.md`  
**Repo:** `www` + `packages/afsserver`  
**Date:** 2026-08-31 (updated)

## Phases P1–P12

| Phase | Status |
|---|---|
| P1 API | Done — see `02`, `04`, `11` completion docs |
| P2 Model | Done — see `05` (partial UI) |
| P3 Inventory | Done — see `06` |
| P4 Registration | Done — see `07`; `form_submission_ref` validated on register |
| P5 Money | Done — see `08` (reconciler; refunds deferred) |
| P6 Admin | Partial — detail/create fetch from API; localStorage list cache remains |
| P7 Communications | Done — see `10` (log driver) |
| P8 Public | Done — SDK, playground, builder blocks (`events_components.ts`) |
| P9 On-site | Partial — API check-in + scanner manifest + playground; offline PWA deferred |
| P10 Reporting | Done — see `13` |
| P11 Accounts | Done — Site Users on register + myRegistrations |
| P12 Passes | Partial — list/redeem API + redemptions table; purchase flow deferred |

## §14.2 verification

- **V7:** Closed — admin uses fetch, not `new ExternalEventsAPI()`
- **V8:** Open — localStorage cache remains for list view; detail/create load from server first

## Commands

```bash
php artisan migrate
php artisan test --filter=Events
php artisan events:reconcile-counters
php artisan events:expire-holds
php artisan events:reconcile-payments --dry-run
```

## Still deferred (explicit)

- §14.3 fourteen-scenario concurrency load suite in CI
- Seven full end-to-end browser journeys
- localStorage importer (§14.4) — skipped pending V8 tenant check
- Scanner offline mode (manifest only; no service worker)
- Season pass purchase/checkout (redemption API only)
- Refunds, scheduled reminders, webhooks
- Full admin promo UI (API CRUD exists)

## Definition of done gap

§14.9 is **not fully met** until concurrency suite + e2e journeys run green. Core module behaviour, builder blocks, pass redemption, and form attachment are implemented and documented per numbered plan `complete/` files.
