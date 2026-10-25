# 08-storage-retention — Completion

**Status:** Complete for everything that's actual application code — the
formula, the cascade, bucket moves, and the full retire→grace→purge state
machine with restoration. The one deliberately-deferred piece is the
member-facing notification content (§6.3's reminder emails), because no
notification/email integration exists in this app for it to hook into, and
alloc_v2 reporting (§7), which the doc itself schedules as Phase 5.

## Summary

- **§2 (the formula):** Unchanged from the Phase 1 pass — `QuotaService`
  implements this fully (see `05-api-contracts.complete.md`).
- **§3 (cascade routing):** Unchanged from Phase 1, now also re-run through
  `RetentionService::reconcile()` before every session open, so a bucket
  that just fell out of plan can never be routed into by a fresh upload
  even before the sweep command next runs.
- **§4 (what the member sees):** `GET /profile` now returns the full
  `retention` block (`pending`, `retired_buckets[]`, `purge_after`) and the
  §3.2 85%-warning payload. The cascade-explanation *copy* and the
  downgrade-consequence *preview sentence* are still not built — the data
  the UI would need is there, the UI text isn't.
- **§5 (moving data between buckets):** Implemented —
  `POST /nodes/move-bucket` in `StorageBucketController::moveBucket()`.
  All five checks from §5.1 in order: plan reach (403
  `BUCKET_NOT_AVAILABLE`), target-bucket retirement (423 `BUCKET_RETIRED`),
  byte total vs free capacity (413 `QUOTA_EXCEEDED`), path conflicts (409
  `PATH_CONFLICT`, all-or-nothing — nothing moves if anything conflicts),
  then one transaction per node: `rename(2)` the directory/file (§5.2 — a
  folder's whole subtree moves in one syscall since buckets share a
  filesystem), a bulk `UPDATE storage_nodes SET bucket = ?` for the node
  and all its descendants (found by `rel_path LIKE 'prefix/%'`, since
  descendant `parent_id` links stay valid — only the moved node's own
  `parent_id` needs re-pointing at the target bucket's tree), and a ledger
  transfer (`UsageLedger::releaseUsed()` + new `creditUsed()`).
- **§6 (downgrade and retirement):** Implemented as real, working code:
  - `RetentionService::reconcile($memberRef)` — the detection step (§6.5),
    comparing every bucket row's state against the member's current plan.
    Retires buckets that fell out of plan (cancelling any open upload
    session targeting them first — §8's edge case), restores buckets the
    plan now covers again, from **either** `retired` or `purging` state
    (§6.4 — restoration from `purging` physically moves the `.trash/`
    directory back with one `rename(2)`; if it's already been hard-deleted,
    the bucket comes back active but empty, which is the honest outcome).
  - `RetentionService::sweepPurge()` — T+30d, moves a retired bucket whose
    `purge_after` has passed into `.trash/{retiredAt}-{bucket}/` and marks
    it `purging`. One `rename(2)`, not a per-file walk.
  - `RetentionService::sweepHardDelete()` — T+37d (the extra 7-day
    operator safety net from §6.3), deletes the `.trash/` directory and
    force-deletes the `storage_nodes` rows, logging `bucket_purged` with
    the byte/file counts captured before deletion.
  - `php artisan storage:retention-sweep` (`StorageRetentionSweep`) ties
    all three together and is registered hourly in `routes/console.php`
    via `Schedule::command()`.
  - Bucket state now actually gates access:
    `retired` blocks writes (`createFolder`, and implicitly every upload
    path via `QuotaService::routeBucket`'s plan check) but still allows
    reads (`tree`/`content`) — `purging` blocks both.
  - **Not built:** the reminder ladder (T+7d/T+21d/T+28d emails/in-app
    banners) — no notification system exists in this app to send them
    through. The state machine and data movement are real regardless of
    whether anyone was told.
- **§7 (alloc_v2 reporting):** Not built — correctly Phase 5 per the doc's
  own scheduling, not a gap in this pass.
- **§8 (edge cases):** Covered: instant-deletion-never (grace window is
  real, not a shortcut), in-flight upload cancelled on retirement (§8 row 3
  — `retire()` cancels open sessions before flipping state), deletion
  while retired still works (delete doesn't check bucket state), two
  buckets retired + upgrade covering one restores only that one (`reconcile()`
  loops per-bucket independently, `purge_after` for the other is untouched).
  **Not verified**: the plan-4-unlimited-downgrades-to-2 shortfall-messaging
  edge case (§8 row 1) — mechanically the buckets retire correctly, but
  there's no "states the shortfall in bytes" UI copy, same gap as §4.

## Files changed

- `database/migrations/2026_08_23_000005_create_storage_retention_events_table.php` (new — this was the one table deferred from the Phase 1 pass, see `04-database-schema.complete.md`)
- `app/Models/RetentionEvent.php` (new)
- `app/Services/Storage/RetentionService.php` (new)
- `app/Services/Storage/UsageLedger.php` — added `creditUsed()`
- `app/Services/Storage/PathResolver.php` — added `trashPath()`
- `app/Console/Commands/StorageRetentionSweep.php` (new)
- `routes/console.php` — registered the hourly schedule
- `app/Http/Controllers/Storage/StorageBucketController.php` — `moveBucket()`, `reconcile()` call + retention block + 85% warnings in `profile()`
- `app/Http/Controllers/Storage/StorageSessionController.php` — `reconcile()` call in `open()`
- `app/Http/Controllers/Storage/StorageTreeController.php` — bucket-state checks in `tree()`, `content()`, `createFolder()`
- `routes/web.php` — `POST nodes/move-bucket`

## Verification steps

- `php -l` on every new/changed file — clean.
- `php artisan route:list` confirms `POST api/v1/storage/nodes/move-bucket`
  is registered inside the same `checkpoint.verify:primary_only` group as
  the rest of storage.
- `php artisan list` confirms `storage:retention-sweep` is registered.
- `php artisan schedule:list` confirms the schedule entry exists (fails
  past that point on the same pre-existing "no local MySQL" environment
  gap noted throughout — not a code issue; the command itself resolved and
  attempted to run before hitting the DB).
- Traced the state machine by hand against §6.2's table and §6.3's
  timeline for every transition (`retire`, `restore×2`, `sweepPurge`,
  `sweepHardDelete`) — matches.
- **Not run against a live database** — no local MySQL, same constraint as
  every other completion doc in this plan. The state machine has not been
  observed executing against real data, only reasoned through and
  lint-checked.

## Follow-up work

- The T+7d/T+21d/T+28d member-facing reminder ladder — needs a
  notification/email integration this app doesn't currently have.
- The cascade/downgrade-consequence UI copy in Cloud Sync (§4, §6.6) — the
  API now returns everything needed; the frontend doesn't render it yet.
- `plan_before` isn't tracked on retention events (no membership-change
  history exists to source it from) — every event logs only the plan
  observed at the moment it fired.
- Run the whole state machine against a real database and a real clock
  (fast-forwarding `purge_after`) to observe it end-to-end — this pass is
  code-reviewed and traced by hand, not executed.
