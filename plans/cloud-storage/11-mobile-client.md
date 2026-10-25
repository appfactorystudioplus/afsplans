# 11 — Mobile client (afsmobile_flutter)

## Completion document

When this plan step is completed, write `complete/11-mobile-client.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using `///` Dart doc comments — every
library, public class, method, and non-trivial type, with plan references. This matches the
convention already used in `lib/config.dart`.

---

> **Not yet updated for [storage-sharding](../storage-sharding/10-members-and-client-changes.md) §2.**
> Everything below still describes uploading straight to members (the local-mode protocol).
> `storage-sharding/10`'s completion doc records this repo as untouched — routing range PUTs to
> a storage device (the same change made to the web client) remains to be done here.

## 1. Starting position

There is **no storage code in the app at all**. But the foundations are in place:

| Existing | Use |
|---|---|
| `lib/config.dart` → `https://members15.appfactory.studio/api` | The API base is already configured |
| `packages/checkpoint` (`checkpoint_client`) | Auth — the same Checkpoint token the API expects |
| `lib/repositories/*.dart` (8 repositories) | The established data-access pattern to follow |
| `flutter_bloc` + `equatable` | The established state-management pattern |
| `http: ^1.2.2` | Sufficient for ranged uploads — see §4.1 |
| `sqflite`, `path_provider`, `path` | Local index and file access |
| `file_picker: ^10.3.8` | Selection, including directories |
| `share_plus` | "Open in…" for downloaded files |

Nothing new needs to be invented; this is a new feature built on the app's existing shape.

## 2. New files

```
lib/
  models/
    storage_node.dart          file/folder node
    storage_bucket.dart        bucket + capacity + state
    storage_profile.dart       the /profile payload (doc 05 §3)
    upload_session.dart        session + per-file state
    scan_report.dart           doc 06 §6
  repositories/
    storage_repository.dart    all /api/v1/storage/* calls
  services/
    name_validator.dart        Dart port of the doc 06 rule set
    upload_engine.dart         the six-state machine (doc 03 §6)
    sync_engine.dart           three-state reconciler (doc 07 §2)
    local_index.dart           sqflite BASE store
    hash_service.dart          SHA-256 in an Isolate
  bloc/storage/
    storage_bloc.dart  storage_event.dart  storage_state.dart
  pages/storage/
    storage_page.dart          browser + bucket meters
    storage_upload_page.dart   selection, scan report, progress
    storage_buckets_page.dart  retention UI (doc 08 §4)
    storage_conflicts_page.dart
test/storage/
    name_validator_test.dart   runs the shared fixture corpus (doc 06 §7)
```

New dependencies — deliberately few:

```yaml
  crypto: ^3.0.3              # SHA-256
  watcher: ^1.1.0             # local change detection where the OS allows it
  flutter_secure_storage: ^9.0.0   # token at rest, if checkpoint_client doesn't already
```

## 3. Authentication

The app already ships `packages/checkpoint` (`checkpoint_client`) and already authenticates
against `auth15.appfactory.studio` — the same IdP whose tokens the members storage API
verifies (doc 01 §2). Nothing new is needed to *get* a token; what matters is how it is
used during long uploads.

- `storage_repository.dart` attaches `Authorization: Bearer <token>` to every call and is
  the only place that does. No page or bloc touches a token.
- **Proactive refresh at ~80 % of token life.** Access tokens live 1 hour (doc 01 §2.4);
  the 30-day refresh token covers any realistic upload.
- **`401 TOKEN_EXPIRED` pauses a job, never fails it.** The repository refreshes, then
  resumes from the server's offsets — because the upload session belongs to the member,
  not to the token (doc 01 §7).
- Tokens at rest go to Keychain/Keystore via `checkpoint_client`'s own persistence, or
  `flutter_secure_storage` — never `shared_preferences`, which is plaintext on disk.
