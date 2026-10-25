# 01 — Current state

## Completion document

When this plan step is completed, write `complete/01-current-state.complete.md`.

---

Read from the tree and the lab on 2026-09-26 (development mode: `vagrant`). Every claim below
was checked by reading the file or running a search; where something is inferred, it says so.
The first draft of this document (written from memory of earlier work) contained errors —
they are listed in §6 so the plan steps that depended on them can be seen to be corrected.

## 1. Where storage is written today

`members/app/Services/Storage/PathResolver.php` holds the only storage mount:
`private const STORAGE_ROOT = '/mnt/node1'` (line 39). Every path is `/mnt/node1/
{member_reference_num}/storage/{bucket}/…`, with `.staging/{sessionId}/{fileId}.part` and
`.trash/…` as siblings of the numbered buckets ([cloud-storage 03 §2](../cloud-storage/03-architecture.md)).
The constant is also exposed by a public `storageRoot()` accessor added for the free-space check.

`cloud-storage` assumes **the members app and the bytes are on the same machine**. The places
that assume it (all under `members/app/`):

| Code | Assumption | What sharding does to it |
|---|---|---|
| `Services/Storage/PathResolver` | One constant root; `resolve()` does `realpath()` containment on the local disk | Splits: members keeps validation and hashing of the *relative* path; the storage app does the filesystem work ([05](05-storage-app.md), [08](08-security.md)) |
| `Http/Controllers/Storage/StorageSessionController::putRange` (l.317) | Streams `php://input` into a local `.part` | Moves to the storage app; members no longer sees chunks |
| `::commit` / `::commitOneFile` (l.387/438) | Local `rename(2)`, `hash_file`, `mime_content_type` | Moves to the storage app; members receives the result and writes rows |
| `::show` (l.277) | Reads `.part` length from disk | The device answers offsets directly |
| `::cancel` (l.542), `Console/Commands/StorageSessionSweep` | `rrmdir` / `File::deleteDirectory` on `.staging/{id}` | Signal the device to drop staging |
| `StorageTreeController::content` (l.407) | `BinaryFileResponse` from a local file | Client downloads from the device with a token |
| `::destroy` / `::update` / `::createFolder` (l.528/291/468) | `unlink`, `rename`, `mkdir`, `rmdir` locally | Become idempotent calls to the device that holds the file |
| `StorageBucketController::moveBucket` / `moveSubtree` (l.167/254) | `rename(2)` between bucket directories | Still a `rename(2)`, but on the device that holds the file — [04 §5](04-placement.md) |
| `App\Checkpoint\Storage\StorageRetentionAdapter` | `hasPhysicalSpace()` (`disk_free_space` on `storageRoot()`, skipped when the path is absent, 5 GiB margin); `rrmdir` of a session's staging (l.251) | The free-space check is replaced by per-device eligibility ([04](04-placement.md)); staging removal becomes a device call |
| `App\Checkpoint\Storage\RetentionService` | Moves and removes bucket directories directly: `rename` between `bucketRoot` and `trashPath` (l.198, 269), `rrmdir` of trash (l.315) | Becomes calls to each device holding that member's bucket; the state machine and its events are unchanged |
| `App\Checkpoint\Storage\QuotaService` | Thin: `hasPhysicalSpace()` delegates to the adapter | Loses that method; capacity/route logic unchanged |

Unchanged: `Services/Storage/NameValidator`, `UsageLedger`, `StorageTree`, `SecurityAudit`, the
quota ledger, plans and buckets, the session/commit *protocol shape* the three clients speak,
rate limiters, the ownership 404-not-403 rule.

### 1.1 The retention layer is shared

