# Step 02 — Environment and configuration across all four projects

**On completion:** write `complete/02_environment_and_config_complete.md`.
**Depends on:** [01](01_start.md).
**Blocks:** every step after it. Nothing else can be written until there is an agreed place to read
a URL from.

---

## 02.1 The rule

> **Tagging (step 01 §01.4).** Every file this step creates or modifies carries
> `MARKET_MODIFIER_ADDITION` — created files in the top doc block, modified files at each changed
> line or region. `grep -rn MARKET_MODIFIER_ADDITION` is the change index for this whole effort.


> Never reference a website directly in code. Retrieve it from the project's `.env`; if the
> variable does not exist, create it.

Implemented as:

1. `.env` holds the value.
2. A file under `config/` reads it with `env()`.
3. Application code calls `config('market.urls.brain')`, not `env('BRAIN_URL')`.

### Why the middle step, given `config:cache` is not being used

**Project owner's decision: `php artisan config:cache` will not be run in production.** So the usual
headline argument for this pattern — that `env()` returns `null` once the config is cached — does not
apply here. `env()` in a controller would work.

The indirection is still worth keeping, on four smaller but real grounds:

- **One place for defaults.** `env('STRIPE_API_PREVIEW_VERSION', '…')` written at three call sites is
  three chances to disagree about the default. In a config file it is written once and every caller
  inherits it.
- **One place for coercion.** `env()` returns strings. `(int)` and `(bool)` casts belong in the config
  file rather than repeated at each use — `'0'` is truthy in PHP, and that has bitten every codebase
  at least once.
- **Testability.** `config(['marketplace.stripe.preview_version' => '…'])` is a one-line override in a
  test. Mutating `$_ENV` mid-test is not, and leaks between tests.
- **Discoverability.** `config/marketplace.php` is a single readable inventory of every marketplace
  setting. Twenty scattered `env()` calls are not.

### The one thing that would still bite

`php artisan optimize` runs `config:cache` as part of its job. If it is ever invoked — by a deploy
script, a Docker entrypoint, a CI step, or by hand — every `env()` outside `config/` starts returning
`null`, silently, and the symptom appears far from the cause: an empty SDK subdomain, a `0` fee, a
blank URL producing a relative fetch.

Following the pattern costs nothing and makes that class of incident impossible. That is the whole
case for it now — not a live trap, but a cheap immunity to one.

**Practical consequence for this plan:** where a step says "read through `config()`, never `env()`",
treat it as a convention to follow rather than a correctness requirement. Nothing breaks today if a
call site uses `env()`. Nothing is gained by it either.

## 02.2 market — `.env`

Append to `apps/market/.env`, and mirror into `_env/dev` and `_env/prod`. Every line tagged.

```ini
# ── MARKET_MODIFIER_ADDITION ──────────────────────────────────────────────
# Cross-project origins. Every one of these is read through config/market.php;
# nothing in application code may contain an appfactory.studio literal.
MARKET_URL=https://market15.appfactory.studio
MEMBERS_URL=https://members15.appfactory.studio
BRAIN_URL=https://www15.appfactory.studio
AUTH_URL=https://auth15.appfactory.studio

# Checkpoint (IdP). AUTH_URL is the SSO domain; the portal is where a
# successful login returns to.
CHECKPOINT_BASE_URL="${AUTH_URL}"
CHECKPOINT_APP_NAME=MARKET
CHECKPOINT_SERVER_URL=
MARKET_PORTAL_PATH=/portal
MARKET_PORTAL_REDIRECT_URL="${MARKET_URL}/portal"

# Appfactory SDK script host. 'api' in production, 'api-sandbox' otherwise.
# Kept as an explicit key rather than derived from APP_ENV so a sandbox
# checkout can be exercised against a production-flagged build.
AFS_SDK_HOST=api-sandbox

# Direct Payments (brain). The ref is the Direct Payment configuration id;
# the http url is what window.Portal.httpUrl is set to at runtime.
DIRECT_PAYMENTS_REF=eq4W10iF8
DIRECT_PAYMENTS_HTTP_URL="${BRAIN_URL}"

# Internal members <-> market API. Shared HMAC secret (step 04). Rotate by
# setting _NEXT, deploying both sides, then promoting.
MARKET_INTERNAL_API_KEY=
MARKET_INTERNAL_API_KEY_NEXT=
MARKET_INTERNAL_APP_NAME=MARKET
MARKET_INTERNAL_MAX_SKEW_SECONDS=300

# Asset storage root (step 05). Absolute server path; the per-member folder
# is appended by the application, never by configuration.
MARKET_ASSET_ROOT=/mnt/node2/market/members
MARKET_ASSET_MAX_UPLOAD_MB=2048
MARKET_ASSET_DOWNLOAD_TTL_SECONDS=300
MARKET_ASSET_XSENDFILE=1

# Commerce
# NOTE: no platform fee percentage here — it is a processor setting held in brain's
# database and snapshotted per listing at publish time (§02.4.2).
MARKET_DEFAULT_CURRENCY=usd

# Vite mirrors — the browser bundle cannot read a non-VITE_ key.
VITE_MARKET_URL="${MARKET_URL}"
VITE_BRAIN_URL="${BRAIN_URL}"
VITE_MEMBERS_URL="${MEMBERS_URL}"
VITE_CHECKPOINT_BASE_URL="${CHECKPOINT_BASE_URL}"
VITE_CHECKPOINT_SERVER_URL="${CHECKPOINT_SERVER_URL}"
VITE_CHECKPOINT_APP_NAME="${CHECKPOINT_APP_NAME}"
VITE_DIRECT_PAYMENTS_REF="${DIRECT_PAYMENTS_REF}"
VITE_DIRECT_PAYMENTS_HTTP_URL="${DIRECT_PAYMENTS_HTTP_URL}"
# ── MARKET_MODIFIER_ADDITION END ──────────────────────────────────────────
```

