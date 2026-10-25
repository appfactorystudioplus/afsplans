# 03 — Database schema

## Table: `afsconnect_api_tokens`

Lives in **coordinator** database (same DB as other `afsconnect_*` tables).

**The token row is the identity record for external API calls.** One valid Bearer token maps to exactly one membership and one creating Checkpoint user. External clients never send those ids; middleware loads them from this table on every request.

```sql
CREATE TABLE afsconnect_api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_reference_num VARCHAR(64) NOT NULL,
    name VARCHAR(64) NOT NULL,
    token_hash CHAR(64) NOT NULL,          -- SHA-256 hex of full secret
    token_prefix VARCHAR(16) NOT NULL,     -- e.g. afc_live_ab12 — for UI display
    scopes JSON NOT NULL,                  -- ["read"] or ["read","write"]
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,             -- NULL = no expiry (v1 default)
    revoked_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,

    UNIQUE KEY uq_afsconnect_api_tokens_hash (token_hash),
    KEY idx_afsconnect_api_tokens_member (member_reference_num),
    KEY idx_afsconnect_api_tokens_revoked (revoked_at)
);
```

## Field notes

| Field | Purpose |
|-------|---------|
| `member_reference_num` | **Only persisted identity field** — scopes all index/search/link queries |
| `token_hash` | Never store plaintext; lookup key for middleware |
| `token_prefix` | Shown in members UI so user knows which token is which |
| `scopes` | `read` → GET endpoints; `write` → POST snapshot/activity |
| `revoked_at` | Soft delete; revoked tokens fail auth immediately |
| `expires_at` | Optional v1; UI can hide expiry until v2 |
| `last_used_at` | Updated on successful external auth (async job OK) |

## Checkpoint `user_id` — not on this table

When coordinator needs the member's current Checkpoint user id (activity `actor_checkpoint_user_id`, live item fetches that pass `requested_by_checkpoint_user_id` to source apps):

```
token → member_reference_num
      → FunctionsController::getMemberByReference($ref)   // Brain GET /member?reference_num=
      → member['user_id']
```

Use the same Brain client and cache pattern as existing coordinator member resolution. **Do not** store `user_id` or `created_by_checkpoint_user_id` on the token — Brain is the source of truth and stays up to date when membership primary user changes.

## Token generation (coordinator)

On create:

1. Generate 32 cryptographically random bytes
2. Encode as base62 or hex → append to prefix `afc_live_`
3. `token_hash = hash('sha256', plaintext)`
4. `token_prefix = first 12 chars of plaintext` (including prefix segment for display)
5. Insert row; return plaintext in HTTP response once

## Limits

- Config: `config/afs_connect.php` → `max_api_tokens_per_member` default `10`
- Enforced on create in `AfsConnectTokenController`

## Migration file

`coordinator/database/migrations/YYYY_MM_DD_HHMMSS_create_afsconnect_api_tokens_table.php`

## No members DB table

Members app proxies all token operations to coordinator. Avoids sync drift between two stores.

## Future columns (not v1)

- `allowed_ips JSON NULL` — IP restriction
- `rate_limit_override INT NULL`
- `source_app_override VARCHAR(32) NULL` — per-token source key for partners
