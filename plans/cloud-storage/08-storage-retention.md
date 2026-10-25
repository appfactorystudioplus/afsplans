# 08 — Storage Retention

## Completion document

When this plan step is completed, write `complete/08-storage-retention.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments. `QuotaService` and
`RetentionService` are the two classes in this plan where a wrong answer either blocks a
paying member or deletes their data — every method documents its inputs, its failure
direction, and which section of this document it implements.

---

> ### Extended by [storage-sharding](../storage-sharding/README.md): retention now moves device bytes too
>
> This document's physical retention actions — `RetentionService::sweepPurge()` moving a
> bucket directory to `.trash/`, `sweepHardDelete()` removing it, `restoreFromPurging()` moving it
> back — originally only touched the **local** `/mnt/storage1` path below, a silent no-op for a
> bucket whose files were placed on a storage device (`storage-sharding/04-placement.md`) while
> the bucket `state` and the `members_storage_nodes` rows still advanced regardless. **Closed
> 2026-09-27**: each method now fans out to every device the bucket touches through
> `OperationJournal`, using the existing per-item `trash`/`purge` device ops plus a new `untrash`
> op (the one genuinely new device primitive this needed) — before any local or row change, so a
> device refusal leaves the bucket exactly as it was rather than half-applied. Full detail in
> `storage-sharding/10-members-and-client-changes.md` ("Retention device fan-out") and
> `storage-sharding/complete/10-members-and-client-changes.complete.md`.

## 1. The feature in one paragraph

A member's plan number selects **how many storage buckets they own**. Bucket `n` lives at
`/mnt/storage1/{member_reference_num}/storage/{n}` and its capacity is
`checkpoint_registry_v3.json → resources.storage_files_size_kb.tiers[n].value` kilobytes.
Uploads fill the lowest bucket with room and **cascade upward** as each fills. A member can
**move files and folders between the buckets they own**, so they control what lives where.
If they downgrade, the buckets above their new plan are **retired** — read-only, then
deleted after a grace window — so the member decides in advance which data survives a
downgrade rather than discovering the answer afterward.

## 2. The formula

### 2.1 Per-bucket capacity

| Bucket | Registry tier | KB | Bytes | Human |
|---|---|---|---|---|
| 0 | `tiers[0]` | 524 288 | 536 870 912 | 512 MB |
| 1 | `tiers[1]` | 2 097 152 | 2 147 483 648 | 2 GB |
| 2 | `tiers[2]` | 10 485 760 | 10 737 418 240 | 10 GB |
| 3 | `tiers[3]` | 52 428 800 | 53 687 091 200 | 50 GB |
| 4 | `tiers[4]` | −1 | unlimited | Unlimited |

`-1` is the registry's `unlimited_sentinel`. Bucket 4 never fills, so a plan-4 member never
cascades and never hits a quota error.

### 2.2 Total capacity — cumulative, not absolute

A member on plan **P** owns buckets `0..P`:

```
capacity(P) = Σ  tiers[n].value        for n in 0..P
              (unlimited if any tiers[n].value == -1)
```

| Plan | Label | Buckets owned | Total capacity |
|---|---|---|---|
| 0 | free | 0 | 512 MB |
| 1 | starter | 0, 1 | **2.5 GB** |
| 2 | professional | 0, 1, 2 | **12.5 GB** |
| 3 | business | 0, 1, 2, 3 | **62.5 GB** |
| 4 | enterprise | 0–4 | **Unlimited** |

> **This is the interpretation to check before building.** It follows directly from the
> stated requirement — *"if storage folder 0 hits its max and the member is on plan 1 then
> they can continue to upload data to the plan 1 folder"* — which only makes sense if
> bucket 0's 512 MB and bucket 1's 2 GB are **both** available, totalling 2.5 GB.
>
> The alternative reading is that plan 1 simply means "2 GB total". If that is what is
> intended, the change is confined to `QuotaService::capacityFor()` and the UI copy in
> §4 — everything else in this plan is unaffected. It is called out here because the two
> readings differ by 25 % at plan 3 and the difference is invisible until a member hits it.

### 2.3 Where the numbers come from

`QuotaService` reads the registry JSON. It does **not** hardcode these values, and it does
not read them from Parse.

```php
/**
 * Per-bucket capacity in BYTES, or -1 for unlimited.
 * Source of truth: apps/auth public/checkpoint/checkpoint_registry_v3.json
 *                  resources.storage_files_size_kb.tiers[$bucket].value  (KILOBYTES)
 * The KB→bytes conversion happens here and nowhere else (doc 05 §2).
 */
