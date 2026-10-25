# 00-overview — Completion (Phase 1)

**Status:** Partial, less so with each follow-up session — 08 (Storage
Retention), 07 (Sync and Dedupe's server-side portion), 09 (the web
client's remaining gaps), 10 (the desktop client, in a separate
repository, `afsdesktop5`), 11 (the mobile client, in a third
repository, `afsmobile_flutter`), and now **12 (Security — rate limiting,
audit logging, the physical free-space check, the session-expiry sweep,
and automated traversal/ownership tests)** are all substantially complete.
All three clients doc 00 §2's G2 goal names now exist. This document
has no code of its own — it's the goals/decisions/glossary read against
what's actually built. The per-document completion docs (`03`, `04`, `05`,
`06`, `07`, `08`, `09`, `10`, `11`, `12`) carry the real detail; this is the
goals-table-level summary.

## Summary

Storage's own "step 01" turned out to already be solved: the platform-wide
`checkpoint-server-auth` plan closed the identity-chain gap everywhere
except storage's own routes, which it deliberately left ungated with a
comment pointing back here (`routes/web.php`, `checkpoint-server-auth/
03-members-remediation.complete.md`). This pass is therefore the first real
implementation work on cloud storage itself: it replaces
`StorageController.php` (hardcoded-admin login, mangled filenames, wrong
mount, no quota — audit findings S1-S3, C1-C2) with the session-based
chunked-upload architecture docs 03-05 specify, for the **web client only**.

### Goals (§2) — status

