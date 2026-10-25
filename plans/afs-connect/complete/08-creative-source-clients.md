# Step 08 — Creative source app clients (coordinator → notes/flow/dream_graph/pdf)

**Status:** Completed  
**Repos:** `apps/coordinator`, `apps/notes`, `apps/flow`, `apps/dream_graph`, `apps/pdf_editor`

## What was built

### Coordinator outbound clients

- `SourceAppAfsConnectClient` — signed GET to each app's `/api/internal/afs-connect/v1/*`
- `SourceAppAfsConnectClientRegistry` — resolves clients for enabled `sources.*` toggles
- `config/afs_connect.php` → `source_app_base_urls` from:
  - `AFS_CONNECT_NOTES_BASE_URL`
  - `AFS_CONNECT_FLOW_BASE_URL`
  - `AFS_CONNECT_DREAM_GRAPH_BASE_URL`
  - `AFS_CONNECT_PDF_EDITOR_BASE_URL`

Each URL must be the **full base** including `/api/internal/afs-connect/v1`.

### Reconcile / backfill

`AfsConnectAggregatorService::reconcileMember()` now:

1. Paginates Brain `/changes` (www) as before
2. For each enabled creative source with a configured base URL, paginates that app's `/changes` and upserts into `afsconnect_index`

CLI output includes creative item counts and page counts.

### Source app internal APIs

Each creative app exposes (service-auth middleware):

| Route | Purpose |
|-------|---------|
| `GET /api/internal/afs-connect/v1/summary` | Counts for member |
| `GET /api/internal/afs-connect/v1/changes` | `since`, `cursor`, `limit`, `has_more`, `next_cursor` |
| `GET /api/internal/afs-connect/v1/health` | Provider probe |

Initial indexing scope:

- **notes** — `notes_notes` rows per `member_reference_num`
- **flow** — `flow_projects`
- **dream_graph** — `graph_projects` (by `member_reference_num`)
- **pdf_editor** — `pefeditor_documents` (new `routes/api.php` registered in bootstrap)

## Deploy checklist

1. Set the four `AFS_CONNECT_*_BASE_URL` values on **coordinator** `.env`
2. Deploy all five apps; `php artisan route:clear` on each
3. Ensure matching `AFS_CONNECT_SERVICE_KEY_*` on coordinator and each source app
4. Enable source toggles for the member in Brain afs-connect tab
5. Run `php artisan afs-connect:reconcile --member=… --backfill` on coordinator

## Not in this step

- ~~Live `/search` and `/items/{id}` on source apps~~ — `/items/{id}` done ([12-live-items-and-backfill-status.md](./12-live-items-and-backfill-status.md)); `/search` still open
- Webhook dispatch from source apps on create/update/delete
- Per-app entitlement guards calling coordinator settings (coordinator gate only for now)
