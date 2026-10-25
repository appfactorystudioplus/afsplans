# 10 — Members and client changes

## Completion document

When this plan step is completed, write `complete/10-members-and-client-changes.complete.md`.

---

What changes in the cloud-storage code that already exists, and in the three clients. The
guiding rule: **the client protocol keeps its shape**; only the destination of range PUTs and
downloads changes.

## 1. Members

### 1.1 New

| Piece | Role |
|---|---|
| `StorageDevice` / `StorageServer` models | **Read-only** views of `brain_storage_devices` / `brain_storage_servers` in the shared database |
| `PlacementService` | [04](04-placement.md) |
| `StorageNodeClient` | Signed HTTP client to a storage app's `/internal` surface (grant, commit, cancel, ops, health), with timeouts, one retry, and a circuit breaker per device |
| `TokenIssuer` | Mints upload/download tokens ([06](06-direct-upload-protocol.md) §3); holds the private key |
| `OperationJournal` | A table (`members_storage_operations`, www migration) recording multi-device fan-out operations (`move_bucket`, `rename`, `delete`, `trash`, `purge`) with per-device state, so a partial failure resumes ([04](04-placement.md) §5) |

### 1.2 Changed

| Existing | Change |
|---|---|
| `PathResolver` | Loses the mount constant and every `realpath`/`mkdir`/`rename`. Keeps `normaliseForStorage()` and the relative-path validation/hash. The filesystem half moves to the storage app. `grep -rn "/mnt/" app/` should return **zero** in members afterwards — the cloud-storage "exactly one match" check becomes "none here, exactly one in the storage app" |
| `StorageSessionController::open` | Calls placement, sends the grant, returns `upload_url`, `upload_token`, `upload_token_expires_at`. Grant failure ⇒ release reservation, `503 STORAGE_UNAVAILABLE` |
| `::putRange` | **Removed** from members. Range PUTs go to the device |
| `::show` | Returns session state and `upload_url`; offsets come from the device |
| `::commit` | Calls the device's commit, then writes file rows and converts reservation to used inside one transaction |
| `::cancel` | Signals the device; releases reservation |
| `StorageTreeController::content` | Replaced by `download-url` returning URL + token; ownership check stays first |
| `::destroy` / `::update` / `::createFolder` | Become `OperationJournal` entries executed against the device(s) holding the descendants; rows change after the device confirms |
| `StorageBucketController::moveBucket` | Same-device `rename(2)` via the device's `move_bucket` op; per-device fan-out for spanning subtrees |
| `App\Checkpoint\Storage\QuotaService::hasPhysicalSpace` and `StorageRetentionAdapter::hasPhysicalSpace` | Removed; replaced by placement's per-device eligibility |
| `App\Checkpoint\Storage\RetentionService` and `StorageRetentionAdapter` (the storage side of the shared `App\Checkpoint\RetentionEngine`) | Retire/restore/purge and staging removal become device ops; the state machine and its `members_storage_retention_events` are unchanged. **`RetentionEngine` and the email retention adapter are not touched** — the adapter interface they share stays as it is ([01 §1.1](01-current-state.md)) |
| `StorageSessionSweep` | Expires sessions and tells the device to drop staging; the device also sweeps on its own ([05](05-storage-app.md) §7) |
| `SecurityAudit` | Gains `device` on every event; adds `device_unavailable`, `grant_failed` |

### 1.3 Unchanged

`NameValidator`, plans/buckets/cascade/quota ledger, session and file *row* semantics,
conflict policies, rate limiters, the ownership 404-not-403 rule, the audit channel.

### 1.4 Data

Every `members_storage_*` change is an `ALTER` (or a new table) in a **www** migration
([04](04-placement.md) §4). There is **no data migration**: the user confirmed `/mnt/node1`
holds no real data. Existing dev rows can be truncated in a lab; rows with `device` NULL are
treated as invalid after cut-over and must not exist in any non-lab environment.

## 2. Clients

The change is the same in all three: **send range PUTs and downloads to `upload_url` with
`upload_token`, refresh the token when it expires, and keep everything else.**

| Client | Change |
|---|---|
| **Web** (`members/resources/js/storage`) | `ApiClient`/`UploadEngine`: PUT ranges to `upload_url`; add the token refresh call; download via `download-url`. `Content-Range`, offsets from the device, 409 re-seek — unchanged. IndexedDB resume records store `upload_url` per session |
| **Desktop** (`afsdesktop5/src/main/cloud/`) | `client.ts`: host and bearer come from the session, not from the fixed members host; token refresh in `auth.ts`/`jobs.ts` (the existing pause-on-`TOKEN_EXPIRED` behaviour already fits). `config.ts` no longer assumes one host |
| **Mobile** (`afsmobile_flutter`) | `storage_repository.dart`: `putRange` targets the session's `upload_url` with the upload token; `UploadSession` gains `uploadUrl`, `uploadToken`, `uploadTokenExpiresAt`; `upload_engine.dart` refreshes on expiry (the `_send` retry path already exists for the Checkpoint token and gets a second, session-scoped one) |