public function bucketCapacityBytes(int $bucket): int
```

The registry is fetched from auth and cached; a fetch failure falls back to the last good
cached copy, and if there is none, **`QuotaService` denies** — `storage_files_size_kb` has
`overage: "block"`, and the platform rule is that `block` resources fail closed
([afs-connect/11](../afs-connect/11-billing-and-allocation.md) §11.4). An unreachable
registry must never become free quota.

### 2.4 Resolving the member's plan

```php
$plan = (int) ($membership->membership ?? 0);   // brain_memberships.membership
$plan = max(0, min(4, $plan));                  // clamp
```

Three rules, all from [afs-connect/11](../afs-connect/11-billing-and-allocation.md) §11.3:

1. The tier index **is** `brain_memberships.membership`. Pass it through.
2. **Never resolve a tier by name.** Both ladders rank 0–4; only the labels differ
   (`Free/Pro/Business/Organization/Enterprise` vs `free/starter/professional/business/enterprise`)
   and labels carry no contract. A name lookup breaks silently the first time marketing
   edits a label.
3. A missing membership record is **plan 0**, not an error and not a paid tier. Fail closed.

`plan_label` appears in API responses for display only and is never an input to logic.

## 3. Upload routing — the cascade

```
                    ┌──────────────────────────┐
                    │ POST /sessions           │
                    │ declared_bytes = N       │
                    └────────────┬─────────────┘
                                 ▼
                    plan = clamp(membership, 0, 4)
                                 ▼
              for b in 0..plan  (ascending):
                 ┌───────────────────────────────────┐
                 │ state(b) == 'active'?             │──no──▶ skip
                 │ free(b) = cap(b) − used(b) − rsv(b)│
                 │ cap(b) == -1  OR  free(b) >= N ?  │──no──▶ next b
                 └───────────────┬───────────────────┘
                                 │ yes
                                 ▼
                    route here; reserve N in bucket b
                                 │
              no bucket matched  ▼
                    413 QUOTA_EXCEEDED {
                      required_bytes, available_bytes,
                      plan, upgrade_unlocks_bytes
                    }
```

### 3.1 Rules

- **Ascending order.** Cheapest bucket first, so a downgrade retires the *least* data.
- **Whole session, one bucket.** A session is not split across buckets. A folder stays
  together, which is what makes bucket-level retirement comprehensible to a member.
- **A session larger than any single bucket fails** even if the *total* free space across
  buckets would hold it. Stated plainly because it is surprising: a plan-3 member with
  62.5 GB total cannot upload a 55 GB folder in one session, because no single bucket holds
  55 GB (bucket 3 is 50 GB). The error message says exactly this and suggests splitting.
  The alternative — silently splitting a folder across buckets — makes retirement
  unexplainable.
- **Reservations count.** `free = capacity − used_bytes − reserved_bytes` (doc 04 §3.2), so
  two concurrent sessions cannot both be told they fit.
- **Retired buckets are skipped** for writes even though the member technically still has
  data there (§6).
- **`explicit bucket`.** A client may pass `bucket: N` to force a target. If it does not
  fit or the plan does not reach it, the request fails rather than silently cascading —
  an explicit choice is honoured or refused, never reinterpreted.

### 3.2 The warning threshold

`warn_at_pct: 85` from the registry. When a bucket crosses 85 % the API response carries:

```jsonc
"warnings": [ { "code": "BUCKET_NEARLY_FULL", "bucket": 0, "used_pct": 87.4,
                "next_bucket": 1, "next_bucket_available": true } ]
