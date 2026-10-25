# 01 — WebRTC call client — completion

**Status:** Implemented and verified (2026-09-19) by typecheck, a clean webpack build, 50 unit
tests, **and a real end-to-end run against the lab** — the actual built `afsdesktop5` app joined a
real meeting over real LiveKit and exchanged real media, through its own "Meetings" UI. See
"Real end-to-end verification" below for how, given the standing rule that a real member/host login
must be the user's, never mine. The one thing still not exercised is the **host** side of the
desktop UI itself (starting a meeting, waiting-room admit/deny, mute-all) — that needs the user's
own Checkpoint login, same as `website/02`'s `npm run e2e:owner` gap.

## Summary

`afsdesktop5` gets a new **Meetings** view (Tools → Meetings): a host sees their personal room's
join code and can start a meeting; a guest can join by code and display name, including a waiting
room. Once in, both see a participant grid (camera tiles, mic/cam indicators, speaking highlight),
self-view controls (mute, camera, mic/camera device pickers), a chat panel, and the recording
indicator everyone sees per `06-security.md` §5. A host/co-host additionally gets mute-all, end
meeting, and a polled waiting-room admit/deny list. A laptop sleep/resume is forwarded from the main
process and triggers an automatic rejoin with the stored rejoin token (`01` §4).

Screen share (`04-screen-share-in-call.md`), and the meeting whiteboard/reactions/raised hands
(`05-advanced-features.md`) are **not** part of this step and were not built — `01` §3 only asks for
a participant grid, self-view, mute/camera/leave, host basics, chat, and the recording indicator.

### A new problem this step had to solve: the desktop app had no way to reach the lab

`w/communications` only exists in `C:\projects\vagrant` today, and `afsdesktop5` had never talked to
that lab — its only two environments were real staging (`auth15.appfactory.studio`) and production
(`auth.appfactory.studio`), neither of which can validate a token the lab's own Checkpoint issues.

Per the user's explicit choice this session ("full lab mode"), then a **second**, more sweeping
instruction later the same session, this ended up as a full env-configuration redesign, not a
one-off flag:

1. First pass: a runtime `AFS_LAB` boolean switch, with `_PRODUCTION`/`_DEVELOPMENT`/`_LAB`-suffixed
   variables per URL (`CHECKPOINT_URL_PRODUCTION`, `CHECKPOINT_URL_LAB`, etc.) and `isLab ? … : …`
   branching in `src/renderer/config.ts`.
2. The user rejected that shape entirely: **one variable per concept, no per-environment suffixes,
   and no runtime branching at all** — environment selection happens by which `.env` file is
   active, the same way `w/www` already works. This is now `_env/production/.env`,
   `_env/development/.env`, `_env/vagrant/.env` (each a complete, ready-to-copy file), and the root
   `.env` is presently a copy of `_env/vagrant/.env`. `IS_PRODUCTION` survives as the one remaining
   flag, but only because `src/main/index.ts` already used it for unrelated menu logic before this
   step touched anything — no URL/host constant branches on it anymore.

Every URL/host this touches — `CHECKPOINT_URL`, `COMMUNICATIONS_URL`, `MEMBERS_URL`,
`MEMBERS_STORAGE_HOST` (Cloud sync's storage host, `src/main/cloud/config.ts` — pulled into this
refactor too once the pattern was established), `RELEASES_URL`, `ISSUES_URL` (the Help menu's two
external links) — now reads from `.env` via `webpack.config.js`'s `requireEnv()`, which fails the
build loudly if one is missing from whichever `.env` is active.

## Files

