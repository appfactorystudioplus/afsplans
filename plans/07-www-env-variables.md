# 07 — www `.env` variables for `config/afs_connect.php` 

`apps/www/config/afs_connect.php` does **not** store settings. It maps **`.env` → `config('afs_connect.*')`** for the Brain meta-provider and internal afs-connect API.

Set afs-connect values in **`apps/www/.env` on the Brain deployment only** (`www.appfactory.studio`). Member tenant sites do not get this block.

Database access uses Laravel’s **standard** `DB_*` variables (`config/database.php`) — there are no separate `AFS_CONNECT_TENANT_DB_*` vars.

---

## Required

### `AFS_CONNECT_APP_NAME`

| | |
|---|---|
| **Config key** | `app_name` |
| **Default** | `www` |
| **Set to** | `www` |
| **Used for** | `X-AfsConnect-App` header when www signs outbound requests (e.g. calling coordinator settings). |

---

### `AFS_CONNECT_SERVICE_KEY`

| | |
|---|---|
| **Config key** | `service_key` |
| **Default** | *(empty)* |
| **Set to** | Secret string unique to www (32+ random chars). |
| **Used for** | Signing outbound requests from www. |
| **Must match** | `AFS_CONNECT_SERVICE_KEY_WWW` on coordinator (when coordinator is configured). |

---

### `AFS_CONNECT_SERVICE_KEY_COORDINATOR`

| | |
|---|---|
| **Config key** | `peer_keys.coordinator` |
| **Default** | *(empty)* |
| **Set to** | Coordinator’s secret — same value as coordinator’s `AFS_CONNECT_SERVICE_KEY`. |
| **Used for** | Verifying inbound signed requests **from coordinator** to `/api/internal/afs-connect/v1/*`. |

---

### `AFS_CONNECT_SERVICE_KEY_WWW`

| | |
|---|---|
| **Config key** | `peer_keys.www` |
| **Default** | *(empty)* |
| **Set to** | Same value as www’s `AFS_CONNECT_SERVICE_KEY`. |
| **Used for** | Verifying inbound requests where sender identifies as `www`. |

---

### `AFS_CONNECT_SERVICE_KEY_MEMBERS`

| | |
|---|---|
| **Config key** | `peer_keys.members` |
| **Default** | *(empty)* |
| **Set to** | Members app secret — same as members’ `AFS_CONNECT_SERVICE_KEY`. |
| **Used for** | Verifying inbound signed requests **from members** to `/api/internal/afs-connect/v1/*` (if members calls Brain directly). |

---

### `AFS_CONNECT_COORDINATOR_URL`

| | |
|---|---|
| **Config keys** | `coordinator_settings_url`, `coordinator_ingest_url` |
| **Default** | *(empty — URLs have no host and are broken)* |
| **Set to** | Coordinator **base URL only**, no path. |

```env
# Production
AFS_CONNECT_COORDINATOR_URL=https://coordinator.appfactory.studio

# Development / server 15 stack
AFS_CONNECT_COORDINATOR_URL=https://coordinator15.appfactory.studio
```

Nothing in PHP hardcodes this host — only `AFS_CONNECT_COORDINATOR_URL` in **this deployment’s** `.env` (then `php artisan config:cache` if you cache config).

**Becomes:**

- `{url}/api/internal/afs-connect/v1/settings`
- `{url}/api/internal/afs-connect/v1/ingest`

**Used for (now):** `AfsConnectEntitlementGuard` and Brain admin afs-connect tab proxy to coordinator settings.

---

### `AFS_CONNECT_COORDINATOR_VERIFY_SSL`

| | |
|---|---|
| **Config key** | `coordinator_verify_ssl` |
| **Default** | `true` when `APP_ENV=production`, otherwise **`false`** (so dev www → coordinator15 works before a dedicated cert exists) |
| **Set to** | `true` or `false` to override. Production should keep verification **on** once `coordinator15` (or prod host) has a valid Let’s Encrypt cert. |
| **Long-term fix** | Add nginx vhost + cert for `coordinator15.appfactory.studio` (same pattern as `www15.appfactory.studio`). |

---

### `BRAIN_API_URL`

| | |
|---|---|
| **Config key** | `brain_api_url` |
| **Default** | `https://www.appfactory.studio/portal/api/v1/brain` |
| **Set to** | This www deployment’s Brain API base. |

```env
# Production
BRAIN_API_URL=https://www.appfactory.studio/portal/api/v1/brain

# Staging
BRAIN_API_URL=https://www15.appfactory.studio/portal/api/v1/brain
```

