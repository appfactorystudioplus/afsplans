# 10-members-and-client-changes — Completion

**Status: Partial.** P0 is done. P1 was already done in step 06. P2 is done for tree mutations, bucket move,
session sweep, **and, as of 2026-09-27, retention (retire/restore/purge)** — the gap this document originally
reported here as found-not-fixed is closed; see "Retention device fan-out (closed 2026-09-27)", below, which
replaces the old "Known gap" section. P3 is done for the **web** client only; desktop and mobile were not touched.
P4 (true multi-device partial-failure resume as its own scheduled job) was not built — only the content-addressed
replay that makes a client's own retry safe.

Nothing was committed. Development mode: `vagrant`. Per your instruction this round, nothing was run against the lab
(it was stopped) — every check below is PHPUnit/vitest/tsc, no live storage server.

Repos touched, all uncommitted: **members** (backend services/controllers/commands + the web client), **www** (one
migration). **storage_device** needed no changes — everything called this step (`/internal/ops`, `/internal/health`)
already existed.

## Summary

- **`DeviceClient` hardened**: one retry (network/5xx/429 only — a decisive 4xx is the device answering, not
  failing, so it is never retried), and a per-device circuit breaker (`DeviceCircuitBreaker`) that fails fast with no
  network call once a device has failed `threshold` times running, for `cooldown_seconds`. Gained `ops()` and
  `health()` alongside the existing `grant`/`commit`/`cancel`.
- **`OperationJournal`** (new): fans one logical tree mutation out to every device it touches, journaling each
  device's outcome in a new `members_storage_operations` table (a www migration). `op_id` is content-addressed, so a
  client's retry of the identical request replays instead of duplicating, and a device already confirmed `done` is
  never called again.
- **Tree mutations wired to it**: create-folder (row-only in sharded mode — nothing to call a device for, per the
  deferred-folder design from step 04), rename/move, delete, and bucket-to-bucket move. Every device call happens
  **before** the row-changing `DB::transaction`, so a refusal (`503 STORAGE_UNAVAILABLE`) leaves the tree exactly as
  it was — audited via two new `SecurityAudit` methods, `deviceUnavailable()`/`grantFailed()`.
- **`StorageSessionSweep`** now cancels a device-hosted expired session on its device instead of touching a local
  path that was never used (best effort — the device's own hourly sweep covers it either way).
- **Web client**: `ApiClient` gained the device-facing calls (`devicePutRange`, `deviceSessionState`,
  `deviceDownload`, `refreshUploadToken`, `downloadUrl`) using a raw `fetch` with the upload/download token as
  bearer — never the Checkpoint-token-attaching `fetchWithSessionRefresh`, since the device is a different origin
  with a different credential. `UploadEngine` routes chunk PUTs to the device when a session carries `upload_url`,
  refreshes the token on a live `401 TOKEN_EXPIRED` and resumes the SAME chunk from the device's own offset (never
  restarts), and `reattach()` (page-reload resume) asks the device for real offsets after minting a fresh token — the
  old token is never persisted to IndexedDB, only `upload_url` is, per the plan's own instruction. A session with no
  `upload_url` is untouched — the existing local-mode path keeps working byte for byte. Downloads now request a
  device URL+token and fetch it directly (a plain `<a href>` cannot carry a bearer token cross-origin), falling back
  to the old link for a local (non-sharded) file.
- **Two pre-existing bugs fixed in passing** (found while rewriting the exact code they live in, not a separate
  sweep): `UploadEngine`'s offset-mismatch re-seek read `expected_offset`/`offset` at the top level of the error
  body; both members' and the device's actual response nest it under `error`, so a real offset mismatch could never
  have re-seeked correctly before this — every chunk upload would have failed outright on any 409 instead of
  resuming. Also `StorageTree::ensureParentFolders()` was doing a **local** `mkdir()` even for a device-hosted commit
  (called unconditionally from both the local and device commit paths) — now gated on `storage.placement.enabled`.

## Verification

