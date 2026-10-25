# 03 — Registry and Brain admin

## Completion document

When this plan step is completed, write `complete/03-registry-and-brain-admin.complete.md`.

---

## 1. Ownership

The registry is Brain control-plane data: prefix `brain_storage_*`, migrations in **www**
(AGENTS §5), one file `www/database/migrations/{date}_create_brain_storage_tables.php`, every
create guarded with `Schema::hasTable`, every index named explicitly. Only the Brain **writes**
these tables. Members **reads** them from the shared database. The storage app never sees
them; it gets what it needs through the signed channel ([05](05-storage-app.md), §5 below).

`www/tests/Unit/Database/MigrationOwnershipTest.php` is extended to cover the new file — it is
the guardrail for exactly this rule.

## 2. Tables

### `brain_storage_servers`

| Column | Notes |
|---|---|
| `id`, `reference_num` (unique) | Same reference convention as `brain_http_servers` |
| `label` | Staff-readable name |
| `hostname`, `public_ipv4`, `private_ipv4`, `region`, `provider` | As `brain_http_servers`, so operators see familiar fields |
| `internal_base_url` | Where the Brain reaches this server's storage app for push (`brain-app/…`). https only, no redirects — [08](08-security.md) §5 |
| `admin_state` | `active` · `maintenance` · `retired` — set by staff |
| `credential_encrypted`, `credential_previous_encrypted`, `credential_rotated_at` | The per-server secret, **encrypted** with the application key (Laravel `encrypt`) — **not hashed**: an HMAC verifier must recover the key to recompute the signature. The previous secret is kept until `credential_rotated_at` + the skew window so a rotation overlaps, exactly as `key`/`key_next` already do for apps. The secret is shown to staff **once**, at creation or rotation. Never returned by any API, never logged |
| `last_report_at`, `last_report_ok` | Server-level liveness, updated by any report |
| `json` | mediumText, encoded like the other brain tables |
| `created_at`, `updated_at` | — |

### `brain_storage_devices`

| Column | Notes |
|---|---|
| `id` | — |
| `number` (unique) | The device number `N`, ≥ 1, **never reused** ([02](02-naming-and-addressing.md)) |
| `server_id` | The hosting server |
| `label` | Optional |
| `declared_capacity_bytes` | **What staff typed.** The ceiling placement respects |
| `soft_watermark_pct`, `hard_watermark_pct` | Default 85 / 95 (Q3). Soft: no new sessions. Hard: no new sessions, no new commits |
| `admin_state` | `pending` · `active` · `draining` · `readonly` · `retired` — **set only by staff** |
| `health` | `unknown` · `healthy` · `degraded` · `offline` — **derived only by the Brain from reports** ([07](07-health-polling-and-reporting.md)) |
| `health_reason` | Short machine string (`stale_report`, `marker_missing`, `write_probe_failed`, …) |
| `reported_total_bytes`, `reported_used_bytes`, `reported_free_bytes`, `reported_inodes_free` | From the last report |
| `mount_ok`, `marker_ok`, `write_probe_ok`, `write_probe_ms` | From the last report |
| `reported_at` | When the storage app measured it |
| `created_at`, `updated_at` | — |

`admin_state` and `health` are two columns on purpose. Staff intent (`draining`, `retired`)
must never be overwritten by a measurement, and a measurement (`offline`) must never be
overwritten by staff clicking around. A device is **eligible for placement** only when
`admin_state = active` **and** `health = healthy` **and** the report is fresh **and** it is
under its soft watermark ([04](04-placement.md)).

### `brain_storage_device_reports`

An append-only history: `device_id`, `reported_at`, the four `reported_*` figures, the three
`*_ok` flags, `write_probe_ms`. Pruned by a Brain schedule after 30 days. It feeds the trend
chart and the audit question "when did this device start filling?".

### `brain_storage_device_events`