### `apps/market/config/market.php` — new file

Full `/** */` header, `MARKET_MODIFIER_ADDITION` in it. Shape:

```php
return [
    'urls' => [
        'market'  => env('MARKET_URL'),
        'members' => env('MEMBERS_URL'),
        'brain'   => env('BRAIN_URL'),
        'auth'    => env('AUTH_URL'),
    ],
    'sdk' => [
        'host' => env('AFS_SDK_HOST', 'api-sandbox'),
    ],
    'direct_payments' => [
        'ref'      => env('DIRECT_PAYMENTS_REF'),
        'http_url' => env('DIRECT_PAYMENTS_HTTP_URL'),
    ],
    'internal_api' => [
        'key'       => env('MARKET_INTERNAL_API_KEY'),
        'key_next'  => env('MARKET_INTERNAL_API_KEY_NEXT'),
        'app_name'  => env('MARKET_INTERNAL_APP_NAME', 'MARKET'),
        'max_skew'  => (int) env('MARKET_INTERNAL_MAX_SKEW_SECONDS', 300),
    ],
    'storage' => [
        'root'            => env('MARKET_ASSET_ROOT', '/mnt/node2/market/members'),
        'max_upload_mb'   => (int) env('MARKET_ASSET_MAX_UPLOAD_MB', 2048),
        'download_ttl'    => (int) env('MARKET_ASSET_DOWNLOAD_TTL_SECONDS', 300),
        'use_xsendfile'   => (bool) env('MARKET_ASSET_XSENDFILE', true),
    ],
    'commerce' => [
        // No platform_fee_percent — §02.4.2. Fetched from brain at publish time and
        // snapshotted onto the listing row.
        'default_currency' => env('MARKET_DEFAULT_CURRENCY', 'usd'),
    ],
    'portal' => [
        'path'         => env('MARKET_PORTAL_PATH', '/portal'),
        'redirect_url' => env('MARKET_PORTAL_REDIRECT_URL'),
    ],
    'checkpoint' => [
        'base_url'   => env('CHECKPOINT_BASE_URL'),
        'server_url' => env('CHECKPOINT_SERVER_URL'),
        'app_name'   => env('CHECKPOINT_APP_NAME', 'MARKET'),
    ],
];
```

### `apps/market/config/filesystems.php` — modify

Add a `market_assets` disk pointing at `config('market.storage.root')`, `'visibility' => 'private'`,
`'throw' => true`. Tag the added block.

### How the browser gets these

Two mechanisms, deliberately different:

- **Blade pages** (storefront, checkout): a `MarketConfigComposer` (step 11) shares `$afssdk` and a
  `$marketConfig` array with *every* view. The checkout page reads `$marketConfig['brain_url']` to
  set `window.Portal.httpUrl` in an inline script.
- **Bundled TS** (`portal.ts`, cart logic): `import.meta.env.VITE_*`.

Never a literal in either.

---

## 02.3 members — `.env`

`apps/members/.env` already has `BRAIN_API_URL`, `CHECKPOINT_BASE_URL`, and a family of
`VITE_*_URL` keys. Follow that established naming exactly rather than inventing a parallel scheme.