| # | Goal | Status |
|---|---|---|
| G0 | Server knows who is calling | **Met** for storage's own routes — gated, identity from verified token. See the `checkpoint_member_reference_num`/`checkpoint_member_plan` naming deviation noted in `03-architecture.complete.md` |
| G1 | Files land as-is | **Met** — real names/paths, `PathResolver` never sanitises-by-stripping |
| G2 | One protocol, three clients | **Met, all three.** Web (09), desktop (10, `afsdesktop5`), and now mobile (11, `afsmobile_flutter`) all speak the same `/api/v1/storage/*` protocol — desktop's `cloud/client.ts` is a direct port of the LAN sync engine's own client shape; mobile's `storage_repository.dart` was written directly against the server's real controllers/routes, not just doc 05's example payloads. See `10-desktop-client.complete.md`, `11-mobile-client.complete.md` |
| G3 | Resumable and efficient | **Partial, improved.** Per-file resume via offset/409-re-seek is real on all three clients now (web: IndexedDB, doc 09 §2.4; mobile: `GET /sessions/{id}` re-query, doc 11 §4). Web uploads run with adaptive concurrency (1-8 files in parallel); mobile uploads run bounded-concurrent with pause/resume/cancel (`UploadControl`) but no wifi-aware throttling. The "10,000 files ≤ 200 requests" efficiency goal (A7) is still **not** met on any client — no batch coalescing (05 §5.3) |
| G4 | Every upload name-scanned, client and server | **Met** — server is authoritative, client scan is real (not stubbed) on web and mobile alike (`name_validator.dart` is the third independent port of doc 06 §3's rule table, now with its own 27-test suite — see `11-mobile-client.complete.md`) |
| G5 | A duplicate is never a silent error | **Met** — conflict detection + explicit member choice (409 + modal on web, a dedicated `StorageConflicts` bloc state + page on mobile) |
| G6 | Real sync (not one-way upload) | **Met on desktop; built but unwired on mobile.** Doc 07 §1 is explicit that sync is a desktop/mobile capability the web client never performs. Desktop's `cloud/sync.ts` implements the full three-state reconciler and is reachable from the UI. Mobile's `sync_engine.dart` ports the same algorithm (decision table, rename detection, delta/full-walk fallback) faithfully, but has **no settings page wiring it in** and walks local folders via plain `dart:io` rather than genuine Android SAF tree URIs — doc 11 §5's own framing treats sync as secondary to upload/browse/download on mobile, so this gap is consistent with the doc's stated priority, not a surprise. See `07-sync-and-dedupe.complete.md`, `10-desktop-client.complete.md`, `11-mobile-client.complete.md` |
| G7 | Storage Retention | **Met**, mechanically — capacity/cascade formula (08 §2-3), bucket-to-bucket moves (08 §5), and the full retire→grace→purge state machine with restoration (08 §6) are all real, working code as of the follow-up 08-storage-retention pass. The member-facing reminder ladder (§6.3's emails) isn't built — no notification system to hook into. See `08-storage-retention.complete.md` |
| G8 | Tenant isolation | **Met** for every endpoint this pass built — `PathResolver` containment + 404-not-403 ownership checks on every id |

### Acceptance criteria (§6) — status

A0, A1 (reasoned, untested live), A4, A5, A8, A9, A11, A12, A14 — met. **A2/A3
now substantially met on the server** — `PathTraversalCorpusTest.php` (16
assertions) and `StorageOwnershipTest.php` (9 tests, 404-not-403 proven for
every id-taking endpoint) landed in the doc 12 security pass, closing §10
checklist items 4 and 6. Still open: the doc 06 §7 *shared*
TypeScript/Dart/PHP fixture corpus doesn't exist — PHP, TypeScript
(`06-name-validation.complete.md`), and Dart (mobile's own 27-test
`name_validator_test.dart`, `11-mobile-client.complete.md`) each have an
independent hand-written suite now, not the one shared corpus A2/A3
ultimately ask for. A6 met in mechanism, A7 not met (no batching, on any of
the three clients). **A10 now met** —
`BucketPanel.ts` renders the cascade explanation, per-bucket state, and the
downgrade-consequence preview with real byte figures (doc 08 §4/§6.6),
sourced from `GET /profile`'s `retention` block (08) and `warnings` array.
A13 not met — `ReconcileStorage` (doc 04 §6's ledger↔disk drift checker) is
a different command from `StorageRetentionSweep` (doc 08 §6) and still
doesn't exist. Full detail per criterion is in the relevant doc's
completion file.

## Files changed

See `03-architecture.complete.md`, `04-database-schema.complete.md`,
`05-api-contracts.complete.md`, `06-name-validation.complete.md`,
`07-sync-and-dedupe.complete.md`, `08-storage-retention.complete.md`,
`09-web-client.complete.md`, `10-desktop-client.complete.md`,
`11-mobile-client.complete.md`, `12-security.complete.md` for the complete
file lists — roughly 27 backend files (migrations, models, services,
controllers, two console commands) and 12 frontend files (up from 3 —
`ApiClient`, `NameValidator`, `UploadEngine`, `IndexedDbStore`,
`ScanWorker`/`ScanWorkerClient`, `ConflictDialog`, `ScanReportDialog`,
`BucketPanel`, `format`, `errors`, `StorageApp`) across four sessions, plus
edits to `routes/web.php`, `routes/console.php`, `cloud.html`,
`CloudController.ts`, `views.ts`, `main.ts`, `appwindow.ts`,
`dashboard.html`, and (environment fixes) `welcome.blade.php`. Two more
clients now exist in their own repositories: `afsdesktop5` (18 new/changed
files, `10-desktop-client.complete.md`) and `afsmobile_flutter` (24
new/changed files across models/services/repository/bloc/pages/tests/l10n,
`11-mobile-client.complete.md`).

## Verification steps

- `php -l` on every new/changed PHP file — clean.
- `tsc --noEmit` on the whole frontend — zero new errors from this change
  (pre-existing, unrelated errors elsewhere in the codebase confirmed by
  file path, not touched by this work).
- **Updated after a follow-up session that fixed the composer lock and
  actually ran the app** (see "Environment fixes" below):
  - `composer.lock` repaired (`afs/checkpoint-auth` now resolves and
    installs) via a targeted `composer update afs/checkpoint-auth
    --with-dependencies --ignore-platform-req=ext-gmp` — only that
    package's resolution changed, nothing else was re-pinned.
  - `php artisan route:list` runs clean; all 11 new `/api/v1/storage/*`
    routes are registered inside `checkpoint.verify:primary_only`, and the
    four old `/api/storage/*` routes are confirmed gone (zero matches).
  - `php artisan serve` boots and serves `GET /` → `200`.
  - `GET /api/v1/storage/profile` with no `Authorization` header →
    `401 {"success":false,"message":"Missing bearer token."}` — proves
    `checkpoint.verify` middleware, routing, and controller wiring all work
    end to end, not just in isolation.
  - `GET /api/storage/files` (deleted old route) → `404`.
  - With `npm run dev` (Vite) running alongside, the compiled JS bundle
    loads with no console errors and the SPA boots, redirecting to
    Checkpoint SSO login as expected when unauthenticated.
  - **Not verified:** logging in as a real member and visually exercising
    the Storage tab / an actual upload — no test credentials were
    available, and no local MySQL/database server is running (migrations
    have not been executed against a real database), so the DB-backed
    parts of the golden path (browse, upload, commit) remain reasoned
    through rather than observed.
- **A later pass (09-web-client's frontend expansion)** additionally ran a
  real `vite build` (not just `tsc --noEmit`) — 1083 modules transformed
  successfully, and the new `ScanWorker.ts` correctly emitted as its own
  bundle chunk, confirming the Worker-based validation actually bundles,
  not just type-checks.

## Environment fixes (not part of the plan's scope, but needed to run it)

Four pre-existing bugs, unrelated to cloud storage, blocked getting the app
running or blocked verifying this plan's own work, and were fixed across
follow-up sessions:

- **`composer.lock` out of sync** with `composer.json` — `afs/checkpoint-auth`
  (a different, newer package than the legacy SDK below — namespace
  `Afs\CheckpointAuth\...`) was required but not locked. Fixed via a
  targeted `composer update` as noted above.
- **`routes/web.php:27`** unconditionally `require_once`'d a legacy vendored
  SDK at `public/checkpoint/server/php/vendor/autoload.php` (namespace
  `Checkpoint\...`) that doesn't exist anywhere in this working copy and
  wasn't findable anywhere else on the machine — this fataled route
  registration for literally every request. Guarded behind a
  `file_exists()` check; the one route that used it (`/`) already had its
  only consumption of the SDK's result (`$logged`) commented out, so
  nothing behavioral changed for that route.
- **`resources/views/welcome.blade.php:11`** referenced `$asfsdk` (typo —
  transposed letters) while `routes/web.php`'s `/` handler passes
  `afssdk`. One-line fix.
- **`database/migrations/2026_07_13_000001_create_checkpoint_tables.php`**
  (the `checkpoint-server-auth` plan's own migration, not this plan's)
  named several indexes identically across different tables —
  `idx_user_id` five times, `idx_expires_at` three times, `idx_email`/
  `idx_username` twice each. Harmless on MySQL (index names are scoped
  per-table there) but fatal on SQLite (scoped per-database), which meant
  `RefreshDatabase` — and therefore *every* PHPUnit feature test in this
  app — failed in `setUp()` before a single assertion ever ran. Fixed by
  making all 12 duplicates table-qualified; confirmed via grep that
  nothing else in the codebase references any of the old names, so this is
  a pure metadata rename with zero behavioral change against the real
  (MySQL) database. Found and fixed during the doc 12 security pass,
  because it made that document's own §10 checklist item 6 (an automated
  ownership test) impossible without it — see `12-security.complete.md`.

None of these four are cloud-storage-specific; they were blocking any
verification of this plan's own work and are recorded here for that
reason.

## Follow-up work

In priority order, spanning what the individual completion docs list:

1. **Run the whole thing against a real database and a real member
   login** — the app boots, routes, and (per `09-web-client.complete.md`)
   builds correctly, but nothing DB-backed (browse, upload, commit, the
   retention sweep) or browser-interactive (drag-drop, resume, the worker
   scan) has actually been observed executing, only reasoned through and
   build-verified. This is the single largest remaining gap across every
   completion doc in this plan.
2. The doc 06 §7 **shared** TypeScript/Dart/PHP fixture corpus
   (`06-name-validation.complete.md`) — PHP, TypeScript, and Dart each now
   have their own independent, passing, hand-written test suite covering
   the same rule table (`12-security.complete.md`'s `PathTraversalCorpusTest.php`,
   `11-mobile-client.complete.md`'s `name_validator_test.dart`), but the one
   *shared* corpus all three languages consume identically still doesn't
   exist. Automated ownership testing (§10 checklist item 6) is now **done**
   — see `12-security.complete.md`'s `StorageOwnershipTest.php`.
3. ~~Session/`.part` expiry sweep~~ **Done** — `storage:session-sweep`,
   hourly, see `12-security.complete.md`.
4. Batch upload coalescing (05 §5.3), per-chunk adaptive sizing (05 §5.4's
   other half — concurrency now adapts, chunk size doesn't), ZIP64 archive
   download (07 §7.2), true windowed tree virtualisation (09 §2.5 —
   pagination is a partial mitigation, not the real thing).
5. `ReconcileStorage` (doc 04 §6's ledger↔disk drift checker, acceptance
   A13) — distinct from the now-built `StorageRetentionSweep`.
6. Verify the `PATCH /nodes/{id}` folder-rename bulk-UPDATE's MySQL
   `SHA2(LOWER(x))` vs PHP `hash('sha256', mb_strtolower(x))` parity claim
   against a real database (07-sync-and-dedupe's one open correctness risk).
7. **Run the desktop and mobile clients** (`afsdesktop5`, `afsmobile_flutter`)
   against a real login and server — same "reasoned through, never
   observed" caveat as everything else, but total for both: no GUI
   environment exists in this session to launch Electron, and no
   emulator/device exists to launch Flutter. See `10-desktop-client.
   complete.md`'s and `11-mobile-client.complete.md`'s own acceptance
   tables — every "reasoned true"/"not observed" line in both is exactly
   that.
8. **Mobile Wi-Fi-only gating and background upload support** (doc 11 §6,
   acceptance M5) — `afsmobile_flutter`'s upload engine currently runs
   foreground-only with no connectivity check at all; this is the single
   largest functional gap in the mobile client, called out first (not
   buried) in `11-mobile-client.complete.md`.
9. **A genuine Android SAF tree walker** for `afsmobile_flutter`'s
   `sync_engine.dart` and folder-upload picker — both currently assume a
   plain `dart:io`-accessible path, which is not how a member-granted
   folder actually looks on Android 10+ scoped storage.
10. ~~Rate limiting (12 §8) and structured audit logging (12 §9)~~ **Done**
    — four per-member named rate limiters and a dedicated `storage_audit`
    log channel, see `12-security.complete.md`. Two narrower gaps remain
    within §8: `PUT` chunk concurrency/size enforcement (≤ 8 concurrent, ≤
    64 MiB each — no code caps either yet) and failed-auth throttling
    (10/min/IP — that one belongs in `VerifyCheckpointToken` platform-wide,
    not this plan).
11. `es`/`de`/`fr`/`zh` translations — every string now routes through
    i18n keys on web (09 §3) and through `messages_en.dart`/`messages_es.dart`
    on mobile (11 §9, already has Spanish); desktop and web still only have
    English values wired.
12. The member-facing retention reminder ladder (08 §6.3, emails/push) —
    the retention *panel* is now fully built on web (`BucketPanel.ts`, A10
    met) and mobile (`storage_buckets_page.dart`'s retention interstitial);
    proactive notification when a downgrade lands is still not built
    anywhere.
13. A live penetration pass against a second member's ids (doc 12 §10 item
    11) — the automated ownership tests (item 2, above) now cover the same
    property mechanically; a real adversarial pass against a running
    server is still distinct, unobtained value.
14. `nginx: proxy_request_buffering off` on the session upload location
    (doc 12 §10 item 9, doc 09's identical deployment-config note) — still
    not code, still not confirmed configured anywhere.
