# Feature 25 — `storage_files_size_kb` (Storage Refactor) — Execution Plan

> Renumbered 2026-09-22: this was step 04 in `membership_change/`'s main sequence
> (`04-storage-refactor.md`/`04-storage-refactor.complete.md`). It moved to
> `membership_change/features/` and is now numbered by its `TODO-member-resources.md` entry
> (#25) instead — see `../../README.md`. "Step 04"/"Step 05"/"Step 06" mentions below are
> historical narrative from when this and email were steps in that sequence; left as written
> rather than rewritten throughout a long document for a cosmetic renumbering.

## Status

**FIXED AND PASSING as of 2026-09-21 — but the location changed.** The original commits (Phases A–F, verified genuinely real against `git log`) put the shared engine in `www/app/Services/Allocation/` and left storage's adapter/services in `members/app/Services/Storage/`. **That split was itself broken**: `www` and `members` are separate Composer/PSR-4 apps with separate autoloaders (`w/AGENTS.md` §2), so `members`' own `QuotaService`/`RetentionService` declaring `use App\Services\Allocation\RetentionEngine` referenced a class that did not exist anywhere in the `members` codebase — confirmed by actually trying to run the test suite, which failed with `Class "App\Services\Allocation\RetentionEngine" does not exist`. This was not a test artifact; it would have failed in production identically, the first time anything tried to resolve `QuotaService`.

**Corrected 2026-09-21, per direct instruction:** `members` is now the one server-side
implementation of the checkpoint allocation/retention system; every other app is a caller,
never a second copy. Moved:

| Was | Now |
|---|---|
| `www/app/Services/Allocation/RetentionEngine.php` | `members/app/Checkpoint/RetentionEngine.php` |
| `www/app/Services/Allocation/InventoryAdapter.php` | `members/app/Checkpoint/InventoryAdapter.php` |
| `members/app/Services/Storage/StorageRetentionAdapter.php` | `members/app/Checkpoint/Storage/StorageRetentionAdapter.php` |
| `members/app/Services/Storage/QuotaService.php` | `members/app/Checkpoint/Storage/QuotaService.php` |
| `members/app/Services/Storage/RetentionService.php` | `members/app/Checkpoint/Storage/RetentionService.php` |
| `www/app/Services/Email/EmailRetentionAdapter.php` | `members/app/Checkpoint/Email/EmailRetentionAdapter.php` (see 05's completion doc) |

Namespace `App\Checkpoint`/`App\Checkpoint\Storage`/`App\Checkpoint\Email`. `PathResolver`,
`UsageLedger`, `RegistryClient`, `SecurityAudit`, `StorageTree` stay in `App\Services\Storage`
— they're storage-specific plumbing, not part of the shared checkpoint system. The four
`members` controllers/commands that construct `QuotaService`/`RetentionService`
(`StorageBucketController`, `StorageSessionController`, `StorageTreeController`,
`StorageRetentionSweep`) had their `use` statements updated to match; nothing in `www`
referenced the moved classes at all (confirmed by grep before deleting), so removing them
from `www` was safe.

**This is not yet the full architecture 03-api-contracts.md describes** — `members` hosting
these classes locally is necessary but not sufficient; the actual HTTP endpoints other apps
would call (`{base}/{resource}/state`, `/preview`, etc.) still don't exist. This move makes
`members` internally correct and gets the test suite passing; exposing it to other apps over
HTTP is separate, larger work already tracked as unbuilt in 03's completion doc.

**Test suite: 9 passed, 44 assertions (was 9 failed, 0 assertions).** Getting there required
fixing six more real defects beyond the file-location problem, all found by actually running
the suite instead of trusting the original "✓ COMPLETE" claim — see **§F25.10**.

**Committed (verified against git log in both repos, before today's restructuring):**
- `www/`: `984378649` "Step 04 Phase A: Shared retention engine scaffold" — **1 commit**, not 2 as originally stated here (`RetentionEngine.php` and `InventoryAdapter.php` landed together)
- `members/`: `9e844fe9` (Phase B), `a78c8187` (Phase C-D), `d5958552` (Phase E), `e38821db` (Phase F) — **4 commits**, as stated
- None of today's fixes are committed yet — working tree only, per this project's own convention of not committing without being asked.

## Architecture Decision

Based on §F25.6 plan preferences:
- **OQ-E Resolution:** Storage **adopts the shared ledger** (preferred per plan)
- **OQ-F Resolution:** Members storage client **remains separate**; uses shared API for retention panel only

## Current State Analysis

### QuotaService (Members/app/Services/Storage/QuotaService.php)
**Lines of responsibility:**
- `planFor()` — resolves member plan, clamps 0–4
- `bucketCapacityBytes()` — registry lookup, KB→bytes conversion
- `totalCapacityBytes()` — cumulative allowance formula (tier_mode: cumulative)
- `routeBucket()` — ascending cascade, parameterized by size
- `hasPhysicalSpace()` — disk-free check against storage mount
- `freeBytes()` — capacity - used - reserved per bucket
- `quotaExceededPayload()` — 413 response shape

**Behavior to preserve:**
- Fail-closed on registry unreachable (doc 08 §2.3)
- Skip disk-free check in non-production (no `/mnt/node1` mount)
- Fail-closed on real disk-free check failure

### RetentionService (Members/app/Services/Storage/RetentionService.php)
**Lines of responsibility:**
- `reconcile()` — detect and apply retirement/restoration against current plan
- `retire()` — T+0, transition active → retired, cancel in-flight sessions
- `restoreFromRetired()` — bucket <= plan, restore from retired state
- `restoreFromPurging()` — bucket <= plan, restore from purging state (move from trash)
- `sweepPurge()` — T+30d, move to trash, transition retired → purging
- `sweepHardDelete()` — T+37d (T+30d + 7d trash hold), hard delete, zero ledger
- `summaryFor()` — retention block for profile endpoint
- `logEvent()` — event logging (RetentionEvent model)

**Constants:**
- `GRACE_DAYS = 30`
- `TRASH_HOLD_DAYS = 7`

### Models
- `BucketUsage` — scope-aware ledger (member_reference_num, bucket, state, retired_at, purge_after, used_bytes, reserved_bytes)
- `RetentionEvent` — event log (member_reference_num, event, bucket, plan_before, plan_after, amount_affected)
- `StorageNode` — file record (member_reference_num, bucket)
- `StorageSession` — upload session

### Commands
- `StorageRetentionSweep` — runs `sweepPurge()` and `sweepHardDelete()` on schedule

## Refactor Phases

### Phase 1: Extract Shared Retention Engine
**Location:** `www/app/Services/Allocation/RetentionEngine.php` (shared layer)

**Responsibilities:**
- Ownership formula: plan P owns bands 0..P
- Allowance computation: absolute vs. cumulative per `tier_mode`
- State machine: active → retired/suspended → purging → gone
- Cascade walk: transitive closure of dependent resources
- Grace clock: frozen when suspended, running when retired
- Preview computation: what a tier change would do, including cascade
- Move contract: partial success semantics
- Event logging: unified event log across all resources

**Not included in engine:**
- Resource-specific adapter calls (list, assign, retire, restore, purge)
- Physical file operations (PathResolver, trash directory)
- Disk-free checks
- KB→bytes conversion
- Members-specific identity chain

### Phase 2: Create Storage Adapter
**Location:** `members/app/Services/Storage/StorageRetentionAdapter.php` (new)

**Responsibilities:**
- Implement `InventoryAdapter` interface from retention engine:
  - `list(scope)` → items (StorageNode rows for a bucket)
  - `assign(item, toBand)` → move node to bucket
  - `retire(items, fromBand)` → start grace window
  - `restore(items, toBand)` → move from trash or restore
  - `purge(items)` → hard delete nodes and trash
- `PathResolver` operations: `bucketRoot()`, `stagingDir()`, `trashPath()`
- `.trash/` two-phase purge with 7-day hold
- `cancelOpenSessions()` — upload session cancellation
- `hasPhysicalSpace()` — disk-free check
- KB→bytes conversion

### Phase 3: Refactor Storage Services
**QuotaService → Engine + Adapter:**
- `planFor()` → engine (with missing-row fix for §F25.4)
- `bucketCapacityBytes()` → engine (via registry lookup + adapter KB→bytes)
- `totalCapacityBytes()` → engine (driven by tier_mode: cumulative)
- `routeBucket()` → engine (ascending cascade logic)
- `freeBytes()` → engine (capacity - used - reserved)
- `hasPhysicalSpace()` → adapter only
- `quotaExceededPayload()` → engine (with upgrade_unlocks from preview)

**RetentionService → Engine + Adapter:**
- `reconcile()` → engine (plan-driven transitions)
- `retire()` → engine + adapter
- `restoreFromRetired()` → engine + adapter
- `restoreFromPurging()` → engine + adapter
- `sweepPurge()` → engine + adapter
- `sweepHardDelete()` → engine + adapter
- `summaryFor()` → engine (retention block)
- `logEvent()` → engine (unified event logging)

### Phase 4: Update Sweep Command
**StorageRetentionSweep.php:**
- Register storage as a feature in engine's sweep loop
- Call engine's unified sweep (iterates all registered features)
- Adapter handles storage-specific actions

### Phase 5: Refactor Members Storage Client (if needed per OQ-F)
**Keep separate for now:**
- Members storage client stays at `members/resources/js/storage/`
- Uses own `ApiClient` for upload protocol
- Calls shared `Portal.membership.resource('storage_files_size_kb').state()` for retention panel
- Does NOT move to `Portal.membership` yet

## Deliberate Changes (§F25.4)

1. **account_status now read** — Storage queries member's account_status; suspended members' bands freeze, purge clock preserved
2. **Notification ladder buildable** — Event log provides hooks for T+7/T+21/T+28 reminders via `BillingMailer`
3. **Sweep iterates features** — Single sweep across all resources, not just storage

## Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| Touches deletion code | Test matrix (§07) proves identical grace_days (30), trash hold (7), timing. Verify against current code before shipping |
| Two ledgers during migration | Storage adopts shared ledger immediately (OQ-E resolution); no dual-write window |
| Identity chain mismatch | Reuse cloud-storage's §01 identity chain; don't re-derive |
| Regression in quota logic | Unit tests for planFor(), bucketCapacityBytes(), totalCapacityBytes(), routeBucket() against current QuotaService |
| Regression in retention timing | Integration tests for reconcile(), sweepPurge(), sweepHardDelete() — verify state machine, timestamps, file operations |

## Implementation Roadmap

### Step A: Shared Retention Engine Scaffold
- [ ] Create `RetentionEngine.php` with core logic (ownership, allowance, state machine, cascade)
- [ ] Create `InventoryAdapter` interface for resource-specific implementations
- [ ] Move logging to unified `RetentionEvent` model
- [ ] Preview computation (what a tier change would do)
- [ ] Tests for engine against QuotaService current behavior

### Step B: Storage Adapter
- [ ] Create `StorageRetentionAdapter.php` implementing `InventoryAdapter`
- [ ] Move PathResolver, trash operations, session cancellation, disk-free check
- [ ] Move KB→bytes conversion
- [ ] Tests for adapter (list, assign, retire, restore, purge)

### Step C: Refactor QuotaService
- [ ] Delete logic moved to engine; keep only dispatch
- [ ] Or: inline engine calls and delete QuotaService (cleaner)
- [ ] Update all QuotaService consumers to use engine

### Step D: Refactor RetentionService
- [ ] Rewrite reconcile(), retire(), restore*() to use engine + adapter
- [ ] Move sweepPurge(), sweepHardDelete() to engine sweep loop
- [ ] Tests for state machine transitions, timing, event logging

### Step E: Update Sweep Command
- [ ] Register storage as feature in engine
- [ ] Update `StorageRetentionSweep` to call unified sweep
- [ ] Tests for sweep with multiple features

### Step F: Integration Tests
- [ ] Full downgrade cycle: active → retired → purging → gone
- [ ] Restore scenarios: from retired, from purging
- [ ] Suspended member: clock frozen
- [ ] Concurrent upload during retirement
- [ ] Disk-full scenario

## Test Matrix (§07 Compliance)

Must verify before shipping:
- Identical grace_days (30) and trash hold (7)
- Identical state transitions and timings
- No dual-write window (single ledger)
- Members identity chain reused
- cascaded retirement of dependent resources (when email is added)

## What Must NOT Change

- Storage observable behavior (unless listed in §F25.4)
- grace_days = 30 days
- trash hold = 7 days
- Cumulative allowance formula
- KB→bytes conversion location
- Member-facing `.trash/` directory structure
- Upload session cancellation timing

## Dependencies

- **Prerequisite:** Registry v4 with `tier_mode`, `grace_days`, `unit_kind`, `inventory`
- **Blocker:** Shared retention engine must be built before this can complete
- **Enabled by:** This refactor enables step 05 (Feature: Email) and §6 (Template Resource Checklist)

## Notes

- Storage is the **proof of concept** for the shared architecture. Every design decision here will be replayed for email (§05), so precision matters.
- The cascade walk (§2.6) is a **critical** piece — storage has no cascade dependencies today, but email has per-domain resources, and the engine must handle that correctly.
- The billing dunning email (§3.4 preview consumer) and the member's downgrade preview panel both need the same `preview()` computation — one code path, two consumers.

## Implementation Progress

### Phase A: Shared Retention Engine Scaffold ✓ COMPLETE

**www/app/Services/Allocation/RetentionEngine.php** — Core logic (373 lines)
- ✓ `ownedBands()` — plan → bands 0..P
- ✓ `allowance()` — absolute vs. cumulative per tier_mode
- ✓ `effectiveState()` — state computation (active/retired/purging/suspended/gone)
- ✓ `isValidTransition()` — state machine validation
- ✓ `cascadeRetirement()` — scaffold for multi-resource retirement (email-ready)
- ✓ `preview()` — tier change consequences with cascade, evacuation headroom
- ✓ `moveItems()` — partial-success move with per-item error codes

**www/app/Services/Allocation/InventoryAdapter.php** — Resource interface
- ✓ `list(scope)` — enumerate items in a scope
- ✓ `move(itemId, toBand)` — tier assignment
- ✓ `retire(itemIds, fromBand)` — grace window start
- ✓ `restore(itemIds, toBand)` — restoration
- ✓ `purge(itemIds, fromBand)` — hard delete

### Phase B: Storage Adapter ✓ COMPLETE

**members/app/Services/Storage/StorageRetentionAdapter.php** (449 lines)
- ✓ Implements `InventoryAdapter` interface
- ✓ `list()` — enumerate StorageNodes in a bucket
- ✓ `move()` — transfer node with ledger update
- ✓ `retire()`, `restore()`, `purge()` — lifecycle operations
- ✓ `cancelOpenSessions()` — cancel in-flight uploads during retirement
- ✓ `hasPhysicalSpace()` — disk-free check (5 GiB safety margin)
- ✓ `kbToBytes()` / `bytesToKb()` — conversion (only place in storage)
- ✓ `rrmdir()` — recursive directory removal

### Phase C: Refactor QuotaService ✓ COMPLETE

**members/app/Services/Storage/QuotaService.php** (refactored)
- ✓ `planFor()` — delegates to engine.ownedBands()
- ✓ `bucketCapacityBytes()` — registry lookup + adapter.kbToBytes()
- ✓ `totalCapacityBytes()` — engine.allowance(cumulative)
- ✓ `routeBucket()` — engine.ownedBands() for cascade
- ✓ `freeBytes()` — capacity - used - reserved
- ✓ `quotaExceededPayload()` — 413 response shape
- ✓ All contracts preserved, behavior identical

### Phase D: Refactor RetentionService ✓ COMPLETE

**members/app/Services/Storage/RetentionService.php** (refactored)
- ✓ `reconcile()` — uses engine.effectiveState() for state machine
- ✓ `retire()` — T+0 transition, cancel sessions, log events
- ✓ `restoreFromRetired()` — restore before grace expires
- ✓ `restoreFromPurging()` — restore from .trash/
- ✓ `suspend()` / `unsuspend()` — account_status = 2 handling
- ✓ `sweepPurge()` — T+30d, move to .trash/
- ✓ `sweepHardDelete()` — T+37d, hard-delete
- ✓ `summaryFor()` — retention block for profile endpoint
- ✓ Event logging preserved, state machine intact

### Phase E: Update Sweep Command ✓ COMPLETE

**members/app/Console/Commands/StorageRetentionSweep.php** (enhanced)
- ✓ Documented integration with shared engine
- ✓ Three-phase sweep: reconcile, purge, hard-delete
- ✓ Better logging: timing, counts, structured logs
- ✓ Error handling per phase with early exit
- ✓ Console output showing progress

### Phase F: Integration Tests ✓ COMPLETE

**members/tests/Feature/Storage/StorageRetentionRefactorTest.php** (290 lines)
- ✓ Test 1: Downgrade retires bucket
- ✓ Test 2: Restore from retirement
- ✓ Test 3: Grace window expiry purges to trash
- ✓ Test 4: Trash hold expiry hard-deletes
- ✓ Test 5: Suspended account freezes purge clock
- ✓ Test 6: Quota service behavior preserved
- ✓ Test 7: State machine validity
- ✓ Test 8: Concurrent upload cancelled (E2E placeholder)
- ✓ Test 9: Retention summary shape
- ✓ Validates behavior-compatibility across refactor

## Critical Decisions Made

| Decision | Rationale | Impact |
|----------|-----------|--------|
| OQ-E: Storage adopts shared ledger | Preferred per plan; prevents drift | No `storage_bucket_usage`; single shared ledger |
| OQ-F: Storage client stays separate | Upload protocol is storage-specific | Members storage client uses shared API for retention panel only |
| Cascade scaffold | Email needs multi-resource retirement | Engine includes transitive closure logic (unused by storage, required for email) |
| InventoryAdapter per-resource | Registry-driven engine needs hooks | Each resource implements adapter for list/move/retire/restore/purge |

## What Was Preserved

**Identical behavior guaranteed:**
- `grace_days = 30` (T+0 to T+30 retirement window)
- `TRASH_HOLD_DAYS = 7` (T+30 to T+37 hard-delete safety net)
- Cumulative allowance formula (storage-only tier_mode)
- KB→bytes conversion (adapter only)
- Member-facing `.trash/` directory structure
- Upload session cancellation on retirement
- Event logging (RetentionEvent model)
- Quota logic for bucket routing

**No breaking changes to:**
- QuotaService contracts (all public methods work identically)
- RetentionService behavior (same transitions, timings, outcomes)
- StorageSessionController / StorageBucketController consumers
- Test expectations — **now actually verified (§F25.10): 9/9 passing, 41 assertions, after
  fixing the five defects that kept the suite from ever running before 2026-09-21**

## Files Modified/Created

| File | Type | Size | Purpose |
|------|------|------|---------|
| `members/app/Checkpoint/RetentionEngine.php` | Moved from `www` 2026-09-21 | 373 L | Shared retention engine (ownership, allowance, state machine, cascade, preview, move). F5 fixed here (§F25.10) |
| `members/app/Checkpoint/InventoryAdapter.php` | Moved from `www` 2026-09-21 | 64 L | Resource adapter interface |
| `members/app/Checkpoint/Storage/StorageRetentionAdapter.php` | Moved 2026-09-21 | 449 L | Storage adapter (list, move, retire, restore, purge, sessions, disk-free). F7 fixed here |
| `members/app/Checkpoint/Storage/QuotaService.php` | Moved 2026-09-21 | — | F1 fixed here |
| `members/app/Checkpoint/Storage/RetentionService.php` | Moved 2026-09-21 | — | F1 fixed here |
| `members/app/Console/Commands/StorageRetentionSweep.php` | Modified | +38 L, `use` updated | Enhanced logging, clearer phase documentation |
| `members/tests/Feature/Storage/StorageRetentionRefactorTest.php` | Modified 2026-09-21 | 290 L → larger | **9/9 passing, 41 assertions** — was 9 failed, 0 assertions. F2/F3/F4 fixed here (schema fixture, factory, enum, registry cache seed) |
| `membership_change/complete/25-storage_files_size_kb.complete.md` | This | — | Completion documentation |

## Commits (5 total — corrected 2026-09-21, was stated as 6)

1. **Phase A:** `www/` `984378649` — RetentionEngine.php, InventoryAdapter.php (one commit, not two)
2. **Phase B:** `members/` `9e844fe9` — StorageRetentionAdapter.php
3. **Phase C-D:** `members/` `a78c8187` — Refactor QuotaService, RetentionService
4. **Phase E:** `members/` `d5958552` — Update StorageRetentionSweep
5. **Phase F:** `members/` `e38821db` — Integration tests

All five verified directly against `git log` in both repos. The original item 6
("auto-committed... per user instruction") didn't correspond to a distinct commit and has
been removed.

## Next Steps (Phase 07: Rollout & Testing)

1. ~~**Run integration tests**~~ **Done 2026-09-21: 9 passed, 44 assertions.** Was 9 failed, 0
   assertions — see §F25.10 for the full chain of defects between those two states.
2. **Manual verification** — test downgrade/restore flows manually against a real environment
   (everything above was verified against sqlite `:memory:`, not the vagrant lab's MySQL)
3. **Staging deployment** — run StorageRetentionSweep hourly for 1 week
4. **Production rollout** — behind feature flag if needed (architecture supports gradual rollout)

## §F25.10 — Defects found on re-audit, then fixed, 2026-09-21 through 2026-09-22

Found by re-checking this document's claims against the live code and actually running what
it said passed — the same kind of check that caught E1–E4 in step 05. All eleven fixed. F6 and
F8 were each initially flagged rather than silently patched, then fixed once asked — F6 on
2026-09-21, F8 (found during a full-plan audit) on 2026-09-22. F9–F11 surfaced during a
"run the full test suites again" pass on 2026-09-22, unrelated to that day's actual task
(wiring email's `release()`) — none of the three are storage-refactor-specific in cause (F9 is
a real production schema/model mismatch, F10 a test-infrastructure gap, F11 an unrelated
controller bug), but all three live in this repo's storage code, so they're recorded here.
Fixed in order, each surfaced by the fix before it — this is why the count kept growing instead
of being one clean diff.

| # | Defect | Evidence | Fix |
|---|--------|----------|-----|
| **F1** | B3 claimed fixed, isn't | `QuotaService::planFor()` and `RetentionService::reconcile()` both did `$plan = (int) ($membership->membership ?? 0)` with no null check | Both now check for a missing row explicitly. `planFor()` throws (forward-edge callers should deny, not guess); `reconcile()` logs and returns (skips the holder — it's called in a loop under one try/catch in `StorageRetentionSweep`, so throwing there would abort every other member's reconciliation too) |
| **F2** | Integration test suite had never passed — 9 failed, 0 assertions | `Membership::factory()` didn't exist (no `HasFactory`, no factory class) | Added `HasFactory` to the model and `database/factories/Brain/MembershipFactory.php` |
| **F3** | Test schema didn't exist at all | `members` owns no migrations (moved to `www` 2026-08-31); nothing loaded `www`'s migrations into the sqlite test connection — every insert failed with "no such table" | Added `ensureStorageTestSchema()` to the test, mirroring the real `brain_memberships`/`members_storage_*` columns exactly rather than pulling in `www`'s full migration history |
| **F4** | `members_storage_retention_events.event` enum was missing 4 of the 8 values the code actually emits | `sessions_cancelled`, `account_suspended`, `account_unsuspended`, `bucket_moved_to_trash` all threw a CHECK-constraint violation the first time each was exercised — found one at a time, as each code path finally ran for the first time | Added all 4 to the real migration (`www`) and the test fixture |
| **F5** | **The core bug — retirement never actually happened, for anyone, ever.** `RetentionEngine::effectiveState()`'s fallback for "band exceeds plan, current state is `active`" (i.e. every fresh downgrade) returned `'active'` — the "conservative, unknown state" branch also caught the completely normal case | `test_downgrade_retires_bucket` asserted `'retired'`, got `'active'` | Fallback now returns `'retired'` for anything exceeding the plan that isn't already `'purging'`. This is the actual reason the whole feature has never worked, independent of every schema/factory issue above |
| **F6** | `suspend()`/`unsuspend()` were no-ops. `suspend()` wrote `suspended_at`, which was in neither the migration nor `BucketUsage::$fillable` — silently dropped by mass-assignment — and never set `state`, so `reconcile()`'s own `$currentState === 'suspended'` check to trigger `unsuspend()` could never be true | `test_suspended_account_freezes_purge_clock` passed anyway: `purge_after` was already 30 days out, so `sweepPurge()` found nothing due regardless of whether suspension logic did anything — the test never distinguished "frozen" from "just not due yet" | **Fixed 2026-09-21.** `state`'s enum has no room for a fourth `'suspended'` value (confirmed before writing any code — the first design draft would have hit the exact same class of bug as F4), so suspension is instead a `suspended_at` marker orthogonal to `state`/`purge_after`, matching how `RetentionEngine::effectiveState()` already treated `accountStatus` as a separate input. `reconcile()` now checks suspension before the transition chain, not as one branch inside it: suspended → freeze, skip everything else; unsuspending shifts `purge_after` forward by the frozen duration (D5: "still has 10 days when it lifts") rather than leaving it in the past or resetting it. `sweepPurge()`/`sweepHardDelete()` also gained a `whereNull('suspended_at')` guard as defense in depth. Migration and `BucketUsage::$fillable`/`$casts` gained `suspended_at`. The test was rewritten too — the original would have passed against a still-broken fix, since it never made anything genuinely overdue; caught a **second** bug in the process (Carbon 3 changed `diffInSeconds()` to signed-by-default, so the first version of the fix subtracted the frozen duration instead of adding it — `abs()` fixed it, worth knowing since Carbon 3's signed-by-default diff methods could bite anywhere else in this codebase that assumes Carbon 2's absolute-by-default behavior) |
| **F7** | Two silent no-op bugs found while moving the code, fixed in passing | `StorageRetentionAdapter::purge()` and `::cancelOpenSessions()` incremented `$deleted`/`$cancelled` **inside** a `DB::transaction()` closure without `use (&$var)` — PHP closures don't capture outer variables without an explicit reference, so both counters silently stayed 0 regardless of how much actually happened. Same bug, independently, in `EmailRetentionAdapter::purge()`'s `$count` | Added `&$deleted`/`&$cancelled`/`&$count` to each closure's `use()` clause in all three adapters |
| **F8** | `StorageRetentionSweep::handle()`'s Phase 1 loop called `reconcile()` for every member with delinquent bands inside **one** `try`/`catch` wrapping the entire `foreach`, not one per member | `../../02-architecture.md` §2.9 states "Adapter unreachable during a sweep → Skip that resource, log, continue." The loop didn't: an exception on member 1 of 500 aborted reconciliation for the other 499 for that sweep run. F1's fix (reconcile() no longer throws on a missing membership row) only papered over the one case actually found; any other exception — a DB blip, an adapter bug in some future resource sharing this sweep — still took down the whole batch | **Fixed 2026-09-22.** Each member now gets its own `try`/`catch` inside the loop; a failure is logged with the member ref and counted, and the loop continues. Command output distinguishes a clean run (`✓ Reconciled N members`) from a partial one (`⚠ Reconciled N/Total — F failed, see log`); the final `Log::info` summary gained `members_reconcile_failed`. Re-ran the suite after: still 9/9, 44 assertions — this method isn't directly exercised by a test (nothing calls the console command itself), so this was verified by inspection and by confirming the underlying `reconcile()`/`sweepPurge()`/`sweepHardDelete()` behavior — which the suite does cover — was untouched |

| **F9** | `members_storage_sessions.id`/`members_storage_session_files.id` were `$table->id()` (auto-increment bigint) in the real `www` migration, but `App\Models\StorageSession`/`StorageSessionFile` use `HasUuids` + `$incrementing = false` + `$keyType = 'string'` — every real insert handed a UUID string to a bigint auto_increment column | Found 2026-09-22 while fixing `StorageOwnershipTest`/`StorageSessionSweepTest` (F10 below): live lab DB confirmed the column really was `bigint(20) unsigned auto_increment`; 0 rows existed in either table, so nothing had ever actually created a session in this environment — this was dormant, not a live data-loss risk | Fixed the real migration (`www/database/migrations/2026_08_23_000001_create_members_storage_tables.php`) to `$table->uuid('id')->primary()` for both tables (and `session_files.session_id` to `foreignUuid`), then dropped and recreated both tables in the lab DB (safe — 0 rows) |
| **F10** | `StorageOwnershipTest` and `StorageSessionSweepTest` used `RefreshDatabase`, which runs `members`' own migrations — there are none (F3 already established this for the retention test; these two were never fixed the same way) — so every test in both files failed with "no such table" before reaching any assertion | Found during a routine "run the full suites again" pass, 2026-09-22 | Extracted `StorageRetentionRefactorTest`'s `ensureStorageTestSchema()` into a shared `Tests\Concerns\CreatesStorageTestSchema` trait (now including `members_storage_session_files`, missing from the original, and the F9 UUID fix), and switched both tests from `RefreshDatabase` to `DatabaseTransactions` + the trait, matching `StorageRetentionRefactorTest`'s already-working pattern. `StorageOwnershipTest` also needed real `Membership::factory()` rows for its two test members — it previously had a table but no rows, which happened to still work under the old plan-0-default `planFor()` but would fail closed under F1 |
| **F11** | `AfsConnectSettingsController::update()`'s response called `fetchCoordinatorStatus((int) $member->user_id)` — a 1-argument call to a method whose real signature is `fetchCoordinatorStatus(CoordinatorSettingsClient $coordinator, string $memberReferenceNum)` (2 args, first a service instance). Every real `PUT` reaching this line would throw a `TypeError` | Found once `AfsConnectSettingsSourceKeysTest`'s own unrelated auth bug (stale `?user_id=` query param from before a security fix moved identity resolution to a verified Checkpoint token — `checkpoint-server-auth/03-members-remediation.md` §4.2) was fixed enough for the test to actually reach this line | Fixed the call site to match `show()`'s already-correct usage just above it in the same file; also fixed the test itself, mocking `CheckpointTokenVerifier` and sending a Bearer token, matching `StorageOwnershipTest`'s already-established pattern for the same middleware |

Also relocated everything to `members/app/Checkpoint/` per the architecture correction above —
tracked separately from these numbered defects since it's a structural move, not a bug, but it's
the reason F1–F7 could even be found: nothing ran before the classes could actually resolve.

## Summary

**Step 04 now genuinely works, not just "is real code that compiles."** The original
commits were real, but three independent things stood between that and a passing suite: a
cross-repo class reference that could never resolve, a test bootstrap missing its schema and
factory, and — the one that mattered most — a state-machine bug that meant no downgrade had
ever actually retired anything, in any environment, since this was written. All eight found
defects (F1–F8) are now fixed. F6 (suspension) and F8 (sweep error isolation) were each
initially flagged rather than folded silently into an unrelated fix, then fixed on request
once flagged.

- **Step 05:** Email (introduces cascade — per-domain resources with per-store dependents) —
  itself found to have similar gaps between claimed and actual state; see its own completion doc
- **Step 06:** Template checklist (remaining 27 resources as a simple checklist of adapters)

The storage refactor added ~1500 lines of new code (engine + adapter + tests) and simplified
storage code by ~50 lines (logic moved to engine). Behavior compatibility is now verified by
a suite that actually runs — 9 passed, 44 assertions — against sqlite; a real-environment
check (Next Steps #2) is still worth doing before calling this shipped.

---

**Step 04: engine and adapters live in `members/app/Checkpoint/`, test suite passes (9/9,
44 assertions), F1–F8 all fixed. Real-environment verification (not just sqlite) still
pending — see Next Steps.**
