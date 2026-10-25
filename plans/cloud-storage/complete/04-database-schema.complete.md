# 04-database-schema — Completion

**Status:** Complete as specified. All five tables now exist —
`storage_retention_events` (§5), originally deferred, was added in the
follow-up 08-storage-retention pass; see the update at the bottom of this
document.

## Summary

All four core tables from §2-4 were created exactly as specified, in
`w/members/database/migrations/2026_08_23_0000{01-04}_*.php` (the plan doc
says `apps/members`; the app actually lives at `w/members` in this repo — see
the platform-wide note in the checkpoint-server-auth Phase 1 work).

- `storage_nodes` (§2) — file/folder tree, `rel_path_hash` unique index on
  `(member_reference_num, bucket, rel_path_hash)`, soft-deletes, the sync
  bookkeeping columns (`version`, `etag`, `client_modified_at`) and
  `collision_mode` included for forward-compatibility even though this
  pass's controllers only ever write `collision_mode = 'portable'`.
- `storage_bucket_usage` (§3) — the authoritative ledger. `state`/
  `retired_at`/`purge_after` columns exist per spec but this pass's
  `QuotaService` never reads or writes `state`; every bucket up to the
  member's plan is treated as available.
- `storage_sessions` / `storage_session_files` (§4) — exactly as specified.
- `storage_retention_events` (§5) — added in the 08-storage-retention pass
  (`2026_08_23_000005_*`), append-only, written by
  `App\Services\Storage\RetentionService`. See
  `08-storage-retention.complete.md` for what writes to it.

Models: `App\Models\StorageNode`, `BucketUsage`, `StorageSession`,
`StorageSessionFile`, `RetentionEvent` — no Eloquent global tenancy scope
(§7), every service method takes `member_reference_num` as its first
argument.

`user_files` (the table this replaces) is untouched, per §8's explicit
instruction to drop it only after 13-rollout-and-testing.md §3 sign-off.

## Files changed

- `database/migrations/2026_08_23_000001_create_storage_nodes_table.php`
- `database/migrations/2026_08_23_000002_create_storage_bucket_usage_table.php`
- `database/migrations/2026_08_23_000003_create_storage_sessions_table.php`
- `database/migrations/2026_08_23_000004_create_storage_session_files_table.php`
- `database/migrations/2026_08_23_000005_create_storage_retention_events_table.php`
- `app/Models/StorageNode.php`, `BucketUsage.php`, `StorageSession.php`, `StorageSessionFile.php`, `RetentionEvent.php`

## Verification steps

- `php -l` passed on all four migrations and four models.
- Migrations were not run against a live database — no dev DB was available
  in this session (same constraint the checkpoint-server-auth pass hit).
  Schema was checked by hand against §2-4's literal `Schema::create(...)`
  blocks, not executed.

## Follow-up work

- Run `php artisan migrate` against a real database and confirm the unique
  index / foreign key constraints apply cleanly — not yet verified live
  (no local MySQL was available in either session that touched this plan).
