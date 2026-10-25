# 10 — Desktop client (afsdesktop5)

## Completion document

When this plan step is completed, write `complete/10-desktop-client.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using `/** */` JSDoc blocks — every file,
exported symbol, public function, non-obvious private helper, IPC handler, and non-trivial
type, including plan references. This matches the standard already applied in
`src/main/sync/`.

---

> **Not yet updated for [storage-sharding](../storage-sharding/10-members-and-client-changes.md) §2.**
> Everything below still describes uploading straight to members (the local-mode protocol).
> `storage-sharding/10`'s completion doc records this repo as untouched — routing range PUTs to
> a storage device (the same change made to the web client) remains to be done here.

## 1. Starting position

The desktop app has a Cloud tab that says "Coming soon" (`src/renderer/html/sync.html`),
explicitly deferred in that repo's own plan
(`afsdesktop5/plans/random/08-sync-rework.md` §9.4).

What it *does* have is the reason this is the cheapest of the three clients:
`src/main/sync/` is a complete, working implementation of the exact state machine this
plan needs, built for LAN device-to-device transfer.

| `src/main/sync/` module | What it already does | Reuse for cloud |
|---|---|---|
| `client.ts` | keep-alive agent pool, ranged `PUT` with `Content-Range`, `409` offset re-seek, streamed bodies | **Direct** — swap host and auth header |
| `jobs.ts` | job registry, lifecycle states, throttled progress (~4/s), auto-resume on peer-up, OS notifications | **Direct** — swap "peer up" for "network up" |
| `ledger.ts` | durable session/job state, atomic JSON writes, restart recovery | **Direct** |
| `paths.ts` | the hardened relPath validator, `bavail` free space | **Direct** — it is the reference implementation for doc 06 |
| `manifest.ts` | recursive folder walk, size totals, conflict planning | **Direct** |
| `server.ts` | receive-side HTTP endpoints | **Not used** — the cloud client is send-only |
| `discovery.ts` | mDNS peer discovery | **Not used** — the server is a known URL |

So the desktop client is largely a **port**, and the two modules it does not need are the
two that carried the LAN-specific complexity.

## 2. Shared vs forked

The tempting move is to have one module serve both LAN sync and cloud storage. **Do not.**
Their transports differ (pinned self-signed cert vs public TLS), their auth differs
(pairing token vs Checkpoint bearer), their addressing differs (mDNS deviceId vs fixed
URL), and their failure semantics differ (peer offline vs server 5xx).

Instead, extract the genuinely common parts and let the two engines sit beside each other:

```
src/main/
  sync/              existing LAN engine — UNCHANGED by this plan
  cloud/             new
    index.ts         registerCloudIpc() — the `cloud:*` handlers
    client.ts        HTTPS client for /api/v1/storage/* (ported from sync/client.ts)
    jobs.ts          CloudJobRegistry (ported from sync/jobs.ts)
    ledger.ts        durable session + BASE index (ported from sync/ledger.ts)
    sync.ts          three-state reconciler (doc 07 §2) — genuinely new
    watcher.ts       filesystem watcher with debounce — genuinely new
    auth.ts          Checkpoint token acquisition + refresh
    types.ts
  storage-common/    extracted, shared by sync/ and cloud/
    pathRules.ts     the doc 06 validator (moved from sync/paths.ts)
    walk.ts          recursive folder walk with depth/entry caps
    hashpool.ts      worker_threads SHA-256 pool
```

**`pathRules.ts` moving into `storage-common/` is the one change this plan makes to the
existing sync module**, and it is a move plus a re-export, not a rewrite. The existing
`src/main/sync/paths.ts` keeps its current API by re-exporting, so nothing in the LAN
engine changes behaviour.

That module already carries the POSIX-normalisation fix described in doc 06 §7.3 — the bug
where OS-native `path.normalize` on Windows defeated the `..` check. Sharing it means the
cloud client inherits the fix rather than re-deriving it, which is the entire argument for
extraction.

## 3. Main-process ownership

Everything runs in **main**, not the renderer, matching the decision already made and
justified for LAN sync:

- Transfers survive a renderer reload, and a renderer reload is not a data-loss event.
- `worker_threads` for hashing actually works (Web Workers in the renderer could not load
  native modules — the reason the old `syncWorker.ts` was dead code).
- One engine per app instance, not one per view construction.
- Tokens go through `safeStorage`, already wired at `src/main/index.ts:386`.

Registered beside the others in `src/main/index.ts`:

```ts
registerMediaIpc();
registerSyncIpc();
registerCloudIpc();   // new
```

**The renderer keeps nothing.** If `components/cloud/index.ts` imports `fs`, `http`, or
`crypto`, the split has failed — the same acceptance rule the LAN sync rework used.

## 4. IPC surface

| Channel | Request | Response |
|---|---|---|
| `cloud:state` | — | `{ ok, account, profile, jobs[], folders[], settings }` — one call on view mount |
| `cloud:sign-in` / `cloud:sign-out` | — | `{ ok }` |
| `cloud:profile` | — | `{ ok, profile }` — buckets, capacities, plan (doc 05 §3) |
| `cloud:scan` | `{ paths[] }` | `{ ok, report }` — local scan, no network |
| `cloud:plan-upload` | `{ paths[], bucket? }` | `{ ok, plan }` — calls `POST /scan`; conflicts, rejects, target bucket, quota |
| `cloud:upload` | `{ paths[], bucket?, policy }` | `{ ok, jobId }` |
| `cloud:download` | `{ nodeIds[], destDir }` | `{ ok, jobId }` |
| `cloud:pause` / `resume` / `cancel` / `retry` | `{ jobId }` | `{ ok }` |
| `cloud:jobs` / `cloud:job` | — / `{ jobId }` | `{ ok, jobs[] }` / `{ ok, job }` |
| `cloud:job-files` | `{ jobId, offset, limit }` | `{ ok, files[], total }` — paged |
| `cloud:tree` | `{ bucket, path, cursor }` | `{ ok, entries[], nextCursor }` |
| `cloud:folder-add` | `{ localPath, bucket, remotePath }` | `{ ok, folder }` — create a sync pair |
| `cloud:folder-remove` | `{ id, deleteLocal }` | `{ ok }` |
| `cloud:sync-now` | `{ id? }` | `{ ok }` |
| `cloud:move-bucket` | `{ nodeIds[], targetBucket }` | `{ ok }` (doc 08 §5) |
| `cloud:reveal` | `{ path }` | `{ ok }` |

Pushed to the renderer:

| Event | Payload |
|---|---|
| `cloud:progress` | `{ jobId, bytesDone, bytesTotal, filesDone, filesTotal, rate, eta, currentFile }` — throttled ~4/sec |
| `cloud:job-done` | `{ jobId, result }` \| `{ jobId, cancelled }` \| `{ jobId, error }` |
| `cloud:sync-status` | `{ folderId, state, pending, conflicts }` |
| `cloud:profile-changed` | `{ profile }` — e.g. after a retirement sweep changes bucket state |
| `cloud:conflict` | `{ jobId, conflicts[] }` — needs a member decision |
| `cloud:auth-required` | `{ reason }` — token expired |

## 5. Authentication

The desktop already knows the Checkpoint domain (`src/renderer/config.ts`:
`CHECKPOINT_BASE_URL`), and the app already has a login flow used by `settings.ts` and
`sidebar.ts`.

- Tokens are acquired through the existing Checkpoint flow and stored via `safeStorage`
  — never in `localStorage`, never in plain files.
- `auth.ts` refreshes proactively before expiry and pauses in-flight jobs on
  `cloud:auth-required` rather than failing them. An expired token during a 40 GB upload
  must be a pause, not a restart — and because session offsets live on the server, the
  resume after re-auth costs one `GET /sessions/{id}`.
- Token acquisition happens in **main**; the renderer never sees a token.

## 6. Sync engine specifics

Implements doc 07 §2. Desktop-only concerns:

### 6.1 The watcher

`chokidar`-style recursive watch with a **2 s debounce**, plus:

- **Ignore the app's own writes.** A download writes files into the watched tree; without
  suppression that fires a change event and the file is re-uploaded. Downloads register
  their target paths in a suppression set until the write settles.
- **Watcher limits are real.** Linux `inotify` has a per-user watch cap (commonly 8192);
  a large tree exhausts it silently and the watcher simply stops reporting. Detect the
  `ENOSPC` failure, fall back to periodic polling, and **tell the member** rather than
  appearing to watch.
- Windows `ReadDirectoryChangesW` can drop events under heavy churn, so a periodic full
  scan (hourly) is a backstop on every platform, not a Linux-only workaround.

### 6.2 Hashing

`hashpool.ts` sizes to `cpus − 1` and hashes only *candidates* (doc 07 §2.2). A full-tree
hash never happens on a routine pass.

### 6.3 Bandwidth

Optional token-bucket throttle, shown in the header rail as Unlimited / 50 / 20 / 5 MB/s —
the same control the LAN sync UI has, so the two features behave alike.

### 6.4 Sleep and network changes

- `powerMonitor` `suspend` → pause jobs; `resume` → re-query offsets and continue.
- Network change → jobs go `stalled`, not `failed`, with jittered backoff to 60 s.
- **Stalled is not failed.** Most transfer failures are a lid closing.

## 7. Renderer

```
src/renderer/components/cloud/
  index.ts       CloudView — mount/unmount, IPC wiring, no node modules
  buckets.ts     the retention panel (doc 08 §4)
  transfers.ts   virtualised job list
  browser.ts     remote tree browser
  folders.ts     sync-pair management
  dialogs.ts     scan report, conflict resolution, downgrade preview
  format.ts      bytes / rate / ETA
```

Replaces the "Coming soon" pane in `sync.html`. Visual language follows what the LAN sync
UI rework established: Tabler icon classes rather than emoji, CSS custom properties rather
than hard-coded hex, `NotificationManager` rather than `alert()`, `textContent`/
`createElement` rather than `innerHTML` for any peer- or file-supplied string, and every
string through `i18n.t` into `locales.json` under `cloud.*`.

## 8. Acceptance

| # | Criterion |
|---|---|
| D1 | No `fs`, `http`, `https`, or `crypto` import exists under `src/renderer/components/cloud/` |
| D2 | An upload started, navigated away from, and returned to shows live progress and never restarted |
| D3 | Reloading the renderer mid-upload does not interrupt it |
| D4 | A 4 GB upload interrupted at 60 % resumes within one chunk of 60 % |
| D5 | 10 000 × 20 KB uploads in ≤ 200 HTTP requests |
| D6 | `storage-common/pathRules.ts` passes the shared fixture corpus (doc 06 §7) |
| D7 | The LAN sync engine's behaviour is unchanged by the `paths.ts` extraction — its existing tests pass untouched |
| D8 | A file downloaded into a watched folder is not re-uploaded |
| D9 | Token expiry mid-upload pauses the job; re-auth resumes without re-sending bytes |
| D10 | Bucket state changes (retirement) reach the UI without a restart |
