# 10 — coordinator `.env` variables for `config/afs_connect.php` 

`apps/coordinator/config/afs_connect.php` does **not** store settings. It maps **`.env` → `config('afs_connect.*')`** for the afs-connect hub (index, settings API, reconcile, www Brain client).

Set values in **`apps/coordinator/.env`** only.

---

## Required

### `AFS_CONNECT_APP_NAME`

| | |
|---|---|
| **Config key** | `app_name` |
| **Default** | `coordinator` |
| **Set to** | `coordinator` |
| **Used for** | `X-AfsConnect-App` header when coordinator signs outbound requests (e.g. calling www Brain `/changes`). |

---

### `AFS_CONNECT_SERVICE_KEY`

| | |
|---|---|
| **Config key** | `service_key` |
| **Default** | *(empty)* |
| **Set to** | Secret string unique to coordinator (32+ random chars). |
| **Used for** | Signing outbound requests from coordinator. |
| **Must match** | `AFS_CONNECT_SERVICE_KEY_COORDINATOR` on www, notes, dream_graph, flow, pdf_editor, members. |

---

### `AFS_CONNECT_SERVICE_KEY_COORDINATOR`

| | |
|---|---|
| **Config key** | `peer_keys.coordinator` |
| **Default** | *(empty)* |
| **Set to** | Same value as coordinator’s `AFS_CONNECT_SERVICE_KEY`. |
| **Used for** | Verifying inbound signed requests where sender identifies as `coordinator` (internal/loopback calls). |

---

### `AFS_CONNECT_SERVICE_KEY_NOTES`

| | |
|---|---|
| **Config key** | `peer_keys.notes` |
| **Default** | *(empty)* |
| **Set to** | Same value as notes’ `AFS_CONNECT_SERVICE_KEY`. |
| **Used for** | Verifying inbound signed requests **from notes** (webhooks, internal API). |

---

### `AFS_CONNECT_SERVICE_KEY_DREAM_GRAPH`

| | |
|---|---|
| **Config key** | `peer_keys.dream_graph` |
| **Default** | *(empty)* |
| **Set to** | Same value as dream_graph’s `AFS_CONNECT_SERVICE_KEY`. |
| **Used for** | Verifying inbound signed requests **from dream_graph**. |

---

### `AFS_CONNECT_SERVICE_KEY_FLOW`

| | |
|---|---|
| **Config key** | `peer_keys.flow` |
| **Default** | *(empty)* |
| **Set to** | Same value as flow’s `AFS_CONNECT_SERVICE_KEY`. |
| **Used for** | Verifying inbound signed requests **from flow**. |

---

### `AFS_CONNECT_SERVICE_KEY_PDF_EDITOR`

| | |
|---|---|
| **Config key** | `peer_keys.pdf_editor` |
| **Default** | *(empty)* |
| **Set to** | Same value as pdf_editor’s `AFS_CONNECT_SERVICE_KEY`. |
| **Used for** | Verifying inbound signed requests **from pdf_editor**. |

---

### `AFS_CONNECT_SERVICE_KEY_MEMBERS`

| | |
|---|---|
| **Config key** | `peer_keys.members` |
| **Default** | *(empty)* |
| **Set to** | Same value as members’ `AFS_CONNECT_SERVICE_KEY`. |
| **Used for** | Verifying inbound signed requests **from members** (settings passthrough, etc.). |

---

### `AFS_CONNECT_SERVICE_KEY_WWW`

| | |
|---|---|
| **Config key** | `peer_keys.www` |
| **Default** | *(empty)* |
| **Set to** | Same value as www’s `AFS_CONNECT_SERVICE_KEY`. |
| **Used for** | Verifying inbound signed requests **from www** (if www webhooks via Brain relay in future). |

---

### `BRAIN_API_URL`

