# 09 — Replication (later)

## Completion document

When this plan step is completed, write `complete/09-replication-later.complete.md`.

---

**Status: not built, and not scheduled.** This step exists so the first delivery leaves the
right hooks and so nobody has to rediscover, later, which earlier decisions were made *for*
replication. Nothing here is implemented in phase 1 of any other step.

## 1. What the earlier steps already do for it

| Decision | Why it is a replication hook |
|---|---|
| **D6** — every file row records its `device` | A file's location is data, so "a file has a second location" is an additional row, not a redesign |
| **D9** — one hostname per device | A replica is reachable by the same addressing as a primary; no new naming |
| **D8** — tokens name one device (`dev`) | A token can be minted for a replica device without new machinery |
| **D5** — placement returns a device | It can return an ordered list: primary first, then replicas |
| **D7** — members commits rows only after the device confirms | A replica set can require *n* confirmations before the row is written |
| Idempotent `/internal/ops` with operation ids ([05](05-storage-app.md) §4) | Replicating an operation to a second device is a retry-safe call |
| The `sha256` of every committed file | Copy verification needs no new metadata |

## 2. The shape it would take

- **`members_storage_node_copies`** — `(node_id, device, state, verified_at)` with `state` in
  `pending · in_sync · stale · lost`. The `device` column on `members_storage_nodes` remains the
  **primary**; a copy is any additional row here. Existing rows have no copies and behave
  exactly as today.
- **Placement** returns `[primary, replica…]`, choosing replicas on **different servers**, not
  merely different devices — two devices on one server share a failure domain.
- **Write path:** the primary commit is authoritative. Replication is **asynchronous by default**
  — the primary device (or members) enqueues a copy job; a member's upload is not held for it. A
  per-plan or per-bucket setting could require a synchronous second copy.
- **Copy transport:** device-to-device, pulling from the primary with a members-minted,
  single-use download token scoped to the replica. Members stays the only signer (D8).
- **Read path:** members returns a download URL for the primary; if the primary's `health` is
  not `healthy`, it returns the first `in_sync` replica's URL. The token's `dev` changes;
  nothing else does.
- **Repair:** a `stale` or `lost` copy is re-copied from any `in_sync` copy, verified by
  `sha256`. Scrubbing (periodic re-hash) is a follow-on.
- **Deletes and renames** fan out to every device holding a copy, through the same journal that
  multi-device buckets already need ([04](04-placement.md) §5, [10](10-members-and-client-changes.md)).

## 3. Quota

A replica is not billed to the member. Ledger and plan quota count **one** copy. Device
capacity accounting ([04](04-placement.md) §2) counts **every** copy, because disks fill with
replicas. This is the one place plan economics and device economics deliberately diverge.

## 4. What must not be done now

- Do not add a copies table, a replica column, or a replication job in the first delivery.
- Do not let any code assume a file has exactly one location beyond reading `device` from the
  row in one helper — so the day a second location exists there is one place to change.

## Phases

None. Scheduling this step is a separate decision, made when a device loss becomes a risk the
business will not carry.

## As built (2026-09-27)

Confirmed by re-reading the actual code (not re-derived from memory), against §1's table:

| Decision | Where it actually is |
|---|---|
| D6 — every file row records `device` | `members_storage_nodes.device`, set on every commit ([03](03-registry-and-brain-admin.md)/[06](06-direct-upload-protocol.md)) |
| D9 — one hostname per device | `StorageDeviceNaming` (www), `DeviceNaming` (storage_device) |
| D8 — tokens name one device | `TokenMinter`/`TokenVerifier`'s `dev` claim ([06](06-direct-upload-protocol.md)) |
| D5 — placement returns a device | `PlacementService::place()` — a single int today, as it should be until there is something to return a list for |
| D7 — members commits rows only after the device confirms | `StorageSessionController::commitOnDevice()` ([06](06-direct-upload-protocol.md)) |
| Idempotent `/internal/ops` | `FileOps` ([05](05-storage-app.md)) |
| `sha256` on every committed file | recorded at commit, both paths |

All seven hold. §4's ban (no copies table, no replica column, no replication job) was not violated by any earlier
step.

**One §4 sentence needed a small correction, not new machinery:** "reading `device` from the row in one helper" —
`$node->device` was being read directly at 3 call sites in `StorageTreeController` and 2 in
`StorageSessionController`'s replace-commit path, not through a single seam. Added
`App\Models\StorageNode::primaryDevice()` (today exactly `return $this->device;`) and routed those 5 sites through
it, so the day `members_storage_node_copies` exists, this method is the one place that changes to consider replicas
— not a grep across two controllers. `PlacementService`'s device-capacity query (`sum('size_bytes')` grouped by
`device`) was left as a direct column read: it is a fleet-wide aggregate, not a "where does this file live" lookup,
so it is a different kind of site and would need its own change either way.

No table, column, job, or other replication behavior was added. `members_storage_node_copies` remains undesigned
beyond §2's sketch.
