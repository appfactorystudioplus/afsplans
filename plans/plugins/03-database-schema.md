# 03 — Database schema

## Completion document

When this plan step is completed, write `complete/03-database-schema.complete.md`.

## Code documentation

Document all new and changed code thoroughly using block comments (PHPDoc on models,
migrations, and services). Every column whose meaning is not obvious from its name gets a
comment in the migration.

---

> **Corrected 2026-09-27.** Every table below was originally `plugin_`-prefixed with a
> `tenant_reference_num` column, as if one shared database served many logical tenants. That's
> not this ecosystem (00 §0): a tenant is a separately deployed, separately databased instance of
> this codebase. This rewrite splits the schema into what's genuinely central (the marketplace,
> living on the Brain's own database, `brain_plugin_*`) and what's local to each tenant's own
> deployment (`laravel_plugin_*`, no tenant column — a deployment's own database has exactly one
> tenant). It also moves the capability catalog out of the database entirely, into shipped code —
> §0 explains why that's not just a rename.

## 0. What changed structurally, not just in naming

- **The capability catalog is code, not a table.** `plugin_capabilities_catalog` — the fixed list
  of what a manifest can request — is identical across every deployment because every deployment
  runs the same codebase. There is no staleness problem a database table would solve, and a table
  would require a live lookup (or a sync mechanism) for something that already ships for free with
  an ordinary deploy. It's now `config/plugin_capabilities.php`, versioned exactly like
  [06 §4](06-extension-points-and-hooks.md)'s extension-point catalog, read the same way
  `config/portal_permissions.php` already is ([01 §4](01-current-state-audit.md)) — no schema
  below defines it.
- **Central tables and local tables are never joined by foreign key**, because they are never in
  the same database. A tenant's `laravel_plugin_installs` row identifies a marketplace release by
  **value** — `plugin_slug`, `version`, and a locally cached copy of everything the deployment
  needs to actually run the plugin (its manifest, its artifact paths, its hashes) — not by an `fk`
  the local database has no way to resolve. This is deliberate: per
  [02 §8](02-architecture-overview.md)'s pull-as-source-of-truth rule, an already-installed plugin
  keeps running even if the marketplace is completely unreachable, which is only possible if the
  tenant's own database already holds everything it needs.

## 1. Central: the marketplace catalog (lives on the Brain's own database)

Owned entirely by the Brain instance's migrations. Every other deployment (every tenant clone)
receives these same migrations — they're part of the same codebase — but never populates them,
exactly like every other `brain_*` table on a clone ([AGENTS.md §5](../../../w/AGENTS.md)).

### `brain_plugin_developers`

