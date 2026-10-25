# Step 12 — Live creative item detail + backfill status

**Status:** Completed  
**Repos:** `apps/coordinator`, `apps/notes`, `apps/flow`, `apps/dream_graph`, `apps/pdf_editor`

## What was built

### Live item detail (creative apps)

Each creative app exposes:

`GET /api/internal/afs-connect/v1/items/{sourceId}?member_reference_num=…`

Coordinator `GET /api/afs-connect/items/{sourceApp}/{sourceId}` now proxies to `SourceAppAfsConnectClient::item()` for `notes`, `flow`, `dream_graph`, and `pdf_editor` (www Brain path unchanged). The Kanban/task **View** drawer shows live payloads for linked creative items.

### Backfill status

- Migration: `afsconnect_settings.backfill_started_at`, `backfill_completed_at`
- Set on first enable + job dispatch; completed when `AfsConnectBackfillMemberJob` finishes
- `GET /api/afs-connect/status` returns real `backfill_status`: `pending` | `in_progress` | `complete`
- Optional `members_settings_url` from `AFS_CONNECT_MEMBERS_SETTINGS_URL` for coordinator disabled-state deep link

### Coordinator PM UX

- `#/afs-connect` shows backfill banner while import runs
- When not entitled/enabled, shows reason + link to members settings

## Deploy

1. `php artisan migrate` on **coordinator**
2. Deploy all four creative apps (new `/items/{sourceId}` routes)
3. Set on coordinator `.env`:

```env
AFS_CONNECT_MEMBERS_SETTINGS_URL=https://members15.appfactory.studio/#/settings/general
```

4. Rebuild coordinator frontend assets

## Verify

1. Enable afs-connect for a member → status `backfill_status` moves to `in_progress` then `complete` after queue worker runs.
2. Link a note to a task → **View** drawer shows note title/document snippet.
3. Visit `#/afs-connect` while backfill running → import banner visible.

## Still open (from step 08)

- Webhook dispatch from source apps on CRUD
- Per-app entitlement guards calling coordinator settings
- Internal `/search` on source apps
