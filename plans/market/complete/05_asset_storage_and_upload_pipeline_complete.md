# Step 05 — Asset storage, filename analysis, and the upload pipeline — completion report

**Date:** 2026-08-19
**Projects touched:** market (`apps/market`), members (`apps/members`).
**Status:** ⚠️ complete with gaps — the full pipeline (filename analysis, zipping, chunked upload,
server re-validation, signed downloads, preview processing, retention) is built and live-tested end
to end on the market side and cross-validated from the members side; the wizard UI that wires these
modules together is explicitly step 06's job and does not exist yet.

## What shipped

A complete, tested upload pipeline: client-side filename analysis (24 rules, ported identically to
PHP for server-side re-validation), a hand-written browser-side ZIP writer, a resumable chunked
upload transport, server-side archive validation (zip-slip, zip-bomb, symlink/device rejection, MIME
sniffing), signed short-lived download URLs redeemed via nginx `X-Accel-Redirect` (with a dev-mode
direct-stream fallback), image preview re-encoding (anti-polyglot, EXIF-stripping, WebP conversion),
and two scheduled retention commands. Three real, previously-undiscovered bugs were found and fixed
by actually exercising this code rather than trusting it on inspection — see "Decisions."

## Files created

**market:**

| File | Purpose |
|---|---|
| `app/Services/Storage/MarketAssetPaths.php` | Every on-disk path this pipeline touches, computed in one place (§05.1) |
| `app/Services/Storage/FilenameAnalyzer.php` | The 24-rule table, ported from the TS module, byte-identical output (verified) |
| `app/Services/Storage/ArchiveValidator.php` | Zip-slip, zip-bomb, entry-type/symlink, MIME sniffing, re-runs `FilenameAnalyzer` |
| `app/Services/Storage/ArchiveValidationException.php` | Carries `ArchiveValidator`'s issues out of `ChunkedUploadService::finalize()` |
| `app/Services/Storage/ChunkedUploadService.php` | begin/writeChunk/receivedChunks/finalize/abandon |
| `app/Services/Storage/SignedDownloadUrlService.php` | Issues + verifies short-lived HMAC download tokens |
| `app/Services/Storage/PreviewProcessor.php` | Anti-polyglot re-encode, EXIF strip, resize, WebP/JPEG |
| `app/Http/Controllers/Internal/UploadController.php` | Chunk PUT, session status GET, abandon DELETE |
| `app/Http/Controllers/Internal/DownloadController.php` | Issues a signed download URL (seller-owns-it case) |
| `app/Http/Controllers/Internal/MediaController.php` | Preview/gallery image upload (raw body — see "Decisions") |
| `app/Http/Controllers/PublicDownloadController.php` | The public, unsigned redemption endpoint |
| `app/Console/Commands/GcAbandonedUploads.php` | Purges `tmp/` sessions older than the configured window |
| `app/Console/Commands/PurgeDeletedAssetFiles.php` | Purges files for soft-deleted, entitlement-free assets past grace |
| `database/migrations/2026_08_19_000034_*.php` | `category_id` nullable on `assets`/`services` (see "Decisions") |
| `database/migrations/2026_08_19_000035_*.php` | `SoftDeletes` on `assets` (see "Decisions") |
| `database/migrations/2026_08_19_000036_*.php` | `asset_downloads.entitlement_id` nullable (see "Decisions") |

**members:**

| File | Purpose |
|---|---|
| `resources/js/market/FilenameAnalyzer.ts` + `.test.ts` | The 24-rule table, pure, no DOM |
| `resources/js/market/PackageZipper.ts` + `.test.ts` | Hand-written ZIP writer (`CompressionStream` + store fallback) |
| `resources/js/market/ChunkedUploader.ts` + `.test.ts` | Transport-agnostic resumable chunk orchestrator |

## Files modified

| File | Change |
|---|---|
| `apps/market/app/Http/Controllers/Internal/AssetController.php` | `beginVersion`/`finalizeVersion` rewritten to drive the real pipeline instead of step 04's accept-a-descriptor stub |
| `apps/market/app/Models/Asset.php` | `SoftDeletes` trait |
| `apps/market/config/market.php` | `chunk_size_mb`, `tmp_gc_hours`, `delete_grace_days` |
| `apps/market/.env` | The three keys above |
| `apps/market/routes/internal.php` | Upload/download/media routes |
| `apps/market/routes/web.php` | Public download redemption route |
| `apps/market/routes/console.php` | The two retention commands scheduled (hourly / daily) |
| `apps/members/app/Services/Market/MarketClient.php` | Added `putChunk()` — raw-binary relay with its own HMAC signing over the chunk body |
| `apps/members/app/Http/Controllers/AssetStoreController.php` | `downloadAsset()` now calls the real, corrected route (see "Decisions") |

