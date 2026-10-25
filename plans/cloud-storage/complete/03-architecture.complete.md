# 03-architecture — Completion

**Status:** Substantially complete for the components this pass builds. Full
scope (§9's "what each repo owns") spans three client repos; only the server
and web client are touched here.

## Summary

- **§1-2 (shape, on-disk layout):** `/mnt/storage1/{member_reference_num}/
  storage/{bucket}/` and `.staging/{sessionId}/{fileId}.part` implemented
  exactly as specified in `PathResolver`. `.trash/{retiredAt}-{bucket}/`
  (retirement holding area, doc 08 §6) is now built too — `PathResolver::
  trashPath()`, written to by `RetentionService`'s purge sweep — as of the
  08-storage-retention pass.
- **§3 (server components):** `StorageSessionController`,
  `StorageTreeController`, `StorageBucketController`, `PathResolver`,
  `NameValidator`, `QuotaService`, `UsageLedger`, and (as of the
  08-storage-retention pass) `RetentionService` all built as named. **Not
  built:** `SessionStore` (its responsibilities — session lifecycle, `.part`
  bookkeeping, expiry sweep — are folded directly into
  `StorageSessionController` in this pass rather than a separate class,
  since there's no expiry sweep console command yet to justify the split),
  `ReconcileStorage` console command (doc 04 §6's ledger↔disk drift
  checker — different from `StorageRetentionSweep`, which now exists and
  covers doc 08 §6's state machine, not doc 04 §6's reconciliation).
- **§3.1 (the PathResolver choke point):** Verified —
  `grep -rn "/mnt/storage1" app/` returns exactly one match, the constant in
  `PathResolver.php`.
- **§4 (upload flow):** Implemented end to end — scan → open session
  (validate, quota-route, conflict-scan) → ranged PUT (offset = `.part`
  file length, never a client counter) → commit (verify → PathResolver
  re-resolve → `rename(2)` → ledger update, one transaction). Resume via
  `GET /sessions/{id}` reading actual `.part` length is implemented as
  specified.
- **§5 (download):** Range-capable single-file download implemented.
  ZIP64 folder archive download is **not built**.
- **§6 (client state machine):** The web client (`resources/js/storage/
  StorageApp.ts`) implements scanning → preflight → awaiting (conflict
  modal) → uploading → verifying → done, but as one consolidated module
  rather than the doc's suggested file split, and with sequential
  (not 4-way parallel adaptive) chunk streams — still resumable via
  Content-Range/409 re-seek, just not concurrent. IndexedDB persistence
  across a page reload is not built; session state is in-memory only for
  this pass.
- **§7 (identity chain):** Consumed as-is via the existing
  `ResolvesCheckpointMember` trait. **Deviation from the doc:** the doc
  assumes prefixed `checkpoint_member_reference_num` /
  `checkpoint_member_plan` request attributes. The real middleware
  (`VerifyCheckpointToken.php`) still sets the unprefixed
  `member_reference_num` only, and sets no plan/tier attribute at all — the
  `checkpoint_*` rename tracked in `checkpoint-server-auth/04-shared-
  validator.complete.md` was never applied to `members`. This pass reads
  identity via the existing trait and resolves plan tier the same way
  `MemberController` does: `Membership::where('reference_num', $ref)->
  first()->membership ?? 0`, clamped 0-4 in `QuotaService::planFor()`.
  Functionally equivalent to what the doc specifies, just not sourced from
  a request attribute.
- **§8 (concurrency):** The unique index (A5) is in place. Quota
  reservation inside the session-open transaction is implemented
  (`UsageLedger::reserve()`). The `.part` advisory lock per fileId, and the
  48h session-expiry sweep, are **not built** — a session's `expires_at` is
  set and checked on every request, but nothing actively sweeps expired
  sessions or releases their reservations; an abandoned session's
  reservation is only released if the member (or a future sweep job)
  explicitly cancels it.

## Files changed

See `05-api-contracts.complete.md` and `09-web-client.complete.md` for the
full file lists (controllers/services and frontend respectively).

## Verification steps

- `grep -rn "/mnt/storage1" app/` → exactly one match (`PathResolver.php`).
- `grep -rn "logInAsUserByUsername" app/` → zero matches (the controller
  that had it is deleted).
- Manual trace of the upload flow against §4's sequence diagram — matches.

## Follow-up work

- `SessionStore` as its own class, and `ReconcileStorage` (doc 04 §6's
  ledger↔disk drift checker — acceptance A13, still not built) once a
  scheduled-sweep pass exists to justify the split/build it.
- `.part`-level advisory locking for concurrent chunk PUTs to the same
  fileId (currently relies on the client not racing itself).
- Session expiry sweep (nothing currently reclaims an abandoned session's
  quota reservation automatically).
- ZIP64 archive download, IndexedDB client-side resume, adaptive parallel
  upload streams.
