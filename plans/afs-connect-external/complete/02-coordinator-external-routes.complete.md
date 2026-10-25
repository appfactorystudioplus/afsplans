# 02-coordinator-external-routes — Completion

**Completed:** 2026-07-31

**Status:** Complete. Every route in the plan's route-group sketch exists, the
`afs_connect.external_token` middleware is registered and enforcing, and the internal
token CRUD group for members is live. Two implementation details diverge from the plan's
step list — the Brain `user_id` resolution and the `last_used_at` write both happen
outside the middleware. Both are noted below.

## Summary

### Route prefix

`/api/external/afs-connect/v1/*` registered in `coordinator/routes/api.php:114` with
group middleware `['afs_connect.external_token', 'throttle:120,1']`.

### Middleware

`coordinator/app/Http/Middleware/AfsConnectExternalTokenMiddleware.php`, aliased as
`afs_connect.external_token` in `bootstrap/app.php`. It:

- Rejects a missing Bearer token with `401`.
- Delegates prefix validation, hash lookup, revocation, and expiry to
  `AfsConnectApiTokenService::findValidByPlaintext()` — a miss is `401`
  `EXTERNAL_TOKEN_INVALID`.
- Attaches `external_member_reference_num`, `external_token_id`, and
  `external_token_scopes` to the request **from the database row**, never from the
  request.
- Touches `last_used_at`.

Client-supplied `X-User-Id`, `X-Checkpoint-User-Id`, and `?user_id=` are simply never
read on this surface — the middleware's class docblock states this as the contract.

### Read routes (Phase 2 of `07-implementation-phases.md`)

| Route | Controller |
|-------|------------|
| GET `/status` | `ExternalAfsConnectStatusController` |
| GET `/summary` | `ExternalAfsConnectSummaryController` |
| GET `/search` | `ExternalAfsConnectSearchController` |
| GET `/activity` | `ExternalAfsConnectActivityController` |
| GET `/insights` | `ExternalAfsConnectInsightsController` |
| GET `/items/{sourceApp}/{sourceId}` | `ExternalAfsConnectItemsController` |
| GET `/links`, GET `/links/counts`, POST `/links`, DELETE `/links/{id}` | `ExternalAfsConnectLinksController` |

The plan's **Option A** (thin per-route controllers delegating to existing services) was
taken, not the single-controller Option B. Two shared concerns carry the repeated work:

- `App\Http\Controllers\Concerns\ResolvesExternalTokenMember` — reads
  `member_reference_num` from the middleware's request attributes.
- `App\Http\Controllers\Concerns\RequiresExternalAfsConnectEntitlement` — reuses the
  entitlement service and the per-source gates, and supplies `requireWriteScope()`.

### Write routes (Phase 4)

| Route | Controller | Service |
|-------|------------|---------|
| POST `/snapshot` | `ExternalAfsConnectSnapshotController` | `ExternalSdkIngestService::ingestSnapshot()` |
| POST `/activity` | `ExternalAfsConnectWriteActivityController` | `ExternalSdkIngestService::ingestActivity()` |

Gating order in the write controllers: entitlement (including the `external_sdk` source
gate) first, then write scope, then payload validation. Persistence:

- Snapshots → `afsconnect_index` via `AfsConnectIndexService::applyChanges()`
- Project tracking → `afsconnect_engine_projects`, with dedup — an unchanged
  `content_hash` skips re-indexing
- Activity → `afsconnect_activity_log`, `actor_checkpoint_user_id` resolved from Brain at
  ingest time via `ExternalMemberIdentityResolver`

### Internal token CRUD (for members)

`Api\Internal\AfsConnectTokenController` under the existing `afs_connect.service` HMAC
group at `/api/internal/afs-connect/v1/tokens` (`index`/`store`/`destroy`), restricted to
the `members` sender.

### Diagnostic fallback

`known_afs_connect_routes` in the `routes/api.php` fallback now lists the internal token
routes and three external paths (`GET status`, `GET search`, `POST snapshot`) for deploy
debugging.

## Files changed

- `coordinator/app/Http/Middleware/AfsConnectExternalTokenMiddleware.php` — new.
- `coordinator/bootstrap/app.php` — `afs_connect.external_token` alias.
- `coordinator/app/Http/Controllers/Api/External/` — nine controllers, new.
- `coordinator/app/Http/Controllers/Concerns/ResolvesExternalTokenMember.php` — new.
- `coordinator/app/Http/Controllers/Concerns/RequiresExternalAfsConnectEntitlement.php` — new.
- `coordinator/app/Http/Controllers/Api/Internal/AfsConnectTokenController.php` — new.
- `coordinator/app/Services/AfsConnect/ExternalSdkIngestService.php` — new.
- `coordinator/app/Services/AfsConnect/ExternalMemberIdentityResolver.php` — new.
- `coordinator/routes/api.php` — external group, internal token routes, fallback list.

## Verification steps

```bash
cd coordinator
php artisan test tests/Feature/AfsConnect/ExternalAfsConnectApiTest.php
```

Covers: `401` without a Bearer token, `200` on `/status` with a valid read token,
entitlement enforced via a settings fixture, `403` on `POST /snapshot` with a read-only
token, and `200` plus index ingest with a write-scoped token.

Manual: mint a token through the members-signed internal API, then

```bash
curl -H "Authorization: Bearer afc_live_..." "{coordinator}/api/external/afs-connect/v1/status"
```

## Deviations from plan

- **Brain resolution is not in the middleware.** The plan's middleware step 7 resolves
  `member.user_id` from Brain when a route needs it. In the shipped code the middleware
  attaches only the token row's fields; `ExternalMemberIdentityResolver` (~1h cache) is
  called by `ExternalSdkIngestService` at activity-write time instead. Same source of
  truth, resolved lazily rather than per request.
- **`last_used_at` is written synchronously**, not via the queue job the plan's step 8
  suggested. `AfsConnectApiTokenService::touchLastUsed()` issues a direct update.

## Follow-up work

- **CORS on the external prefix** — deferred exactly as the plan says, until a browser
  JavaScript use case is confirmed. Native and CLI callers ignore CORS.
- **Snapshot/activity parity with the engine surface** — `EngineSnapshotController` and
  `EngineActivityController` were the stubs the plan named as Phase 4's dependency;
  external ingest went through the new `ExternalSdkIngestService` rather than a service
  shared with the engine controllers. Consolidating the two is open.