## Database changes

Three migrations (`000034`–`000036`, listed above), all discovered as genuine gaps by running the
pipeline end to end against real HTTP requests, not by re-reading the step 03 schema. All verified via
`php artisan migrate:fresh --force` on scratch SQLite before use.

## Decisions made during execution that diverge from the plan

- **Two route shapes deviate from §05.4's literal path**: `PUT /sellers/{ref}/uploads/{sessionId}/chunks/{n}`
  and `DELETE/GET /sellers/{ref}/uploads/{sessionId}` (the step file writes these without a seller
  ref). `ChunkedUploadService` needs the owning member's reference to compute the staging path, and
  the HMAC signature alone only proves "this came from the members app," not which member — every
  other endpoint in this API scopes by `/sellers/{ref}/…` for exactly this reason. Documented in
  `UploadController`'s own header comment.
- **A new `GET /sellers/{ref}/uploads/{sessionId}` status endpoint was added**, not specified by
  §05.4. Found by reasoning through the resume story: once `beginVersion` creates a version row
  (`status = uploading`), calling it again for the same version number is correctly rejected
  ("already exists") — so a client that loses its in-memory session id (a page reload) has no path
  back to it through `begin`. The fix is a read-only status check the client can poll after
  recovering its own persisted `{asset_ref, version_ref, session_id}` (step 06's job to persist
  client-side), not a change to `begin`'s rejection.
- **`MediaController` accepts a raw request body with an `X-Filename` header, not
  `multipart/form-data`.** Found by testing, not assumed: PHP's SAPI consumes `php://input` while
  parsing a multipart body to populate `$_FILES`, so `Request::getContent()` — which the shared HMAC
  scheme always hashes — returns empty for that content type specifically, on both the signing and
  verifying side. A multipart request can never produce a signature either side can verify. Switched
  to the same raw-body-plus-header shape the chunk endpoint already uses successfully; re-verified
  live afterward.
- **`assets.category_id`, `services.category_id` made nullable** (migration 000034). Both were
  `NOT NULL` from the original 2026_01_23 migrations — invisible until the very first live
  `POST /sellers/{ref}/assets` call failed with a database integrity error, because step 04's/06's
  draft-first flow creates the row before a category is chosen. The publish gate (step 04) already
  requires `category_id` before a listing goes live, which is the correct enforcement point.
- **`assets.deleted_at` added, `Asset` given `SoftDeletes`** (migration 000035). Step 04's
  `AssetController::destroy()` did `$asset->delete()` as a hard delete — correct at the time, but
  incompatible with this step's 30-day file-retention grace window, which needs a durable "when was
  this deleted" record to purge against. Adding the trait made `delete()` a soft delete with **no
  controller change required**.
- **`asset_downloads.entitlement_id` made nullable** (migration 000036). Found by actually redeeming
  a download end to end: the legitimate seller-owns-it download case (step 04's own
  `AssetStoreController::downloadAsset()` stub, which this step completes) has no entitlement to
  reference — a seller downloading their own upload is not a purchase, and step 03's schema had only
  modeled the buyer/entitlement path §05.5's prose describes.
- **`DownloadController`'s scope is the seller-owns-it case only**, not the full buyer/entitlement
  path §05.5 frames as the "two consumers." Neither of those consumers (/portal, step 12; the engine,
  step 18) exists yet. What already existed — from step 04 — was members' seller-download stub, which
  this step completes for real. `SignedDownloadUrlService`/`PublicDownloadController` are written
  generically enough that step 12/18 reuse them unchanged; only a new issuance authorization path
  (entitlement-based instead of ownership-based) needs to be added when those steps land. Documented
  in `DownloadController`'s own header comment.
- **The `market_assets` Laravel disk (created in step 02) is not used by this step's services.**
  `MarketAssetPaths`/`ChunkedUploadService` use direct filesystem calls (`fopen`/`rename`/`chmod`)
  instead of the `Storage` facade, because the chunked-assembly logic needs precise control over
  atomic rename ordering and exact file permissions that the facade's higher-level API does not
  expose cleanly. The disk still exists and is harmless; nothing currently reads it.
- **`FilenameIssueDialog.ts`, `MarketApi.ts`, `AssetPublishWizard.ts`, `LicenseEditor.ts`,
  `PricingBreakdown.ts`, and `asset_publish.html` were not created.** Step 05 has no `## Files`
  section of its own; step 06 §06.4 explicitly lists these as its own created files, and step 06's
  stage-2 text attributes `FilenameAnalyzer` to "(step 05)" — confirming the analyzer/zipper/uploader
  are this step's deliverables and the wizard UI that wires them together is step 06's.