**Used for (now):** `WebsiteContactUrl` — when domain is `appfactory.studio`, contact URLs use the host from this URL (e.g. `https://www.appfactory.studio/...`).

---

## Optional (recommended)

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

**Used for:** Tenant contact URLs: `https://{subdomain}.sites.appfactory.studio/{path}`

`{subdomain}` comes from `brain_http_server_tenants.subdomain` (lookup by `domain_name`).

---

## Database (standard Laravel — not in `config/afs_connect.php`)

afs-connect reads tenant module data through Laravel’s **default database connection**. Configure in `.env` as usual:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=brain
DB_USERNAME=...
DB_PASSWORD=...
```

| Deployment | `DB_DATABASE` points to |
|------------|-------------------------|
| **Brain** (`www.appfactory.studio`) | Brain central DB (`brain_http_server_tenants`, etc.) |
| **Tenant portal** (`x1sexample7k2m9.sites.appfactory.studio` for `example.com`) | That site’s tenant DB (e.g. `x1sexample7k2m9_db`) |

No `AFS_CONNECT_TENANT_DB_*` variables.

---

### `CHECKPOINT_URL`

| | |
|---|---|
| **Config key** | `checkpoint_features_url` (with `/checkpoint/authz/features` appended) |
| **Default if unset** | `https://auth.appfactory.studio` when `APP_ENV=production`, else `https://auth15.appfactory.studio` |
| **Used for (now)** | **Not wired yet** in www afs-connect code. Config is prepared for future global feature-flag checks. |

```env
CHECKPOINT_URL=https://auth.appfactory.studio
```

---

### `MEMBERS_API_URL`

| | |
|---|---|
| **Config key** | `members_entitlements_url` |
| **Default** | *(empty)* |
| **Used for (now)** | **Not wired yet** in www afs-connect code. Config is prepared for future plan entitlement checks. |

```env
MEMBERS_API_URL=https://members.appfactory.studio
```

---

## Other Laravel `.env` variables used indirectly

| Variable | Used when |
|----------|-----------|
| `APP_ENV` | Default Checkpoint URL when `CHECKPOINT_URL` is unset |
| `DB_*` | Default database connection for module queries (see above) |

---

## Used by www afs-connect code but not in `config/afs_connect.php`

### `AFS_CONNECT_DEV_ENABLED`

| | |
|---|---|
| **Default** | `false` |
| **Set to** | `true` only in local dev when coordinator is not set up. |
| **Used for** | `AfsConnectEntitlementGuard` skips coordinator settings and pretends afs-connect is enabled. |

Per-member afs-connect access is configured in **Brain → Memberships → member → afs-connect tab** (no member ref in `.env`).

---

## Not from `.env` (hardcoded in config)

| Config key | Value |
|------------|-------|
| `feature_key` | `afs_connect` |
| `appfactory_studio_domain` | `appfactory.studio` — only domain that uses `www.appfactory.studio` instead of `{subdomain}.sites…` |
| `www_source_apps` | Eight module keys (`www_forms`, `www_direct_payments`, …) |
| `provider_version` | `1.0.0` — returned by `/health` |

---

## Minimal afs-connect additions for Brain `apps/www/.env`

Merge from [env-www/.env](_env/env-www/.env). **Do not duplicate** vars already in the main www `.env` (`BRAIN_API_URL`, `DB_*`, `CHECKPOINT_BASE_URL`, `APP_ENV`, etc.).

```env
AFS_CONNECT_APP_NAME=www
AFS_CONNECT_SERVICE_KEY=<generate-a-secret>
AFS_CONNECT_SERVICE_KEY_COORDINATOR=<coordinator-secret>
AFS_CONNECT_SERVICE_KEY_WWW=<same-as-AFS_CONNECT_SERVICE_KEY>
AFS_CONNECT_SERVICE_KEY_MEMBERS=<members-secret>

AFS_CONNECT_COORDINATOR_URL=https://coordinator.appfactory.studio
CHECKPOINT_URL=https://auth.appfactory.studio
MEMBERS_API_URL=https://members.appfactory.studio

AFS_SITES_BASE_DOMAIN=sites.appfactory.studio
```

---

## Key pairing reminder

When coordinator is configured, these must match across apps:

| www `.env` | coordinator `.env` |
|------------|-------------------|
| `AFS_CONNECT_SERVICE_KEY` | `AFS_CONNECT_SERVICE_KEY_WWW` |
| `AFS_CONNECT_SERVICE_KEY_COORDINATOR` | `AFS_CONNECT_SERVICE_KEY` |

---

See also: [10-coordinator-env-variables.md](_env/10-coordinator-env-variables.md) for coordinator `.env` reference.