One row per **change** of `health` or `admin_state`: `device_id`, `at`, `kind` (`health` |
`admin_state`), `from`, `to`, `reason`, `actor` (staff user, or `system` for a derived change).
This is what the Fleet screen shows as "device 4: healthy → offline (marker_missing)". The
Brain's app-events table from [www_brain 10](../www_brain/10-brain-owned-config-and-push.md) is
**planned, not built**, so storage does not depend on it.

## 3. What staff enter — the example

"300 GB total, split across three devices of 100 GB each" is **three device rows**, not one
figure divided by the app:

| number | server | declared capacity |
|---|---|---|
| 1 | store-a | 100 GB |
| 2 | store-a | 100 GB |
| 3 | store-a | 100 GB |

Adding capacity later is: stand up a server, mount devices, then add the server and its
devices in the admin. Nothing is deployed.

Capacity is entered with a unit (GB or TB, binary or decimal stated on the form) and stored in
bytes. The form's upper bound is a setting, defaulting to 10 000 GB — a typo cannot declare a
petabyte. Declared capacity **cannot be lowered below reported used bytes**, and a device with
any file rows **cannot be deleted**, only `retired`.

## 4. Brain admin

A **Storage** section beside the existing Brain screens
(`www/resources/js/portal/admin/controllers/brain/`), backed by a `Brain/BrainStorageController`
in the same style and route group as `BrainHttpServerController`, under the same staff
authorisation as the Apps screens (level 99, enforced on the server, per
[www_brain 08](../www_brain/08-security-and-safety.md)).

| Screen | Does |
|---|---|
| **Fleet** | Every server and device on one page: number, server, declared vs reported capacity, fill %, `admin_state`, `health`, last report age. Sortable; a red row for anything not `healthy` |
| **Add server** | Label, hostname/IPs, region, provider, `internal_base_url`. Shows the generated credential **once** |
| **Add device** | Number pre-filled with the next unused, server, capacity + unit, watermarks. Creates as `pending` |
| **Device detail** | Edit capacity / watermarks / `admin_state`; trend chart from the reports table; last 20 reports; the placement reasons that currently exclude it |
| **Rotate credential** | Generates a new server secret, invalidates the old one, shows the new one once |

Every change writes who/when/old/new to the Brain's existing audit trail, as the Apps screens
do.

> **Amendment 2026-10-11 ([12](12-server-enrollment.md)).** **Add server** no longer shows a credential: it takes an address
> and/or domain and an optional SSH login, and the server enrolls itself. **Rotate credential** becomes automatic. New
> screens: **Connect**, **Re-pair**, **Unrecognised servers**, **Discovered devices**, and the per-server event history.

## 5. What the storage app is given

Over the signed channel ([08](08-security.md) §2), on pull (and on push refresh):

- The **devices this server hosts**: number, declared capacity, watermarks, `admin_state`.
- The **token verification public key(s)** ([06](06-direct-upload-protocol.md) §3), with key ids so a
  rotation overlaps.
- The **members callback base URL**(s) the storage app may call ([06](06-direct-upload-protocol.md) §5).
- The `base_domain` for hostnames.

And it sends, on a schedule, a **report** ([07](07-health-polling-and-reporting.md)). It is given
nothing about members, files, or any other server's devices.

## 6. The storage surface on the Brain

