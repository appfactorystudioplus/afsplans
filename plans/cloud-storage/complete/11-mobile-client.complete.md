# 11-mobile-client — Completion

**Status:** Code-complete for everything doc 11 specifies as new files, across a third
repository (`afsmobile_flutter`) with a third language (Dart/Flutter) than any other
document in this plan (`w/members` = PHP/TS, `afsdesktop5` = TypeScript/Electron).
`dart analyze` is clean project-wide (zero new errors/warnings — the 17-and-43-issue
baselines from `dart analyze lib/` and `flutter analyze` respectively are 100%
pre-existing, unrelated code) and `flutter test` passes all 33 tests, including a new
27-test suite for the name validator that runs real assertions against real logic, not
just a compile check. **Not run**: the actual app UI. No emulator/device/GUI is available
in this session — same constraint doc 10's completion doc hit for Electron, now hit a
third time for Flutter. Every acceptance criterion below not backed by a passing
`dart analyze`/`flutter test` run is reasoned from the code, not observed.

## Summary

### §1-2 — starting position, new files

All eleven files in doc 11 §2's list were written: 5 models, `name_validator.dart`,
`storage_repository.dart`, `hash_service.dart`, `local_index.dart`, `upload_engine.dart`,
`sync_engine.dart`, `bloc/storage/*.dart` (3 files), 4 pages under `pages/storage/`,
`test/storage/name_validator_test.dart`, plus the `pubspec.yaml` and `lib/l10n/*`
additions §2/§9 call for. Two new files not in the doc's list, both small and load-bearing
for what it does ask for: `lib/utils/byte_format.dart` (a `formatBytes()` helper every
storage page needs and no existing utility in this app provides) and this document.

### §3 — authentication

Implemented as specified, with one necessary adaptation: `CheckpointAuth`/
`CheckpointClient` expose no public `getAccessToken()` (only a private
`CheckpointHttpClient._http.getAccessToken()`), so `storage_repository.dart` reads the
bearer token directly from `SecureTokenStorage()` (exported by `checkpoint_client`,
keyed `checkpoint_access_token` — confirmed via `packages/checkpoint/lib/src/core/
storage.dart`), rather than through a getter that doesn't exist. A `401 TOKEN_EXPIRED`
triggers exactly one `checkpoint.auth.refreshToken()` (via an injected `CheckpointClient`)
and one retry — implemented in both `_send` (buffered) and `_sendStreamed` (download)
paths in `storage_repository.dart`. `CheckpointConfig`'s default `TokenStorage.secure`
means a *freshly constructed* `CheckpointClient` (as `storage_page.dart` builds for the
bloc) reads/writes the exact same on-disk keys `AuthRepository`'s own client uses — no
shared instance is required, confirmed by reading `core/config.dart`.

Tokens never touch `shared_preferences` — only the upload engine's *resume record*
(session id + local file paths + sizes, never a token) is persisted there, which is safe
per doc 11 §3's actual requirement (only the token itself needs Keychain/Keystore).

### §4 — the upload engine

