# 05 — Security

## Threat model

| Threat | Mitigation |
|--------|------------|
| Token leaked in git / logs | Prefix-only display in UI; hash at rest; docs warn one-time copy; revoke flow |
| Stolen token used from anywhere | v1: accept risk (like GitHub PAT). v2: optional IP allowlist |
| Token replay | Stateless Bearer — no replay window beyond HTTPS transport |
| Forged token creation | Internal members→coordinator calls require HMAC service auth |
| Escalation to another member's data | Token stores `member_reference_num` only; middleware never trusts client ids |
| Client sends `X-User-Id` trying to impersonate | **Ignored** on external routes — token + Brain lookup only |
| Stale checkpoint user id | **Not stored on token** — Brain `member.user_id` resolved fresh (cached ~1h) when needed |
| Write flood / DoS | Rate limiting per token + per member |
| Read exfiltration | Same entitlement gates as PM API; metadata-only index |

## Token storage

- **Never** log plaintext token
- **Never** return plaintext except on `POST` create (once)
- Store `SHA-256(plaintext)` only
- Use `hash_equals` for comparison

## Scopes

| Scope | Allows |
|-------|--------|
| `read` | All GET external routes |
| `write` | `POST /snapshot`, `POST /activity` |

Create UI offers presets:

- **Read only** → `["read"]`
- **Read and write** → `["read", "write"]`

## Rate limiting

Coordinator middleware or route group:

- Default: **120 requests / minute / token**
- Burst: **30 / second**
- Write routes: **20 / minute / token**

Return `429` with `Retry-After` header.

## Revocation

- Immediate: set `revoked_at = now()`
- Middleware checks `revoked_at IS NULL` on every request
- No token cache in v1 (DB lookup acceptable at SDK traffic levels; add Redis cache if needed)

## Entitlement defense in depth

1. Token must be valid
2. `AfsConnectEntitlementService::check(member_reference_num)` must pass
3. Write routes check source `external_sdk` enabled for member
4. Link/item fetches still respect source-app live authorization when proxying to providers

## Members UI security

- Token CRUD requires Checkpoint session (existing members auth)
- Confirm checkbox on create
- Show warning about not embedding in client binaries if token has `write` scope

## Comparison to other auth surfaces

| Surface | Trust boundary |
|---------|----------------|
| PM headers | Browser session — same user already logged into coordinator |
| HMAC ingest | Server-to-server — shared secret per app |
| Engine Checkpoint | Per-user OAuth token — Joystick SSO |
| **External token** | **Long-lived secret → `member_reference_num` on token row → Brain for current `user_id`; client sends token only** |

External tokens are closest to **GitHub personal access tokens** or **Stripe secret keys**: powerful, revocable, scoped.

## Compliance / ops

- Log token id (not hash) on auth failure for anomaly detection
- Brain admin (future): view token count per member, force-revoke all tokens for a member
- Rotate: user creates new token, updates SDK, revokes old

## What external SDK must NOT do

- Commit tokens to version control
- Ship write-scoped tokens in public mobile/game builds (prefer read-only or server-side write)
- Send tokens over HTTP (HTTPS only)
