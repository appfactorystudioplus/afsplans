# 04-placement — Completion

**Status:** Partial. P0, P1 and P2 are built and tested. **P3 (multi-device fan-out and its journal) is
blocked**: it calls storage devices, and the storage app (05), the upload protocol (06) and the operation
journal (10) do not exist yet. Nothing was committed. Development mode: `vagrant`.

Changes landed in two repos, both uncommitted:
- **www** (`C:\projects\vagrant\afs-eco\w\www`): one migration.
- **members** (`C:\projects\vagrant\afs-eco\w\members`): the service, models, config, controller wiring, tests. The
  members tree already had other uncommitted changes; they were not touched.

## Summary

- **P0** — `2026_09_27_000003_create_members_storage_placements.php`: `device` (nullable int, the device *number*)
  on `members_storage_sessions` (index `device, status`) and `members_storage_nodes` (index `device`), and the new
  `members_storage_placements` table (unique `member_reference_num, bucket`). Guarded, so re-running is a no-op.
  The file is named `create_members_*` because `MigrationOwnershipTest` demands it for any `members_` table.
- **P1** — `App\Services\Storage\PlacementService::place($member, $bucket, $sessionBytes): int` and
  `rankedEligible()`. Implements §1 rules 1–6 and the §2 maths exactly: usable = min(declared, reported_total) −
  headroom; in_use = max(reported_used, ledger of files recorded on the device); reserved = open sessions on the
  device; eligible when admin_state `active`, health `healthy`, report within the TTL, and projected fill ≤ that
  device's own soft watermark. Least full first, lowest number breaks ties. Sticky preference; spill updates it and
  logs `storage.placement.spill`. No eligible device throws `NoEligibleDeviceException`. Plan quota is never read.
  `App\Models\Brain\StorageDevice` is a read-only view of the registry (`$guarded = ['*']`).
- **P2** — `StorageSessionController::open` calls placement inside the session-creation transaction, so the device
  row locks cover the moment the reservation becomes visible; no eligible device rolls back the reservation and
  returns `503 STORAGE_UNAVAILABLE` (the quota check still runs first and still gives `413`). Commit records the
  session's device on the file node.
- **Config** — `members/config/storage.php`: `STORAGE_PLACEMENT_ENABLED` (default **off**),
  `STORAGE_REPORT_TTL_SECONDS` (180), `STORAGE_HEADROOM_PCT` (2).

## Decision to confirm

Placement is behind a switch that defaults **off**. The plan did not say this. Reason: until steps 06 and 10, bytes
still go to the local disk, so turning placement on would make sessions depend on a fleet that receives nothing.
Off is byte-for-byte the old behaviour. On is mandatory: it never falls back to local disk. Flip it in step 10 when
uploads actually go to devices.

## Verification

| Check | Result |
|---|---|
| `PlacementServiceTest` (members) | 37 pass — every §1 rule and every §2 term, incl. stale report, full device, draining, tie-break, reserved sessions, headroom, per-device watermark, boundary at exactly 85 % |
| `StorageSessionPlacementTest` (members, HTTP) | 7 pass — off = no device; on = lands and records device and preference; sticky; 503 with reservation rolled back; empty fleet; 503 vs 413 ordering; stale report |
| members full suite | 125 pass (81 existing + 44 new) |
| www `Unit/Database` + `Feature/Brain` | 172 pass, incl. `MigrationOwnershipTest` |
| Migration up → up → down on SQLite | columns and table created, second run no-op, down removes all three |

## Not verified

- The migration was not run against the lab MySQL database (touching the running lab was not asked for), and the
  members test schema is a hand-mirrored copy of it.
- **Commit recording `device` on the node is untested**: `PathResolver::STORAGE_ROOT` is a hardcoded `/mnt/node1`
  constant, so a test cannot stage a `.part` file. It is a two-line change.
- Row locking (`lockForUpdate`) is a no-op on SQLite; the concurrency guarantee rests on MySQL semantics and is
  covered only by the reserved-bytes logic, not by a real concurrent test.
- **With placement on, nothing is placeable until step 07** derives `health`; every device is `unknown` until then.

## Known gap for steps 06/10

A `replace` upload that spills to a different device than the existing file's leaves the old bytes on the old
device while the row now points at the new one. Commit currently rewrites `device` on replace. Step 06 (the commit
happens on the device) must delete or trash the old copy on the old device.