`upload_engine.dart` implements doc 03 §6's six states (`scanning → preflight → awaiting →
uploading ⇄ stalled → verifying → done|failed`) plus doc 11 §7's `UploadJob`/per-file
progress shape. Two deliberate, documented deviations from the docs' literal text (both
called out in the file's own header comment):

1. **Where the engine lives.** Doc 03 §6's table names `storage_repository.dart` for
   mobile; doc 11 §2's later, more detailed file list puts it in its own
   `upload_engine.dart`. Followed doc 11 (the file list a later document in the same plan
   revises), keeping `storage_repository.dart` a pure API client.
2. **Hashing happens before upload, not only during it.** Doc 07 §4.3 says content hashes
   are "computed streaming, during upload, never as a second read pass" — true for
   desktop's concurrent hash-while-read pipeline (`hashpool.ts`), but `POST /sessions`'
   manifest can only declare a `sha256` *before* the session opens (doc 05 §5.1). This
   engine hashes each file off the UI isolate (`hash_service.dart`, via `compute()`)
   during the `scanning` phase — one extra full read per file, in exchange for
   skip-identical (doc 05 §4.3) and server-side digest verification at commit (doc 05
   §5.5). Judged worth the tradeoff for mobile's typically-smaller batches.

`putRange` (§4.1) streams `file.openRead(start, end + 1)` — the exclusive/inclusive
off-by-one the doc's own example comment warns about is handled the same way the example
shows. Chunk size is clamped to the mobile range doc §4.3 specifies (1-8 MiB, default
4 MiB) regardless of what a caller passes the constructor. `OFFSET_MISMATCH` re-seeks
without consuming retry budget; `SESSION_EXPIRED`/410/413 fail the file immediately;
everything else backs off 1s→60s jittered and fails only after 6 attempts — doc 07 §5's
failure table implemented directly, including "a vanished source file fails just that
file, the session continues."

**Pause/resume/cancel — built beyond what doc 03's diagram alone implies.** The diagram
shows `stalled` as network-driven, but doc 11 §7 names `PauseUpload`/`ResumeUpload`/
`CancelUpload` as bloc *events*, which needs some way to interrupt a run already in
flight, not just start fresh ones. Added `UploadControl` (cooperative pause/cancel,
polled between chunks — never mid-chunk, so a chunk write always finishes and the
server's offset stays exact) rather than leave those three events as dead letters.

**Not built**: doc 11 §6's Wi-Fi-only gating (acceptance M5) and the iOS/Android
background-transfer split (§6.1/§6.2) — the engine runs only in the foreground isolate;
there is no `connectivity_plus`-style dependency and no wifi-check hook anywhere in
`upload_engine.dart`. This is the largest functional gap against the doc, not a smaller
one — flagged first in Follow-up work below, not buried.

### §5 — local file access reality

Not directly exercised: this is a description of platform constraints, not a module to
build. `sync_engine.dart`'s own header comment documents the concrete consequence — see
§7 below.

### §6 — background uploads

**Not built.** No `URLSession` background configuration, no `WorkManager`, no
foreground-service notification, no "resume on open" prompt UI. The engine simply stops
making progress if the OS suspends the isolate; nothing in the UI claims otherwise
(satisfying M8's letter — no false claim exists — while missing §6's actual feature).

### §7 — state management

`bloc/storage/storage_bloc.dart` + `storage_event.dart` + `storage_state.dart` implement
doc 11 §7's event/state lists exactly as named: 12 events (`LoadProfile`, `LoadTree`,
`PickFiles`, `ScanSelection`, `StartUpload`, `PauseUpload`, `ResumeUpload`,
`CancelUpload`, `ResolveConflicts`, `MoveBucket`, `DownloadNodes`, `DeleteNodes`), 8
states (`StorageInitial`, `StorageLoading`, `StorageReady`, `StorageScanning`,
`StorageScanReport`, `StorageConflicts`, `StorageUploading`, `StorageError`). Follows
this app's existing `Bloc` pattern (`AuthBloc`, not a `Cubit` — matched on purpose, since
`PauseUpload`/`CancelUpload` need to reach a still-running `StartUpload` handler, and
flutter_bloc processes distinct event types concurrently by default, which is exactly
what makes a live pause/cancel work without a custom `EventTransformer`).
`StorageScanReport`/`StorageConflicts` are distinct states carrying their own staged-file
list, not dialogs — survive rotation/backgrounding as the doc requires, since they're
just bloc state.

### §8 — bucket UI on a phone

`storage_buckets_page.dart` implements the doc's mockup: a vertical list of bucket cards
(not the desktop horizontal strip), each with a linear meter, full/retired/locked chips,
and an arrow marking `next_write_bucket`. The retirement banner (doc 08 §6.6) is a
full-screen, non-dismissible interstitial (`_RetentionInterstitial`) shown whenever
`profile.retention.pending` is true — exactly the "must not be missed" requirement, at
the cost of the ring-meter graphic in the doc's ASCII mockup being a `CircularProgress
Indicator` instead of custom-painted art (`storage_page.dart`'s usage summary, same
simplification).

### §9 — localisation

`lib/l10n/messages_en.dart`/`messages_es.dart` + `app_localizations.dart` follow this
app's existing hand-written-getter pattern (not `flutter gen-l10n`/ARB — confirmed by
reading the existing files, which have no `.arb` source anywhere in the repo). ~35 new
`storage*` UI keys in both languages, plus a `storageValidationMessage(ruleId)` dispatch
method (backed by a `Map<String,String>` per language) covering **all 15**
`NameValidator` rule ids from doc 06 §3, in English and Spanish — doc §9's specific
requirement ("one message per validation rule id") met exactly, implemented as a lookup
method rather than 15 separate getters since that's the existing pattern's own style for
parameterised strings (`errorLoadingSettings(String error)` etc.) extended to a rule-id
key instead of a free string.

## Deviations and gaps (honest accounting, not buried in Follow-up)

- **`sync_engine.dart` walks a plain filesystem path**, not a genuine Android SAF tree
  URI (`content://…`). Doc 11 §5's real hard constraint — a member-granted sync folder on
  Android 10+ is usually SAF-only — needs a platform channel or a SAF-capable plugin
  neither doc 11 §2 nor this pass added as a dependency. The three-state reconciliation
  *algorithm* (doc 07 §2 in full: decision table, rename detection, delta/full-walk
  fallback) is complete and correct against any `Directory`-accessible path; only the
  SAF adapter underneath it is missing. Documented in the file's own header, not
  discovered later.
