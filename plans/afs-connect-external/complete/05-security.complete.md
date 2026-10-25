# 05-security — Completion

**Completed:** 2026-07-31

**Status:** Complete for the controls the plan calls v1. Three items shipped in a weaker
form than the document describes — the rate-limit tiers, the auth-failure logging, and
`hash_equals` — and are listed under deviations rather than claimed as done.

## Summary

The security work has no source files of its own; it is the set of properties the token
service, middleware, route group, and members UI were built to hold. Each is recorded
here against the threat it answers.

### Token storage

- Plaintext is never persisted. `AfsConnectApiTokenService::create()` stores
  `hash('sha256', $plaintext)` and a 16-character display prefix.
- Plaintext is returned exactly once, in the create response, and surfaced once in the
  members UI behind a copy button.
- Nothing logs the plaintext.

### Scopes

`read` gates every GET route; `write` additionally gates `POST /snapshot` and
`POST /activity`. Enforced by `requireWriteScope()` in
`RequiresExternalAfsConnectEntitlement`, returning `403` with code
`EXTERNAL_TOKEN_SCOPE`.

`normalizeScopes()` makes `read` a required baseline — the create path cannot mint a
write-only token. The members UI offers the plan's two presets, read-only and
read-and-write.

### Revocation

Immediate and checked on every request: `revoke()` sets `revoked_at`, and
`findValidByPlaintext()` rejects revoked and expired rows on lookup. No token cache was
added, as the plan permits — the DB lookup is acceptable at SDK traffic levels.

`revokeForMember()` scopes by `member_reference_num`, so a member cannot revoke another
member's token by guessing an id.

### Identity, and the impersonation threat

The plan's two sharpest threats — a client sending `X-User-Id` to reach another member's
data, and a stale checkpoint user id — are both closed structurally rather than by a
check:

- The middleware populates `external_member_reference_num` from the token row and the
  external controllers read only that. There is no code path on this surface that reads
  a client-supplied id, so there is nothing to spoof.
- No checkpoint user id is stored on the token. `ExternalMemberIdentityResolver` resolves
  `member.user_id` from Brain (~1h cache) at the moment an actor id is needed.

### Entitlement defense in depth

In order, per request: valid token → entitlement check for the member → for write routes,
`external_sdk` source enabled for that member → then the work. Not entitled returns `503`
with the same shape as the PM API.

### Forged token creation

Members → coordinator token CRUD runs over the existing HMAC service auth
(`afs_connect.service`), and `Api\Internal\AfsConnectTokenController` additionally
requires the calling app to be `members`. An unsigned internet caller cannot mint a token
even if a members route were misconfigured.

### Members UI security

- Token CRUD requires the existing Checkpoint session.
- The create form carries the plan's confirmation checkbox — "I understand this token
  grants access to my afs-connect data" — and refuses to submit without it
  (`afsConnectSettings.ts:376`).
- The panel warns against committing tokens to public repositories.

### Rate limiting

`throttle:120,1` on the external route group — the plan's 120 requests/minute default.
Laravel's throttle middleware supplies `Retry-After` on `429`.

## Files changed

None specific to this document. The controls live in the files recorded in
[02-coordinator-external-routes.complete.md](02-coordinator-external-routes.complete.md),
[03-database-schema.complete.md](03-database-schema.complete.md), and
[01-token-management-members.complete.md](01-token-management-members.complete.md).

## Verification steps

```bash
cd coordinator
php artisan test tests/Unit/AfsConnect/AfsConnectApiTokenServiceTest.php
php artisan test tests/Feature/AfsConnect/ExternalAfsConnectApiTest.php
```

Between them these cover scope normalization (including the read-required rule),
revocation, `401` without a Bearer token, entitlement enforcement, and `403` for a
read-only token on a write route.

## Deviations from plan

- **Rate limiting is one tier, not three.** The plan specifies 120/min/token, a 30/second
  burst, and a tighter 20/min on write routes. Only the group-level `throttle:120,1`
  shipped, and it keys on the default throttle resolver rather than explicitly per token.
- **No `hash_equals`.** The plan calls for constant-time comparison; the implementation
  looks the token up by an indexed `token_hash` equality match instead. The lookup does
  not compare secrets in application code, so there is no local comparison to make
  constant-time — but the timing profile of the query itself was not analysed.
- **Auth failures are not logged.** The plan asks for the token id to be logged on
  failure for anomaly detection. `AfsConnectExternalTokenMiddleware` returns `401`
  silently — and on the common failure there is no row, so there is no id to log; a
  logged prefix would be the workable version.

## Follow-up work

- **Brain admin security surface** — view token count per member and force-revoke all
  tokens for a member. Listed as future in the plan; not built.
- **Per-token IP allowlist** — explicitly v2 in the plan; the `allowed_ips` column was not
  added.
- **Rotation** has no tooling; it is the manual create-new / update-SDK / revoke-old flow
  the plan describes.