## Known gaps / deferred

- **The nginx `X-Accel-Redirect` production path is code-reviewed, not live-tested** — there is no
  real nginx in this environment. The dev-mode direct-stream fallback (`use_xsendfile = false`) *is*
  live-tested (byte-for-byte correct downloads, confirmed in "How this was verified"). The nginx
  block itself is below, for whoever configures the production host.
- **Members' side of the chunk/media relay has no HTTP route or browser-facing controller method
  yet** — `MarketClient::putChunk()` exists and is proven (a live vitest integration test drives
  `ChunkedUploader.ts` through a hand-signed request straight to market and confirms the chunks land
  correctly), but nothing in members' own routes calls it yet. That wiring — and the equivalent for
  `MediaController`'s raw-body upload — is step 06's wizard integration.
- **Members' own Laravel app still cannot boot** (the pre-existing missing `public/checkpoint/server/php/`
  tree, flagged in steps 02 and 04). `AssetStoreController::downloadAsset()`'s corrected route was
  verified by `php -l` and by testing the *target* endpoint directly on market; it could not be
  exercised through members' own HTTP stack.

### The nginx block (§05.5, for the deployed host)

```nginx
# MARKET_MODIFIER_ADDITION
location /__market_assets/ {
    internal;
    alias /mnt/node2/market/members/;
    sendfile on;
    aio threads;
}
```

## How this was verified

Everything below is a real, live exercise of the code — a running `php artisan serve` instance
against a scratch SQLite database (and the step 03 migration set re-verified on scratch MySQL
earlier still holds), not a reading of the source.

**Filename analysis:**
- The exact §05.8 fixture (`bad name.png`, `-flag.txt`, `CON.cs`, `Assets/A.png`+`a.png`, a 300-byte
  name, `.DS_Store`) produces exactly 6 findings (5 block + 1 warn) — in **both** the TypeScript
  module (vitest) and the PHP port (a standalone script), with identical rule numbers and paths.
- Two real bugs were caught by these tests and fixed: (1) four character-class regexes used in
  `.replace()` were missing the global flag, so only the *first* illegal/control/bidi/zero-width
  character in a name was ever stripped from a suggestion; (2) case-collision detection flagged only
  the second-seen path in a colliding pair, not both — fixed to flag every path in the group, which
  is also what makes the fixture's expected count of 6 (not 5) correct.
- A related, unrelated-to-logic problem: several `\uXXXX`-style escapes I intended to write as literal
  6-character sequences in the TypeScript source kept arriving as raw Unicode/control bytes instead
  (a tool-content-pipeline issue, not a logic bug) — including, in one pass, a literal NUL byte that
  made a file register as "binary" to `file`(1)/git. Every affected file was rewritten byte-for-byte
  to use real `\uXXXX` text and re-verified with `file`(1) reporting clean UTF-8 text before being
  treated as done.

**Zipping:**
- `PackageZipper.ts`'s output was opened by real PHP `ZipArchive` (the exact reader
  `ArchiveValidator` uses) in three scenarios: a small mixed-content archive (deflate path), a
  `CompressionStream`-unavailable fallback (store method, simulated by deleting the global), and a
  full round-trip through market's actual `ArchiveValidator::validate()` — all three entries
  extracted with correct names, sizes, and content; the client-computed SHA-256 matched the server's
  independently-computed hash of the same bytes exactly.

**Archive validation:**
- Four hand-crafted zips (a zip-slip entry, a legitimate small package, a 50 MB-of-zeros
  high-compression-ratio "bomb," and a genuine symlink entry via `ZipArchive::setExternalAttributesName`)
  were each fed to `ArchiveValidator` directly. The symlink case failed on the first pass — `statIndex()`
  does not expose external attributes at all, silently defaulting every entry to "plain file"; fixed
  to use `getExternalAttributesIndex()`, re-verified correct.
- The zip-slip case was additionally re-verified through the **full HTTP finalize path** (begin →
  chunk → finalize), confirming `422` with `MARKET_E_ARCHIVE_INVALID`, nothing written outside the
  storage root, and no `package.zip` left in the version directory.

**Chunked upload, end to end (live HTTP, via `php artisan serve`):**
- Full happy path: create asset → begin version (real zip, real total-bytes/sha256) → upload chunks →
  re-send an already-received chunk (confirmed idempotent, `200` not `201`, no duplicate row) →
  finalize → `asset_version_files` populated with correct per-entry sha256 (a bug — a fake
  path+size-derived hash was initially wired in by mistake — caught and fixed to use the real
  content hash `ArchiveValidator` already computes) → request a signed download → redeem it →
  downloaded bytes identical to the originally-built archive.