```

`next_bucket_available: false` on a plan-0 member is the moment to surface an upgrade
prompt — before they are blocked, not after.

## 4. What the member sees

The requirement is explicit that the formula is **shown**, not merely enforced. This lives
in the AFS pane of **Tools → Cloud Sync**.

```
┌────────────────────────────────────────────────────────────────────────┐
│  STORAGE                              Plan: Business (3)   [ Manage ▸ ]│
│                                                                        │
│  ████████████████░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░  1.5 GB of 62.5 GB     │
│                                                                        │
│  Your plan gives you 4 storage buckets. Uploads fill the lowest        │
│  bucket first, then move up automatically as each one fills.          │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐ │
│  │ Bucket 0   ██████████████████████████████████  512 MB / 512 MB   │ │
│  │            FULL · 1,204 files          [ Browse ]  [ Move data ] │ │
│  ├──────────────────────────────────────────────────────────────────┤ │
│  │ Bucket 1   ████████████░░░░░░░░░░░░░░░░░░░░░░  1.0 GB / 2 GB     │ │
│  │            ← uploads land here now      [ Browse ]  [ Move data ] │ │
│  ├──────────────────────────────────────────────────────────────────┤ │
│  │ Bucket 2   ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░  0 B / 10 GB       │ │
│  ├──────────────────────────────────────────────────────────────────┤ │
│  │ Bucket 3   ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░  0 B / 50 GB       │ │
│  ├──────────────────────────────────────────────────────────────────┤ │
│  │ Bucket 4   🔒 Unlimited — available on Enterprise    [ Upgrade ] │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                        │
│  ⚠  If you downgrade to Free, buckets 1–3 are retired. You'd have 30   │
│     days to move or download 1.0 GB before it is deleted.              │
│     [ See what would be affected ]                                     │
└────────────────────────────────────────────────────────────────────────┘
```

Four things this deliberately makes visible:

1. **The cascade** — stated in a sentence, and shown by "← uploads land here now".
2. **Per-bucket meters**, not just a total, because retirement operates per bucket.
3. **The locked bucket** with its upgrade value.
4. **The downgrade consequence, before it happens**, with the exact byte figure. A member
   should never learn this from a deletion email.

## 5. Moving data between buckets

A plan-P member can move any file or folder between buckets `0..P`.

`POST /api/v1/storage/nodes/move-bucket` `{ node_ids: [...], target_bucket: N }`

### 5.1 Server behaviour

1. Reject `403 BUCKET_NOT_AVAILABLE` if `N > plan`.
2. Reject `423 BUCKET_RETIRED` if bucket `N` is retired.
3. Sum the moving bytes; reject `413 QUOTA_EXCEEDED` if they do not fit in `N`.
4. Reject `409 PATH_CONFLICT` if any `rel_path` already exists in `N` — with the full
   conflict list, same contract as an upload (doc 07 §4.2). **Never silently merge.**
5. In one transaction: `rename(2)` each path, update `storage_nodes.bucket`, decrement the
   source bucket's ledger, increment the target's.

### 5.2 Why this is fast

All five buckets are on one filesystem (doc 03 §2.1), so a move is `rename(2)` — a
directory-entry update, no data copied. Moving 40 GB between buckets is milliseconds.

### 5.3 The dependency this creates

**If buckets are ever placed on different mounts, every move becomes a copy-then-delete**
and the above stops being instant or atomic. That would require: a progress UI, a job
queue, transient double-counting against quota, and a crash-recovery path.

This is a real constraint on the storage layout, not an implementation detail. It belongs
in the Phase 0 checklist ([13](13-rollout-and-testing.md) §1).

### 5.4 The UI

Drag a file or folder onto a bucket meter, or select → "Move to bucket…". The confirmation
shows the byte total, the destination's free space after the move, and any conflicts.

An **"Optimise placement"** helper is offered but never automatic: it proposes filling
lower buckets first to minimise downgrade exposure, shows the plan, and requires a click.
Automatic data movement on a member's behalf is exactly the kind of helpfulness that
becomes a support ticket.

## 6. Downgrade and retirement

The requirement: *"if a member goes to the free tier which is 0 from plan 3 then all the
data in the folder 1-3 will be deleted."*

That is the end state. **How it is reached is decision D6, and it is not instant deletion.**

### 6.1 Why not delete immediately

A plan downgrade is a billing event. Billing events fire for reasons that have nothing to
do with intent: an expired card, a failed renewal, a currency issue, an accidental click,
a fraud hold. Deleting 60 GB of a member's files within seconds of a failed card is
irreversible data loss caused by a payment glitch, and it is unrecoverable and
unforgivable in a way that a 30-day grace period is not.

The grace window also *serves the requirement*: the data does get deleted. It gets deleted
after the member has been told, repeatedly, with a way out.

### 6.2 The state machine

```
   active ──plan drops below bucket──▶ retired ──grace expires──▶ purging ──▶ (gone)
      ▲                                   │
      └────────plan restored──────────────┘
                bucket_restored
