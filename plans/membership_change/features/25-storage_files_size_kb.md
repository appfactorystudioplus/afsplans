# Feature 25 — `storage_files_size_kb` (Storage Refactor)

> Renumbered 2026-09-22: was `membership_change/04-storage-refactor.md` (step 04 in the main
> sequence). Moved to `features/`, numbered by its `TODO-member-resources.md` entry (#25)
> instead of a step number — see `../README.md`.

> **STUB, FOR AUDIT.** Rewritten against `checkpoint_registry_v4.json`. The first draft was
> blocked on "where does the shared code live"; that is largely settled — the engine is
> registry-driven ([02](../02-architecture.md) §2.1) and storage supplies only an **adapter**.
>
> **Re-audited 2026-09-21** — the design below holds up, and the engine is genuinely built and
> wired in. But [complete/25-storage_files_size_kb.complete.md](complete/25-storage_files_size_kb.complete.md)
> claimed two things that turned out false on inspection: B3 (missing-membership-row-as-plan-0)
> was claimed fixed and isn't, and the integration test suite was claimed passing and has never
> run a single assertion successfully. See that document's §F25.10 for the full findings.

## F25.1 The rule this refactor is under

**Storage's observable behaviour does not change.** It is live, it holds members' files,
and its state machine is correct. This is a refactor onto shared machinery, not a
redesign — every difference between before and after is a bug unless it is listed in §F25.4.

In particular: storage stays **cumulative** (**M2**), now declared explicitly as
`tier_mode: cumulative` in registry v4. Email is `absolute`. Both are correct — §F25.8.

## F25.2 What maps directly

| Storage today | Shared machinery |
|---|---|
| `QuotaService::planFor()` | plan resolution (clamp, missing ⇒ 0) |
| `QuotaService::bucketCapacityBytes()` | registry lookup, unit conversion stays feature-local |
| `QuotaService::totalCapacityBytes()` | cumulative ownership formula |
| `QuotaService::routeBucket()` | the ascending cascade, parameterised by size |
| `RetentionService::reconcile()` | the detection step |
| `RetentionService::retire()` / `restoreFrom*()` | state transitions |
| `RetentionService::sweepPurge()` / `sweepHardDelete()` | the two-phase clock |
| `RetentionService::summaryFor()` | the `retention` block |
| `BucketUsage` | the scope-aware ledger row ([02](../02-architecture.md) §2.6) |
| `RetentionEvent` | the shared event log |
| `StorageRetentionSweep` | the shared sweep, iterating registered features |

## F25.3 What stays storage-specific

- `PathResolver` — `bucketRoot()`, `stagingDir()`, `trashPath()`. Physical layout is policy.
- The `.trash/` two-phase purge and its `TRASH_HOLD_DAYS = 7` operator safety net.
- `cancelOpenSessions()` — upload sessions are a storage concept.
- `hasPhysicalSpace()` — a disk-free check has no email analogue.
- KB→bytes conversion (cloud-storage doc 05 §2 says it happens in exactly one place).

## F25.4 Deliberate changes

Only these. Anything else is a regression.

1. **`account_status` is now read** (**M5**). A suspended member's storage bands freeze,
   and any running purge clock is preserved rather than advanced (**D4**,
   [02](../02-architecture.md) §2.3).
2. **The notification ladder is built** (**M3**, **OQ6**) — if the audit puts it in scope.
   Storage specified T+7/T+21/T+28 and never built them; billing's `BillingMailer` now
   exists to hook into.
3. **The sweep iterates features**, not just storage.

## F25.5 Risks

| Risk | Note |
|---|---|
| The refactor touches deletion code | Every change here is one step from deleting a member's files early. The test matrix ([05](../05-rollout-and-testing.md)) must prove the clock is unchanged before this ships |
| Two ledgers during migration | If storage keeps `storage_bucket_usage` while email gets a new shared table, they must not disagree about storage. Prefer one table or a clean cutover, not a dual-write window |
| `members` has its own auth story | cloud-storage's step 01 built the server-side identity chain. The shared API must use it, not re-derive one |

## F25.6 Open questions

- **OQ-E** — Does storage adopt the shared ledger table, or keep `storage_bucket_usage`
  with the shared *shape*? Prefer adoption: two tables with one shape is the drift D1 exists
  to prevent.
- **OQ-F** — Does the members storage client (`members/resources/js/storage/`) move onto
  `Portal.membership`, or keep its own `ApiClient` and use the shared API only for the
  retention panel? The upload protocol is storage-specific and should almost certainly stay
  where it is.

---

## F25.7 What storage becomes, concretely

Storage stops being *the* implementation and becomes **one adapter** plus a registry entry.

| Today | After |
|---|---|
| `QuotaService::planFor()` | engine (with the **missing-row fix**, §F25.4) |
| `QuotaService::totalCapacityBytes()` | engine, driven by `tier_mode: cumulative` |
| `QuotaService::routeBucket()` | engine, driven by `unit_kind: continuous` |
| `RetentionService` state machine + clock | engine |
| `BucketUsage` | the shared ledger row |
| `RetentionEvent` | the shared event log |
| `StorageRetentionSweep` | the shared sweep |
| `PathResolver`, `.trash/`, `cancelOpenSessions()`, `hasPhysicalSpace()`, KB→bytes | **the storage adapter** |

The adapter is where everything genuinely storage-shaped lives: physical layout, the
two-phase `.trash/` purge with its 7-day operator hold, upload-session cancellation, and the
disk-free check that has no analogue anywhere else.

`registry.inventory` for storage is already filled in: `members` / `storage_nodes`.

## F25.8 Storage is the only `cumulative` resource

`tier_mode: cumulative` exists in the registry **because of storage**, and storage is its
only user. That is correct, not an oddity: storage's bands are **five real directories** that
coexist and fill in sequence, so their capacities genuinely sum. For every other resource a
band is **only a label** on an item, recording which tier's allowance covers it — nothing is
physically anywhere, so there is nothing to add up.

That is also why storage's shipped code keeps `bucket` while these documents say `band`: in
storage, and only in storage, the label and a directory the member browses are the same thing
([../../handoff.md](../../handoff.md) §8.3).

Keeping it as a per-resource field rather than a global rule is what lets email be
`absolute` without either being wrong — and what stops a future implementer "harmonising"
them and silently changing every storage member's quota.

## F25.9 What must not regress

The refactor touches deletion code. The bar before it ships:

- Identical `grace_days` (30) and trash hold (7).
- Identical transition timings, verified against the test matrix in [05](../05-rollout-and-testing.md).
- No dual-write window between the old and new ledger — one table or a clean cutover.
- `members`' own server-side identity chain (cloud-storage step 01) is reused, not
  re-derived.
