# 04 — API Contracts

> ⏳ **Planned changes (steps 60/61):** `GET /api/afs-connect/status` grows
> `tier`, per-meter `usage`, and per-source `allowed`/`enabled`/`min_tier` so
> UIs can show numbers and a specific upgrade path instead of a bare refusal
> ([11-billing-and-allocation.md](11-billing-and-allocation.md) §11.6). A new
> `GET /api/afs-connect/sources` projects the source registry, replacing every
> hardcoded label map. §4.1's four provider endpoints apply **only to
> `provider`/`meta` sources**; `hosted` sources implement none of them and
> push instead ([12-source-registry.md](12-source-registry.md) §12.3). Step 61
> adds coordinator's third auth surface, `/api/engine/v1/*`, authenticated by a
> per-user Checkpoint access token, plus two internal task endpoints — the
> first *write* endpoints in the internal API, which therefore run coordinator's
> real PM authorization against the forwarded acting user.

Two API surfaces are introduced. All request/response bodies are JSON.
Every internal endpoint is versioned under `/api/internal/afs-connect/v1/...`
so a source app can evolve its contract without breaking coordinator mid-flight.

## 4.1 Source-app internal API (implemented by notes, dream_graph, flow, pdf_editor)

Each app implements the same four endpoints against its own data. Auth: see
[06-security-and-service-auth.md](06-security-and-service-auth.md) — every
request must carry a valid signed-request header set, and the app
independently re-checks entitlement before responding.

### `GET /api/internal/afs-connect/v1/summary`

Query: `member_reference_num` (required), `team_client_id` (optional filter).

```json
{
  "source_app": "notes",
  "member_reference_num": "MR-8841",
  "counts": { "total": 214, "updated_last_7d": 12 },
  "generated_at": "2026-07-21T18:04:00Z"
}
```

### `GET /api/internal/afs-connect/v1/changes`

Query: `member_reference_num` (required), `since` (ISO8601, required — pass
`1970-01-01T00:00:00Z` for a full backfill), `cursor` (optional, for
pagination), `limit` (default 200, max 500).

```json
{
  "items": [
    {
      "source_type": "note",
      "source_id": "b6b9...uuid",
      "op": "upsert",
      "title": "Sprint 14 retro",
      "snippet": "Action items: ...",
      "owner_checkpoint_user_id": "10432",
      "pm_team_client_id": 3,
      "url": "https://notes.dev.appfactory.studio/n/b6b9...",
      "tags": ["retro", "sprint-14"],
      "metadata": { "folder": "Sprint Planning", "word_count": 812 },
      "source_created_at": "2026-06-01T10:00:00Z",
      "source_updated_at": "2026-07-20T09:15:00Z"
    },
    {
      "source_type": "note",
      "source_id": "a112...uuid",
      "op": "delete"
    }
  ],
  "next_cursor": "eyJpZCI6...",
  "has_more": false
}
```

`op` is one of `upsert` | `delete`. This is the contract both the webhook
payload (§4.3) and the reconciliation pull use — they share one shape.

### `GET /api/internal/afs-connect/v1/items/{source_id}`