```ini
# ── MARKET_MODIFIER_ADDITION ──────────────────────────────────────────────
MARKET_URL="https://market15.appfactory.studio"
MARKET_API_URL="${MARKET_URL}/api/internal/v1"
MARKET_INTERNAL_API_KEY=
MARKET_INTERNAL_API_KEY_NEXT=
MARKET_INTERNAL_APP_NAME=MEMBERS
MARKET_REQUEST_TIMEOUT_SECONDS=30
MARKET_UPLOAD_TIMEOUT_SECONDS=900

VITE_MARKET_URL="${MARKET_URL}"
# Client-side upload limits, mirrored so the pre-upload analyzer (step 05)
# can reject an oversized package before a byte leaves the browser.
VITE_MARKET_MAX_UPLOAD_MB=2048
VITE_MARKET_MAX_FILENAME_BYTES=255
# ── MARKET_MODIFIER_ADDITION END ──────────────────────────────────────────
```

`MARKET_INTERNAL_API_KEY` must be byte-identical to market's. Same rotation discipline.

### `apps/members/config/market.php` — new file

`urls.market`, `api.base_url`, `api.key`, `api.key_next`, `api.app_name`, `api.timeout`,
`api.upload_timeout`. Same `/** */` + tag treatment.

---

## 02.4 brain — `.env`

> ### ⚠️ Almost nothing Connect-related belongs here
>
> **Processor settings live in the database, configured through the www Processor dashboard**
> (step 08 §08.3). The fee percentage, integration mode, collectors, country and currency are all
> per-processor values, and brain is multi-tenant — a single `.env` value cannot represent them. See
> §02.4.1.
>
> Brain's `.env` gets only what is genuinely per-deployment.

```ini
# ── MARKET_MODIFIER_ADDITION ──────────────────────────────────────────────
MEMBERS_URL="https://members15.appfactory.studio"
MARKET_URL="https://market15.appfactory.studio"

# Accounts v2 requires the preview API version string; stripe-php 21.2.0 pins the
# GA version in lib/Util/ApiVersion.php. Per-deployment, not per-processor — every
# processor on this deployment talks to the same Stripe API version.
STRIPE_API_PREVIEW_VERSION="2026-07-29.preview"
# ── MARKET_MODIFIER_ADDITION END ──────────────────────────────────────────
```

That is the whole of it. No `CONNECT_*`, no `PLATFORM_FEE_PERCENT`,
no `STRIPE_CONNECT_RETURN_PATH`.

### 02.4.1 Why processor settings are database values, not environment values

Three independent reasons, any one of which is sufficient:

- **Brain is multi-tenant** (step 01 §01.2.5). Every tenant configures their own processor. A single
  `PLATFORM_FEE_PERCENT` in `.env` cannot express "10% on the marketplace processor, 0% on a
  tenant's own processor" — it is one value for a per-row concern.
- **They are configured, not deployed.** The Processor dashboard exists precisely so these are
  editable without a deploy. Putting them in `.env` moves a configuration task into a release.
- **Some of them are immutable per account and must be recorded, not re-derived.** `dashboard` and
  `defaults.responsibilities` are fixed on a Stripe account at creation. What matters is what the
  account *was created with* — a stored row, not a current setting.

### `apps/www/config/marketplace.php` — new file

A small file: everything per-processor lives in the database (§02.4.1), so only per-deployment values remain.

```php
return [
    'urls' => [
        'members' => env('MEMBERS_URL'),
        'market'  => env('MARKET_URL'),
    ],
    'stripe' => [
        // Passed per-request on /v2/core/* calls ONLY (step 08 §08.7.1) — never as a
        // global client default, or it changes behaviour on v1 endpoints too.
        'preview_version' => env('STRIPE_API_PREVIEW_VERSION', '2026-07-29.preview'),
    ],
];
```

Everything else — fee percentage, integration mode, collectors, country, currency, configurations,
onboarding mode, return/refresh paths — is read from the **processor record** (step 08 §08.3), never
from config.

### 02.4.2 The fee percentage has exactly one home

The platform fee is a **processor setting**, configured in the Processor dashboard and stored on the
processor record. It is not in brain's `.env`, and it is not in market's `.env`.

| Where | What it holds |
|---|---|
| **Processor record** (brain, database) | The authoritative current rate. The one editable value. |
| `assets.platform_fee_percent` / `services.*` (market) | A **snapshot** taken at publish time (step 03), so a seller's agreed rate survives a later change to the processor setting. |
| market `.env` | **Nothing.** Market fetches the current default from brain when a listing is published; it does not keep its own copy. |

