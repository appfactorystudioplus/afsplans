# Step 04 — Queued webhook processing

**Status:** Completed  
**Date:** 2026-07-23  
**Repo:** `apps/coordinator`

## What was built

Inbound webhook ingest is now **asynchronous**. The HTTP handler returns `202 Accepted` immediately and a queue worker applies index changes in the background.

### New job

`app/Jobs/AfsConnect/ProcessAfsConnectWebhookJob.php`

| Property | Value |
|----------|-------|
| Queue | Default Laravel queue (`ShouldQueue`) |
| Retries | 3 with backoff 10s / 60s / 300s |
| Input | `afsconnect_webhook_events.id` |

### Updated controller

`AfsConnectIngestController::store()` now:

1. Validates entitlement and idempotency (`event_id` already `processed` → `409`)
2. Creates or reuses a `pending` webhook event row
3. Dispatches `ProcessAfsConnectWebhookJob`
4. Returns `202` with `{ message, event_id }`

The job loads the stored payload and calls `AfsConnectIndexService::applyChanges()`, then marks the event `processed` or `failed`.

## How it works

```
Source app POST /api/internal/afs-connect/v1/ingest
  → coordinator validates + writes pending event
  → ProcessAfsConnectWebhookJob dispatched
  → HTTP 202 returned immediately

Queue worker
  → job loads event by id
  → skip if already processed
  → applyChanges(member, source_app, items[])
  → event.status = processed | failed
```

### Sync driver note

With `QUEUE_CONNECTION=sync`, the job runs inline during the request (same as before, but through the job class). For production, use `database` or `redis` and run `php artisan queue:work`.

## Files changed

- `app/Jobs/AfsConnect/ProcessAfsConnectWebhookJob.php` (new)
- `app/Http/Controllers/Api/Internal/AfsConnectIngestController.php`

## Contract reference

[04-api-contracts.md](../04-api-contracts.md) §4.2