- **No page wires `sync_engine.dart`/`local_index.dart` to the UI at all.** Doc 11 §2
  lists `sync_engine.dart` as a file to build but names no corresponding page, and doc
  11 §5 itself frames sync as secondary ("The default mobile mode is upload / browse /
  download, not sync"). Built, unit-testable, unreachable from any screen — a real gap,
  not a contradiction of the doc's own stated priority.
- **`MoveBucket` and `DownloadNodes` reach the repository/bloc; only `DownloadNodes` is
  wired into a page** (`storage_page.dart`'s per-file "Download" menu item, added during
  this pass's own review specifically because leaving it unwired after building
  `downloadContent()`, `DownloadNodes`, and its handler would have been dead code with no
  reason not to finish it). `MoveBucket` has no UI trigger — no page offers "move to
  bucket N" — left as bloc-level-only, same category of gap as sync.
- **No periodic polling.** Doc 07 §5's schedule table (60s/15min `/delta` polling while a
  view is open) is not implemented; `storage_page.dart` fetches on open and on manual
  pull-to-refresh only.
- **Doc 06 §7.2's shared fixture corpus does not exist.** Neither `NameValidator.php` nor
  `NameValidator.ts` has a test file (confirmed by search before writing this pass's own
  test) — this mobile pass is the *first* test suite for the rule set in any of the three
  languages. `test/storage/name_validator_test.dart` is a hand-written, Dart-native
  27-test suite covering the same rule table (every reachable rule id, both accept and
  reject cases, a UTF-8-byte-vs-UTF-16-code-unit boundary case), not the cross-language
  corpus the doc envisions. All 27 pass. The `PATH_UNC` rule id is asserted unreachable
  by design (see the test's own comment) — confirmed identical in `NameValidator.php`
  (the authoritative server-side rule table) and `NameValidator.ts`: any input that could
  reach the UNC check already matches the preceding `PATH_ABSOLUTE` check first, in all
  three ports. Not a Dart-specific bug; not fixed here, since fixing the ordering would be
  a three-file, cross-repository behavior change outside this document's scope.
- **NFC Unicode normalisation (doc 06 §4) is not implemented** — `name_validator.dart`'s
  own header states this: Dart's standard library has no built-in NFC normaliser, unlike
  PHP's `\Normalizer` and JS's `String.prototype.normalize()`. Flagged, not silently
  skipped.
- **`storage_upload_page.dart`'s folder picker** (`FilePicker.platform.getDirectoryPath()`)
  walks the returned path with plain `dart:io` — the same SAF caveat as `sync_engine.dart`
  applies to folder *upload* selection on Android 10+, not just sync.

## Files changed

**`lib/models/`** (new): `storage_node.dart`, `storage_bucket.dart` (+`RetiredBucket`,
`RetentionInfo`), `storage_profile.dart` (+`BucketWarning`), `upload_session.dart`
(+`SessionFile`, `SessionFileState`, `ScanConflict`), `scan_report.dart` (+`ScanRejected`)
**`lib/services/`** (new): `name_validator.dart`, `hash_service.dart`,
`local_index.dart`, `upload_engine.dart` (+`UploadControl`), `sync_engine.dart`
**`lib/repositories/storage_repository.dart`** (new): every `/api/v1/storage/*` endpoint
the members server actually implements (confirmed against `routes/web.php` — excludes
`PUT .../batch` and `GET /download/archive`, which the server itself doesn't implement
either, per `05-api-contracts.complete.md`)
**`lib/bloc/storage/`** (new): `storage_bloc.dart`, `storage_event.dart`, `storage_state.dart`
**`lib/pages/storage/`** (new): `storage_page.dart`, `storage_upload_page.dart`,
`storage_buckets_page.dart`, `storage_conflicts_page.dart`
**`lib/utils/byte_format.dart`** (new): `formatBytes()`
**`test/storage/name_validator_test.dart`** (new): 27 tests
**`lib/l10n/messages_en.dart`, `messages_es.dart`, `app_localizations.dart`**: ~35 UI
keys + `storageValidationMessage()` per language
**`lib/app.dart`**: `/storage` route registered
**`lib/pages/profile_page.dart`**: Storage button added next to the existing Domains button
**`pubspec.yaml`**: `crypto: ^3.0.3`, `watcher: ^1.1.0` added (`flutter_secure_storage`
correctly *not* added directly — already transitive via `checkpoint_client`, confirmed
via `flutter pub get` resolving it as "from transitive dependency to direct dependency"
only for `crypto`, not `flutter_secure_storage`)

## Verification steps

- `dart analyze` on every new/changed file individually while writing it, then
  `dart analyze lib/` and `flutter analyze` project-wide after each major addition —
  final state: zero new issues; the 17 (`dart analyze lib/`) / 43 (`flutter analyze`,
  includes the vendored `packages/checkpoint`) pre-existing issues are unchanged and
  confirmed unrelated to this pass file-by-file.
- `flutter test` — 33/33 passing, including the new 27-test `name_validator_test.dart`
  suite and the pre-existing `widget_test.dart` ("App loads"), confirming `app.dart`'s
  new `/storage` route registration didn't break app construction.
- `flutter pub get` — resolved cleanly after the `pubspec.yaml` additions.
- Read `routes/web.php`, `StorageSessionController.php`, `StorageTreeController.php`,
  `StorageBucketController.php` in full before writing `storage_repository.dart`, so its
  request/response shapes match what the server actually returns (including fields
  outside doc 05's literal example payloads, e.g. `profile`'s real `warnings` array —
  added a `BucketWarning` model for it rather than dropping the field on the floor).
- Read `afsdesktop5/src/main/cloud/sync.ts` in full (already reviewed and bug-fixed
  during this session's doc-10 pass) before writing `sync_engine.dart`, porting its
  structure — including the rename-detection candidate-array discipline the desktop
  pass's own review caught as a real bug — rather than re-deriving the algorithm from
  the doc text alone.
- **Not done**: launching the app on a device/emulator. No GUI environment is available
  in this session — the same constraint every completion doc in this plan that touches a
  real client UI has hit. Nothing in "Deviations and gaps" above claiming "reasoned true"
  behavior (chunk streaming under memory pressure, resume-after-kill, hashing off the UI
  isolate not dropping frames) has been observed running.

## Follow-up work

In priority order:

1. **Wi-Fi-only gating and background-transfer support (doc 11 §6, acceptance M5)** —
   the largest gap in this pass. Needs a connectivity-check dependency and, for real
   background continuation, platform-specific work (`URLSession` background config on
   iOS, `WorkManager` on Android) this session has no way to build or test.
2. **Run it.** Launch on an Android/iOS device or emulator against a real Checkpoint
   login and a live members server, and manually exercise M1-M8 — only M1 (name
   validator correctness) has an automated result; the rest are reasoned from the code.
3. **A genuine Android SAF tree walker** underneath `sync_engine.dart` (and the folder
   picker in `storage_upload_page.dart`) — the reconciliation algorithm and upload
   pipeline are ready for it; only the "read a `content://` tree URI as files" adapter
   is missing.
4. **A settings/sync-folder-pair page** wiring `sync_engine.dart`/`local_index.dart` to
   the UI — currently built and unit-addressable but unreachable from any screen.
5. **A "move to bucket" UI action** — `MoveBucket`/`repository.moveToBucket()` work;
   no page triggers them.
6. Doc 06 §7's shared fixture corpus (M1) — three languages, three hand-written test
   suites (PHP has none, TS has none, this pass added Dart's), still not one shared
   corpus. Same follow-up item every prior completion doc in this plan that touches name
   validation has flagged.
7. NFC normalisation (doc 06 §4) — no Dart standard-library normaliser; would need a
   third-party package (`characters`/`unorm_dart` or similar) evaluated and added.
8. Periodic `/delta` polling while a storage view is open (doc 07 §5's schedule table) —
   currently on-open and pull-to-refresh only.
9. Small-file batch coalescing and ZIP64 archive download — blocked on the members
   server implementing `PUT .../batch`/`GET /download/archive` first (it doesn't yet,
   per `05-api-contracts.complete.md` and `07-sync-and-dedupe.complete.md`).