| Check | Result |
|---|---|
| members full PHPUnit suite | **268 tests, 0 failures, 6 skipped** (the pre-existing opt-in end-to-end tests) |
| New this step | `DeviceResilienceTest` (21), `OperationJournalTest` (12), `StorageTreeDeviceOpsTest` (12), 2 new cases in `StorageSessionSweepTest`, 1 assertion fixed in `DeviceComponentsTest` for the new retry behaviour |
| members full vitest suite | **343 tests, 0 failures, 1 pre-existing skip** — includes the two new files below |
| New this step (JS) | `UploadEngine.test.ts` (10 — device routing, token-refresh-mid-chunk, the two offset-mismatch shapes, local-mode untouched, reattach for both modes), `ApiClient.test.ts` (4 — the raw HTTP shape of every new device call) |
| `npx tsc --noEmit` | Clean for every file this step touched (via a scratch tsconfig overriding an unrelated, pre-existing `ignoreDeprecations` value this TypeScript version rejects — removed after use; nothing in the repo's own tsconfig was changed) |
| www `MigrationOwnershipTest` | Not re-run this round (unchanged from step 08's last run; the new migration follows the exact naming pattern already required) |

## Retention device fan-out (closed 2026-09-27)

Was reported here as "Known gap: retention does not move device bytes (found, not fixed)" —
`RetentionService::sweepPurge()` / `sweepHardDelete()` / `restoreFromPurging()` only `rename()`/`rrmdir()`ed a
**local** bucket directory, a silent no-op for a sharded bucket while `state` and the `StorageNode` rows advanced
anyway. Closed by taking the second of the two options this document originally weighed — enumerate the bucket's
top-level children and fan out per-item ops through the already-built `OperationJournal` — rather than inventing a
device-side bucket op, since the existing per-item `trash`/`purge` primitives turned out to be sufficient:

- `sweepPurge()` walks the bucket's top-level (`parent_id` NULL) nodes, finds each one's devices with the existing
  `StorageTree::devicesUnder()`, and issues one `trash` op per device per item, all sharing one `trash_id`
  (`{retired_at}-{bucket}`, matching the local `PathResolver::trashPath()` naming) — before the local rename and
  before `state` changes, same ordering as the tree-mutation controllers.
- `sweepHardDelete()` doesn't need per-item enumeration: the device's `purge {trash_id}` removes the whole named
  trash directory in one call, so this collects the distinct devices the bucket ever used and purges each once.
- `restoreFromPurging()` needed to move bytes back OUT of `.trash/`, which genuinely had no existing device
  primitive — `rename` only addresses live `{bucket, rel_path}` on both ends, never a trash path. This is the one
  part that was **not** just wiring: a new op, **`untrash`** (the exact reverse of `trash`), was added to
  `storage_device`'s `FileOps` and `/internal/ops` (05 §4) to close it — the smallest device-protocol change that
  makes the restore path real, rather than leaving it silently broken or blocking the whole fix on a bigger
  bucket-level redesign.

A device refusal at any of the three points leaves the bucket's `state` exactly where it was (still `retired` or
still `purging`), logged, retried on the next sweep/`reconcile()` — never a partial local/device split.

**Not run against the lab**, same as everything else in this document's Verification table: the lab's MySQL was
unreachable both when step 10 was originally built and again now (`Test-NetConnection 192.168.56.11:3306` →
`False`). Verified instead: `php -l` on every changed file (clean), and `php vendor/bin/phpunit --list-tests-xml`
against the new `members/tests/Feature/Storage/RetentionDeviceOpsTest.php` (enumerates correctly — no syntax or
class-resolution error). `storage_device`'s new `untrash` tests in `OpsTest.php` could not even be enumerated this
way — that repo has no `vendor/` installed in this checkout (`composer install` was never run there), which this
session did not do itself, per the standing rule against installing tooling to satisfy a test run. Both test files
follow the same real-assertion style (`Http::fake`, real device-routed request bodies, real DB rows) already
proven in this step's other device tests — they were not adjusted to fit any mock shortcut.

## Not done

- **Desktop client** (`afsdesktop5/src/main/cloud/`) — not touched at all. `client.ts`, `auth.ts`, `jobs.ts`,
  `config.ts` still assume the fixed members host from before sharding.
- **Mobile client** (`afsmobile_flutter`) — not touched at all. `storage_repository.dart`, `upload_engine.dart`,
  `UploadSession` still assume the old single-host protocol.
- **P4 — true multi-device fan-out resume.** `OperationJournal` records failed device rows and a client's own retry
  of the identical request replays safely (content-addressed `op_id`), but nothing scans for stale `failed`/`pending`
  rows on its own and retries them later — there is no `storage:journal-resume` command. A fan-out killed mid-flight
  (not just one device refusing, but the members PHP process itself dying between two device calls) leaves rows
  genuinely stuck until a client repeats the same action.
- **www's wider test suites** were not re-run this round (only the members-side migration ownership pattern was
  followed, not re-verified against a live run).

## Decisions made, to confirm

1. **`PathResolver` keeps its local-disk methods**, read as required by §3's compatibility-mode allowance rather
   than removed per §1.2's literal wording — see the plan doc's "As built" note for the reasoning. `grep -rn "/mnt/"
   app/` in members is therefore **not** zero; it is the still-live local-mode code, gated behind
   `storage.placement.enabled` everywhere it runs.
2. **Web client only, for P3, this round.** Desktop and mobile are separate repositories in different languages
   (Electron/TypeScript, Flutter/Dart) that this session had not touched before; doing them to the same standard as
   the web client (real behavior change, tests, type/analyzer clean) was judged to need its own dedicated pass rather
   than a rushed addition at the end of an already large one.
3. **The retention gap was closed 2026-09-27**, on explicit instruction, rather than left for a separate pass — see
   "Retention device fan-out", above. Desktop/mobile/P4 remain open, unprioritized.