```

| State | Read | Write | Move data out | Counts toward quota |
|---|---|---|---|---|
| `active` | ✅ | ✅ | ✅ | ✅ |
| `retired` | ✅ | ❌ | ✅ (to an active bucket, if it fits) | ❌ |
| `purging` | ❌ | ❌ | ❌ | ❌ |

A retired bucket is **readable and evacuable**. That is the entire point: the member can
still download everything and move what fits into the buckets they kept.

### 6.3 The timeline

| When | What |
|---|---|
| T+0 | Downgrade detected. Buckets `> plan` → `retired`. `purge_after = now + 30 days`. In-app banner, email. `bucket_retired` + `grace_started` events logged |
| T+0 | Any open upload session targeting a retired bucket is cancelled, its reservation released |
| T+7d | Reminder: days remaining, byte total, one-click "Download everything" |
| T+21d | Second reminder |
| T+28d | Final warning, explicitly naming the deletion date |
| T+30d | → `purging`. Files moved to `.trash/{retiredAt}-{bucket}/` |
| T+37d | Hard delete from `.trash/`. `bucket_purged` logged with byte and file counts |

The extra 7 days in `.trash/` is an operator safety net for the case where the automation
itself is wrong. It is not advertised to the member — a published deadline that is secretly
softer trains people to ignore deadlines.

### 6.4 Upgrade during grace

If the member's plan returns to cover the bucket at any point before hard deletion, it goes
straight back to `active`, untouched, and a `bucket_restored` event is logged. Nothing was
moved or rewritten, so restoration is a state change and not a recovery operation.

This is why purge is a two-phase (`purging` → `.trash` → delete) process rather than an
`rm -rf` at T+30d.

### 6.5 Detecting the downgrade

`brain_memberships.membership` changes outside this subsystem, so retention cannot rely on
being called.

| Mechanism | Role |
|---|---|
| Billing hook on membership change | Primary — immediate |
| `storage:retention-sweep` scheduled hourly | **Authoritative.** Compares every member's plan against their non-empty buckets and reconciles |
| On `GET /profile` | Opportunistic — a member looking at the page gets a current answer |

The sweep is authoritative because a missed hook must not mean a member silently keeps
storage they no longer pay for — and, in the other direction, must not mean a member who
upgraded stays blocked.

### 6.6 What the member sees while retired

```
┌────────────────────────────────────────────────────────────────────────┐
│ ⚠  Buckets 1–3 are retired                                             │
│                                                                        │
│    Your plan changed from Business to Free on 22 Aug 2026. Buckets     │
│    1, 2 and 3 are now read-only and hold 1.0 GB across 842 files.      │
│                                                                        │
│    These files will be deleted on 21 Sep 2026 (29 days from now).      │
│                                                                        │
│    Bucket 0 has 0 B free of 512 MB, so you cannot move this data       │
│    into it without deleting something first.                          │
│                                                                        │
│    [ Download everything (1.0 GB) ]  [ Choose what to keep ]           │
│    [ Upgrade to keep it all ]                                          │
└────────────────────────────────────────────────────────────────────────┘
```

The third paragraph matters: telling a member to "move your data" while their remaining
bucket is full is advice they cannot follow. The UI computes and states the real situation.

## 7. Reporting usage to alloc_v2

The local ledger is authoritative for enforcement (D4). alloc_v2 is still updated so that
platform-wide reporting sees storage consumption alongside every other metered resource.

- After each commit/delete/move, enqueue a debounced (60 s) job that reports the member's
  **total** used KB to `storage_files_size_kb` via the `AllocationGateway` seam described
  in [afs-connect/11](../afs-connect/11-billing-and-allocation.md) §11.4.
- The report is a **set**, not an increment — the local ledger's total is the truth, and a
  set is idempotent, so a lost or duplicated job cannot skew the number.
- **A failure to report never blocks an upload.** The local ledger already decided.
- Two facts from that document constrain this and are not assumed away here:
  `AllocationV2Manager.php` is implemented in no app, and `MemberRegistry`'s class-level
  permissions are currently public — so this integration is **reporting only** and is
  built behind the gateway interface so it can be switched to the direct-to-Parse
  transport without touching storage code.

Because of that, §7 is scheduled in **Phase 5** ([13](13-rollout-and-testing.md)) and the
feature is fully functional without it.

## 8. Edge cases

| Case | Behaviour |
|---|---|
| Plan 4 (unlimited) downgrades to 2 | Buckets 3 and 4 retired. Bucket 4 may hold more than any lower bucket can absorb — the UI states the shortfall in bytes and does not pretend a move is possible |
| Member is over quota after a registry tier *reduction* | Existing data is never deleted for this reason. The bucket is over-full, writes are blocked, the UI explains. Deleting data because a limit changed under them is not acceptable |
| Upload in flight when a downgrade lands | Session cancelled, `423 BUCKET_RETIRED`, reservation released, partial `.part` files removed. Already-committed files are untouched |
| Bucket 4 with `-1` and a full disk | `-1` means "no plan limit", not "infinite hardware". The physical-free-space check in `QuotaService` still applies and returns `507 INSUFFICIENT_STORAGE`, which is an operator alert, not a member-facing quota error |
| Member deletes files while retired | Allowed and encouraged — deletion from a retired bucket is not a write in the sense of §6.2's table; it reduces what will be purged |
| Two buckets retired, member upgrades to cover only one | Only the covered bucket returns to `active`. The other keeps its original `purge_after` — the clock does not restart |
| `membership` is `NULL` or non-numeric | Plan 0. Fail closed (§2.4) |
| `membership` > 4 (registry gains tiers) | Clamped to 4. Adding a bucket 5 is a schema + registry change, not an accident |
