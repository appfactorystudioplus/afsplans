# 10-desktop-client — Completion

**Status:** Code-complete for everything the doc specifies, across a different
repository (`afsdesktop5`) than every other document in this plan (`w/members`).
Type-checks clean (`tsc --noEmit`, `strict: true`, zero errors project-wide) and a full
production-config `webpack` build succeeds for all three bundles (main, renderer,
transcription worker). **Not run** — no way to launch and click through an Electron GUI
app in this environment, so nothing here has been exercised against a live members
server. One real logic bug (rename detection matching the wrong candidate array, which
would have silently produced wrong remote file content on a coincidental hash/size
collision) was found and fixed during self-review before this doc was written, not after
— see §6 below.

## Summary

### §1 — starting position, what ported directly

`src/main/sync/` was read in full before writing anything (`client.ts`, `jobs.ts`,
`ledger.ts`, `paths.ts`, `manifest.ts`, `types.ts`, `index.ts` — 2,342 lines). Ported
directly, per the doc's own "Direct" column:
- `client.ts` → `cloud/client.ts` — same keep-alive-agent/streamed-body/JSON-response
  shape, `http` → `https`, LAN peer → `MEMBERS_BASE_HOST`, pairing token → Checkpoint
  bearer token.
- `jobs.ts` → `cloud/jobs.ts` — same job-registry shape (queued/running/paused/stalled/
  verifying/completed/failed/cancelled), same `~4/sec` progress throttle, same
  OS-notification pattern via Electron's `Notification` (not `window.Notification`).
- `ledger.ts` → `cloud/ledger.ts` — same durable-JSON-per-session-file layout under
  `<userData>/cloud/`, same atomic-write-via-tmp-then-rename pattern, plus folder-pair
  and BASE-index storage the LAN engine has no equivalent of.
- `paths.ts` → moved to `storage-common/pathRules.ts`, re-exported unchanged from
  `sync/paths.ts` so the LAN engine's imports and behaviour don't change (acceptance
  D7 — verified by grep: every symbol `sync/*.ts` imported from `./paths` before the
  move is still exported after it).

`server.ts` and `discovery.ts` correctly not ported — no receive-side HTTP server, no
mDNS (the server is a known URL), exactly as the doc predicts.

### §2 — shared vs forked

`storage-common/` built as specified: `pathRules.ts` (the move above), `walk.ts` (new —
`sync/manifest.ts`'s inline walk has no caps and was left untouched, since the LAN
engine's behaviour must not change; this is a fresh walk for the cloud engine with the
same depth/entry caps the members server enforces), `hashpool.ts` (see the stated
deviation in §6.2 below).

### §3 — main-process ownership

Everything lives in `src/main/cloud/`, registered in `src/main/index.ts` beside the
existing `registerMediaIpc()`/`registerSyncIpc()` calls:
```
registerMediaIpc();
registerSyncIpc();
registerCloudIpc();   // this pass
```
Verified (acceptance D1): `grep -rn` for `fs`/`http`/`https`/`crypto` imports under
`src/renderer/components/cloud/` returns zero matches — every one of those lives in
`src/main/cloud/` or `src/main/storage-common/`.

### §4 — IPC surface

Every channel in the doc's table is registered: `cloud:state`, `cloud:sign-out`
(no `cloud:sign-in` — see §5's deviation, sign-in stays entirely in the existing
settings/login flow), `cloud:profile`, `cloud:scan`, `cloud:plan-upload`,
`cloud:upload`, `cloud:download`, `cloud:pause`/`resume`/`cancel`/`retry`,
`cloud:jobs`/`cloud:job`, `cloud:job-files`, `cloud:tree`, `cloud:folder-add`/
`folder-remove`, `cloud:sync-now`, `cloud:move-bucket`, `cloud:reveal`. One addition not
in the doc's table: `cloud:set-token`, `cloud:create-folder`, `cloud:delete-node` — the
first is the renderer→main token bridge (§5), the latter two are straightforward
CRUD the doc's table implies (via `cloud:folder-add` needing folders, and the browser
needing delete) but doesn't list as separate channels; added because the renderer
needs them and doc §7's `browser.ts`/toolbar clearly assume they exist.

Pushed events: `cloud:progress`, `cloud:job-done`, `cloud:sync-status`,
`cloud:profile-changed`, `cloud:conflict`, `cloud:auth-required` — all implemented,
all wired to real emitters in `jobs.ts`/`sync.ts`/`index.ts`.

### §5 — authentication: the one deliberate, documented deviation

**The doc assumes token acquisition happens in main, via `safeStorage`, with the
renderer never seeing a token. This app's actual architecture is the opposite, and
that's not a shortcut — it's the correct call given what already exists.**