| Where | What |
|---|---|
| `src/renderer/call/types.ts`, `communicationsApi.ts`, `hostApi.ts`, `joinFlow.ts`, `session.ts` | Ported from `w/members/resources/js/conference/*` (the website reference client) essentially unchanged — same wire contract, same `w/communications` service |
| `src/renderer/call/messages.ts`, `chatMessaging.ts` | Chat-only trim of the website's `messages.ts`/`dataMessaging.ts` (same `afs.chat` topic, so desktop and website chat interoperate); reactions and raised hands stripped out — that's `05`, not this step |
| `src/renderer/call/callController.ts` | Trimmed port of the website's `callController.ts`: LiveKit `Room` lifecycle, tile snapshots, mic/camera/device switching, chat, recording indicator. Screen share, whiteboard, reactions, hand-raise removed |
| `src/renderer/components/call.ts` (new), `src/renderer/html/call.html` (new) | The view: host status/start, join-by-code with waiting room, in-call UI, host panel, chat, reconnection |
| `src/renderer/views.ts`, `src/renderer/renderer.ts`, `src/renderer/components/tools.ts`, `src/renderer/html/tools.html` | View registration, navigation wiring, Tools card ("Meetings") |
| `src/renderer/locales.json` | `tools.callDesc` and a full `call.*` block in **all four** languages (en/es/de/zh) — this app already ships real per-language text elsewhere, unlike the website's English-only precedent, so these were translated, not left in English |
| `src/main/call/index.ts` (new), `src/main/index.ts` | `powerMonitor` suspend/resume forwarding (`call:power-event`) for the reconnection flow (`01` §4) |
| `src/renderer/config.ts`, `src/main/cloud/config.ts`, `webpack.config.js`, `.env`, `_env/production/.env`, `_env/development/.env`, `_env/vagrant/.env` (new) | The env-file-based scheme (see above). `renderer.ts`, `settings.ts`, `components/cloud/index.ts` also updated — they built their own Checkpoint host from `isProduction ? API_URLS.production : API_URLS.development`, which no longer exists as a shape; they now import `CHECKPOINT_SSO_DOMAIN` directly. **`.env.example` was deliberately left untouched** — it kept disappearing from disk on its own (outside any edit made here, possibly antivirus/EDR flagging `.env*`-pattern files) and the user asked for it to be left alone; it has no runtime effect regardless (only `.env` is ever loaded) |
| `package.json`, `package-lock.json` | `livekit-client` (new runtime dependency, approved this session) and `cross-env` (new dev dependency, needed for the `build`/`build:prod`/`watch` heap-size wrapper below); new `test-call` script |
| `scripts/test_call.ts` (new) | 50 unit tests for every Electron/DOM-free piece (see Verification) |
| `.gitignore` | `.tmp-test-call/`, the new test script's standalone `tsc` output |
| `afsdesktop5/scripts/smoke_call.js` (new), `w/communications/scripts/lab-desktop-call.ts` (new), `w/communications/package.json` (`e2e:desktop` script, new) | Real end-to-end test of the guest join path against the lab — see below |

## A second problem: webpack ran out of memory

`npm run build` (which type-checks via `ts-loader` across all 4 webpack configs — main, renderer,
worker, overlay) crashed with a V8 "JavaScript heap out of memory" after `livekit-client` was added
(its own large type surface pushed total memory over Node's default heap limit on this machine). Per
the user's direction, `build`/`build:prod`/`watch` now wrap the webpack invocation in
`cross-env-shell NODE_OPTIONS='--max-old-space-size=8192'`, the same pattern `w/www/package.json`
already uses. The vagrant lab VMs were also halted for this same build to free host RAM — bring them
back up (`vagrant up` from `C:\projects\vagrant`) before testing this feature, since `w/communications`
only runs there.

## Real end-to-end verification against the lab

`npm run e2e:desktop` (new, from `w/communications`, lab up) runs the whole thing for real, following
this project's own established pattern for testing without a real member login (`compose()` +
a made-up `HostIdentity`, exactly as `lab-media.ts`/`lab-web-join.ts` already do it — "FAKE: the
host's Checkpoint login", REAL everything else):

1. **`lab-desktop-call.ts`** seeds one room/session in the real lab database for a fake host
   (`s.roomService.getOrCreatePersonalRoom` / `s.meetings.startSession`, in-process — no HTTP, no
   Checkpoint token, same technique this project already uses for every other "host" in its e2e
   scripts), then spawns `media-probe` (already existed, built for `lab-media.ts`) to connect that
   host to LiveKit for real, publishing fake camera + microphone.
2. Once the probe logs `READY_TO_LEAVE` (host genuinely connected and publishing), it spawns
   **`afsdesktop5/scripts/smoke_call.js`** — new, modeled on the existing `smoke_video_comms.js` —
   which launches the REAL, already-built `afsdesktop5` app with Playwright's Electron driver and:
   navigates Tools → Meetings through the real UI, joins by the seeded code and a display name (the
   **guest** path, which needs no login at all), and checks the result against the real DOM.
3. `lab-desktop-call.ts` cleans up the seeded room afterward, same as its siblings.

**What this proved, for real, against the real lab (three clean runs, see the script's own docstring
for exactly what's real vs. fake):** the app's `POST /v1/join` call succeeds against the deployed
service; `Room.connect()` succeeds against real LiveKit; the participant grid shows both the local
guest and the remote fake host (cross-participant visibility, not just "it didn't crash"); the local
camera track is genuinely live (`video.srcObject` has a real `MediaStreamTrack`); the mic **and**
camera both come on by default per `connect()`'s `{ mic: true, camera: true }` request, with no
error notification; the mic mute/unmute toggle works; a chat message sent from the desktop side
appears in its own chat log; the recording badge is correctly hidden when nobody is recording;
Leave returns cleanly to the landing screen.

**One finding investigated, concluded not a bug:** the first run's diagnostic pass showed the local
tile briefly labelled "🔇" (mic-off) right when the participant grid first reached two tiles. A
second run with instrumentation (full console capture, an explicit settle-wait before asserting)
showed the mic actually comes on immediately, with both tracks published successfully. Root cause:
the remote host's tile can appear the instant LiveKit fires `ParticipantConnected`, independently of
whether our *own* `setMicrophoneEnabled(true)` call (also inside `connect()`) has finished — so the
first run's assertion simply ran before that settled. Fixed in the test (wait for it to settle before
asserting, and turned the check into a permanent assertion instead of a one-off print), not in the
app, since the app's actual behavior was already correct.

**What's still not covered by this**: the *host* side of the desktop UI (starting a meeting from the
"Your room" card, waiting-room admit/deny, mute-all, end meeting) — the fake host in this test is a
`media-probe` window, not the desktop app's own UI acting as host, because a real host action through
the desktop app's HTTP calls needs a real Checkpoint-issued token, which per the standing safety rule
must be the user's own login, never mine. Two participants talking to each other in the desktop app's
own UI simultaneously (rather than one desktop guest + one probe host) is also not covered.