| | |
|---|---|
| **Config keys** | `brain_api_url`, `www_afs_connect_base_url` |
| **Default** | `https://www.appfactory.studio/portal/api/v1/brain` |
| **Set to** | www Brain API base for this environment. |

```env
# Production
BRAIN_API_URL=https://www.appfactory.studio/portal/api/v1/brain

# Staging
BRAIN_API_URL=https://www15.appfactory.studio/portal/api/v1/brain
```

**Becomes:**

- `brain_api_url` — Brain control-plane API (member lookup, tenants).
- `www_afs_connect_base_url` — derived automatically:
  `{BRAIN host}/api/internal/afs-connect/v1`
  e.g. `https://www.appfactory.studio/api/internal/afs-connect/v1`

**Used for (now):**

- `WwwBrainClient` — coordinator calls www Brain meta-provider (`/changes`, `/websites`, `/summary`, `/health`).
- `WebsiteContactUrl` — `appfactory.studio` contact base uses host from this URL.

**Do not** set a separate www afs-connect URL env var — it is always derived from `BRAIN_API_URL`.

---

## Required for entitlement checks (production)

### `CHECKPOINT_URL`

| | |
|---|---|
| **Config key** | `checkpoint_features_url` (with `/checkpoint/authz/features` appended) |
| **Default if unset** | `https://auth.appfactory.studio` when `APP_ENV=production`, else `https://auth15.appfactory.studio` |
| **Used for (now)** | `AfsConnectEntitlementService::isGlobalEnabled()` — global afs-connect kill switch. |

```env
CHECKPOINT_URL=https://auth.appfactory.studio
```

---

### `MEMBERS_API_URL`

| | |
|---|---|
| **Config key** | `members_entitlements_url` |
| **Default** | *(empty)* |
| **Set to** | Members app base URL, no path. |

```env
MEMBERS_API_URL=https://members.appfactory.studio
```

**Used for (now):** `AfsConnectEntitlementService::planIncludesAfsConnect()` — checks if member’s plan includes afs-connect.

If empty, coordinator falls back to `AFS_CONNECT_DEV_PLAN_ENTITLED` (dev only).

---

## Optional

### `AFS_CONNECT_TIMESTAMP_SKEW`

| | |
|---|---|
| **Config key** | `timestamp_skew_seconds` |
| **Default** | `300` (5 minutes) |
| **Set to** | Usually leave unset. |
| **Used for** | Max age of an incoming signed request before middleware rejects it as a replay. |

---

### `AFS_SITES_BASE_DOMAIN`

| | |
|---|---|
| **Config key** | `sites_base_domain` |
| **Default** | `sites.appfactory.studio` |
| **Set to** | Usually leave unset. There is **no** `sites15` — always `sites.appfactory.studio`. |

```env
AFS_SITES_BASE_DOMAIN=sites.appfactory.studio
```

**Used for:** `WebsiteContactUrl` — building tenant contact URLs in index/deep links:
`https://{subdomain}.sites.appfactory.studio/{path}`

---

### `AFS_CONNECT_DEV_PLAN_ENTITLED`

| | |
|---|---|
| **Default** | `false` |
| **Set to** | `true` only in local dev when `MEMBERS_API_URL` is not wired yet. |
| **Used for** | `AfsConnectEntitlementService` — bypasses members entitlements API and treats all members as plan-entitled. |

**Not in `config/afs_connect.php`** — read directly via `env()` in entitlement service.

---

## Fallback variables (read by config, not `AFS_*` names)

| Variable | Used when |
|----------|-----------|
| `APP_ENV` | Chooses default Checkpoint URL when `CHECKPOINT_URL` is unset |

---

## Not from `.env` (hardcoded in config)

| Config key | Value |
|------------|-------|
| `feature_key` | `afs_connect` |
| `appfactory_studio_domain` | `appfactory.studio` — only domain that uses `www.appfactory.studio` instead of `{subdomain}.sites…` |
| `source_apps` | All twelve source apps (notes, dream_graph, flow, pdf_editor + eight www modules) |
| `www_source_apps` | Eight www module keys only |

