# Step 13 — Webhook ingest from creative apps + www forms

## What was built

Signed, queued POSTs to coordinator `POST /api/internal/afs-connect/v1/ingest` when members change content, so the PM index updates without waiting for reconcile.

### Pattern (notes, flow, dream_graph, pdf_editor)

- `CoordinatorHttp` — TLS verify follows `AFS_CONNECT_COORDINATOR_VERIFY_SSL`
- `AfsConnectEntitlementGuard` — cached GET coordinator settings; skips dispatch when feature or source is off
- `SendAfsConnectIngestJob` — HMAC-signed ingest payload, retries with backoff
- `AfsConnectIngestDispatcher` — builds upsert/delete item rows per app

### Controller hooks

| App | Triggers |
|-----|----------|
| notes | Note create/update/trash/restore/delete/favorite |
| flow | Project create/update/delete |
| dream_graph | Project create/update/delete |
| pdf_editor | Document create/update/delete |

### www (phase 5 — forms)

- `WwwAfsConnectIngestDispatcher` — `www_forms` upsert/delete rows with `website_domain` / `website_reference_num`
- `WwwAfsConnectTenantFromRequest` — builds `TenantContext` from request fields
- `FormsController` create/update dispatches when the client sends tenant context:
  - `member_reference_num`, `website_reference_num`, `website_domain`, `subdomain` (and optional `system_user`)
- Portal forms admin (`FormsViewsController.ts`) merges those fields automatically via `withWwwAfsConnectTenant()` from `subdomain_properties`.
- If the body omits tenant fields, `WwwAfsConnectTenantFromRequest::resolve()` falls back to `FunctionsController::__get_managed_domain()` on the server.

If tenant fields are omitted and managed-domain resolution fails, ingest is skipped (reconcile/backfill still applies).

### Notes internal search (optional coordinator fan-out)

- `GET /api/internal/afs-connect/v1/search?q=&limit=` on notes for live title/preview search.

## Operations

1. Set `AFS_CONNECT_COORDINATOR_URL` and matching `AFS_CONNECT_SERVICE_KEY` on each source app and coordinator peer keys.
2. Run queue workers on **notes, flow, dream_graph, pdf_editor, www** so `SendAfsConnectIngestJob` runs.
3. Coordinator queue worker for `ProcessAfsConnectWebhookJob` (existing step 04).

## Verify

1. Enable afs-connect for a test member with the relevant source toggles on.
2. Create or edit an item in each app; confirm coordinator `afsconnect_index` and activity log update within seconds.
3. For www forms, POST create/update with tenant fields; confirm indexed row includes website metadata.