No cross-project drift check is needed (step 16 §16.1): with one home and a snapshot there is
nothing to drift, and a check that can never fire is noise that teaches people to ignore checks.

### members — Connect additions

`apps/members/.env` also gains, for the embedded components mounted in its dashboard (step 10):

```ini
# ── MARKET_MODIFIER_ADDITION ──────────────────────────────────────────────
BRAIN_URL="https://www15.appfactory.studio"
VITE_BRAIN_URL="${BRAIN_URL}"
MEMBERS_CONNECT_RETURN_PATH=/services/assetstore?connect=return
MEMBERS_CONNECT_REFRESH_PATH=/services/assetstore?connect=refresh
VITE_MEMBERS_CONNECT_RETURN_PATH="${MEMBERS_CONNECT_RETURN_PATH}"
AFS_SDK_HOST=api-sandbox
VITE_AFS_SDK_HOST="${AFS_SDK_HOST}"
# ── MARKET_MODIFIER_ADDITION END ──────────────────────────────────────────
```

`VITE_BRAIN_URL` becomes `window.Portal.httpUrl` on members pages that mount Connect components —
the same rule as market's checkout, and for the same reason: it must be set before `sdk.js` runs.

---

## 02.5 joystick_engine — `Resources/EngineConfig.json`

The engine has no `.env`. Its equivalent is `Resources/EngineConfig.json` — a tracked, non-secret,
routing-only config, copied into both app bundles at build time
(`JoystickWindow/CMakeLists.txt:174`, `JoystickLauncher/CMakeLists.txt:121`).

Add a `market` section, following the shape `afsConnect` already established:

```jsonc
"market": {
  "available": true,
  "baseUrl": "https://market15.appfactory.studio",
  "membersUrl": "https://members15.appfactory.studio",
  "portalUrl": "https://market15.appfactory.studio/portal",
  "endpoints": {
    "entitlements":   "/api/engine/v1/market/entitlements",
    "package":        "/api/engine/v1/market/packages/{package}",
    "packageVersions":"/api/engine/v1/market/packages/{package}/versions",
    "download":       "/api/engine/v1/market/packages/{package}/versions/{version}/download",
    "license":        "/api/engine/v1/market/packages/{package}/license",
    "services":       "/api/engine/v1/market/services"
  },
  "distributionTag": "joystick_engine",
  "requestTimeoutSeconds": 30,
  "downloadTimeoutSeconds": 600,
  "entitlementCacheSeconds": 300,
  "cacheDirName": "ServiceAssetsMarket"
}
```

Three properties of that block are load-bearing:

- **`available`** mirrors `afsConnect.available` — a build-level switch that hides the panel. It is
  *not* a security boundary; entitlement is enforced server-side on every request.
- **`distributionTag`** is sent as a query parameter, but market filters on it server-side
  regardless. Sending it makes the intent explicit in traffic logs; trusting it would be a bug.
- **No credential, ever.** The Checkpoint session token lives in the per-user session file that
  `ConnectIdentity` already reads. A token appearing in this file is a defect, per the file's own
  header comment.

### `Joystick/src/Joystick/Core/EngineConfig.h/.cpp` — modify

Add `struct MarketSection` alongside `DreamGraphSection` / `AfsConnectSection` / `CheckpointSection`,
with the same `IsConfigured()` + `GetEndpoint(key)` contract, and a `const MarketSection& Market()
const` accessor. Parse in `TryParseEngineConfig`. A missing `market` section must degrade to
`Available = false` with no warning — same tolerance the loader's header already promises.

**No `MARKET_MODIFIER_ADDITION` markers in the engine** — that convention covers brain, members and
market only (step 01 §01.4). Engine changes follow the engine's own documentation conventions.

---

## 02.6 Ownership map — which project defines a value

Values duplicated across `.env` files must have one owner. When they disagree, this table says who
is right.

| Value | Owner | Copied to | Consequence of drift |
|---|---|---|---|
| Market origin | market `MARKET_URL` | members, brain, engine | Members writes to the wrong market; engine downloads 404. |
| Members origin | members `APP_URL` | brain, market, engine | Connect onboarding returns to nowhere. |
| Brain origin | brain `APP_URL` | market, members | `window.Portal.httpUrl` wrong → checkout dead. |
| Auth origin | auth project | market, members, engine | Login redirect loop. |
| Internal API key | **generated once, shared** | members + market | Every internal call 401s. Loudest, safest failure. |
| Platform fee % | **brain's processor record** (database) | market snapshots per listing | Single home, no duplication — §02.4.2. |
| Direct Payment ref | brain | market | Checkout initializes an unknown DP. |
| Asset root | market | — | Not duplicated. Deliberately. |
| Stripe preview API version | brain | — | v2 account calls rejected; accounts silently read as null. |
| Connect integration mode | **brain's processor record** (database) | — | Decides who carries negative-balance liability, so it is a reviewed change made in the Processor dashboard, not a config edit. |

