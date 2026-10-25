# 04 — Placement

## Completion document

When this plan step is completed, write `complete/04-placement.complete.md`.

---

Placement answers one question at session open: **which device does this upload session go
to?** It runs in **members**, reading the registry from the shared database. It is separate
from bucket routing ([cloud-storage 08 §3](../cloud-storage/08-storage-retention.md)), which
still decides *which bucket*; the two compose — first the bucket, then the device.

## 1. Rules

1. **Whole session, one device.** Every file in a session lands on the same device, exactly as
   a whole session already lands in one bucket. This keeps the commit an all-local atomic
   `rename(2)` per file on one filesystem.
2. **Sticky per `(member, bucket)`.** A bucket's data normally lives on one device: the first
   session for `(member, bucket)` picks a device and records it as the preference; later
   sessions try that device first. This keeps a member's tree together, keeps folder
   operations single-device, and makes a member's data easy to reason about.
3. **Spill when the preferred device cannot take it.** If the preferred device is not eligible
   (below), the session goes to the best eligible device instead and the preference is
   **updated** to it. The bucket now spans devices — legal, because every file row records its
   own device (D6).
4. **Only eligible devices are candidates.** Eligible means all of:
   - `admin_state = active`;
   - `health = healthy`;
   - the last report is fresh (`now − reported_at ≤ report_ttl`, default 3 × the poll interval) — D10;
   - **after** this session's declared bytes, projected fill stays under the soft watermark.
5. **Among eligible devices, prefer the least full** (projected fill ratio), tie-broken by the
   lowest device number so the choice is deterministic and testable. Spreading rather than
   packing keeps the blast radius of one failed device small and leaves headroom everywhere.
6. **No eligible device is a `503 STORAGE_UNAVAILABLE`**, distinct from `413 QUOTA_EXCEEDED`.
   The first says "the fleet cannot take this right now"; the second says "your plan cannot".
   A member never sees a quota message caused by a full fleet.

## 2. Capacity maths

Per device, all in bytes:

```
usable      = min(declared_capacity, reported_total) − headroom
in_use      = max(reported_used, ledger_used_on_device)
reserved    = Σ declared_bytes of open sessions placed on the device
free        = usable − in_use − reserved
projected   = (in_use + reserved + session_bytes) / usable
eligible if   projected ≤ soft_watermark
```

- `declared_capacity` is the staff figure; `reported_total` is `df`. **The smaller wins**, so a
  mistyped declaration can never overfill a device and a declaration below reality is honoured.
- `headroom` covers filesystem overhead and the in-flight bytes a report cannot see yet
  (default 2 % of `reported_total`, configurable).
- `ledger_used_on_device` is the sum of `size_bytes` of file rows with that `device`. Taking the
  larger of that and `reported_used` means a stale report cannot make a device look emptier
  than the database knows it is.
- `reserved` counts **open sessions**, so two concurrent sessions cannot both be told the last
  gigabyte is theirs — the same rule the per-bucket ledger already follows.
- **Plan quota is not used to size devices.** Plan 3's 62.5 GB is an entitlement, not usage;
  allocating by entitlement would leave devices mostly empty. Devices are allocated by what is
  actually stored, and the fleet is monitored by watermark. Overcommit is expected.

## 3. Numbers, for a sense of scale

One 10 000 GB device holds about 160 members at the full plan-3 total (62.5 GB) if every one
filled it — in practice far more. The unlimited bucket is the reason placement exists at all:
one member with 8 TB in bucket 4 is a normal case here, and it is exactly where rule 3
(spill) earns its keep.

## 4. Data model changes (members tables, ALTERed by a www migration)

| Table | Change |
|---|---|
| `members_storage_sessions` | `device` (int, the placement result), index on `(device, status)` for the reserved-bytes sum |
| `members_storage_nodes` | `device` (int, the device holding this file; **NULL for a folder row**, since folders exist per device implicitly) |
| `members_storage_placements` (new) | `member_reference_num`, `bucket`, `device` — the sticky preference; unique on `(member_reference_num, bucket)` |
| `members_storage_bucket_usage` | Unchanged. It counts bytes per bucket regardless of device |

A folder is a row in `members_storage_nodes` with no device; on disk it is a directory that
exists on **every device that holds a descendant**. Creating an empty folder creates the row and
defers the directory until the first file under it lands on a device.

## 5. When a bucket spans devices

The two operations that used to be one local call become a small fan-out:

- **Bucket move (`POST /nodes/move-bucket`)**: each file moves from `…/storage/{b1}/…` to
  `…/storage/{b2}/…` **on the device that holds it** — still a `rename(2)` on one filesystem,
  never a copy. A file never changes device by being moved. A move of a subtree that spans
  devices is one idempotent call per device.
- **Folder rename / delete**: one idempotent call per device holding descendants.

These are **not atomic across devices**. The fan-out is driven by members from a small
operation journal so a failure after device 3 of 4 is resumable, not lost — see [10](10-members-and-client-changes.md).
The single-device case, which is the normal one by rule 2, is a single call.

## 6. What placement does not do

- It never moves existing data. Draining or rebalancing a device is a later step.
- It never places by member "zone". A member has no fixed device; only a sticky preference.
- It never reads plan quota to pick a device.

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | The `ALTER`s and the `members_storage_placements` table in www's migrations | Applied with a plain `migrate --force`; guardrail test passes |
| **P1** | `PlacementService` in members: eligibility, projection, sticky preference, spill | Automated tests cover every rule in §1 and every term in §2, including stale report, full device, draining, and no-candidate `503` |
| **P2** | Wired into session open; `503 STORAGE_UNAVAILABLE` | A session opened against a fleet with one healthy device lands on it and records `device` |
| **P3** | Multi-device fan-out for move/rename/delete and its journal | A bucket with files on two devices moves, renames and deletes correctly, and a mid-fan-out failure resumes |

## 7. As built (2026-09-26)

- **P0** — `www/database/migrations/2026_09_27_000003_create_members_storage_placements.php` (named `create_members_*` because
  `MigrationOwnershipTest` requires it for any `members_` table). Adds `device` to sessions and nodes, creates
  `members_storage_placements`. `device` is the device **number**, not a foreign key.
- **P1** — `members/app/Services/Storage/PlacementService.php`, `NoEligibleDeviceException`, read-only model
  `App\Models\Brain\StorageDevice` (`$guarded = ['*']`), `App\Models\StoragePlacement`, `members/config/storage.php`.
- **P2** — `StorageSessionController::open`. **Behind `STORAGE_PLACEMENT_ENABLED` (default off).** Off = the old local-disk path,
  `device` NULL. On = placement is mandatory: it runs *inside* the session-creation transaction (so its row locks on the
  device rows cover the moment the reservation becomes visible) and no eligible device is `503 STORAGE_UNAVAILABLE`.
  `hasPhysicalSpace` is skipped only when placement is on; its removal stays in [10](10-members-and-client-changes.md).
  Commit records `session.device` on the file node.
- **P3 — not built.** Fan-out means calling devices, which need the storage app ([05](05-storage-app.md)), the upload protocol
  ([06](06-direct-upload-protocol.md)) and the `members_storage_operations` journal ([10](10-members-and-client-changes.md)).
  None exist. It is blocked, not skipped.

Consequence worth knowing: a device's `health` is only set by step 07, so with placement on **nothing is placeable until 07
lands**. That is the rule as written, not a defect.
