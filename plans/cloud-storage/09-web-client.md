# 09 — Web client (members)

## Completion document

When this plan step is completed, write `complete/09-web-client.complete.md` with status,
summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments — PHPDoc on every
controller, service, model, and migration; JSDoc on every exported TypeScript symbol.

---

> **Extended by [storage-sharding 10](../storage-sharding/10-members-and-client-changes.md) §2.**
> `ApiClient.ts`/`UploadEngine.ts` below now route range PUTs and downloads to a storage device
> when a session is placed, with token refresh on expiry — done as of 2026-09-27
> (`storage-sharding/complete/10-members-and-client-changes.complete.md`). A session with no
> device (placement off, the default) uses exactly what this document describes, unchanged.

## 1. Server: what replaces `StorageController.php`

The existing 353-line controller is retired whole. Its defects (audit S1–S6, C1–C6, C13,
C14) are structural, and every one of its four methods does auth, path building, and
persistence inline.

### 1.1 New files

```
app/Http/Controllers/Storage/
  StorageSessionController.php    upload session lifecycle (doc 05 §5)
  StorageTreeController.php       browse, download, mutate (doc 05 §6–§8)
  StorageBucketController.php     profile, bucket moves (doc 05 §3, doc 08 §5)

app/Services/Storage/
  PathResolver.php                THE only place a member string becomes a path
  NameValidator.php               doc 06 rule set, pure, no I/O
  QuotaService.php                registry tiers + plan → routing decision (doc 08 §2–§3)
  UsageLedger.php                 transactional byte accounting (doc 04 §3)
  SessionStore.php                sessions, .part/.meta, expiry
  RetentionService.php            downgrade → retire → grace → purge (doc 08 §6)
  ArchiveStreamer.php             ZIP64 folder download
  RegistryClient.php              cached fetch of checkpoint_registry_v3.json

app/Console/Commands/
  StorageReconcile.php            storage:reconcile      (doc 04 §6)
  StorageRetentionSweep.php       storage:retention-sweep (doc 08 §6.5)
  StorageSessionSweep.php         storage:session-sweep

app/Models/
  StorageNode.php  BucketUsage.php  StorageSession.php
  StorageSessionFile.php  RetentionEvent.php
```

### 1.2 Routes

Registered **inside** the `checkpoint.verify` group. By the time this step runs, step 01
has already restructured `routes/web.php` so that group covers the whole authenticated
surface (doc 01 §5.1) — storage simply joins it. The four current storage routes at
`:62–65`, which sit outside it, are deleted in step 01c.

Handlers read identity only from request attributes, via the trait (doc 01 §5.3):

```php
$memberRef = $this->currentMemberReferenceNum();   // never from query/input/session
$plan      = $this->currentMemberPlan();           // 0–4, already clamped
```

```php
Route::middleware('checkpoint.verify')->prefix('api/v1/storage')->group(function () {
    Route::get   ('profile',                    [StorageBucketController::class,  'profile']);
    Route::post  ('scan',                       [StorageSessionController::class, 'scan']);
    Route::post  ('sessions',                   [StorageSessionController::class, 'open']);
    Route::get   ('sessions/{id}',              [StorageSessionController::class, 'show']);
    Route::put   ('sessions/{id}/files/{file}', [StorageSessionController::class, 'putRange']);
    Route::put   ('sessions/{id}/batch',        [StorageSessionController::class, 'putBatch']);
    Route::post  ('sessions/{id}/commit',       [StorageSessionController::class, 'commit']);
    Route::delete('sessions/{id}',              [StorageSessionController::class, 'cancel']);
    Route::get   ('tree',                       [StorageTreeController::class,   'tree']);
    Route::get   ('delta',                      [StorageTreeController::class,   'delta']);
    Route::get   ('files/{id}/content',         [StorageTreeController::class,   'content']);
    Route::get   ('download/archive',           [StorageTreeController::class,   'archive']);
    Route::post  ('folders',                    [StorageTreeController::class,   'createFolder']);
    Route::patch ('nodes/{id}',                 [StorageTreeController::class,   'update']);
    Route::delete('nodes/{id}',                 [StorageTreeController::class,   'destroy']);
    Route::post  ('nodes/move-bucket',          [StorageBucketController::class, 'moveBucket']);
});
```

### 1.3 Streaming, not buffering

PHP's default request handling loads an upload into `$_FILES` via a temp file, and
`post_max_size` rejects the request outright above its limit. The chunked protocol makes
this a non-issue *if* the range handler streams:

```php
/**
 * Stream the raw request body into the .part file at the Content-Range offset.
 * Never buffers the chunk in memory (acceptance A8), never uses $_FILES,
 * so post_max_size / upload_max_filesize do not apply — the body is read
 * as a stream, and chunks are capped at 64 MiB by doc 05 §9 anyway.
 */
$in  = fopen('php://input', 'rb');
$out = fopen($partPath, 'c+b');
fseek($out, $rangeStart);
stream_copy_to_stream($in, $out);
```

Nginx must not buffer these either — `proxy_request_buffering off` and
`client_max_body_size 64m` on the `/api/v1/storage/sessions/*` location. Without the first,
nginx spools every chunk to disk before PHP sees a byte, which doubles write I/O and
destroys progress reporting. This is a deployment prerequisite, not an optimisation; it
belongs in the Phase 0 checklist.

### 1.4 `PathResolver` — the choke point

```php
/**
 * The ONLY place in the application where a member-supplied string becomes a
 * filesystem path. Every filesystem call in the storage subsystem takes its
 * path from this class (doc 03 §3.1).
 *
 * Verifiable property: `grep -rn "/mnt/storage1" app/` returns exactly one match,
 * in this file.
 *
 * @throws InvalidPathException when validation fails or containment is violated
 */
final class PathResolver
{
    public function bucketRoot(string $memberRef, int $bucket): string;
    public function resolve(string $memberRef, int $bucket, string $relPath): string;
    public function stagingPath(string $memberRef, string $sessionId, string $fileId): string;
}
```