Checkpoint auth in `afsdesktop5` lives entirely in the renderer
(`src/renderer/checkpoint/`, `CheckpointClient`/`HttpClient`, token storage in
`localStorage`), and it was *just* deliberately hardened there —
`checkpoint-server-auth/07-desktop-client-auth.md` (already complete) added
`assertPrimary()`, a full 2FA flow, and removed a plaintext-secondary-token side
channel, all inside that same module. Building a second, main-process-only Checkpoint
auth path alongside it would duplicate a just-hardened flow rather than reuse it.

Implemented instead: `cloud/auth.ts` holds the current token **in memory only**
(no `safeStorage` persistence — the renderer already owns durable storage for it).
`CloudView` (`src/renderer/components/cloud/index.ts`) creates its own
`CheckpointClient` instance — mirroring `components/settings.ts:87`'s own independent
instantiation of the same class, an established pattern here, not a new one — and
pushes a valid access token into main via `cloud:set-token` on mount and every 5
minutes thereafter (well inside the ~1 hour token lifetime). If a call fails with
`401 TOKEN_EXPIRED` mid-job anyway, `auth.ts`'s `requestFreshToken()` broadcasts
`cloud:auth-required`, the job pauses (never fails), and the renderer immediately
re-pushes rather than waiting for the next timer tick. This satisfies the doc's actual
functional requirements (proactive refresh, pause-not-fail, resume costs one
`GET /sessions/{id}`) without the literal mechanism.

### §6 — sync engine

`cloud/sync.ts` implements doc 07 §2's three-state model in full — the genuinely new
code the doc's own table flags as having no LAN-engine equivalent to port.

- **§2.1-2.2 (BASE, cheap detection):** `ledger.ts`'s `getBaseIndex`/`saveBaseIndex`;
  only size/mtime-changed candidates get hashed (`storage-common/hashpool.ts`), an
  unchanged size+mtime is trusted without hashing.