New routes in `www/routes/api.php`, inside the existing `internal/brain` group (so `brain.only`
already makes a tenant clone return 404):

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/internal/brain/storage/config` | The pull in §5, with `If-None-Match`/ETag so an unchanged pull is a `304` |
| `POST` | `/api/internal/brain/storage/report` | The report ([07](07-health-polling-and-reporting.md)) |

They sit behind a **new** middleware, `brain.storage`, modelled on `VerifyBrainSurfaceSignature`
and reusing `BrainSurfaceSignature::verify()` and the same skew and nonce-replay rules. The
difference is the key lookup: identity is an `X-Brain-Server` header naming a
`brain_storage_servers.reference_num`; the key is that row's decrypted `credential_encrypted`,
with `credential_previous_encrypted` as the rotation fallback. A server credential is accepted
**only** on `brain/storage/*` and an app key **only** elsewhere — neither can reach the other's
routes. Failure is the same bare `401` with the reason logged, never the key.

## 7. As built (2026-09-26)

Where the built registry differs from the plan text above, the built version is authoritative:

- **`from_value` / `to_value`** on `brain_storage_device_events`, not `from` / `to` — both are reserved SQL words. `kind` is one of `health`, `admin_state`, `capacity`, `created`.
- **`brain_storage_devices.consecutive_clean_reports`** was added now (unsigned small int, default 0) for step 07's two-clean-reports recovery, so 07 needs no `ALTER`.
- **`MigrationOwnershipTest` was not edited.** It globs every migration, so the new file is already covered by its four assertions (prefix, one creator per table, unique name stem, `members_` filing), and it passes with it.
- **`retired` is terminal and is reachable only from `pending` or `draining`.** The allowed `admin_state` moves are the table `StorageRegistry::TRANSITIONS`; reviving hardware means registering it under a new number.
- **URLs.** Staff API: `/portal/api/v1/brain/storage/…` (`fleet`, `servers`, `servers/{reference}`, `servers/{reference}/rotate-credential`, `devices`, `devices/{number}`). Signed surface: `/api/internal/brain/storage/{config,report}`.
- **`internal_base_url` is checked at write time for the static SSRF cases only**: https, no credentials/query/fragment, not `localhost`, not a private/loopback/link-local/reserved IP literal. Whether the host *resolves* to a public address is checked where the call is made (the Brain→storage push client, [05](05-storage-app.md) §4), because DNS answers change and a write-time check goes stale. The lab suffix allowance belongs with that check.
- **Capacity is entered in GB or TB and means binary units** (1 GB = 1024³ bytes); the API takes bytes. Upper bound: `brain.storage.max_device_bytes`, default 10 000 GiB.
- **Server-level changes are audited by log line** (`brain.storage.server_created`, `server_updated`, `credential_rotated`, each with the staff `actor`, never the secret) because servers have no events table. Device changes are audited by `brain_storage_device_events`, kept indefinitely; only report history is pruned.
- **Config keys** in `config/brain.php` → `storage`: `base_domain`, `max_device_bytes`, `report_interval_seconds` (60), `report_ttl_seconds` (180), `rotation_grace_seconds` (300), `report_retention_days` (30).
- **The report ingest records what was measured and nothing more.** It does not derive `health`; that is [07](07-health-polling-and-reporting.md) P1, so until then an active device shows `health: unknown` however good its reports are.

Not built here, because it depends on later steps: the "placement reasons that currently exclude it" panel on the device page (needs the placement service, [04](04-placement.md)), a trend chart (the last 20 reports are listed as a table), token verification keys and the members callback URL in the config document ([06](06-direct-upload-protocol.md), [08](08-security.md)), and the members read models ([10](10-members-and-client-changes.md)).

## Phases

| Phase | Deliverable | Status |
|---|---|---|
| **P0** | The migration, models, and `MigrationOwnershipTest` covering it | **Done 2026-09-26** — `2026_09_27_000002_create_brain_storage_tables.php`, four `BrainStorage*` models, guard passes |
| **P1** | `BrainStorageController` (servers, devices), the `brain.storage` middleware and the two routes in §6 | **Done 2026-09-26** — plus `StorageRegistry`, `StorageReportIngest`, `StorageConfigBuilder`; 84 feature tests (26 surface, 54 staff, 4 prune) |
| **P2** | The Fleet, Add server, Add device and Device detail screens | **Built 2026-09-26; type-checked, not built into assets or opened in a browser** — `controllers/brain/storage/brain_storage.ts`, a Storage button on the Brain page, two views |
| **P3** | Credential rotation, the audit entries, history pruning | **Done 2026-09-26** — rotation with grace overlap, device events and server log lines, `brain:storage-prune` (daily) |