`resolve()` performs, in order: `NameValidator` on every segment → NFC normalisation →
join under the bucket root → `realpath()` of the parent → assert the real parent is inside
the real bucket root. The last step is what catches a symlink planted between session open
and commit (doc 12 §3).

## 2. Frontend: one file manager, mounted in Cloud Sync

### 2.1 The decision

Audit §5 found two competing views: **Tools → Cloud Sync** (`CloudController.ts` +
`cloud.html`, a tab shell with an empty AFS pane) and a standalone **storage** view
(`StorageController.ts` + `storage.html`, the only working upload UI).

The requirement names Cloud Sync as the member's entry point. So:

- The file manager becomes a self-contained module, `resources/js/storage/`.
- `CloudController` mounts it into `#afs-content` when the AFS tab activates, and calls
  its `destroy()` on switch away. `CloudController`'s existing tab/persistence behaviour is
  otherwise unchanged — it is a shell, and it stays a shell.
- `storage.html` and the standalone `StorageController.ts` are retired once parity is
  reached. Two file managers is the worst outcome available.

### 2.2 New frontend files

```
resources/js/storage/
  StorageApp.ts          mount/unmount, owns the panes
  UploadEngine.ts        the six-state machine (doc 03 §6)
  ScanService.ts         local walk + NameValidator port (doc 06)
  NameValidator.ts       TS port of the shared rule set + fixture test
  ApiClient.ts           typed wrapper over /api/v1/storage/*
  BucketPanel.ts         the retention UI (doc 08 §4)
  TreeView.ts            virtualised listing
  ConflictDialog.ts      the manifest-time resolution sheet (doc 07 §4.2)
  ScanReportDialog.ts    the rejection report (doc 06 §6)
  format.ts              bytes / rate / ETA
```

### 2.3 Fixes carried into the rewrite

Each of these is an audit defect that the new module must not reproduce:

| Audit | Fix in the new module |
|---|---|
| **C9** — `readEntries()` returns ≤ 100 entries per call | Loop until it yields an empty array. Without this, dropping a 500-file folder silently uploads 100 files. Add a depth cap (64, per doc 06) and an entry cap with a clear error |
| **C7** — sequential whole-file XHR | `UploadEngine` runs 4 parallel ranged PUTs with adaptive sizing (doc 05 §5.4) |
| **C8** — failures `alert()` and continue | Per-file state in the engine; a failed file is retryable and visible; the job is not "done" while files failed |
| **C10** — `Map<File,string>` leaks on failure | Manifest is a plain array of `{relPath, size, file}`; no identity-keyed map, cleared with the session |
| **C11** — progress bar swept 0→100 % per file | Aggregate bytes across the whole job; ≤ 4 UI updates/sec |
| **C12** — CSRF hunt, warns and proceeds | Bearer token via `sessionFetch.ts`'s existing `buildDefaultHeaders()` (which already attaches it); no CSRF on the API surface (doc 05 §2). A missing token is a hard stop, not a warning |
| **C1** — filenames slugged | Client sends real relPaths; server stores them (G1) |

### 2.4 Resume across a page reload

`UploadEngine` persists `{sessionId, fileIds, relPaths, offsets}` to **IndexedDB**, not
`localStorage` — a manifest of 50 000 entries exceeds `localStorage`'s ~5 MB budget, and
`localStorage` is synchronous and would jank the main thread on every write.

On mount, the engine looks for an unfinished session, calls `GET /sessions/{id}`, and
offers to resume. The browser cannot re-open the member's local files without a fresh user
gesture (`File` handles do not survive a reload), so the resume prompt asks the member to
re-select the same folder, then matches by relPath and continues from the server's offsets.
Bytes already uploaded are not re-sent.

This is the honest limit of browser resume, and the UI states it rather than appearing to
resume and silently restarting.

### 2.5 Large-folder UX

- The scan runs in a **Web Worker** so a 50 000-file walk does not freeze the tab.
- `TreeView` is virtualised — a 10 000-entry folder holds ~100 DOM nodes.
- The tree pages from `GET /tree` (doc 05 §6.1); no client assumes it can hold the whole
  tree.

## 3. i18n

Every user-visible string goes through the existing `data-i18n` / `i18n.t` mechanism into
the members locale files, under `storage.*`. New key groups:

`storage.buckets.*` (labels, meters, cascade explanation), `storage.scan.*` (one key per
rule id in doc 06 §3, so the member gets a specific reason, never "invalid filename"),
`storage.conflict.*`, `storage.retention.*` (retirement banners, grace copy, the
downgrade preview), `storage.upload.*`, `storage.errors.*` (one per API `code`).

The rule-id-to-message mapping is what makes the scan report in doc 06 §6 possible, and it
is why error `code` is a stable machine string separate from `message` (doc 05 §2).

## 4. Removals

| Item | Action |
|---|---|
| `app/Http/Controllers/StorageController.php` | Delete |
| `routes/web.php:62–65` | Delete — replaced by the group in §1.2 |
| `resources/js/controllers/StorageController.ts` | Delete after parity; salvage the folder-walk and hierarchy logic |
| `resources/js/templates/views/storage.html` | Delete after parity |
| `user_files` table + model | Drop **only** after [13](13-rollout-and-testing.md) §3 sign-off, in a later release |
| `resources/js/forms/services/StorageService.ts` | **Untouched.** Unrelated (audit §1.1) |
| `AFSController.ts`, Linode/AWS/DO controllers | **Untouched.** Out of scope (doc 00 §3) |