See also: [06-environment-config.md](afs-connect-www/06-environment-config.md) for cross-app rules and tenant contact URL convention.

---

## Brain identity and the Brain surface (`www/config/brain.php`; `members/config/brain_surface.php`)

Added 2026-09-25 with the enforcement trigger ([`billing/08`](billing/08-brain-enforcement.md)) and
[`www_brain`](www_brain/08-security-and-safety.md) §8.3. Values are secrets where marked; never commit a
production value.

### `IS_BRAIN` (www)

`true` only in the Brain's env sets (`www/_env/prod`, `dev`, `local`, `vagrant` and the active `.env` copied
from one of them). **Omitted** from the default tenant file `www/_env/www/.env` — omission means "not the
Brain", which is the correct value for every tenant. Read as `config('brain.is_brain')`; anything other than a
true boolean is false. In production (`APP_ENV=production`) it is only honoured when `APP_URL` is
`https://www.appfactory.studio`. The Brain-only routes (`brain.only`) answer `404` when it is false.

### `BRAIN_SURFACE_KEY_MEMBERS`, `BRAIN_SURFACE_KEY_MEMBERS_NEXT` (www) — secret

The key `www` accepts from `members` on the signed `/api/internal/brain/*` surface, and an optional second key
so a rotation needs no downtime. 64 hex characters, random, one per environment. Not `WWW_INTERNAL_API_KEY` or
`MEMBERS_INTERNAL_API_KEY` (those authenticate the opposite direction). Empty means the surface refuses every
call from that app.

### `WWW_BRAIN_SURFACE_URL`, `WWW_BRAIN_SURFACE_KEY` (members) — the key is secret

Where `members` sends surface calls (`https://www.<host>/api/internal/brain`, no trailing slash) and the key it
signs with. `WWW_BRAIN_SURFACE_KEY` must equal `www`'s `BRAIN_SURFACE_KEY_MEMBERS`. Empty means `members` sends
nothing.

### `BILLING_ENFORCEMENT_ENABLED` (members and www)

On `members`: whether the scheduled `billing:enforce-membership` calls `www` at all (default **false** in every
env set; `--force` overrides for a manual run). On `www`: the env kill switch inside the command (defaults on;
`brain_billing_settings.enabled` is the database switch behind it). Membership tiers change only when all three
are on.

### `BRAIN_SURFACE_KEY_<APP>`, `BRAIN_SURFACE_KEY_<APP>_NEXT` (www) — secret

One per app that may call the Brain's app surface (`MEMBERS`, `COORDINATOR`, `NOTES_EDITOR`, `PDF_EDITOR`, `FLOW_EDITOR`,
`GRAPH_EDITOR`, `MARKET`) — the same construction as `BRAIN_SURFACE_KEY_MEMBERS` above, one key per app so a leaked key
can only act as that app. 64 random hex characters, matching that app's `WWW_BRAIN_SURFACE_KEY`. Empty in the `dev` and
`prod` env sets until provisioned. See [`www_brain`](www_brain/08-security-and-safety.md) §8.2. Set (and paired with the apps) by `bin/set-surface-keys.sh` — see [`www_brain/complete/00_extras.md`](www_brain/complete/00_extras.md) E1.

### `BRAIN_APP_NAME`, `WWW_BRAIN_SURFACE_URL`, `WWW_BRAIN_SURFACE_KEY`, `BRAIN_CLIENT_ENABLED` (each app, via `afs/brain-client`)

The app's canonical Checkpoint name; the Brain's surface URL (`https://www.<host>/api/internal/brain`); the app's key;
and an off switch (`false` = the client never touches the network and every setting is its manifest default). Optional
tuning: `BRAIN_CLIENT_TTL_HOT` (60), `BRAIN_CLIENT_TTL` (600), `BRAIN_CLIENT_TIMEOUT` (5), `BRAIN_CLIENT_CONNECT_TIMEOUT` (2),
`BRAIN_CLIENT_BREAKER_FAILURES` (3), `BRAIN_CLIENT_BREAKER_COOLDOWN` (30), `BRAIN_CLIENT_CACHE_STORE`.

### `BRAIN_MAIL_FROM`, `BRAIN_MAIL_PER_APP_PER_MINUTE`, `BRAIN_MAIL_PER_APP_PER_DAY`, `BRAIN_MAIL_PER_RECIPIENT_PER_HOUR` (www)

The mailbox used by the built-in fallback message (default `no-reply`) and the sending limits for apps mailing through the
Brain (defaults 60/minute, 5000/day, 5 per recipient per hour for one event).
