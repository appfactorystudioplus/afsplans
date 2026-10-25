# 01 — Token management (members app)

## Purpose

Let a **human** (already logged into members via Checkpoint) create, list, and revoke API tokens that **external code** will use against coordinator.

## UI location

**Primary (v1):** AFS Connect settings card in `members/resources/js/templates/views/settings.html`, managed by `afsConnectSettings.ts`.

Add a collapsible section **“API access for external apps”** below the source toggles:

```
┌─ AFS Connect ─────────────────────────────────────┐
│ [master enable] [source toggles...]                 │
│                                                     │
│ ▼ API access for external apps                      │
│   Use these tokens in Unity, mobile, or CLI tools.  │
│   Never commit tokens to public repositories.       │
│                                                     │
│   Name          Prefix      Scopes    Last used     │
│   Unity build   afc_live_ab…  read      2h ago  [×] │
│   CI search     afc_live_cd…  read      never [×]   │
│                                                     │
│   [+ Create token]                                  │
└─────────────────────────────────────────────────────┘
```

**Create-token modal:**

- **Name** (required, max 64 chars) — e.g. “Unity plugin”, “Staging CI”
- **Scopes** (required) — `Read only` | `Read and write`
- Checkbox: “I understand this token grants access to my afs-connect data”

On success, show plaintext token in a copy-only field with warning: **“Copy now — you won’t see this again.”**

**Not in v1:** `MarketController.ts` — unrelated to credentials. Revisit only if API keys become a marketplace seller feature.

## Members API routes (Checkpoint session required)

All routes require the same authenticated session the rest of members uses (existing Checkpoint middleware / controller auth pattern).

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/v1/afs-connect/tokens` | List tokens for current membership |
| `POST` | `/api/v1/afs-connect/tokens` | Create token |
| `DELETE` | `/api/v1/afs-connect/tokens/{id}` | Revoke token |

### `GET /api/v1/afs-connect/tokens`

Response:

```json
{
  "success": true,
  "tokens": [
    {
      "id": 42,
      "name": "Unity build",
      "token_prefix": "afc_live_ab12",
      "scopes": ["read"],
      "created_at": "2026-07-31T12:00:00Z",
      "last_used_at": "2026-07-31T14:22:00Z",
      "expires_at": null
    }
  ]
}
```

Never return the full secret after creation.

### `POST /api/v1/afs-connect/tokens`

Body:

```json
{
  "name": "Unity build",
  "scopes": ["read"]
}
```

Validation:

- `name`: required, string, 1–64
- `scopes`: required, array, subset of `read`, `write` (v1 bundle: `read` only, or `read`+`write` for read_write)

Pre-checks (members or coordinator):

- At token **create** (browser session only): resolve `member_reference_num` from the logged-in membership (`member.user_id` → reference num via existing Brain lookup). **Only `member_reference_num` is stored on the token row.**
- afs-connect entitled for member (same gates as settings panel)
- Token count under limit

**External API callers never send any ids.** Coordinator derives everything from the token → `member_reference_num` → Brain member row.

Response `201`:

```json
{
  "success": true,
  "token": {
    "id": 43,
    "plaintext": "afc_live_ab12cd34....full_secret....",
    "token_prefix": "afc_live_ab12",
    "name": "Unity build",
    "scopes": ["read", "write"],
    "created_at": "2026-07-31T12:05:00Z"
  }
}
```

### `DELETE /api/v1/afs-connect/tokens/{id}`

Soft-revoke: set `revoked_at`, clear ability to authenticate. Response `200` `{ "success": true }`.

Return `404` if token belongs to another member.

## Members → coordinator delegation

Members does **not** store token hashes locally (coordinator validates external calls). Members controllers call coordinator via a new signed internal endpoint:

| Coordinator internal | Called by members |
|---------------------|-------------------|
| `GET /api/internal/afs-connect/v1/tokens?member_reference_num=...` | List |
| `POST /api/internal/afs-connect/v1/tokens` | Create |
| `DELETE /api/internal/afs-connect/v1/tokens/{id}` | Revoke |

Internal requests include:

- Existing HMAC headers (`X-AfsConnect-*`) with members' service identity
- Body fields at **create**: `member_reference_num`, `name`, `scopes` (no checkpoint user id)

Coordinator verifies the session user belongs to that membership before insert. The token row stores **`member_reference_num` only** as the identity anchor.

Coordinator generates the random secret, stores the hash, returns plaintext once to members, which forwards to the browser.

**Why internal + signed:** Prevents arbitrary internet callers from minting tokens if members routes were ever misconfigured.

## Frontend files to add/change

| File | Change |
|------|--------|
| `members/resources/js/templates/views/settings.html` | API access subsection HTML |
| `members/resources/js/settings/afsConnectSettings.ts` | Load/create/revoke token UI |
| `members/app/Http/Controllers/AfsConnectSettingsController.php` | Add token methods OR new `AfsConnectTokenController.php` |
| `members/routes/web.php` | Register three token routes |
| `members/tests/Feature/` | Feature tests for token CRUD proxy |

## Entitlement gating in UI

- Hide API access section if plan does not include afs-connect (same as master toggle visibility).
- Disable “Create token” if master afs-connect switch is off.
- Show link to coordinator `#/afs-connect` for testing search after token creation.

## Audit expectations

Display **last used** from coordinator (updated on each external API call). Members UI is read-only for audit; coordinator writes `last_used_at`.