- **§2.3 (decision table):** all eleven rows implemented, including both
  stated-default conflict rows (Δ,− and −,Δ — "keep the modification, never the
  deletion") and the two undecided rows (Δ,Δ and +,+-different) surfaced as
  `SyncConflict`s via `cloud:conflict`, never auto-resolved.
- **§2.4 (rename detection):** implemented — a local add matched against a local
  delete by size+hash within one pass issues `PATCH /nodes/{id}` instead of a fresh
  upload. **Bug found and fixed during this pass's own review, worth recording
  precisely because it's the kind of mistake that would have silently corrupted data
  rather than crashed:** the first draft matched new-file candidates against
  `toDeleteLocal` (the unrelated "remote deleted it, remove our local copy" action)
  instead of `toDeleteRemote` (the actual rename-source candidates), and separately
  let Δ-sourced (modified-file) uploads into the same candidate pool as genuine
  new-file (`+`) uploads — a coincidental hash+size match on either bug would have
  PATCHed a delete-target's *name* onto the wrong remote node without updating its
  *content*. Both fixed: rename matching now only ever compares `toUpload` (true `+`)
  against `toDeleteRemote`, and `modifiedUploads` (Δ) never enters rename matching at
  all, rejoining the upload batch only after rename detection has run. Cross-directory
  moves are detected and deliberately **not** attempted via PATCH (a bare `{name}`
  patch would leave the file in its old parent folder) — falls back to upload+delete.
- **§3 (delta cursor):** `client.getDelta()` with `reset_required` handling — a reset
  falls through to a full recursive `client.getTree()` walk instead.
- **§4 (duplicate prevention):** `+,+` with matching content hash adopts without
  transfer; different content is a conflict, never auto-merged.
- **§6.1 (watcher):** `cloud/watcher.ts` — manually recursive (watches every
  subdirectory individually; `fs.watch(dir, {recursive:true})` isn't reliably
  supported on Linux for the Node version Electron 28 bundles, and this app has no
  chokidar dependency to reach for instead — confirmed via `package.json`), 2s
  debounce, `ENOSPC` detection falling back to 30s polling with a `watch-degraded`
  status pushed to the UI (never silently stops), hourly full-scan backstop on every
  platform. Own-write suppression (`watcher.suppress()`) is called before every
  download write so a downloaded file is never re-uploaded (D8).
- **§6.2 (hashing) — the other stated deviation:** the doc specifies a
  `worker_threads` pool sized `cpus-1`. Implemented instead: bounded **concurrency**
  (same `cpus-1` sizing) using streaming `crypto.createHash` piped from
  `fs.createReadStream`, not real OS threads. Reasoning, in full in
  `storage-common/hashpool.ts`'s header comment: this main process is one webpack
  bundle with no separate worker entry point; a `worker_threads.Worker` would need to
  either self-reference the whole bundle (gated on `workerData`) or a new webpack
  entry, and Electron's `app`/`ipcMain`/`BrowserWindow` bindings (imported at the top
  of the very file that would become the worker entry point) are not safe to load
  inside a `worker_threads` thread. That risk, in code this session has no way to
  launch and test, wasn't worth taking for what streaming I/O already gets mostly
  right (the event loop isn't blocked between chunks). True CPU parallelism across
  cores for many large files hashing at once is what's genuinely lost.
- **§6.3 (bandwidth throttle):** **not built** — the doc marks it optional.
- **§6.4 (sleep/network):** `powerMonitor` `suspend`/`resume` pause and re-query
  offsets (`jobs.ts`'s `pauseAllRunning`/`resumeAllPaused`); network-error patterns
  (`ECONNREFUSED`/`ECONNRESET`/etc.) go `stalled` with jittered exponential backoff
  1s→60s, not `failed`.

### §7 — renderer

`src/renderer/components/cloud/` built as specified: `index.ts` (`CloudView`),
`buckets.ts`, `transfers.ts`, `browser.ts`, `folders.ts`, `dialogs.ts`, `format.ts`.
Replaces the "Coming soon" pane in `sync.html`'s `#cloud-content`, mounted via a small
extension to the existing `SyncManager` (`setCloudView()`, called once from
`renderer.ts` after both are constructed — `CloudView` needs `notificationManager`,
constructed after `SyncManager` in the existing sequence, so a setter avoided
reordering that sequence).

Visual language followed as specified: Tabler `ti ti-*` icon classes (already the
convention in `components/coordinator/`, just not yet in `sync.ts`) instead of emoji;
new CSS custom properties (`--cloud-success`/`--cloud-danger`/`--cloud-warning`) reusing
this app's existing `--text-primary`/`--text-secondary`/`--brand-color`/`--glass-border`
rather than hard-coded hex scattered through rules; `NotificationManager.add()` instead
of `alert()` for every error/conflict surfaced from `CloudView`; `textContent`/
`createElement` for every member-or-file-supplied string (filenames, folder paths,
labels) in `transfers.ts`/`browser.ts`/`folders.ts` — `innerHTML` is used only for this
module's own fixed template strings, never to interpolate an untrusted string directly
(the one exception, `dialogs.ts`'s rejected/conflict lists, runs every interpolated
string through `escapeHtml()` first, same discipline as the members web client's
`StorageApp.ts`). All 65 new strings route through `i18n.t()` into `locales.json` under
`cloud.*` — added for `en` only; `es`/`de`/`zh` fall back to the raw key, same
documented gap as every other completion doc in this plan that touches i18n.

**Simplification, stated plainly:** `transfers.ts` is called a "virtualised job list"
in the doc. This renders a plain DOM list — the same simplification already made and
documented for the members web client's `TreeView`. Realistic job counts (a handful of
concurrent transfers) don't make this a real cost; hundreds of simultaneous jobs would.

### §8 — acceptance

| # | Criterion | Status |
|---|---|---|
| D1 | No `fs`/`http`/`https`/`crypto` under `components/cloud/` | **Met** — grep-verified, zero matches |
| D2 | Navigate away and back mid-upload shows live progress, never restarts | Reasoned true (jobs live in main, `CloudView.unmount()` only clears its own timer) — **not observed** |
| D3 | Renderer reload doesn't interrupt an upload | Reasoned true (same reason) — **not observed** |
| D4 | 4GB upload interrupted at 60% resumes within one chunk | Reasoned true (offset is always the server's `.part` length via `GET /sessions/{id}`, same mechanism the members server and web client both already rely on) — **not observed**, no 4GB file was pushed through a live session |
| D5 | 10,000×20KB uploads in ≤200 requests | **Not met** — no batch coalescing (same gap as the members server itself, `05-api-contracts.complete.md`); this client is a full session-open+PUT+commit per file, same as the web client |
| D6 | `storage-common/pathRules.ts` passes the shared fixture corpus | **Not met** — the corpus (doc 06 §7) doesn't exist yet anywhere in this plan, members-side or here; flagged as a cross-cutting follow-up in every completion doc that touches name validation |
| D7 | LAN engine behaviour unchanged by the `paths.ts` extraction | **Met** — verified by grep that every symbol the LAN engine imported from `paths.ts` before the move is still exported after it; the LAN engine's own test suite was not re-run this session (none exists to run — confirmed no test files under `src/main/sync/`) |
| D8 | A downloaded file isn't re-uploaded | Reasoned true (`watcher.suppress()` called before every download write) — **not observed** |
| D9 | Token expiry mid-upload pauses, re-auth resumes without re-sending | Reasoned true (§5's mechanism) — **not observed** |
| D10 | Bucket state changes reach the UI without a restart | **Met** mechanically — `cloud:profile-changed` is broadcast after every reconcile pass and every completed job; `showDowngradePreview()` fires when a profile refresh reveals a newly-pending retirement — **not observed** live |

## Files changed

**`storage-common/`** (new): `pathRules.ts`, `walk.ts`, `hashpool.ts`
**`sync/paths.ts`**: rewritten to re-export from `storage-common/pathRules.ts`
**`main/cloud/`** (new): `types.ts`, `config.ts`, `auth.ts`, `client.ts`, `ledger.ts`,
`jobs.ts`, `watcher.ts`, `sync.ts`, `index.ts`
**`main/index.ts`**: `registerCloudIpc()` import + call
**`renderer/components/cloud/`** (new): `index.ts`, `buckets.ts`, `transfers.ts`,
`browser.ts`, `folders.ts`, `dialogs.ts`, `format.ts`
**`renderer/components/sync.ts`**: `cloudView` field, `setCloudView()`, `renderCloudTab()`
now mounts it, `updateAuthUI()` forwards auth state to it
**`renderer/renderer.ts`**: constructs `CloudView`, wires it via `setCloudView()`
**`renderer/html/sync.html`**: "Coming soon" placeholder removed (now an empty mount
point), ~330 lines of new `.cloud-*` CSS added to the existing `<style>` block
**`renderer/locales.json`**: `en.cloud.*` — 65 new keys

## Verification steps

- `npx tsc --noEmit -p tsconfig.json` — exit code 0, zero errors, project-wide,
  `strict: true`, across every file this pass touched or added.
- `npx webpack --config webpack.config.js --mode development` — all three configured
  bundles (main → `dist/main/index.js`, renderer → `dist/renderer/index.js`,
  transcription worker) compiled successfully. This is the same check
  `checkpoint-server-auth/07-desktop-client-auth.complete.md` used to confirm nothing
  deleted was still imported; here it confirms everything *added* actually bundles —
  including the main-process-bundle-self-reference risk that `hashpool.ts`'s deviation
  exists specifically to avoid triggering.
- `grep -rn` for `fs`/`http`/`https`/`crypto` imports under
  `src/renderer/components/cloud/` — zero matches (D1).
- `grep -rn "from '\./paths'"` across `src/main/sync/*.ts` — confirmed every symbol
  used (`ensureReceiveRoot`, `getAvailableBytes`, `partPath`, `resolveDestPath`,
  `sessionPartsDir`, `validateRelPath`, `DEFAULT_RECEIVE_ROOT`) is still exported after
  the `storage-common/` extraction (D7).
- The rename-detection bug (§6) was caught by re-reading `sync.ts`'s decision-table
  code against doc 07 §2.3/§2.4 line by line after first writing it, not by any
  automated check — worth naming as a category of bug (wrong-array, not
  wrong-syntax) that neither `tsc` nor a successful `webpack` build can catch.
- **Not done, and the largest gap in this document:** launching the actual Electron
  app. No GUI environment is available in this session to run `npm start`, click
  through a real login, drag a real folder onto the upload zone, or watch a real sync
  pass reconcile two directories. Every acceptance criterion marked "reasoned true" or
  "not observed" above is exactly that — traced against the code by hand, never run.

## Follow-up work

In priority order:

1. **Run it.** Launch the app against a real Checkpoint login and the real members
   server (once that server itself is reachable — see the members-repo completion
   docs for the same "no live database" constraint on that side), and manually step
   through D2-D5, D8-D10 — none of which have been observed, only reasoned through.
2. Doc 06 §7's shared fixture corpus (D6) — this desktop client, the members server's
   PHP validator, and the web client's TypeScript validator all need to agree on the
   same corpus; none of the three currently have an automated test proving it.
3. Batch upload coalescing (D5) — would need the members server's own `PUT .../batch`
   endpoint to exist first (it doesn't yet — see `05-api-contracts.complete.md`).
4. Bandwidth throttling (§6.3, explicitly optional in the doc).
5. `worker_threads`-based hashing, if the webpack self-reference risk is ever
   re-examined and judged worth taking (§6.2's stated deviation).
6. True job-list virtualisation for large concurrent-job counts (§7's stated
   simplification).
7. `es`/`de`/`zh` translations for the new `cloud.*` locale keys.
8. The LAN sync engine (`src/main/sync/`) has no automated test suite to have run
   against the `paths.ts` extraction (D7) — confirmed by its absence, not by a failing
   run. Worth having one, independent of this plan's scope.
