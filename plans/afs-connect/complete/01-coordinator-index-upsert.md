# Step 01 — Coordinator index upsert

**Status:** Completed  
**Date:** 2026-07-23  
**Repo:** `apps/coordinator`

## What was built

Coordinator now persists afs-connect change items into the local `afsconnect_index` table instead of only updating website sync status during reconciliation.

### New service

`app/Services/AfsConnect/AfsConnectIndexService.php`

| Method | Purpose |
|--------|---------|
| `applyChanges()` | Batch processor for webhook/reconcile payloads |
| `upsertItem()` | Insert or update one indexed row |
| `deleteItem()` | Remove a row when `op: delete` is received |

### Wired into

1. **`AfsConnectAggregatorService::reconcileMember()`** — after Brain `/changes`, each entitled item is upserted.
2. **`AfsConnectIngestController::store()`** — inbound webhooks from source apps are processed synchronously (job queue deferred to a later phase).

## How it works

### Shared item contract

Every item uses the shape from [04-api-contracts.md](../04-api-contracts.md):

```json
{
  "source_app": "www_forms",
  "source_type": "www_form",
  "source_id": "FR-123",
  "website_domain": "example.com",
  "website_reference_num": "WR-456",
  "op": "upsert",
  "title": "Contact form",
  "snippet": "12 submissions",
  "url": "https://x1sexample7k2m9.sites.appfactory.studio/portal/admin#forms/FR-123",
  "source_updated_at": "2026-07-20T09:15:00Z"
}
```

`op` is `upsert` (default) or `delete`.

### Unique key / website scoping

The migration unique index is:

```
(source_app, source_id, website_reference_num)
```

Non-www sources (notes, flow, etc.) use an **empty string** for `website_reference_num` so MySQL unique semantics dedupe correctly (NULL would allow duplicates).

### Activity log

Each upsert and delete also writes a row to `afsconnect_activity_log` with `event_type` of `indexed` or `deleted`. The minimal PM UI reads this table for the “Recent activity” panel.

### Reconciliation flow

```
Coordinator reconcileMember()
  → WwwBrainClient::changes(member)
  → Brain meta-provider fans out to tenant DBs
  → returns { items[], websites[] }
  → AfsConnectIndexService applies each entitled item
  → AfsConnectWebsiteSyncStatus updated per website
```

### Webhook ingest flow

```
POST /api/internal/afs-connect/v1/ingest
  → entitlement check (global + plan + member + source)
  → idempotency via afsconnect_webhook_events.event_id
  → AfsConnectIndexService::applyChanges()
  → event marked processed (or failed with error text)
```

## Files changed

- `app/Services/AfsConnect/AfsConnectIndexService.php` (new)
- `app/Services/AfsConnect/AfsConnectAggregatorService.php`
- `app/Http/Controllers/Api/Internal/AfsConnectIngestController.php`

## Not in this step

- Queued `ProcessAfsConnectWebhookJob` (still synchronous)
- Notes / dream_graph / flow / pdf_editor reconciliation clients
- Full-text search index (search uses `LIKE` for now)
