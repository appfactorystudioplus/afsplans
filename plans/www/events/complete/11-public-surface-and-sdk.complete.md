# 11 — Public surface and SDK (complete)

**Plan:** `11-public-surface-and-sdk.md`  
**Rollout phase:** P1 + P8 (partial)  
**Repo:** `www`

## Shipped

- Storefront API under `/portal/api/events/storefront/*`
- SDK: `events`, `event`, `hold`, `releaseHold`, `validatePromo`, `register`, `joinWaitlist`, `myRegistrations`, `registration`, `cancelRegistration`, `calendarUrl`, `passes`, `redeemPass`, `scannerManifestUrl`
- Playground at `public/playground/externals/events/` (minimal, register, scanner)
- Builder blocks: `events_components.ts` + `events_component_script.ts` registered in `imp.ts` (list, detail, register)

**Builder surface superseded (2026-09-08)** — events is now a page-level module
with a working four-view runtime (list / detail / register / my-registrations),
chrome, and DP-ref indexing. See [`00_extras.md` E3](00_extras.md#e3--events-builder-integration-four-views).
The stub `eventsComponentScript` that only rendered `<article>` rows is gone.
- JSON-LD on storefront `show` response (`json_ld` field)
- No admin writes on public SDK (V6 closed)

## Deferred

- Full hosted-page structured data injection in builder output (JSON-LD returned by API only)

## Verification

`npm run build-all` in www; open playground index.