Query: `member_reference_num` (required), `requested_by_checkpoint_user_id`
(required — used for the source app's own re-authorization).

```json
{
  "source_type": "note",
  "source_id": "b6b9...uuid",
  "title": "Sprint 14 retro",
  "content_preview_html": "<p>...</p>",
  "url": "https://notes.dev.appfactory.studio/n/b6b9...",
  "permissions": { "can_view": true, "can_edit": false }
}
```

Returns `403` if the requesting user does not actually have access to this
item under the source app's own authorization rules — coordinator having
called this at all does not imply access. Returns full/richer content than
`changes`/`summary` (still capped — e.g. a note's full body but not
binary attachments; a PDF's outline, not the raw file bytes, unless the
source app decides otherwise for its own item type).

### `GET /api/internal/afs-connect/v1/search`

Query: `member_reference_num` (required), `q` (required), `limit` (default
20).

```json
{
  "results": [
    { "source_type": "note", "source_id": "b6b9...", "title": "Sprint 14 retro", "snippet": "...", "score": 0.82 }
  ]
}
```

Used only when coordinator wants a live, authoritative search (rare — most
search hits `afsconnect_index` locally). Primarily useful right after a
member first enables afs-connect, before the backfill has completed.

### `GET /api/internal/afs-connect/v1/health`

No auth required beyond standard rate limiting. Returns
`{"status":"ok","afs_connect_provider_version":"1.0.0"}`. Used by
coordinator's client circuit breaker and by ops monitoring.

## 4.2 Coordinator's internal ingest API

### `POST /api/internal/afs-connect/v1/ingest`

Called by the four source apps' webhook dispatchers. Body:

```json
{
  "event_id": "notes-2026072118-4471",
  "source_app": "notes",
  "member_reference_num": "MR-8841",
  "occurred_at": "2026-07-21T18:04:00Z",
  "items": [
    { "source_type": "note", "source_id": "b6b9...uuid", "op": "upsert", "title": "...", "snippet": "...", "...": "..." }
  ]
}
```

Same per-item shape as `changes` above. `event_id` is the idempotency key —
coordinator writes it to `afsconnect_webhook_events.event_id` (unique) before
processing; a resend with the same `event_id` is a no-op `200`.

Response: `202 Accepted` immediately (processing is queued); `409` if
`event_id` was already seen and fully processed;
`503` if afs-connect is globally disabled or this member is currently
disabled (the sender should stop retrying and stop sending, not treat this
as transient).

### `POST /api/internal/afs-connect/v1/settings` (called by `apps/members`)

Body:

```json
{
  "member_reference_num": "MR-8841",
  "enabled": true,
  "sources": { "notes": true, "dream_graph": true, "flow": false, "pdf_editor": true },
  "changed_by_checkpoint_user_id": "10432"
}
```

Coordinator persists to `afsconnect_settings`, re-validates entitlement
(plan + global flag) before honoring `enabled: true`, and — if this is the
first time `enabled` transitions `false → true` — enqueues a full backfill
job (`AfsConnectBackfillMemberJob`) that calls `changes` with
`since = 1970-01-01T00:00:00Z` against every enabled source app.

### `GET /api/internal/afs-connect/v1/settings?member_reference_num=...`

Returns the current `afsconnect_settings` row, read by the Members
dashboard to render the toggle UI's current state.

## 4.3 Coordinator's public API (consumed by coordinator's own frontend)

Standard session-authenticated endpoints (same `ResolvesCoordinatorAuth`
header-trust pattern coordinator already uses elsewhere), all gated by
`AfsConnectEntitlementService` first:

| Method & path | Purpose |
|---|---|
| `GET /api/afs-connect/status` | Current entitlement state for the caller's member (`enabled`, `sources`, `plan_includes_afs_connect`) — drives whether the UI shows the feature, an upsell prompt, or a disabled-state message. |
| `GET /api/afs-connect/summary` | Per-source-app counts + recent activity, from `afsconnect_index` / `afsconnect_activity_log`. |
| `GET /api/afs-connect/search?q=` | Cross-app search against `afsconnect_index` (full-text). |
| `GET /api/afs-connect/items/{source_app}/{source_id}` | Proxies to the source app's `items/{id}` (live fetch, §4.1), after re-checking entitlement + coordinator-side team visibility. |
| `POST /api/afs-connect/links` | Body `{linkable_type, linkable_client_id, source_app, source_type, source_id}` — create a link. |
| `GET /api/afs-connect/links?linkable_type=pm_task&linkable_client_id=42` | List links for a coordinator entity. |
| `DELETE /api/afs-connect/links/{id}` | Remove a link. |
| `GET /api/afs-connect/activity` | Paginated cross-app activity timeline for the dashboard. |

## 4.4 Contract ownership & versioning

- Each source app's `AfsConnectProvider` interface and its DTO shapes are
  defined once, in coordinator, as a small shared reference (a PHP
  interface + a JSON Schema doc) so all four implementations stay
  consistent — but each app implements it independently in its own
  codebase; there is no shared package/dependency between the five apps.
- Breaking changes bump the path version (`v1` → `v2`); coordinator's
  clients declare which version they speak per source app, so apps can
  upgrade independently.
- `GET .../health` reports the provider version so coordinator can log/alert
  on version drift.