- A chunk with an incorrect declared hash → `422`, rejected before being written.
- A chunk corrupted **on disk after** the per-chunk check passed (simulating filesystem-level
  corruption) → whole-archive SHA-256 check at finalize catches it, `422`, confirmed no `package.zip`
  and no leftover `tmp/` session afterward.
- `GET /sellers/{ref}/uploads/{sessionId}` (the new status endpoint) confirmed returning the correct
  `received_chunks` for a session populated by the live `ChunkedUploader.ts` integration test below.

**`ChunkedUploader.ts`:**
- 6 mock-transport tests: correct chunk count/order, resume skips already-received chunks, per-chunk
  SHA-256 is independently verifiable, progress callbacks fire correctly (including reflecting
  already-received chunks on the initial call), abort stops mid-upload with the right error type, and
  a zero-byte file produces exactly one (empty) chunk.
- 1 live integration test (opt-in via env vars, run for real in this session): the module drives real
  chunk uploads against the running market server using hand-computed HMAC signatures — 3 real chunks
  (8192, 8192, 3616 bytes) landed on disk in market's `tmp/` staging directory, matching exactly what
  the mock-transport tests predicted.

**Signed downloads:**
- Valid token → `200`, correct bytes. Tampered signature → `403`. A token issued with a 1-second TTL
  → valid immediately, rejected (`null` from `verify()`) after a 2-second sleep — real time-based
  expiry, not just a code read. A non-owning seller requesting another seller's download → `403`
  `MARKET_E_FORBIDDEN`.

**Preview processing:**
- A real 3000×2000 PNG (with a byte size that would carry EXIF if a phone had produced it) resized to
  2048×1365 (aspect-ratio preserved, capped correctly), converted to WebP, written to disk with `0640`
  permissions. A text file renamed `.png` (`imagecreatefromstring` returns `false` on it) correctly
  rejected as "not a valid, supported image" — the anti-polyglot check.

**Retention:**
- `market:gc-abandoned-uploads --dry-run` correctly reports 0 for a session younger than the
  configured window and 1 after the session directory's mtime was backdated past it; the real run
  deleted exactly that directory.
- `market:purge-deleted-asset-files`: a soft-deleted asset with `deleted_at` backdated past the grace
  period was correctly identified, its files removed from disk, and the row `forceDelete()`d — the
  asset count before/after confirms exactly one row was purged.

**Permissions:** every created file under the storage root is `-rw-r-----` (0640); every created
directory is `drwxr-x---` (0750) — checked directly with `ls -l`, matching §05.1 exactly.

**Syntax/type checks:** `php -l` across every PHP file created or modified in this step; `tsc --noEmit`
(strict, matching the project's own `tsconfig.json` settings) across every TypeScript file; all 18
vitest tests (3 files) pass.

## Follow-ups spawned

None spawned as separate work items. The members-app boot blocker (routes/web.php's missing
Checkpoint vendor tree) continues to block any live HTTP verification on that side and should be
raised with the project owner directly, as noted in steps 02 and 04's reports.

---

## Exit criteria (§05.8)

- [x] The named fixture produces exactly six findings (five block, one warning), each on the correct rule — verified in both TypeScript and the PHP port.
- [x] Upload-button-disabled-while-blocked — this is wizard UI state (step 06); `AnalysisReport.blocked` is the field that drives it, confirmed correct in isolation.
- [x] A zip-slip archive posted directly to finalize → `422`, nothing written outside the root — verified through the full HTTP path, not just the validator in isolation.
- [x] A 10 KB-shaped high-ratio archive → rejected on compression ratio.
- [x] Kill-mid-upload/resume returns the correct `received_chunks` and completes without re-sending — verified via the new status endpoint plus `ChunkedUploader`'s resume logic (mock + live).
- [x] A corrupted chunk → finalize fails on sha256, no `package.zip` exists — verified live, including confirming the `tmp/` session is also cleaned up ("no partial state").
- [x] File permissions `0640`, correct owner — verified directly with `ls -l`.
- [x] Signed download URL works; expired → rejected; tampered → `403`; another member (here, another *seller*, the case this step actually authorizes) → `403`.
- [x] Download served without a PHP memory spike — the production path is `X-Accel-Redirect` (nginx serves the bytes, PHP never touches them); the dev fallback streams via `response()->download()`, not a full-file read into memory. The nginx path itself is code-reviewed, not live-tested (no nginx in this environment) — see "Known gaps."
- [x] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4) — reconciled by `grep -rn` against the file lists above, including the three test files (also tagged; one required a byte-level fix first — see "How this was verified").
- [x] This file written, including the nginx block.