---

## Coordinator does **not** use these (www / source apps only)

| Variable | Why |
|----------|-----|
| `AFS_CONNECT_COORDINATOR_URL` | Coordinator **is** the hub — other apps point here. |
| `AFS_CONNECT_TENANT_DB_*` | Removed — use standard Laravel `DB_*` instead. |
| `AFS_CONNECT_DEV_ENABLED` | www Brain entitlement bypass — not used on coordinator. |

---

## Minimal afs-connect additions for `apps/coordinator/.env`

Merge from [env-coord/.env](env-coord/.env). **Do not duplicate** vars already in the main coordinator `.env` (`BRAIN_API_URL`, `DB_*`, `CHECKPOINT_BASE_URL`, `APP_ENV`, etc.).

```env
AFS_CONNECT_APP_NAME=coordinator
AFS_CONNECT_SERVICE_KEY=<generate-a-secret>
AFS_CONNECT_SERVICE_KEY_COORDINATOR=<same-as-AFS_CONNECT_SERVICE_KEY>
AFS_CONNECT_SERVICE_KEY_NOTES=<notes-secret>
AFS_CONNECT_SERVICE_KEY_DREAM_GRAPH=<dream_graph-secret>
AFS_CONNECT_SERVICE_KEY_FLOW=<flow-secret>
AFS_CONNECT_SERVICE_KEY_PDF_EDITOR=<pdf_editor-secret>
AFS_CONNECT_SERVICE_KEY_MEMBERS=<members-secret>
AFS_CONNECT_SERVICE_KEY_WWW=<www-secret>

CHECKPOINT_URL=https://auth.appfactory.studio
MEMBERS_API_URL=https://members.appfactory.studio

AFS_SITES_BASE_DOMAIN=sites.appfactory.studio
```

Dev-only fallback while members entitlements API is unavailable:

```env
AFS_CONNECT_DEV_PLAN_ENTITLED=true
```

---

## Key pairing reminder

Each source app holds **its own** `AFS_CONNECT_SERVICE_KEY`. Coordinator holds a copy of each as `AFS_CONNECT_SERVICE_KEY_{APP}`.

| Source app `.env` | Coordinator `.env` |
|-------------------|-------------------|
| `AFS_CONNECT_SERVICE_KEY` | `AFS_CONNECT_SERVICE_KEY_{APP}` |

Examples:

| notes | `AFS_CONNECT_SERVICE_KEY` → coordinator `AFS_CONNECT_SERVICE_KEY_NOTES` |
| www | `AFS_CONNECT_SERVICE_KEY` → coordinator `AFS_CONNECT_SERVICE_KEY_WWW` |

Coordinator outbound to www:

| coordinator `.env` | www `.env` |
|--------------------|------------|
| `AFS_CONNECT_SERVICE_KEY` | `AFS_CONNECT_SERVICE_KEY_COORDINATOR` |

---

## Internal endpoints coordinator exposes (inbound)

Other apps call these on coordinator (signed with their own key):

| Path | Purpose |
|------|---------|
| `POST /api/internal/afs-connect/v1/ingest` | Webhook index updates |
| `GET /api/internal/afs-connect/v1/settings` | Read member afs-connect settings |
| `POST /api/internal/afs-connect/v1/settings` | Update member afs-connect settings |

Public (member-facing):

| Path | Purpose |
|------|---------|
| `GET /api/afs-connect/status` | Dashboard entitlement status |

---

See also:

- [07-www-env-variables.md](../07-www-env-variables.md) — www Brain `.env` reference
- [06-environment-config.md](../afs-connect-www/06-environment-config.md) — cross-app rules, tenant contact URLs
- [06-security-and-service-auth.md](../afs-connect/06-security-and-service-auth.md) — signing protocol