`App\Checkpoint\RetentionEngine` is a **generic** retention state machine; storage plugs into it
through `StorageRetentionAdapter`, and email retention plugs into it through its own adapter
(`app/Checkpoint/Email/`, `members_email_retention_*` tables, "mirrors
`members_storage_bucket_usage` / `members_storage_retention_events`"). Sharding touches only the
**storage adapter and `RetentionService`**. The engine and the email adapter must not change,
and any change to the storage adapter's interface must keep the engine's contract.

## 2. Tables

The cloud-storage tables are `members_storage_nodes`, `members_storage_bucket_usage`,
`members_storage_sessions`, `members_storage_session_files` and
`members_storage_retention_events` (models' `$table` confirmed), all created by
`www/database/migrations/2026_08_23_000001_create_members_storage_tables.php` — www owns the
migration, members reads and writes the rows (AGENTS §5). No other migration creates or alters
them. **None has a device column.** Adding one is an `ALTER` of a table www already owns, the
sanctioned direction.

Other migrations *mention* `members_storage_nodes`: the communications tables reference it
("`storage_ref` … points into `members_storage_nodes`; NULL until the file is stored") and the
email retention tables mirror its shape. **No code currently writes `storage_ref`** — a search of
`communications/`, `members/app`, `members/resources/js` and `www/app` finds none. Recordings are
therefore a **future consumer** of storage (LiveKit egress writes to its own filepath today), not
a current touch point; when they land they must go through placement like any other writer.

`members/tests/Concerns/CreatesStorageTestSchema.php` builds the storage tables for tests. It is
the one place outside the migration that hard-codes their columns, so adding `device` ([04](04-placement.md) §4)
means updating it in the same change.

## 3. `/mnt/node1` and `/mnt/node2` in the estate

Both are in use for other things, in the lab and in application code:

- `members/app/Http/Controllers/Brain/DomainController.php` writes to
  `/mnt/node1/appfactorystudio/tmp` (lines 496, 506) — the Brain's temp/work directory.
- `afsserver/commands/commands.sh` uses them for mailboxes, module documents and temp/work
  directories (as recorded in `AFS-DEPLOYMENT-VAGRANT.md` §6; not re-read here).
- In the lab every VM has two 10 GB disks labelled `NODE1` (primary data) and `NODE2` (backup
  target), attached by `attach_node_disks` in the `Vagrantfile` and mounted by
  `provision/mount-node-storage.sh`.

A cloud-storage install that writes under `/mnt/node1` shares a mount with all of that. That is
the second reason for D1's rename. The estate-wide search for `/mnt/node` across all repositories
under `w/` is slow on this tree; the members app was searched exhaustively and the other Laravel
apps and the TypeScript/Dart clients were searched for the storage-specific paths only. **Not
re-verified for the other repos: any other reference to `/mnt/node1`** — the first phase that
touches each repository repeats it with a bounded search there.

## 4. What the Brain already has that this reuses

| Existing (verified) | Use here |
|---|---|
| `brain_http_servers`, `brain_http_servers_regions`, `brain_http_server_tenants`, `brain_server_clusters` (`www/database/migrations/2026_05_15_000001_create_brain_tables.php`) and `Brain/BrainHttpServerController` (servers, `servers/available`, tenants, move, clusters, regions) | The pattern for a storage registry and its admin routes. Storage is a **separate** registry — those rows are website hosts with tenant semantics |
| `Brain/BrainDnsController` (servers, zones, subdomains, reload) | Where the `*.storage-device.…` records are managed |
| **The Brain surface**: `www/routes/api.php` `Route::prefix('internal/brain')` behind `brain.only` (404 on a tenant clone) and `brain.surface` = `App\Http\Middleware\VerifyBrainSurfaceSignature`. Identity is the `X-Brain-App` header; the key comes from `config("brain.surface.apps.{APP}")` (`key` and `key_next` for rotation); signature is `HMAC-SHA256(key, "{ts}.{nonce}.{METHOD}.{path}.{sha256(body)}")` via `App\Services\Brain\BrainSurfaceSignature`; ±300 s skew; replay rejected with `Cache::add` on the nonce; any failure is a bare 401 | The signing scheme and replay defence to copy. **Its key lookup is per calling *app* from `.env`** — it does not fit a per-server credential stored in a table (Q2), so a sibling middleware is needed ([03 §6](03-registry-and-brain-admin.md), [08 §2](08-security.md)) |
| Existing surface routes `apps/{app}/manifest`, `settings`, `mail/send`, `heartbeat` (`BrainAppSurfaceController`) | The storage app could use `heartbeat`; config and report are new storage-specific routes |
| `brain_app_registry` tables (`2026_09_26_000001_create_brain_app_registry_tables.php`) and `BrainApp` model | How an app becomes known to the Brain. The storage app is **not** a Checkpoint app and does not need to be one |
| `packages/brain-client` — **built**: `setting()/settings()`, `mail()`, `registerManifest()/manifest()`, signed pull with `If-None-Match` ETag, last-known-good cache, circuit breaker, `Signature` (sign only) | The pull / last-known-good / breaker *pattern*, and the signing helper. **Not built:** `Brain::report()` and the Brain→app `brain-app/` receiver (refresh, state, actions) — [www_brain 10](../www_brain/10-brain-owned-config-and-push.md) still lists those as planned (Q2–Q4). This plan cannot assume them |
| Brain admin UI (`www/resources/js/portal/admin/controllers/brain/`: `BrainController.ts`, `brain.ts`, `brain_membership.ts`, `apps/brain_apps.ts`) | Where the storage screens are added |

## 5. What does not exist

- Any notion of more than one storage location, in code or in the schema.
- Any service that runs on a storage server. Nothing in the estate receives file bytes except
  the members app itself.
- DNS records or a certificate for `*.storage-device.…` — the lab zones are `test1.zone` and
  `tenants.zone` and contain no storage names.
- A storage VM. The `Vagrantfile` defines exactly three VMs (`dns`, `web`, `web2`), each with
  `attach_node_disks`; `mount-node-storage.sh` is wired as the named provisioner
  `mount-node-storage`.
- A Brain→app push receiver, and `Brain::report()` (§4).

## 6. Corrections this verification forced

The first draft of this document and the steps written from it said things the tree does not
support. Each is fixed in the file named:

| Was | Is | Fixed in |
|---|---|---|
| `QuotaService` and `RetentionService` are in `Services/Storage` and do the filesystem work | They are in `App\Checkpoint\Storage`; the work is in `StorageRetentionAdapter` and `RetentionService`; retention sits on a shared `RetentionEngine` that email also uses | §1 above, [10](10-members-and-client-changes.md) |
| `brain-client` provides `Brain::report()` and a `brain-app/` receiver | Neither exists; only settings, mail, manifest, signed pull and `Signature::sign` | §4 above, [05](05-storage-app.md), [07](07-health-polling-and-reporting.md) |
| The per-server secret is stored **hashed** at the Brain | An HMAC verifier needs the secret itself. It is stored **encrypted**, with the previous secret kept for rotation overlap (as `key`/`key_next` already do) | [03](03-registry-and-brain-admin.md), [08](08-security.md) |
| The Brain's signed surface can verify a per-server credential | It keys on `X-Brain-App` and `.env`; a per-server table-backed verifier is new work | [03](03-registry-and-brain-admin.md), [08](08-security.md) |
| Recordings are an existing storage writer | The `storage_ref` column exists; nothing writes it | §2 above |

## Phases

| Phase | Deliverable | Status |
|---|---|---|
| **P0** | This document verified against the tree at the start of implementation: re-search `/mnt/node1`, re-list the touch points, confirm table names, confirm what the Brain and `brain-client` actually provide | **Done 2026-09-26** — see the completion document |
