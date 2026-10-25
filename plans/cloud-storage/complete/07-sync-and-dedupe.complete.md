# 07-sync-and-dedupe — Completion

**Status:** Every server-side mechanism this document specifies is built.
The sync *engine* itself — the three-state comparison, the BASE store, the
scheduling, selective sync — is not, and per the document's own §1 ("**The
web client does not sync**... Sync is a desktop and mobile capability"),
it never will be in this app. That's docs 10/11's job, and neither has
been started.

## Summary

### §1 — scope

Correctly out of scope for `w/members` (the app this whole plan lives in):
real-time push, multi-user concurrent editing, block-level delta, and —
per the doc's own words — sync itself, which is a desktop/mobile-only
capability the web client never performs. What's in scope for a **server**
is: the endpoints a sync client would call, and the duplicate-prevention
guarantees that apply regardless of which client is uploading. That's what
this pass builds.

### §2 — the three-state model, rename detection

**Not built, and not this app's job.** The three-state comparison
(LOCAL/BASE/REMOTE), the BASE SQLite/sqflite store, and the rename
heuristic (§2.4) all live entirely in a sync client's own code — a desktop
or mobile app decides locally whether something looks like a move and
issues `PATCH /nodes/{id}` instead of a fresh upload. The one piece of this
section that's genuinely server-side — `PATCH /nodes/{id}` existing at all
so a client *can* do that — is built (see §8 below, doc 05's route).

### §3 — the delta cursor

Implemented in full: `GET /api/v1/storage/delta?cursor=&bucket=`
(`StorageTreeController::delta()`).

- Cursor is the compound `(updated_at, id)` key the doc requires, base64
  of a small JSON object — not a bare timestamp, so rows sharing a
  timestamp at a page boundary are neither skipped nor repeated.
- Returns `{op: 'upsert', node: {...}}` for live rows and
  `{op: 'delete', id, rel_path, deleted_at}` for soft-deleted ones, exactly
  the shape in §3's example.
- **§3.1 — tombstones and `reset_required`:** a new `StorageNode::
  TOMBSTONE_RETENTION_DAYS = 90` constant, checked in `delta()` — a cursor
  older than the horizon gets `reset_required: true` with an empty change
  list, never a silent gap. A new `php artisan storage:tombstone-sweep`
  command (registered daily) hard-deletes tombstones past that horizon, so
  the horizon is an enforced fact, not just a comment referring to nothing.

### §4 — duplicate prevention

- **§4.1 (DB constraint):** unchanged from the Phase 1 pass — the unique
  index on `(member_reference_num, bucket, rel_path_hash)` was already
  built. This is acceptance A5.
- **§4.2 (conflict detection at manifest time):** the four `conflict_policy`
  values were already implemented in the Phase 1 pass, with one real gap
  closed in this pass: **`replace` now actually preserves old bytes.**
  Previously, `commitOneFile()` just `rename(2)`'d the new file over the
  old one — correct on-disk, but "old bytes go to `.trash/` for the trash
  window" (§4.2's literal text) wasn't true; the old content was gone the
  instant the overwrite happened. Fixed: a new `PathResolver::
  replacedTrashPath()` moves the pre-existing file into
  `.trash/replaced/{bucket}-{nodeId}-{timestamp}-{name}` before the new
  file takes its place, inside the same transaction.
- **§4.3 (content identity):** implemented — `scan()` now accepts an
  optional `sha256` per file and reports `identical: true/false` correctly
  (previously always `false`, since the field was never compared). `open()`
  now checks the same thing and routes an identical file straight to
  `skipped` with `reason: "IDENTICAL"`, bypassing `conflict_policy`
  entirely and transferring zero bytes — the "biggest win for re-syncing
  an unchanged folder" the doc names. **True-duplicate reporting** is a new
  `GET /api/v1/storage/duplicates` endpoint (not in doc 05's literal route
  table — added here because doc 07 names it as a concrete feature),
  grouping the member's files by `content_sha256` where more than one path
  shares content.
- **§4.4 (folder duplicates):** unchanged — already correct in the Phase 1
  pass (`POST /folders` on an existing path returns `409 ALREADY_EXISTS`;
  uploading into an existing folder merges rather than conflicting at the
  folder level).

### §5 — sync scheduling, §6 — selective sync

**Not built, and not this app's job** — both are entirely client-side
concerns (filesystem watchers, poll intervals, per-folder-pair
configuration) that belong to the desktop/mobile clients, which don't
exist in this codebase.

### §7 — failure semantics

Mostly a client-side retry/backoff spec (stalled-vs-failed, exponential
backoff) — client work, not built here. The one row that's genuinely
server-observable, "network drop mid-upload: session survives, reconnect →
`GET /sessions/{id}` → resume at the true offset," was already correct
from the Phase 1 pass (offset is always the live `.part` file length).

### §8 — a related doc 05 gap this pass also closed

While wiring `PATCH /nodes/{id}` to support §2.4's move-not-reupload
optimisation, implemented the full doc 05 §8 contract: `{name}` to rename,
`{parent_id}` to move within a bucket (present-and-`null` moves to the
bucket root). A folder rename/move rewrites every descendant's `rel_path`
in **one** bulk `UPDATE` statement using MySQL's `CONCAT`/`SUBSTRING`/
`SHA2`/`LOWER`, per doc 04 §2.2's explicit "bounded, transactional... one
`UPDATE`... rather than row-by-row" requirement — not a per-descendant PHP
loop. **Caveat, stated plainly:** this trades a small correctness risk for
that performance property — MySQL's `SHA2(LOWER(x), 256)` must reproduce
this app's `hash('sha256', mb_strtolower($x, 'UTF-8'))` exactly for every
descendant's rel_path, and while that holds for the ASCII range and for
common Unicode case-folding, MySQL's collation-driven `LOWER()` and PHP's
`mb_strtolower()` are not guaranteed to agree on every exotic Unicode
codepoint. A mismatch would produce a `rel_path_hash` a future PHP-side
lookup can't find. Not observed (no live DB to test against), flagged as
a follow-up rather than silently assumed correct.

## Files changed

- `app/Http/Controllers/Storage/StorageTreeController.php` — `delta()`,
  `duplicates()`, `update()` (new); docblock updated
- `app/Http/Controllers/Storage/StorageSessionController.php` —
  `scan()`/`open()` identical-detection, `commitOneFile()`
  replace-preserves-old-bytes
- `app/Services/Storage/PathResolver.php` — `replacedTrashPath()`
- `app/Models/StorageNode.php` — `TOMBSTONE_RETENTION_DAYS` constant
- `app/Console/Commands/StorageTombstoneSweep.php` (new)
- `routes/console.php` — daily tombstone-sweep schedule
- `routes/web.php` — `GET delta`, `GET duplicates`, `PATCH nodes/{id}`

## Verification steps

- `php -l` on every changed/new file — clean.
- `php artisan route:list` confirms all three new routes register inside
  `checkpoint.verify:primary_only`, alongside the rest of storage.
- `php artisan list` confirms `storage:tombstone-sweep` is registered.
- Traced the cursor encode/decode round-trip and the compound
  `(updated_at, id)` comparison by hand against §3's example payload —
  matches.
- Traced the bulk-`UPDATE` SQL for the folder-rename case by hand against
  a worked example (`start/old` → `start/new`, with a child
  `start/old/file.txt`) — produces `start/new/file.txt` correctly via the
  `SUBSTRING` offset math.
- **Not run against a live database** — same constraint as every other
  completion doc in this plan; no local MySQL was available. The MySQL
  `SHA2`/`LOWER` parity assumption above is reasoned, not observed.

## Follow-up work

- Verify the MySQL `SHA2(LOWER(x))` vs PHP `hash('sha256', mb_strtolower(x))`
  parity claim against a real database with a representative set of
  Unicode folder names — the one real correctness risk this pass
  introduces.
- The `.trash/replaced/` directory from the `replace` policy fix has no
  scheduled cleanup — bytes accumulate there forever in this pass. Doc
  §4.2 says "for the trash window," implying eventual deletion; not built
  (would need either its own sweep or folding into
  `StorageRetentionSweep`, which currently only knows about *bucket*
  retirement, not individual replaced files).
- `GET /duplicates` is O(n) queries for n duplicate-hash groups (one query
  per group after the initial GROUP BY) — fine for a cleanup UI, not
  optimised for a member with thousands of duplicate groups.
- Everything genuinely gated on docs 10/11 (desktop/mobile clients): the
  actual sync engine, BASE store, filesystem watchers, background
  transfer, selective sync configuration. This document's real remaining
  work is almost entirely there, not here.
