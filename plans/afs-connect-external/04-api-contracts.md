# 04 — API contracts (external SDK)

Base URL: `{coordinator_url}/api/external/afs-connect/v1`

Authentication (all routes) — **the token is the only credential**:

```
Authorization: Bearer afc_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Accept: application/json
```

**Do not send** (not required, not read, ignored if present):

- `X-User-Id`
- `X-Checkpoint-User-Id`
- `X-Coordinator-Role`
- `?user_id=` query parameter
- `member_reference_num` in body or query

Coordinator resolves identity in two steps:

| Resolved field | Source |
|----------------|--------|
| Membership scope | `afsconnect_api_tokens.member_reference_num` (from token lookup) |
| Checkpoint `user_id` (when needed) | Brain `GET /member?reference_num={member_reference_num}` → `member.user_id` |

No checkpoint or member user ids are stored on the token row or accepted from the client.

## Difference from PM web API (`/api/afs-connect/*`)

The coordinator **website** still uses Checkpoint session headers + `user_id` query because it runs in an authenticated browser tab. The **external SDK** uses a different route prefix and a single Bearer token — same backend services, different auth entry point, **no parallel identity fields on the wire**.

## Read endpoints (scope: `read`)

Mirror PM API semantics; response shapes match `/api/afs-connect/*` so SDK types can be shared.

### `GET /status`

Entitlement + source toggles for the token's member.

Same JSON as `GET /api/afs-connect/status` (minus browser-only fields if any). No `user_id` query param — member comes from token.

### `GET /summary`

Same as `GET /api/afs-connect/summary`.

### `GET /search`

Query params:

| Param | Required | Default |
|-------|----------|---------|
| `q` | yes | — |
| `limit` | no | 20, max 50 |

Same response as PM search.

### `GET /activity`

Query: `per_page` (default 25, max 100).

### `GET /insights`

Same as PM insights.

### `GET /items/{source_app}/{source_id}`

Query: optional `website_domain`.

### Links

| Method | Path | Body / query |
|--------|------|--------------|
| `GET` | `/links` | `linkable_type`, `linkable_client_id` |
| `GET` | `/links/counts` | `linkable_type`, `linkable_client_ids` (comma-separated) |
| `POST` | `/links` | `{ linkable_type, linkable_client_id, source_app, source_type, source_id, website_domain?, website_reference_num? }` |
| `DELETE` | `/links/{id}` | — |

## Write endpoints (scope: `write`)

Require token scopes to include `write`. Otherwise `403`.

### `POST /snapshot`

Push indexed items from an external project (hosted source pattern).

Body:

```json
{
  "project_id": "uuid-or-stable-id",
  "content_hash": "sha256-of-canonical-snapshot-json",
  "snapshot": [
    {
      "source_type": "scene",
      "source_id": "guid",
      "op": "upsert",
      "title": "Main Menu",
      "snippet": "42 entities",
      "url": "optional-deep-link"
    }
  ]
}
```

Response `200`:

```json
{
  "success": true,
  "project_id": "...",
  "content_hash": "...",
  "items_count": 1,
  "skipped": false
}
```

If hash unchanged since last snapshot, `skipped: true` (idempotent).

Stored with `source_app = external_sdk`.

### `POST /activity`

Body:

```json
{
  "events": [
    {
      "event_type": "build_finished",
      "source_type": "project",
      "source_id": "project-uuid",
      "occurred_at": "2026-07-31T12:00:00Z",
      "payload": { "platform": "Windows", "success": true }
    }
  ]
}
```

Response:

```json
{
  "success": true,
  "ingested_count": 1
}
```

`actor_checkpoint_user_id` on stored activity rows = **`member.user_id` from Brain** at ingest time (via `member_reference_num` from token). Never from request body or token columns.

## Error responses

| HTTP | When |
|------|------|
| `401` | Missing, malformed, revoked, or unknown token |
| `403` | Valid token but missing `write` scope on write route |
| `503` | afs-connect disabled (global, plan, or member) |
| `422` | Validation error |
| `429` | Rate limit exceeded |

Error body:

```json
{
  "success": false,
  "message": "Human-readable summary",
  "code": "EXTERNAL_TOKEN_INVALID"
}
```

## SDK mapping (`apps/afsconnect`)

| SDK class (planned) | Routes |
|---------------------|--------|
| `ExternalClient` | All external routes |
| Replaces primary use of `PmClient` + Checkpoint session for third-party integrators |

Config — **two fields only**:

```typescript
{
  coordinatorUrl: 'https://coordinator.example.com',
  apiToken: 'afc_live_...',
}
```

No `memberUserId`, `checkpointUserId`, or `pmSession`. The SDK sends only:

```
Authorization: Bearer ${apiToken}
```

## Versioning

Breaking changes → `/api/external/afs-connect/v2/*`. v1 remains until deprecation window ends. SDK sends optional `X-AfsConnect-Sdk-Version` header for support diagnostics.