---

## 02.7 Verification

- [ ] `grep -rn "appfactory\.studio" --include=*.php --include=*.ts --include=*.blade.php apps/market/app apps/market/resources apps/market/routes` → **zero** hits.
- [ ] Same grep over `apps/members/app/Http/Controllers/Market`, `apps/members/resources/js/controllers/{AssetStore,Freelance}Controller.ts` → zero.
- [ ] `grep -rn "env(" apps/market/app apps/market/routes` → zero (all `env()` confined to `config/`).
- [ ] `php artisan tinker --execute="echo config('market.urls.brain');"` prints the brain URL. *(Optional, dev only: run `config:cache` once beforehand and repeat, then `config:clear`. Not required — `config:cache` is not used in production — but it proves the config would survive `php artisan optimize` if that ever runs.)*
- [ ] Engine: build `JoystickApp`, confirm `Resources/EngineConfig.json` in the bundle contains the `market` section, and that `EngineConfig::Get().Market().IsConfigured()` is true.
- [ ] Delete the `market` section from a copy of `EngineConfig.json`, relaunch: editor starts, panel absent, no crash, no error dialog.
- [ ] Every key added by this step exists in the root `.env`, `_env/dev/.env` and `_env/prod/.env`, with the §02.8 values.
- [ ] `AFS_SDK_HOST` is read through `config('market.sdk.host')`; no code branches on `APP_ENV` to choose an SDK host.
- [ ] Members makes no direct query against any `brain_*` table (step 01 D7): `grep -rn "brain_memberships\|brain_dns_zones\|brain_config\|brain_http_servers" apps/members/app` returns only API paths and comments, never `DB::table()` or a model binding.
- [ ] `EngineConfig.json` value editing is on the engine's release checklist (§02.8).
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/02_environment_and_config_complete.md` written.

---

## 02.8 Dev and production values — reference only

**How deployment works (project owner):** the root `.env` is the source of truth. At deployment it is
swapped for `_env/prod/.env`, which the owner audits at that time. **All work in this plan happens
against the development `.env`, the development database and the `*15` hostnames.** Environment
correctness is not a plan concern and no step blocks on it.

This section exists solely to record the values, so the keys added in §02.2–02.4 are written into
both files with the right content.

| Project | Development | Production | Dev database | Prod database |
|---|---|---|---|---|
| brain (www) | `www15.appfactory.studio` | `www.appfactory.studio` | `testdb_appfactorystudio` | `afsdb_appfactorystudio` |
| members | `members15.appfactory.studio` | `members.appfactory.studio` | `testdb_appfactorystudio` | `afsdb_appfactorystudio` |
| market | `market15.appfactory.studio` | `market.appfactory.studio` | `testdb_afsdb_market` | `afsdb_market` |
| auth | `auth15.appfactory.studio` | `auth.appfactory.studio` *(inferred; confirm at deploy)* | — | — |

`AFS_SDK_HOST` is `api-sandbox` in dev, `api` in production.

### Two facts that affect planning, not configuration

Not deployment hygiene — these change decisions made *now*:

- **Stripe test-mode objects do not exist in live mode.** Connected accounts (`acct_` ids), webhook
  signing secrets, the v2 event destination, the Direct Payment config (`eq4W10iF8`), and
  subscription Prices are all mode-scoped. In particular, **every seller onboarded in test mode must
  re-onboard after go-live** — which argues for going live before recruiting sellers rather than
  after.
- **Market has its own database.** Nothing marketplace-shaped is shared with brain or members, which
  is why step 04's channel is an API. The test identities `m1xTHpRmdtH2Y` and `m1xJppRmdtH2Y` live in
  `testdb_appfactorystudio`, so steps 17 and 19 are development-only by construction.

### The engine

`Resources/EngineConfig.json` is copied into the app bundle at build time
(`JoystickWindow/CMakeLists.txt:174`, `JoystickLauncher/CMakeLists.txt:121`), so it is a **release**,
not a file swap.

**Project owner's decision: the values are edited by hand before packaging.** No override path, no
runtime redirect. Add the `EngineConfig.json` edit to the engine's release checklist — that is the
whole of it.
