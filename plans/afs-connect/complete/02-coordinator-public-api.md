# Step 02 — Coordinator public afs-connect API

**Status:** Completed  
**Date:** 2026-07-23  
**Repo:** `apps/coordinator`

## What was built

Public session-authenticated endpoints for the coordinator PM frontend, all gated by `AfsConnectEntitlementService` via the `RequiresAfsConnectEntitlement` trait.

### Routes (`routes/api.php`)

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/api/afs-connect/status` | `AfsConnectStatusController` | Entitlement state (pre-existing) |
| GET | `/api/afs-connect/summary` | `AfsConnectSummaryController` | Counts by source app |
| GET | `/api/afs-connect/search?q=` | `AfsConnectSearchController` | Search indexed title/snippet |
| GET | `/api/afs-connect/activity` | `AfsConnectActivityController` | Paginated activity log |
| GET | `/api/afs-connect/items/{sourceApp}/{sourceId}` | `AfsConnectItemsController` | Indexed row + live www proxy |
| GET | `/api/afs-connect/links` | `AfsConnectLinksController` | List entity links |
| POST | `/api/afs-connect/links` | `AfsConnectLinksController` | Create link |
| DELETE | `/api/afs-connect/links/{id}` | `AfsConnectLinksController` | Remove link |

### New services

| Service | Role |
|---------|------|
| `AfsConnectQueryService` | Summary, search, activity read queries |
| `AfsConnectLinkService` | Link CRUD with index existence check |

### Auth pattern

Same as existing PM APIs:

- Header `X-User-Id` → resolves `member_reference_num`
- Header `X-Checkpoint-User-Id` for audit fields on link creation
- Optional `user_id` query param fallback

Entitlement denial returns **503** with `reason` (`global_disabled`, `plan_not_entitled`, `member_disabled`, `source_disabled`).

## How each endpoint works

### Summary

Aggregates `afsconnect_index` grouped by `source_app`:

```json
{
  "member_reference_num": "MR-8841",
  "total_items": 42,
  "updated_last_7d": 5,
  "by_source_app": {
    "www_forms": { "total": 10, "updated_last_7d": 2 }
  },
  "generated_at": "2026-07-23T23:00:00Z"
}
```

### Search

`LIKE` match on `title` and `snippet`, ordered by `source_updated_at` desc, max 50 results.

### Activity

Paginated rows from `afsconnect_activity_log` (newest first).

### Items (live proxy)

1. Load indexed row for member + source + id.
2. If `source_app` starts with `www_`, call `WwwBrainClient::item()` with `website_domain`.
3. Return `{ indexed, item }`. Non-www sources return indexed data only until their provider clients ship.

### Links

Create requires the indexed item to exist. Unique constraint prevents duplicate links per entity + source identity.

## Files added

- `app/Http/Controllers/Concerns/RequiresAfsConnectEntitlement.php`
- `app/Http/Controllers/Api/AfsConnect/AfsConnectSummaryController.php`
- `app/Http/Controllers/Api/AfsConnect/AfsConnectSearchController.php`
- `app/Http/Controllers/Api/AfsConnect/AfsConnectActivityController.php`
- `app/Http/Controllers/Api/AfsConnect/AfsConnectLinksController.php`
- `app/Http/Controllers/Api/AfsConnect/AfsConnectItemsController.php`
- `app/Services/AfsConnect/AfsConnectQueryService.php`
- `app/Services/AfsConnect/AfsConnectLinkService.php`

## Contract reference

[04-api-contracts.md](../04-api-contracts.md) §4.3
