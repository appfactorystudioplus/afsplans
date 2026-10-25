# 02 — Coordinator external routes

## Route prefix

All external SDK traffic uses a **new versioned prefix**:

```
/api/external/afs-connect/v1/*
```

Rationale:

- Clearly separates external token auth from browser PM API and internal/engine surfaces
- Versioned (`v1`) so SDK contracts can evolve without breaking coordinator web UI
- Matches existing internal versioning style (`/api/internal/afs-connect/v1/...`)

Register in `coordinator/routes/api.php`:

```php
Route::prefix('external/afs-connect/v1')
    ->middleware('afs_connect.external_token')
    ->group(function () {
        // read
        Route::get('/status', ...);
        Route::get('/summary', ...);
        Route::get('/search', ...);
        Route::get('/activity', ...);
        Route::get('/insights', ...);
        Route::get('/items/{sourceApp}/{sourceId}', ...);
        Route::get('/links', ...);
        Route::get('/links/counts', ...);
        Route::post('/links', ...);
        Route::delete('/links/{id}', ...);

        // write (requires write scope on token)
        Route::post('/snapshot', ...);
        Route::post('/activity', ...);
    });
```

## Middleware: `afs_connect.external_token`

New file: `coordinator/app/Http/Middleware/AfsConnectExternalTokenMiddleware.php`

Steps:

1. Read `Authorization: Bearer {token}` (reject missing → `401`)
2. Validate prefix `afc_live_` (reject malformed → `401`)
3. Hash token with SHA-256, lookup `afsconnect_api_tokens` where `token_hash = ?` and `revoked_at IS NULL` and (`expires_at IS NULL` OR `expires_at > now()`)
4. On miss → `401` `{ "message": "Invalid or revoked token." }`
5. Load token row; attach from **database** (never from request):
   - `external_member_reference_num` ← `token.member_reference_num`
   - `external_token_id`
   - `external_token_scopes` (array)
6. **Ignore** any client-supplied `X-User-Id`, `X-Checkpoint-User-Id`, or `user_id` query param
7. When a route needs Checkpoint `user_id` (activity actor, live item proxy): resolve via **`FunctionsController::getMemberByReference($memberReferenceNum)['user_id']`** (Brain API), with cache TTL matching existing member resolution (~1 hour). If Brain returns no member → `503` or `401` as appropriate
8. Update `last_used_at` asynchronously (queue job)
9. Continue to controller

Register alias in `coordinator/bootstrap/app.php`:

```php
'afs_connect.external_token' => AfsConnectExternalTokenMiddleware::class,
```

## Controllers

**Option A (recommended v1):** Thin wrappers that delegate to existing services.

| External controller | Reuses logic from |
|--------------------|-------------------|
| `ExternalAfsConnectStatusController` | `AfsConnectStatusController` + entitlement |
| `ExternalAfsConnectSearchController` | `AfsConnectSearchController` |
| `ExternalAfsConnectLinksController` | `AfsConnectLinksController` |
| `ExternalAfsConnectSnapshotController` | `EngineSnapshotController` (once implemented) |
| `ExternalAfsConnectActivityController` | `EngineActivityController` (once implemented) |

External route handlers pass `member_reference_num` into existing services directly — no `?user_id=` on external URLs.

**Option B:** Single `ExternalAfsConnectController` with methods — faster to ship, harder to maintain. Avoid unless time-constrained.

## Entitlement

Every external route runs `AfsConnectEntitlementService::check($memberReferenceNum)` before work.

- Not entitled → `503` with same shape as PM API (`reason`, `global_enabled`, etc.)
- Write routes additionally require `write` in token scopes → else `403`

## Write routes and source app

External pushes use a dedicated registry key **`external_sdk`** (name TBD in source registry):

- `source_app = external_sdk` on index rows and activity log entries
- Member must have `external_sdk` source enabled in afs-connect settings (Brain admin registers key; member toggles like creative apps)

Snapshot/activity payload shapes mirror engine API (`project_id`, `content_hash`, `snapshot[]`, `events[]`) so SDK code stays similar — only auth and path differ.

## Internal token CRUD (for members app)

Under existing internal middleware group:

```php
Route::prefix('internal/afs-connect/v1')
    ->middleware('afs_connect.service')
    ->group(function () {
        // existing ingest, settings...
        Route::get('/tokens', [AfsConnectTokenController::class, 'index']);
        Route::post('/tokens', [AfsConnectTokenController::class, 'store']);
        Route::delete('/tokens/{id}', [AfsConnectTokenController::class, 'destroy']);
    });
```

`AfsConnectTokenController` validates calling app is `members` (from HMAC `X-AfsConnect-App`), verifies `member_reference_num` in body matches an active membership.

## Diagnostic fallback

Update `routes/api.php` fallback `known_afs_connect_routes` list to include external paths for deploy debugging.

## Relationship to existing routes

| Do not change | Reason |
|---------------|--------|
| `/api/afs-connect/*` | Coordinator web app |
| `/api/internal/afs-connect/v1/ingest` | Internal source apps |
| `/api/engine/v1/connect/*` | Joystick Checkpoint flow |

External SDK documentation should **only** document `/api/external/afs-connect/v1/*`.

## CORS (optional phase)

If external SDK is called from browser JavaScript (not Unity/native), coordinator may need CORS headers on the external prefix. Native apps ignore CORS. Defer until a browser use case is confirmed.