## Verification

- `npx tsc --noEmit -p tsconfig.json` — 0 errors across the whole app.
- `npm run build` (with the new 8 GB heap limit, lab VMs halted) — webpack 5.104.1 compiles all four
  bundles successfully; `copy_assets.js` runs cleanly.
- `npm run test-call` — 50 checks, all passing, covering:
  - `messages.ts`: `cleanChatText`, encode/decode round-trip, rejection of wrong topic/empty/oversized/
    malformed/empty-id payloads, `RateLimiter` (allow/refuse/per-key/forget).
  - `chatMessaging.ts`: send trims and rate-limits, receive drops our-own-identity and unknown-sender
    messages, delivers well-formed ones, and `peerLeft` resets that sender's incoming rate limiter.
  - `session.ts` (against a minimal in-memory `Storage` stub, since plain Node has no
    `sessionStorage`/`localStorage`): rejoin token and display name round-trip, a mismatched key
    returns `null`, `clearRejoin` clears.
  - `joinFlow.ts`: immediate admission, `JoinDenied`, waiting-room polling until admitted,
    `JoinCancelled` on an aborted wait, and `wait_timeout` after `maxWaitMs`.
  - `communicationsApi.ts`: network failure, a non-OK response's own error code, a 204 with no body,
    the `isAdmitted`/`isDenied` narrowing helpers.
  - `hostApi.ts`: a `participant` credential sends `Authorization: Participant <token>`, a `member`
    credential with no token refuses locally (never makes the request), a `member` credential sends
    `Authorization: Bearer <token>`.
  - `callController.ts`'s pure helpers: `roleOf`, `mapDisconnectReason` (every `DisconnectReason`
    case), `classifyMediaError` (every `getUserMedia` error name).
- `livekit-client` loads cleanly under plain Node (checked directly), which is why the pure-helper
  tests above can import from `callController.ts` without a real Electron/browser context.
- The `package-lock.json` diff from `npm install livekit-client` also dropped ~80 stale, unreferenced
  Express/Multer-family transitive packages (nothing in `src/` requires them) — lockfile hygiene, not
  a removal of anything this app actually uses.
- After the env-scheme redesign (single variable per concept, `_env/<name>/.env` files): re-ran
  `npx tsc --noEmit` (0 errors), `npm run test-call` (50/50, unaffected — none of those modules import
  `config.ts`), and `npm run build` (webpack 5.104.1 compiled successfully, assets copied) again from
  a clean state, confirming the redesign didn't regress anything the first pass had verified.
- `NODE_EXTRA_CA_CERTS='C:\projects\vagrant\certs\mkcert-rootCA.pem' npm run e2e:desktop` (from
  `w/communications`, lab up) — three clean runs, 14/14 checks passing each time; see "Real
  end-to-end verification against the lab" above for exactly what this proves.

## Not verified / follow-up

- **The host side of the desktop UI itself** (starting a meeting, waiting-room admit/deny, mute-all,
  end meeting) — needs the user's own Checkpoint login through the lab (`vagrant up` first if the lab
  isn't already up; `npm run e2e:desktop` from `w/communications` covers the guest side for real
  already, see above).
- A real sleep/resume reconnection (the `powerMonitor` hook) — the wiring is in place and typechecks,
  but no actual laptop sleep/resume cycle has been triggered against a live call.
- Two real participants in the desktop app's own UI at once (this session's test used one desktop
  guest + one `media-probe` host, not two desktop windows).
- Device picker labels: Chromium leaves `MediaDeviceInfo.label` blank until `getUserMedia` has been
  granted at least once, so the first time a person opens this view the pickers may show generic
  names until they've allowed the camera/microphone once.
- The website's "join as yourself" option (an authenticated member joining someone else's room with
  their own Checkpoint JWT, not as an anonymous guest) was not built — the desktop join form always
  joins as a guest with a typed display name. Small, deliberate scope cut for this pass.
- macOS and Linux are unverified (Windows only, same as `02`/`03`).
- `npm audit` was not re-run specifically for `livekit-client`; this repo already has known
  pre-existing audit findings (per `TODOS.md`/handoff conventions elsewhere in this project).

See `../../complete/01-architecture-and-media-topology.complete.md` and
`../../complete/03-signaling-and-session-model.complete.md` for the shared contract this builds
against, and `02-screen-recording.complete.md`/`03-draw-over-screen.complete.md` for the two sibling
desktop features this reuses conventions from.
