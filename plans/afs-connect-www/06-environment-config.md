# 06 — Environment & Configuration

All runtime configuration for afs-connect and afs-connect-www lives in **each app's own `.env` file**. There is no shared config server; `apps/afsserver` only hosts these plan documents.

---

## `BRAIN_API_URL` (required in every app)

The **Brain** control-plane HTTP API (member identity, DNS zones, HTTP tenants, brain config) is reached exclusively via the **`BRAIN_API_URL`** environment variable. **Every participating app must define it in its own `.env`** — including `coordinator`, `notes`, `dream_graph`, `flow`, `pdf_editor`, `members`, and `www` (Brain instance and tenant portals).

### Standard pattern (already in the codebase)

```php
// app/Http/Controllers/FunctionsController.php (each app)
public static function brainApiUrl(): string
{
    return rtrim(
        env('BRAIN_API_URL', 'https://www.appfactory.studio/portal/api/v1/brain'),
        '/'
    );
}
```

All Brain calls (`GET /member`, `GET /config`, `POST /http/tenants`, etc.) go through `brainApiUrl()` / `brainCall()` — **never** hardcode `www.appfactory.studio` in application code.

### Example `.env` values

```env
# Production (typical)
BRAIN_API_URL=https://www.appfactory.studio/portal/api/v1/brain

# Development / staging (typical)
BRAIN_API_URL=https://www15.appfactory.studio/portal/api/v1/brain
```

Each app sets the value appropriate to **that app's environment** (prod vs dev). Values may differ per deploy target but the **variable name is always `BRAIN_API_URL`**.

### Who calls Brain today

| App | Example use |
|-----|-------------|
| `coordinator` | Resolve `member_reference_num` via `GET /member?user_id=` |
| `notes`, `dream_graph`, `flow`, `pdf_editor` | Same — `ResolvesMember` → brain lookup |
| `members` | Website provisioning — DNS, tenants, config (`DomainController`) |
| `www` (Brain) | Serves the API; may still read `BRAIN_API_URL` when calling itself or a peer Brain in multi-region setups |

---

## afs-connect-www: Brain API vs www internal afs-connect API

Two URL paths on the **same www deployment**, both derived from `BRAIN_API_URL`:

| API | Path suffix | Purpose |
|-----|-------------|---------|
| **Brain API** | `/portal/api/v1/brain/...` | Identity, tenants, DNS — existing |
| **afs-connect internal API** | `/api/internal/afs-connect/v1/...` | Meta-provider (forms, DP, sponsorships, …) — new |

**Coordinator `WwwBrainClient`** must not introduce a separate hardcoded www host. Derive the internal API base from `BRAIN_API_URL`:

```php
// config/afs_connect.php (coordinator, members, and any app calling www afs-connect)
'www_afs_connect_base_url' => preg_replace(
    '#(/portal/api/v1/brain)?/?$#',
    '',
    rtrim(env('BRAIN_API_URL', ''), '/')
) . '/api/internal/afs-connect/v1',
```

Or equivalently in application code: strip the `/portal/api/v1/brain` suffix from `BRAIN_API_URL` and append `/api/internal/afs-connect/v1`.

Tenant portals posting webhooks use **`AFS_CONNECT_COORDINATOR_URL`** (coordinator ingest), not Brain URL — unless using Brain relay (Phase 2+ option).

---

## Tenant www site contact URLs

Member websites get a **secondary hostname** at provisioning time for when the member does not yet own their custom domain:

```
https://{subdomain}.sites.appfactory.studio/{path}
```

| Piece | Source |
|-------|--------|
| `{subdomain}` | `brain_http_server_tenants.subdomain` — lookup by `domain_name` |
| `{path}` | Portal route, e.g. `portal/admin#forms/vabc123` |

**Exception — `appfactory.studio` only:** use the Brain host from `BRAIN_API_URL`, not the sites hostname:

```
https://www.appfactory.studio/{path}
```

(staging: `https://www15.appfactory.studio/{path}` when `BRAIN_API_URL` points at www15)

Implementation: `WebsiteContactUrl` service in `www` and `coordinator`. Brain `/websites` response includes `tenant_subdomain` and `contact_base_url`.

Config (both apps):

```env
AFS_SITES_BASE_DOMAIN=sites.appfactory.studio
```

```php
// Default contact URL for a tenant
$url = $contactUrl->url($domainName, $tenantSubdomain, 'portal/api/v1/forms');
// appfactory.studio → https://www.appfactory.studio/portal/api/v1/forms
// example.com       → https://x1sabc….sites.appfactory.studio/portal/api/v1/forms
```

Do **not** use `https://www.{domain_name}/…` for tenant contact unless the domain is `appfactory.studio` — the member may not control DNS for their registered domain yet.

---

## Other required `.env` variables (afs-connect)

Each app also carries its own afs-connect service keys and identity. See [base afs-connect §06](../afs-connect/06-security-and-service-auth.md) and [03-schema-and-api.md](03-schema-and-api.md) §3.6.

| Variable | Apps | Purpose |
|----------|------|---------|
| `BRAIN_API_URL` | **All** | Brain control-plane API base |
| `AFS_CONNECT_SERVICE_KEY` | Each app | This app's signing key |
| `AFS_CONNECT_SERVICE_KEY_*` | Each app | Peer verification keys |
| `AFS_CONNECT_COORDINATOR_URL` | Source apps, members, www tenant | Coordinator base URL for webhooks / settings |
| `CHECKPOINT_URL` | Apps checking feature flags | Auth/Checkpoint base (separate from Brain) |
| `MEMBERS_API_URL` | Coordinator, Brain | Plan entitlements endpoint (members app) |

---

## `.env.example` requirement

When implementing afs-connect, add **`BRAIN_API_URL`** to every app's `.env.example` if not already present, alongside the `AFS_CONNECT_*` placeholders. Never commit real secrets or production URLs to git.

---

## Correction to earlier plan drafts

Some earlier drafts referenced ad-hoc Brain URLs, `apps/afsserver` provisioning secrets, or implicit www hostnames. **Authoritative rule:** Brain is always `BRAIN_API_URL` in each app's `.env`; www afs-connect internal paths are derived from that same variable's host, not a second config source.