- A secondary user resolves to the primary member's storage (doc 01 §6). The UI shows
  whose storage is being used when the signed-in user is a secondary account, so a
  sub-account user is never surprised to find the primary's files.

## 4. The upload engine in Dart

### 4.1 Streaming a range without loading the file

The single most important implementation detail. `http.post(body: bytes)` loads the whole
chunk into memory; on a phone that is how you get an OOM kill on a 32 MiB chunk while the
OS is already under pressure.

```dart
/// Uploads one byte range of [file] using a streamed request body so that
/// neither the chunk nor the file is ever fully resident in memory.
///
/// The range start MUST equal the server's current .part length; a 409 carries
/// the true offset (doc 05 §5.2) and the caller re-seeks rather than restarting.
Future<int> putRange({
  required String sessionId,
  required String fileId,
  required File file,
  required int start,
  required int end,      // inclusive
  required int total,
  required void Function(int sentBytes) onProgress,
}) async {
  final request = http.StreamedRequest('PUT', uri)
    ..headers.addAll({
      'Authorization': 'Bearer $token',
      'Content-Type': 'application/octet-stream',
      'Content-Range': 'bytes $start-$end/$total',
    });

  // openRead(start, end + 1) — Dart's end is EXCLUSIVE, HTTP's is INCLUSIVE.
  // Off-by-one here corrupts every chunk boundary, silently.
  final source = file.openRead(start, end + 1);
  ...
}
```

That comment is in the plan because the exclusive/inclusive mismatch between
`File.openRead` and `Content-Range` is the defect most likely to be written here, and it
produces files that are subtly wrong rather than obviously broken.

### 4.2 Hashing off the UI isolate

SHA-256 of a 2 GB video on the main isolate freezes the app. `hash_service.dart` runs
digests in an `Isolate` via `compute()`, streaming the file rather than reading it whole.

### 4.3 Chunk sizing on mobile

Smaller than desktop: **4 MiB default, 1–8 MiB range**. Mobile links are lossier and a
failed 32 MiB chunk on a cellular connection wastes real money on a metered plan. The
server's `chunk_size_hint` is a hint; the client clamps it to the mobile range.

## 5. Local file access reality

This is where mobile differs most from the other two clients, and pretending otherwise
produces a broken app.

| Platform | What the app can reach |
|---|---|
| **iOS** | Its own sandbox, plus files the member explicitly picks through the document picker. **No arbitrary filesystem access.** Security-scoped URLs must be resolved and released correctly |
| **Android 10+** | Scoped storage. `MediaStore` for media; SAF (`file_picker`) for anything else. Broad filesystem access requires `MANAGE_EXTERNAL_STORAGE`, which Play Store review will reject without a qualifying use case |

**Consequences designed for, not discovered:**

- **Two-way folder sync is not offered on iOS at all**, and on Android only for a
  SAF-granted tree URI whose permission has been persisted. Offering a "sync this folder"
  toggle that silently stops working after a reboot is worse than not offering it.
- The default mobile mode is **upload / browse / download**, not sync. The sync engine
  (doc 07) is compiled in and used for the Android SAF case and for the download side; it
  is not the headline feature it is on desktop.
- **Picked-file URIs are not durable.** A resumable session stores the picked file's
  *bookmark*/persisted URI where the platform supports it, and where it does not, a resume
  after an app kill asks the member to re-pick — the same honest limitation the web client
  has (doc 09 §2.4).

## 6. Background uploads

Neither iOS nor Android will run an ordinary HTTP client indefinitely in the background.

| Platform | Mechanism | Real constraint |
|---|---|---|
| iOS | `URLSession` background configuration | Only **whole-file** upload tasks from a file on disk. It will not run our ranged chunk loop. See §6.1 |
| Android | `WorkManager` foreground service | Works, but requires a persistent notification and is subject to Doze |

### 6.1 The iOS chunk problem, and the answer

iOS background `URLSession` uploads a file; it does not run application code between
chunks. A ranged, adaptive, multi-stream loop cannot survive backgrounding.