All three keep the same `CheckpointException`/error codes; `STORAGE_UNAVAILABLE` (503) is added and
is shown as "storage is temporarily unavailable — your upload will resume", never as a quota
error.

## 3. Compatibility

Nothing is deployed to real members yet, so there is no old-client period. If that changes
before cut-over, members can keep a `local` mode behind a setting (write to a local path as
today) — recommended **only** for the lab and CI, where standing up a storage app is heavier
than the test warrants. It is a test convenience, not a production path, and is off unless
explicitly enabled.

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | `StorageDevice`/`StorageServer` read models, `StorageNodeClient`, `TokenIssuer`, `OperationJournal` migration | Members can grant a session on a lab storage app and mint a token it accepts |
| **P1** | Session open/commit/cancel through the device; `putRange` removed; `download-url` | The existing members feature tests pass with a stubbed `StorageNodeClient`; a real single-device upload commits |
| **P2** | Tree mutations, bucket move, retention and sweep as journaled device ops | Rename, delete, move and retention behave as before on one device |
| **P3** | Web client, then desktop, then mobile, in that order | Each client uploads, resumes across a token expiry, and downloads against the lab device |
| **P4** | Multi-device fan-out and journal resume | A bucket spanning two devices renames, deletes and moves; a killed fan-out resumes |

## As built (2026-09-27)

Repos touched, all uncommitted: **members** (backend + web client), **www** (one migration). **storage_device**
needed nothing new — its side of every call used below (`ops`, `health`) was already built in steps 05/07.

### §1.1 (P0)

- `StorageDevice`/`StorageServer` read models, `PlacementService`, `TokenIssuer` (named `TokenMinter`) and
  `StorageNodeClient` (named `DeviceClient`) already existed from steps 04/06. **New this step:** `DeviceClient`
  gained the plan's own asks — **one retry** (network error / 5xx / 429 only; a decisive 4xx is never retried, since
  the device answered fine) and a **circuit breaker per device** (`DeviceCircuitBreaker`, cache-backed, opens after
  `circuit_breaker.threshold` counted failures for `circuit_breaker.cooldown_seconds`, fails fast with no network
  call while open) — plus `ops()` and `health()` methods alongside the existing `grant`/`commit`/`cancel`.
- **`OperationJournal`** (new): `members_storage_operations` (a www migration, named `create_members_*` for
  `MigrationOwnershipTest`), fanning one logical mutation out to every device it touches via `DeviceClient::ops()`.
  `op_id` is content-addressed (sha256 of op+member+device+payload, never random) so a genuine client retry replays
  the same row instead of creating a second one, and a row already `done` is never called again.

### §1.2 (P1, P2)

P1 (session open/commit/cancel through the device, `putRange` gone from the *sharded* path, `download-url`) was
already built in step 06 — nothing new here. **New this step:**

- **Tree mutations** — `StorageTreeController::createFolder` (row-only in sharded mode: 04 §4's deferred-folder
  design, so there is nothing to call a device for), `::update` (rename: one `OperationJournal` `rename` call per
  distinct device under the node, before any row changes), `::destroy` (one `delete` call per device, same). `
  StorageBucketController::moveBucket` (one `move_bucket` call per node's distinct devices). Every one of these calls
  the device(s) **before** its `DB::transaction`, so a device refusal (`503 STORAGE_UNAVAILABLE`) leaves every row
  exactly as it was. A new `StorageTree::devicesUnder()` finds the distinct devices under a node in one query.
- **`StorageSessionSweep`** now tells a device-hosted session's device to cancel (best effort — the device's own
  `storage:sweep-staging` cleans up independently either way) instead of touching a local path that was never used.
- **`SecurityAudit`** gained `deviceUnavailable()` and `grantFailed()`, used at every new 503 site and the existing
  grant/commit ones (previously ad hoc `log()` calls).
- **`PathResolver` was NOT stripped of its mount constant or `realpath`/`mkdir`/`rename` methods.** §1.2 says it
  should lose them, and §3 separately allows keeping a `local` mode "behind a setting... off unless explicitly
  enabled" for lab/CI. These are in tension: the compatibility mode NEEDS somewhere to write local bytes. Read §3 as
  the more specific, overriding rule (nothing is deployed yet, so there is no cutover to force the stronger cleanup):
  kept the full local-disk path, gated everywhere behind `storage.placement.enabled` (already the pattern since step
  06). Consequence: **`grep -rn "/mnt/" app/` in members is NOT zero** — it is exactly the pre-existing, still-used
  local-mode code. This is a deliberate reading, not an oversight — flag it if it is wrong.
