# 09-web-client — Completion

**Status:** Server-side §1 complete (see `05-api-contracts.complete.md`,
`07-sync-and-dedupe.complete.md`, `08-storage-retention.complete.md`).
Frontend §2 substantially complete as of a follow-up pass that split the
original single-file consolidation into the doc's intended module
boundaries and built out §2.3's remaining fix (parallel uploads), §2.4
(IndexedDB resume), and most of §2.5 (Web Worker scanning; tree pagination
in place of full virtualisation). §3 (i18n) covers every static label and
every server error code; dynamic non-English translation is still English.

## Summary

### §1 — server

Unchanged from the first pass — see `05-api-contracts.complete.md` for the
full route/controller breakdown, `07-`/`08-*.complete.md` for what was
added in the follow-up passes (delta, duplicates, rename/move,
bucket-to-bucket move, the full retention state machine).

**Deployment prerequisite still not configured or confirmed:** §1.3's
`proxy_request_buffering off` / `client_max_body_size 64m` on the storage
session nginx location.

### §2 — frontend

**Mount point deviation, unchanged from the first pass:** a dedicated
"Storage" tab in Cloud Sync rather than colliding with `AFSController.ts`'s
unrelated VM-provisioning mock — see the earlier version of this document
(still accurate) or `00-overview.complete.md` for the full rationale.

**§2.2 file structure — now much closer to the doc's own split.** What was
one 550-line `StorageApp.ts` is now:

| File | Doc's name | Role |
|---|---|---|
| `ApiClient.ts` | `ApiClient.ts` | typed `/api/v1/storage/*` wrapper |
| `NameValidator.ts` | `NameValidator.ts` | client-side rule port (doc 06) |
| `UploadEngine.ts` | `UploadEngine.ts` | the six-state machine, adaptive concurrency, IndexedDB checkpointing |
| `IndexedDbStore.ts` | *(part of UploadEngine in the doc)* | cross-reload job persistence, split out as its own class |
| `ScanWorker.ts` + `ScanWorkerClient.ts` | *(part of ScanService)* | off-main-thread path validation |
| `ConflictDialog.ts` | `ConflictDialog.ts` | session-wide conflict-policy choice |
| `ScanReportDialog.ts` | `ScanReportDialog.ts` | per-file rejection report with fix suggestions |
| `BucketPanel.ts` | `BucketPanel.ts` | the full doc 08 §4 usage/retention panel |
| `format.ts` | `format.ts` | bytes/rate/ETA/escapeHtml |
| `errors.ts` | *(not in the doc's list)* | API-error-code → i18n-key mapping |
| `StorageApp.ts` | `StorageApp.ts` | DOM wiring / mount only now |

No `ScanService.ts` or `TreeView.ts` as separate files — the folder walk
stayed in `StorageApp.ts` (it needs `webkitGetAsEntry()`, a DOM API a
Worker can't reach) and the tree listing is ~80 lines inline rather than
warranting its own module.

**§2.3 (fixes carried into the rewrite) — C7 now addressed, not just
partially:** `UploadEngine.runSession()` runs a worker-pool of files
concurrently (default 4, adaptive 1-8: halved on a failure, grown by 1 on
success), checkpointing to IndexedDB after every chunk. **Stated
simplification:** this adapts *concurrency*, not *chunk size* — doc 05
§5.4 also wants per-chunk size adapting 4-32 MiB; chunk size here is fixed
at 8 MiB. And parallelism is across files, not within one file's chunks —
the common case (a folder of many files) benefits; one very large file
still uploads correctly, just without internal parallelism.

**§2.4 (resume across reload) — built.** `IndexedDbStore` persists
`{sessionId, bucket, files: [{relPath, fileId, size}]}` after every
successful chunk (not `localStorage` — doc 09 §2.4's own reasoning: a
50,000-entry manifest exceeds its ~5MB budget and it's synchronous). On
mount, `StorageApp` checks for unfinished jobs and shows a resume banner.
Because a browser can't re-open local `File` handles after a reload, the
resume flow asks the member to re-select the same folder
(`webkitdirectory` input), matches re-selected files back to the persisted
job by `relPath`, and re-queries `GET /sessions/{id}` for the
authoritative current offset before continuing — exactly the "honest limit
of browser resume" the doc describes, not a silent full-auto resume.

**§2.5 (large-folder UX):**
- Web Worker scanning: **built**, with the caveat noted above (the walk
  itself can't move to a worker; the CPU-bound *validation* pass does, via
  `ScanWorkerClient.validatePathsInWorker()`, used automatically above a
  300-file threshold — below that, spawning a worker costs more than it
  saves).
- Virtualised `TreeView`: **still not built.** What's built instead is
  server-driven pagination with a "Load more" button
  (`StorageApp.loadTree()`'s `next_cursor` handling) — the DOM never holds
  more than however many pages the member has clicked through, which
  bounds memory growth but isn't the windowed/recycled-row virtualisation
  the doc describes. Honest partial credit, not full credit.

### §3 — i18n

**Now covers every server error code and every doc-06 rule id**, not just
static labels. `errors.ts` maps API `{error: {code}}` responses through
`storage.errors.<CODE>` keys (`translateError()`) and doc 06 rule ids
through `storage.scan.<RULE>` keys (`translateRule()`), both with a
same-as-key fallback so a missing translation degrades to the server's
own English message rather than a raw key string. All of these were added
to `en.json` only. **Still not done:** `es`/`de`/`fr`/`zh` translations —
non-English members see the English fallback for every storage string,
same gap as before.

### §4 — removals

Unchanged from the first pass — see the earlier version of this section
(still fully accurate).

## Files changed

**Backend:** see `05-`, `07-`, `08-*.complete.md`.

**Frontend (this pass):**
- `resources/js/storage/UploadEngine.ts`, `IndexedDbStore.ts`,
  `ScanWorker.ts`, `ScanWorkerClient.ts`, `ConflictDialog.ts`,
  `ScanReportDialog.ts`, `BucketPanel.ts`, `format.ts`, `errors.ts` (new)
- `resources/js/storage/StorageApp.ts` — rewritten to delegate to the
  above instead of implementing everything inline
- `resources/js/templates/views/cloud.html` — bucket panel markup replaced
  with one mount point BucketPanel.ts owns; added the "Keep Both" conflict
  button and the scan-report modal
- `resources/js/i18n/en.json` — `storage.errors.*`, `storage.scan.*`,
  `storage.keep_both`, `storage.continue`, `storage.scan_report_title`

## Verification steps

- `tsc --noEmit` — zero errors in any storage file, confirmed by listing
  every file with an error afterward and checking none live under
  `resources/js/storage/` (all 18 remaining errors are pre-existing and
  unrelated, same set as before this pass).
- **`vite build` (a real production build, not just tsc) succeeds** —
  1083 modules transformed, and `ScanWorker` correctly emits as its own
  chunk (`ScanWorker-*.js`, 2.54 kB), confirming the
  `new Worker(new URL(...), {type:'module'})` import pattern actually
  bundles correctly, not just type-checks.
- `php artisan route:list`/browser testing not re-run this pass (no code
  changed on the backend) — see the earlier verification note for what was
  confirmed running end-to-end (server boots, routes register, 401 on a
  missing token).
- **Still not observed:** an actual browser session driving the new
  UploadEngine/resume/worker-scan/BucketPanel code against a live backend
  with a real database. No local MySQL, no test credentials, same
  constraint as every other completion doc in this plan.

## Follow-up work

- **Run this in an actual browser against a real backend with a real
  login** — the single most important follow-up, repeated from the last
  pass because it's still true.
- Per-chunk adaptive sizing (doc 05 §5.4's other half — concurrency now
  adapts, chunk size doesn't).
- True windowed/recycled-row virtualisation for `TreeView` (pagination is
  in place as a partial mitigation, not a replacement).
- `es`/`de`/`fr`/`zh` translations for the new `storage.errors.*`/
  `storage.scan.*` keys.
- The `.trash/replaced/` cleanup gap noted in
  `07-sync-and-dedupe.complete.md` also affects the UI: `BucketPanel`
  doesn't surface "replaced" bytes sitting in trash anywhere.
- Configure `proxy_request_buffering off` / `client_max_body_size 64m` at
  the nginx layer (deployment prerequisite, not code).
