# 05-api-contracts — Completion

**Status:** Core upload/browse/download/delete surface implemented. Several
route-table entries from §1 are deferred.

## Summary

Implemented, all inside `checkpoint.verify:primary_only` (see the deviation
note below), under `/api/v1/storage`:

| Route | Status |
|---|---|
| `GET /profile` | Implemented (§3), including the `retention` block and the §3.2 85%-warning payload as of the 08-storage-retention pass |
| `POST /scan` | Implemented (§4), including real `identical` detection (accepts an optional per-file `sha256`) as of the 07-sync-and-dedupe pass |
| `POST /sessions` | Implemented (§5.1) — validation, quota routing, conflict detection, all four `conflict_policy` values |
| `GET /sessions/{id}` | Implemented — offset read from live `.part` file length |
| `PUT /sessions/{id}/files/{fileId}` | Implemented (§5.2) — streamed, `OFFSET_MISMATCH` 409 with true offset |
| `POST /sessions/{id}/commit` | Implemented (§5.5) — size/digest verify, re-validated path, atomic rename, one DB transaction |
| `DELETE /sessions/{id}` | Implemented — releases the reservation for whatever wasn't committed |
| `GET /tree` | Implemented (§6.1) — paged, cursor-based |
| `GET /files/{id}/content` | Implemented (§7.1) — Range-capable via `BinaryFileResponse`, RFC 5987 filename, `nosniff` |
| `POST /folders` | Implemented (§8) |
| `DELETE /nodes/{id}` | Implemented (§8) — recursive, soft-deletes, ledger decrement, one transaction |
| `POST /nodes/move-bucket` | Implemented (§8, doc 08 §5) as of the 08-storage-retention pass — see `08-storage-retention.complete.md` for the full behaviour |
| `PATCH /nodes/{id}` | Implemented (§8) as of the 07-sync-and-dedupe pass — rename and/or move within a bucket, folder rename bulk-rewrites descendants' `rel_path` in one statement |
| `GET /delta` | Implemented (§6.2, doc 07 §3) as of the 07-sync-and-dedupe pass — see `07-sync-and-dedupe.complete.md` |
| `GET /duplicates` | Implemented — not in this document's literal route table, added per doc 07 §4.3's true-duplicate-reporting requirement |

**Not implemented:**

- `PUT /sessions/{id}/batch` (§5.3, small-file coalescing) — every file is a
  full session-file even at a few bytes; no 10k→~40 request collapsing.
- `GET /download/archive` (§7.2) — no ZIP64 streaming.
- Rate limiting (§9) — none of the listed per-endpoint limits are enforced
  except the 5-open-sessions cap on `POST /sessions`.
- Protocol versioning header (§10) — not applicable yet; nothing consumes
  `X-AFS-Storage-Protocol`.

**Deviation:** §1 says routes sit inside "the `checkpoint.verify` middleware
group... the whole authenticated surface." The real `routes/web.php`
distinguishes an owner-only group (`checkpoint.verify:primary_only`) from a
shared/launcher group, and its own header comment already lists storage as
belonging to the owner group (checkpoint-server-auth plan 11 §5.2, already
shipped). Storage routes were added to `checkpoint.verify:primary_only`, not
a bare `checkpoint.verify` — a secondary user gets `secondary_not_permitted`
on every storage route, same as domains/VoIP. `cloud-storage/01-server-
authentication.md` §2.1 describes secondary users sharing the primary's
storage scope; that description predates the already-shipped owner/launcher
split and was not re-litigated here — flagging it as a product decision to
confirm, not a bug.

## Files changed

- `app/Http/Controllers/Storage/StorageSessionController.php` (new)
- `app/Http/Controllers/Storage/StorageTreeController.php` (new)
- `app/Http/Controllers/Storage/StorageBucketController.php` (new)
- `app/Services/Storage/PathResolver.php`, `NameValidator.php`,
  `QuotaService.php`, `UsageLedger.php`, `RegistryClient.php`,
  `StorageTree.php`, `InvalidPathException.php` (new)
- `routes/web.php` — new `/api/v1/storage/*` group inside
  `checkpoint.verify:primary_only`; old ungated `/api/storage/*` routes and
  their `StorageController` import removed
- `app/Http/Controllers/StorageController.php` — deleted

## Verification steps

- `php -l` on every new PHP file.
- `grep -rn "logInAsUserByUsername" app/` → zero matches.
- No storage route remains outside a `checkpoint.verify*` group (manual
  review of `routes/web.php`).
- **Updated in a follow-up session that fixed the composer lock and ran the
  app** (see `00-overview.complete.md`'s "Environment fixes" section):
  `composer.lock` repaired, `php artisan route:list` runs clean and shows
  every route above (including `nodes/move-bucket`) registered inside
  `checkpoint.verify:primary_only`, and `GET /api/v1/storage/profile`
  without a bearer token returns `401` end to end through the real HTTP
  server. Still not observed: any request that reaches a controller and
  actually touches the database — no local MySQL is running.

## Follow-up work

- Batch upload coalescing (§5.3), ZIP64 archive download (§7.2).
- Per-endpoint rate limiting (§9).
- Confirm the secondary-user access decision (owner-only vs shared-scope)
  with whoever owns the product call — the two plan documents currently
  disagree and this pass followed the already-shipped code convention.
- Run against a real database — nothing DB-backed here has been executed,
  only lint-checked, route-verified, and reasoned through.