Rather than fight it:

- **Foreground:** the full engine — parallel ranged chunks, adaptive sizing.
- **Background:** the engine **pauses**. Because every offset lives on the server, pausing
  costs nothing and resuming is one `GET /sessions/{id}`.
- The UI states this plainly: *"Uploading continues when the app is open."* It never
  displays a progress bar that is secretly frozen.

An iOS background upload of remaining whole files via `URLSession` is a possible Phase 5
enhancement — it would need a second, non-chunked server path and is not worth its
complexity until members ask.

### 6.2 What the member sees

- A persistent progress notification on Android.
- On iOS, a foreground progress UI plus a resume-on-open prompt.
- Both platforms: **"Wi-Fi only" is on by default.** Uploading a 5 GB video folder over
  cellular without asking is a bill the member did not agree to.

## 7. State management

Follows the app's existing `flutter_bloc` pattern.

```
StorageBloc
  events:  LoadProfile · LoadTree · PickFiles · ScanSelection ·
           StartUpload · PauseUpload · ResumeUpload · CancelUpload ·
           ResolveConflicts · MoveBucket · DownloadNodes · DeleteNodes
  states:  StorageInitial · StorageLoading · StorageReady(profile, tree) ·
           StorageScanning(progress) · StorageScanReport(report) ·
           StorageConflicts(list) · StorageUploading(jobs) · StorageError(code, message)
```

`StorageScanReport` and `StorageConflicts` are distinct states, not dialogs bolted onto a
ready state, because both are decision points the member must pass through and both must
survive a rotation or an app switch.

## 8. Bucket UI on a phone

The desktop layout (doc 08 §4) does not fit a phone screen. Same information, restructured:

```
┌─────────────────────────────┐
│  Storage         Business ▸ │
│                             │
│   ◕  1.5 GB of 62.5 GB      │   ← ring, tappable
│                             │
│  Buckets                    │
│  ┌─────────────────────────┐│
│  │ 0  ████████████ FULL    ││
│  │    512 MB · 1,204 files ││
│  ├─────────────────────────┤│
│  │ 1  ████░░░░░░░░  ← now  ││
│  │    1.0 GB of 2 GB       ││
│  ├─────────────────────────┤│
│  │ 2  ░░░░░░░░  0 of 10 GB ││
│  ├─────────────────────────┤│
│  │ 3  ░░░░░░░░  0 of 50 GB ││
│  ├─────────────────────────┤│
│  │ 4  🔒 Enterprise        ││
│  └─────────────────────────┘│
│  Uploads fill the lowest    │
│  bucket, then move up.      │
└─────────────────────────────┘
```

The retirement banner (doc 08 §6.6) becomes a full-screen interstitial on first open after
a downgrade — it is the one message that must not be missed, and a dismissible banner on a
phone is missed.

## 9. Localisation

The app already has `flutter_localizations` + `intl` and an `lib/l10n/` directory. Every
new string goes there under `storage*` keys, including one message per validation rule id
from doc 06 §3 so a rejected file gets a specific reason.

## 10. Acceptance

| # | Criterion |
|---|---|
| M1 | `name_validator.dart` passes the shared fixture corpus identically to the TS and PHP ports (doc 06 §7.2) |
| M2 | A 2 GB upload never holds more than one chunk in memory; no OOM on a 2 GB device |
| M3 | An upload interrupted by backgrounding resumes from the server's offset when the app reopens, re-sending no more than one chunk |
| M4 | Hashing a 2 GB file does not block the UI isolate (no dropped frames) |
| M5 | Cellular upload is refused by default and requires an explicit opt-in |
| M6 | `Content-Range` boundaries are byte-exact — verified by uploading a known file in 3 chunks and comparing server-side SHA-256 (guards the §4.1 off-by-one) |
| M7 | The retention interstitial appears on first open after a downgrade and states the correct byte total and deletion date |
| M8 | No feature in the UI implies background upload continues while the app is closed |