- **Retention (retire/restore/purge) as device ops — done 2026-09-27**, closing the gap this section used to
  describe as found-not-fixed. See "Retention device fan-out", below, replacing the old "Known gap" section.

### §2 (P3) — clients

- **Web** (`members/resources/js/storage/`) — done. `ApiClient` gained `devicePutRange`/`deviceSessionState`/
  `deviceDownload` (raw `fetch`, a bearer *upload/download* token, never `fetchWithSessionRefresh`'s Checkpoint
  bearer — cross-origin to the device besides), `refreshUploadToken`, `downloadUrl`. `UploadEngine` routes range PUTs
  to the device when a session carries `upload_url`, refreshes on `401 TOKEN_EXPIRED` and resumes from the device's
  own offset (never restarts), and `reattach()` asks the device for real offsets after minting a fresh token (never
  persists a token to IndexedDB — only `upload_url`, per this section's own instruction). A session with no
  `upload_url` is untouched (the local-mode compatibility path). Downloads: `StorageApp.ts` now requests a
  `download-url` token and fetches the device directly (a plain `<a href>` cannot carry a bearer token to a
  cross-origin device), falling back to the old direct link on `409 NOT_ON_DEVICE`.
- **Desktop** (`afsdesktop5/src/main/cloud/`) and **Mobile** (`afsmobile_flutter`) — **not done.** Neither repository
  was touched. See "Not done" below.

### §1.4 (data)

No data migration was needed or attempted (matches the plan). The new `members_storage_operations` table is
additive only.

## Retention device fan-out (closed 2026-09-27)

Was: "Known gap (found, not fixed): retention does not move device bytes" —
`RetentionService::sweepPurge()`/`sweepHardDelete()`/`restoreFromPurging()` only `rename()`/`rrmdir()`ed a
**local** `bucketRoot`/`trashPath`, silently doing nothing for a device-hosted bucket while the `state` and the
`StorageNode` rows advanced anyway — active data leakage the moment `placement.enabled` is true. Fixed, not just
documented around:

- **`sweepPurge()`** enumerates the bucket's top-level (`parent_id` NULL) nodes and, for each, finds its devices
  via the existing `StorageTree::devicesUnder()` (a file: its own `device`; a folder: the distinct devices of its
  file descendants) and runs a `trash` op per device through `OperationJournal`, all sharing one `trash_id`
  (`{retired_at->format('YmdHis')}-{bucket}`, the same naming `PathResolver::trashPath()` already used locally) —
  **before** the local rename and the row's `state` change, matching §1.2's tree-mutation pattern exactly. A device
  refusal leaves the bucket `retired`, to retry on the next sweep.
- **`sweepHardDelete()`** doesn't re-enumerate items: the device's `purge {member, trash_id}` op removes a whole
  named trash directory in one call, so it collects every device the bucket ever used (`whereNotNull('device')`
  across the bucket's nodes) and issues one `purge` per device, before force-deleting the rows.
- **`restoreFromPurging()`** (a plan restored inside the 7-day trash-hold window) needed a way to move bytes back
  OUT of `.trash/`, which the device's op set genuinely did not have — `rename` only addresses `{bucket, rel_path}`
  on both ends, never a trash path. Closed by adding a new op, **`untrash`**, to `storage_device`'s `FileOps` (05
  §4): the exact reverse of `trash`. This is the one piece of this fix that is not just wiring — it's a new,
  symmetric device primitive, shipped alongside because there was no way to close the gap without it.
- Every fan-out is skipped entirely (zero device calls, `journal->run()` short-circuits on `$devices === []`) when
  a bucket holds nothing sharded — pure local-mode buckets, and local-mode deployments generally, are unaffected.

Tests: `members/tests/Feature/Storage/RetentionDeviceOpsTest.php` (multi-device trash/purge/untrash fan-out, a
top-level folder split across two devices, a refused device leaving state unchanged) and new `untrash` coverage in
`storage_device/tests/Feature/OpsTest.php`. Not verified end-to-end against the lab — see this plan's
`complete/10-members-and-client-changes.complete.md` for the same caveat already on record for step 10's other
device tests (the vagrant lab's MySQL was unreachable when this was written).

`STORAGE_PLACEMENT_ENABLED` may now be turned on without this specific leak; the rest of this document's "Not
done" list (desktop/mobile clients, P4 true multi-device resume) still applies.