One row per registered developer account (a developer submits to the marketplace; they do not
necessarily run a tenant on this platform at all).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `reference_num` | string(64), unique | External-facing id, same `reference_num` convention used estate-wide |
| `display_name` | string(255) | Shown on every plugin's detail page |
| `contact_email` | string(255) | |
| `status` | enum(`pending`,`verified`,`suspended`) | A suspended developer's existing published releases can still run (tenants already installed them); they cannot submit new ones — see [10 §6](10-review-and-publishing-pipeline.md) |
| `identity_fingerprint` | char(64) | `sha256` over the strongest identity signal available at registration (verified email domain at minimum; once [12](12-billing-and-monetization.md) exists, folded together with the payout account's underlying bank/card fingerprint from Stripe Connect) — never displayed, used only to check against `brain_plugin_developer_bans` |
| `payout_account_ref` | string(64), nullable | Set only once [12](12-billing-and-monetization.md) is built |
| `created_at`, `updated_at` | timestamps | |

### `brain_plugin_developer_bans`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `identity_fingerprint` | char(64) | Copied from the suspended developer's fingerprint at the moment of suspension |
| `reason` | string(255) | |
| `banned_at` | timestamp | |
| `original_developer_id` | fk → `brain_plugin_developers.id`, nullable | For investigation only |

Index: `brain_plugin_developer_ban_fingerprint_index` on `identity_fingerprint`. Checked at
registration ([10 §2](10-review-and-publishing-pipeline.md)) — a match routes the new account into
mandatory manual review rather than blocking registration outright.

### `brain_plugin_catalog`

One row per plugin **identity** (a slug), independent of any specific version.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `slug` | string(64), unique | Immutable once created — the value every tenant's local install row references |
| `developer_id` | fk → `brain_plugin_developers.id` | |
| `name` | string(255) | |
| `summary` | string(500) | |
| `category` | string(64) | |
| `status` | enum(`draft`,`in_review`,`published`,`rejected`,`delisted`) | See [07 §1](07-installation-and-activation-lifecycle.md) |
| `latest_published_release_id` | fk → `brain_plugin_releases.id`, nullable | |
| `created_at`, `updated_at` | timestamps | |

Index: `brain_plugin_catalog_status_category_index` on (`status`, `category`).

### `brain_plugin_releases`

One row per **version**. Immutable once signed (D6).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `plugin_id` | fk → `brain_plugin_catalog.id` | |
| `version` | string(32) | Semver, unique per plugin |
| `manifest_json` | json | The full, as-reviewed manifest — this is what a tenant's pull downloads and caches locally |
| `backend_artifact_path` | string(255) | The reviewed, re-packaged PHP artifact — a tenant's install fetches and stores its own copy |
| `backend_artifact_sha256` | char(64) | |
| `frontend_bundle_path` | string(255), nullable | |
| `frontend_bundle_sha256` | char(64), nullable | |
| `signature` | text | Over the manifest + both hashes ([04 §5](04-manifest-and-package-format.md)) |
| `signing_key_id` | string(64) | Which signing key produced `signature` ([05 §11](05-security-and-sandboxing.md)) |
| `reviewer_approval_ref` | string(64) | The reviewer-issued approval assertion's id ([05 §11](05-security-and-sandboxing.md)) |
| `review_status` | enum(`pending`,`passed_automated`,`in_manual_review`,`approved`,`rejected`) | |
| `changelog` | text, nullable | |
| `published_at` | timestamp, nullable | |
| `revoked_at` | timestamp, nullable | The kill switch ([05 §7](05-security-and-sandboxing.md)) — checked by every tenant's own next pull, and pushed as an accelerator ([02 §8](02-architecture-overview.md)) |
| `created_at`, `updated_at` | timestamps | |

Index: `brain_plugin_release_plugin_review_index` on (`plugin_id`, `review_status`).

## 2. Local: this deployment's own installs (lives in each tenant's own database)

Owned by this same codebase's migrations, shipped to and populated independently by every
deployment. **No column here identifies which tenant this is** — a deployment's own database only
ever holds its own installs.

### `laravel_plugin_installs`

One row per plugin **this deployment** has installed — a local, durable cache of everything needed
to keep running it without the marketplace being reachable.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `plugin_slug` | string(64) | References `brain_plugin_catalog.slug` **by value**, not by foreign key — the marketplace is a different database entirely |
| `installed_version` | string(32) | The currently installed release's version |
| `manifest_json` | json | This deployment's own cached copy of the installed release's manifest, fetched and verified at install/update time — the dispatcher and consent screens read this copy, never a live marketplace call |
| `backend_artifact_path` | string(255) | Where the verified artifact was stored on **this deployment's own** filesystem |
| `backend_artifact_sha256` | char(64) | Re-verified against this whenever the Plugin Runtime loads it |
| `frontend_bundle_path` | string(255), nullable | |
| `frontend_bundle_sha256` | char(64), nullable | |
| `signature`, `signing_key_id` | text, string(64) | Kept locally so this deployment can re-verify integrity at any time without calling home |
| `state` | enum(`installed`,`active`,`disabled`,`uninstalling`) | [07 §1](07-installation-and-activation-lifecycle.md)'s state machine |
| `installed_at`, `activated_at`, `disabled_at` | timestamps, nullable where applicable | |
| `disabled_reason` | string(255), nullable | Distinguishes this deployment's own admin disabling it from a marketplace kill switch or local circuit-breaker trip ([13 §2](13-observability-and-incident-response.md)) |
| `last_checked_at` | timestamp, nullable | When this deployment last pulled the marketplace for an update or revocation check ([02 §8](02-architecture-overview.md)) |

Unique index: `laravel_plugin_installs_slug_unique` on `plugin_slug` — this deployment has at most
one install per plugin identity, ever.

### `laravel_plugin_grants`

One row per capability this deployment's admin has approved for one install.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `install_id` | fk → `laravel_plugin_installs.id` | A normal local foreign key — same database |
| `capability_key` | string(128) | Validated against the shipped `config/plugin_capabilities.php` (§0), not a table |
| `parameters_json` | json, nullable | Set only for a parameterized capability (`http.egress` today) — the exact approved values, copied at consent time ([04 §3.1](04-manifest-and-package-format.md)) |
| `granted_at` | timestamp | |
| `granted_by_portal_user_id` | fk → this deployment's own portal users table | |
| `revoked_at` | timestamp, nullable | Checked by the local dispatcher on every invocation — immediate effect, no reinstall needed |

Index: `laravel_plugin_grant_install_capability_index` on (`install_id`, `capability_key`).

### `laravel_plugin_settings`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `install_id` | fk → `laravel_plugin_installs.id` | |
| `key` | string(128) | |
| `value_json` | json | |
| `updated_at` | timestamp | |

Unique index: `laravel_plugin_setting_install_key_unique` on (`install_id`, `key`).

## 3. Local: plugin-owned data

Same reasoning as before (a plugin never gets `CREATE TABLE` power —
[05 §5](05-security-and-sandboxing.md)), just re-homed to this deployment's own database:

### `laravel_plugin_data`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `install_id` | fk → `laravel_plugin_installs.id` | |
| `collection` | string(64) | Declared in the manifest ([04 §4](04-manifest-and-package-format.md)) |
| `record_key` | string(191) | |
| `payload_json` | json | |
| `created_at`, `updated_at` | timestamps | |

Unique index: `laravel_plugin_data_install_collection_key_unique` on
(`install_id`, `collection`, `record_key`). Index:
`laravel_plugin_data_install_collection_index` on (`install_id`, `collection`).

## 4. Local: audit log

### `laravel_plugin_audit_log`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `install_id` | fk → `laravel_plugin_installs.id`, nullable | Null for a deployment-level event unrelated to one install |
| `event_type` | string(64) | e.g. `capability.invoked`, `install.created`, `grant.revoked`, `runtime.error`, `runtime.timeout` |
| `capability_key` | string(128), nullable | |
| `detail_json` | json | Never the payload of tenant data itself — see [13 §1](13-observability-and-incident-response.md) |
| `occurred_at` | timestamp | |

Index: `laravel_plugin_audit_log_install_occurred_index` on (`install_id`, `occurred_at`).
Retention: [13 §4](13-observability-and-incident-response.md).

## 5. Entity relationship summary

```
CENTRAL (Brain's own database)                    LOCAL (each tenant's own database)
────────────────────────────────                  ──────────────────────────────────
brain_plugin_developer_bans                        laravel_plugin_installs
        ▲                                                  │ (plugin_slug — by value,
        │ fingerprint at suspension                        │  not a foreign key, across
brain_plugin_developers ──< brain_plugin_catalog            │  the database boundary)
                                  │                          │
                                  └──< brain_plugin_releases  ├──< laravel_plugin_grants
                                       (manifest_json,        ├──< laravel_plugin_settings
                                        signed artifact) ────▶├──< laravel_plugin_data
                                       pulled and cached      └──< laravel_plugin_audit_log
                                       into the install row
                                       at install/update time
```

## 6. What this schema deliberately does not do

- **No plugin ever owns a table outside this list.** `laravel_*`, `brain_*`, and every other
  existing table remain exactly as owned today; a plugin reaches them only indirectly, through the
  capability-scoped API ([05 §4](05-security-and-sandboxing.md), [06](06-extension-points-and-hooks.md)).
- **No foreign key crosses the central/local boundary**, because nothing can — they are different
  physical databases, and a tenant deployment has no live connection to the Brain's database at
  all (§0). Everything a tenant needs from a release is copied, verified, and cached locally at
  install/update time.
- **No foreign key from a plugin table into a core table.** Unchanged from before: `payload_json`
  may *reference* a core entity's `reference_num` as a value, but nothing constrains a core
  table's lifecycle to a plugin's data.
