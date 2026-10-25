# 00 — Extras (off-plan adjustments)

## What this file is

The numbered `complete/` files are what the storage-sharding plan **became**. This file is everything added or fixed
that **was not a plan step**. It does not supersede the numbered completes for how the storage app works; it is the
authority for the work that sat **beside** those steps.

**How to add to it.** Append a new `E*` section (next number, dated): symptom/context, cause, what changed, the files,
and the operational consequence.

---

## Index

| # | Date | What | Why it is not in the plan |
|---|---|---|---|
| E1 | 2026-10-11 | `w/storage_device` upload hardening: streaming digest, commit lock, 507, slot ordering, no per-chunk eager load, small-file batch | Found by reading the built app against 05 §5.3 / §5.5 and 08 §7; fixes to steps 05–08, not new steps |

---

## E1 — Upload hardening in `storage_device` (2026-10-11)

Five defects found reading `w/storage_device` against the plans. All are in PHP, in the storage app; nothing in members,
the Brain or a client changed.

### 1. Commit re-read the whole file, with no lock

**Symptom.** `SessionService::commitFile` ran `hash_file('sha256', …)` over the `.part` inside the commit request.
Members gives a device call 15 s (`STORAGE_DEVICE_TIMEOUT`); a multi-GiB file cannot be re-read in that. When members
timed out and retried, two commits ran at once and the second saw the target the first had just renamed into place and
reported `EXISTS`.

**Cause.** 05 §5.5 says the digest is computed streaming during upload; the code never did. Nothing serialised commits.

**What changed.**
- `putRange` copies in 1 MiB reads and updates a SHA-256 context as it writes. The context is stored base64-serialised
  in the new `session_files.hash_state`, with `hashed_bytes` = how many bytes it covers (migration
  `2026_10_11_000001_add_hash_state_to_session_files.php`).
- The stored state is trusted only when `hashed_bytes` equals the chunk's start offset. A gap (bytes written, database
  update lost) sets it to null and commit falls back to the old read pass for that file — slower, never wrong.
- `commit()` takes a per-session `flock` on `.staging/{sessionId}.lock`, waits up to `commit_lock_wait_seconds` (10),
  then answers `503 COMMIT_BUSY`. After getting the lock it reloads the session, so a waiting commit returns the first
  one's recorded results.

### 2. A chunk PUT loaded every file of the session

`UploadController::session` did `GrantSession::with('files')` on every chunk. Now it does not; `offsets()` lazy-loads the
relation, which only `GET /v1/sessions/{id}` uses.

### 3. The small-file batch endpoint was never built

05 §5.3 / goal G3 / acceptance A7. With the 600/min per-member limit, 10,000 one-file PUTs take at least ~17 minutes.
Added `PUT /v1/sessions/{id}/batch` (frame format and limits in the app README). **Device side only: no client (web,
desktop, mobile) calls it yet, and members has no change for it.** It is not exercised end to end.

### 4. A full disk was reported as `400 BAD_RANGE`

A short write was indistinguishable from a short body. Now a free-space precheck (`min_free_bytes`, 128 MiB reserve)
refuses the chunk up front, and a write that fails mid-chunk (body not exhausted) is rolled back and answered
`507 INSUFFICIENT_STORAGE`, the code members already uses for its own session-open check. A genuinely short body is still
`400 BAD_RANGE`. The mid-chunk write failure cannot be reproduced on a temp directory, so only the precheck path is
covered by a test.

### 5. The concurrency slot was claimed before the cheap checks

`StreamConcurrencyGuard::claim` ran before the chunk-size, Content-Length, lock and offset checks, so a refused chunk
left the file `uploading` for the 30 s window while the code comment said it claimed nothing. The claim now comes after
all of them, and `StreamConcurrencyGuard::release()` hands it back (restoring state and `updated_at`) if the copy fails.

### Files

`app/Services/SessionService.php`, `app/Services/StreamConcurrencyGuard.php`,
`app/Http/Controllers/Client/UploadController.php`, `app/Models/SessionFile.php`, `routes/storage.php`,
`config/storage_device.php`, the new migration, `README.md`, and `tests/Feature/UploadHardeningTest.php` (22 tests).

### Operational consequence

- Run `php artisan migrate` on each storage server (two new nullable/default columns on `session_files`).
- Files already mid-upload when this ships have no hash state; they commit through the read pass, as before.
- Members should treat `COMMIT_BUSY` (503) as retryable; whether it already does has not been checked.
- Nginx: the 64 MiB body limit already in the vhost notes covers a full batch.
