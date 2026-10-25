# 04-api-contracts — Completion

**Completed:** 2026-07-31

**Status:** Complete. Every documented endpoint exists at the documented path with the
documented auth model. The wire contract landed as written — a single Bearer credential,
no identity fields of any kind on the request.

## Summary

Base URL `{coordinator_url}/api/external/afs-connect/v1`, authenticated by
`Authorization: Bearer afc_live_...` alone.

`X-User-Id`, `X-Checkpoint-User-Id`, `X-Coordinator-Role`, `?user_id=`, and any
client-supplied `member_reference_num` are not read on this surface. Identity resolves in
the two steps the contract specifies: membership scope from
`afsconnect_api_tokens.member_reference_num` at middleware time, and Checkpoint `user_id`
from Brain via `ExternalMemberIdentityResolver` only where an actor id is needed.

### Read endpoints (scope `read`)

All shipped: `/status`, `/summary`, `/search`, `/activity`, `/insights`,
`/items/{source_app}/{source_id}`, and the four link routes — `GET /links`,
`GET /links/counts`, `POST /links`, `DELETE /links/{id}`. Response shapes delegate to the
same services behind the PM API, so SDK types stay shared.

### Write endpoints (scope `write`)

- **`POST /snapshot`** — validates `project_id` (string, ≤191), `content_hash` (string,
  ≤128), and `snapshot` (array), all required. Returns the plan's response shape
  including `skipped`, which is `true` when `content_hash` is unchanged since the last
  snapshot — the idempotency behaviour the contract promises. Rows stored with
  `source_app = external_sdk`.
- **`POST /activity`** — ingests `events[]`. `actor_checkpoint_user_id` on stored rows is
  `member.user_id` resolved from Brain at ingest time, never read from the request body
  and never stored on the token row.

### Error responses

The contract's status codes are all reachable:

| HTTP | Where it comes from |
|------|---------------------|
| `401` | `AfsConnectExternalTokenMiddleware` — missing, malformed, revoked, or unknown token; body code `EXTERNAL_TOKEN_INVALID` |
| `403` | `requireWriteScope()` on write routes; body code `EXTERNAL_TOKEN_SCOPE` |
| `503` | `RequiresExternalAfsConnectEntitlement` — afs-connect off globally, by plan, or for the member |
| `422` | Laravel validation on the write payloads |
| `429` | `throttle:120,1` on the route group |

Error bodies follow the documented `{ success, message, code }` shape.

### SDK config

Honoured — the clients take `coordinatorUrl` and `apiToken` and nothing else. See
[06-sdk-and-docs.complete.md](06-sdk-and-docs.complete.md).

## Files changed

No files are unique to this document — the contract is realised by the controllers and
middleware recorded in
[02-coordinator-external-routes.complete.md](02-coordinator-external-routes.complete.md)
and the SDK clients in [06-sdk-and-docs.complete.md](06-sdk-and-docs.complete.md). This
document tracks that the shipped surface matches what was specified.

## Verification steps

```bash
cd coordinator
php artisan test tests/Feature/AfsConnect/ExternalAfsConnectApiTest.php
```

The route table was also read back directly from `coordinator/routes/api.php:114-129` and
matched line by line against this document's endpoint list.

## Follow-up work

- **`X-AfsConnect-Sdk-Version`** — the contract describes this optional diagnostic header;
  no coordinator-side handling was added for it.
- **v2 prefix / deprecation window** — not needed yet; `v1` is the only external version.
