# 03-database-schema — Completion

**Completed:** 2026-07-31

**Status:** Complete. The `afsconnect_api_tokens` table, model, and token service shipped
as specified. One cosmetic deviation: `token_prefix` stores 16 characters, not the 12 the
plan sketched.

## Summary

### Table

`afsconnect_api_tokens` created in the coordinator database by
`coordinator/database/migrations/2026_07_31_120000_create_afsconnect_api_tokens_table.php`,
with the model at `coordinator/app/Models/AfsConnect/AfsConnectApiToken.php`.

The row is the identity record for external API calls, and it holds
**`member_reference_num` only** — no `created_by_checkpoint_user_id`, no `user_id`. This
was the plan's central schema decision and it survived implementation intact: membership
primary users change in Brain, so a checkpoint id stored here would go stale.

### Checkpoint `user_id` resolution

Implemented off-table as the plan requires, in
`coordinator/app/Services/AfsConnect/ExternalMemberIdentityResolver.php`:

```
token → member_reference_num
      → getMemberByReference($ref)   // Brain GET /member?reference_num=
      → member['user_id']
```

Cached ~1 hour, matching the existing coordinator member-resolution pattern. Called at
activity-write time, where an actor id is actually needed.

### Token generation

`AfsConnectApiTokenService::create()` follows the plan's steps:

1. 32 cryptographically random bytes (`random_bytes(32)`)
2. Hex-encoded and appended to the prefix constant `afc_live_`
3. `token_hash = hash('sha256', $plaintext)`, stored; plaintext never persisted
4. `token_prefix = substr($plaintext, 0, 16)` for UI display
5. Row inserted; plaintext returned once in the create response

### Scopes

Normalized on create by `normalizeScopes()`: the allowed set is `read` and `write`,
duplicates collapse, an empty array is rejected, an unknown scope is rejected, and
**`read` is required as the baseline** — a `write`-only token cannot be minted.

### Lookup and lifecycle

- `findValidByPlaintext()` — rejects anything without the `afc_live_` prefix before
  touching the database, then matches on `token_hash` and rejects revoked or expired rows.
- `revoke()` / `revokeForMember()` — soft revoke via `revoked_at`; `revokeForMember()`
  scopes by `member_reference_num` so one member cannot revoke another's token.
- `listForMember()` — active tokens only, newest first.
- `touchLastUsed()` — direct update, called from the external middleware.

### Limits

`assertUnderLimit()` enforces `config('afs_connect.max_api_tokens_per_member')`, default
`10`, counting only non-revoked rows. Config key added to
`coordinator/config/afs_connect.php`.

### No members-side table

Held to. Members proxies every token operation to coordinator, so there is no second
store to drift.

## Files changed

- `coordinator/database/migrations/2026_07_31_120000_create_afsconnect_api_tokens_table.php` — new.
- `coordinator/app/Models/AfsConnect/AfsConnectApiToken.php` — new, with `isRevoked()` and
  `isExpired()`.
- `coordinator/app/Services/AfsConnect/AfsConnectApiTokenService.php` — new; create,
  find-by-plaintext, list, revoke, touch last-used, scope normalization, limit check.
- `coordinator/app/Services/AfsConnect/ExternalMemberIdentityResolver.php` — new, Brain
  `member_reference_num` → `user_id`.
- `coordinator/config/afs_connect.php` — `max_api_tokens_per_member`.

## Verification steps

```bash
cd coordinator
php artisan test tests/Unit/AfsConnect/AfsConnectApiTokenServiceTest.php
```

Covers create/find round-trip, revoke, and scope normalization.

## Deviations from plan

- **`token_prefix` is 16 characters**, not the 12 the plan's generation step named. Purely
  a display-width choice; the column is `VARCHAR(16)` and the value still leads with the
  `afc_live_` segment so users can tell tokens apart.

## Follow-up work

- **`expires_at` has no UI.** The column exists and `findValidByPlaintext()` honours it,
  but nothing sets it — the plan marks expiry as optional for v1 and hides it until v2.
- **Future columns not added**, as planned: `allowed_ips`, `rate_limit_override`,
  `source_app_override`.
