# 07 — Health polling and reporting

## Completion document

When this plan step is completed, write `complete/07-health-polling-and-reporting.complete.md`.

---

The storage app measures its devices on a schedule and reports to the Brain. The Brain turns
reports into a `health` value; placement reads `health`. This is the loop that keeps a broken,
full or missing device from receiving new sessions.

## 1. What the storage app measures

Every minute, per hosted device `N` (`storage:poll-devices`):

| Measurement | How | Meaning |
|---|---|---|
| `mount_ok` | `/mnt/storage{N}` is a mount point (device id differs from its parent) | The volume is actually mounted |
| `marker_ok` | `/mnt/storage{N}/.afs-storage-{N}` exists and names `N` | It is the right volume ([02](02-naming-and-addressing.md) §5) |
| `total`, `used`, `free` (bytes), `inodes_free` | `disk_total_space` / `disk_free_space` and an inode read | Capacity truth |
| `write_probe_ok`, `write_probe_ms` | Write, `fsync`, read back and delete a small file in `.probe/` | Writable, and how slowly |
| `open_sessions`, `staging_bytes` | Private database and `.staging` size | Context for an operator, not used for health |

A failed `mount_ok` or `marker_ok` **skips** the other measurements for that device — measuring
free space on an unmounted mount point would report the boot disk's space as the device's.

## 2. The report

One signed request per poll covering every hosted device:

```
POST {brain}/api/internal/brain/storage/report      (signed with the server credential)
{ server_reference, measured_at, app_version,
  devices: [ { number, mount_ok, marker_ok, write_probe_ok, write_probe_ms,
               total_bytes, used_bytes, free_bytes, inodes_free,
               open_sessions, staging_bytes } ] }
```

The Brain rejects a report that names a device the credential's server does not host (no
detail in the response), ignores a `measured_at` far in the future or older than the last
accepted one, and is idempotent per `(server, measured_at)`. A `202` is the whole answer — the
storage app does not wait for a decision.

If the Brain is unreachable the storage app **keeps polling and keeps the latest report queued
in its private database** (bounded), and sends when it can. A storage server never stops
serving because the Brain is down; the Brain, on its side, will simply see stale data and stop
placing on those devices — the safe direction (D10).

## 3. What the Brain does with it

On ingest, in one transaction per device:

1. Write the `reported_*` columns and append a row to `brain_storage_device_reports`.
2. Derive `health` and `health_reason`:

| Condition | `health` | `health_reason` |
|---|---|---|
| `mount_ok = false` | `offline` | `not_mounted` |
| `marker_ok = false` | `offline` | `marker_missing` |
| `write_probe_ok = false` | `offline` | `write_probe_failed` |
| `write_probe_ms` above threshold, or `inodes_free` low, or fill ≥ hard watermark | `degraded` | `slow_writes` · `low_inodes` · `over_hard_watermark` |
| none of the above | `healthy` | null |

3. Never touch `admin_state`. Staff intent and measurement stay separate ([03](03-registry-and-brain-admin.md) §2).

**Staleness is a Brain-side derivation, not a report.** A scheduled Brain job marks any device
whose `reported_at` is older than `report_ttl` as `unknown` (reason `stale_report`). A device
that has never reported is `unknown`. Placement treats `unknown` as ineligible.

Transitions write a row to `brain_storage_device_events` ([03](03-registry-and-brain-admin.md) §2) so staff see
"device 4: healthy → offline (marker_missing)" with a time, and can be alerted. **The Brain
never deletes or moves data because of a health change.**

## 4. Recovery

A device returns to `healthy` only after **two consecutive** clean reports — a flapping mount
must not oscillate in and out of placement every minute. An `offline` device with open
sessions leaves those sessions valid ([06](06-direct-upload-protocol.md) §8); when it is healthy
again clients resume from its offsets.

## 5. Server-level liveness

Any accepted report updates the server's `last_report_at`. The Fleet screen shows a server whose
reports stop as down, and every device on it as `unknown` within one `report_ttl`. This is the
signal for "the whole storage server is gone", which no single device measurement can give.

## 6. Cadence and cost

One request a minute per storage server, regardless of device count, and a `df`, an inode read
and a small write probe per device (7–15 per server). Both are trivial. The poll interval and
`report_ttl` (default 3 × interval) are Brain-side configuration, pulled with the rest ([03](03-registry-and-brain-admin.md) §5).

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | `storage:poll-devices` measurements and the signed report; queued retry in the private database | A poll produces the report body in §2 against a real mount |
| **P1** | Brain ingest, derivation table, history rows, two-clean-reports recovery | Unmounting a lab device turns it `offline`; remounting turns it `healthy` after two reports |
| **P2** | Stale-report job, server liveness, transition events on the Fleet screen | Stopping the storage app turns its devices `unknown` and placement stops using them |

## As built (2026-09-26)

Repos touched, all uncommitted: **www** (derivation, stale job, Fleet data + screen), **storage_device** (retry queue).

- **P0** — the measurements and signed report were built in [05](05-storage-app.md) P3. Added here: a **durable, bounded
  retry queue** (`report_queue` table, `ReportQueue`). Every poll enqueues its report *first*, then flushes oldest-first,
  so the normal case is still one request per poll; a Brain outage keeps reports (max 120, none older than 24 h — oldest
  dropped first) and the backlog drains in order, each sent with its original `measured_at`. A `202` deletes it; a
  network error, timeout, 5xx, 408, 429 or 401/403 is *retry*; any other 4xx is *rejected* and dropped (retrying the same
  bytes cannot succeed). Only one attempt per poll against a Brain that is down.
- **P1** — `StorageHealthDeriver` (www): the §3 table exactly, with the priority `not_mounted` > `marker_missing` >
  `write_probe_failed` > `slow_writes` > `low_inodes` > `over_hard_watermark`. Thresholds are config
  (`brain.storage.slow_write_ms` 2000, `low_inodes_free` 50000; 0 switches a check off); "over the hard watermark" uses the
  same usable capacity placement uses (the smaller of declared and reported). Only an explicit `false` is a failure — a
  field the report left out is neither a fault nor proof of health. Ingest applies it in the same transaction as the
  `reported_*` write; a report not newer than the last accepted changes nothing, health included. `admin_state` is never
  touched. One `brain_storage_device_events` row per change (actor `system`); a repeated identical problem writes none.
- **P2** — `brain:storage-mark-stale` every minute (Brain only): a device silent for more than `report_ttl_seconds` becomes
  `unknown` / `stale_report`; a silent *server* takes all its devices down together (the same job), and the Fleet
  screen shows each server as `reporting` / `not reporting` / `never reported`, plus a "Recent health changes" feed
  (newest 25 health transitions across the fleet).

**Decisions to confirm (the plan was silent or ambiguous):**
1. **A device that is `unknown` (never reported, or gone stale) also needs two clean reports** to become `healthy`, not
   one. The plan states the two-report rule for recovery; a brand-new device or one returning from silence is treated the
   same, so nothing enters placement on a single reading.
2. **A partial repair moves `offline → degraded` at once** (any problem reading takes effect immediately). Both are
   ineligible for placement, so delaying the more accurate label would gain nothing.
3. `inodes_free` is only reported on Linux (`stat -f`); on any other platform it is `null`, which the Brain reads as "not
   judged", never as "low".
