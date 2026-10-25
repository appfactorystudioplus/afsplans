# 05 — Advanced features — completion (incremental)

`05-advanced-features.md` lists six independent sub-features, not one thing to build; per the
user's explicit "build it all, whatever order you deem best" (2026-09-19), they're being built one
at a time, cheapest/highest-reuse first per the plan doc's own ordering, each with its own real
end-to-end verification before moving to the next. **This file is a single, running report —
extended as each sub-feature lands, not replaced.** See the status table for where things stand.

## Status

| § | Feature | Status |
|---|---|---|
| 4 | Reactions and raised hand | **Done** (2026-09-19) |
| 6 | Invite integration | **Done** (2026-09-19) |
| 2 | Meeting whiteboard (Excalidraw) | **Done** (2026-09-19) |
| 1 | Live captions / transcription (Whisper reuse) | **Deferred — user's explicit choice**, see below |
| 3 | Virtual backgrounds / blur | **Done** (2026-09-19) |
| 5 | Breakout rooms | **Done** (2026-09-20) |

### A note on §1, live captions — deferred, not abandoned

Investigated before writing anything: the native Whisper bridge IS directly reusable from the
renderer (`src/renderer/workers/transcriptionWorker.ts` already loads the `.node` addon and accepts
`postMessage`d 16 kHz mono `Float32Array` audio — `components/whisper.ts`'s existing dictation view
already resamples exactly this way from a recorded buffer, a proven pattern to mirror). But the
plan's own `afsdesktop5/plans/speech/08-live-dictation.md` describes the *proper* real-time design
as VAD-based utterance detection with a bounded ring buffer and a provisional/committed text
distinction — rated **Effort: L**, "the hardest step" in that entire plan — and it has never been
built, even for the file-transcription case. A simplified fixed-interval-chunking version for the
call feature was a real option (transcribe every ~4 s of local mic audio, broadcast the text over a
new LiveKit data topic) but would ship choppier, more awkwardly-cut captions than the plan's own
design calls for. **The user chose to skip it for now** rather than ship that simplified version,
deferring proper live captions to whenever (if ever) the VAD-based dictation system gets built
generally, and to move on to `05` §3 instead.

### A note on §5, breakout rooms — designed jointly, as the plan itself required

The plan doc said this one "should be designed jointly with the master plan before desktop-
specific work starts, not bolted on unilaterally here" (`05` §5) — not just a caution, a hard
technical fact: a working breakout room needed `w/communications` to gain sub-rooms it had no
concept of at all. That design conversation happened with the user before any code was written
(scope decisions: auto-split **with** manual override, the host can **visit** any room, **and**
an optional auto-close timer — the fuller option at every fork). The design itself is recorded in
`desktop/05-advanced-features.md` §5, not duplicated here; this section covers what was built
against that design and how it was verified. See §5 below for the full report.

---

## §4 — Reactions and raised hand (done)

### Summary

The chat/reactions/raised-hand data-message design (LiveKit data messages, ported from the
website's `w/members/resources/js/conference/messages.ts`/`dataMessaging.ts`) was deliberately
**trimmed to chat-only during `01-webrtc-client.md`**, with an explicit note that reactions and
raised hands were this step's job. This restores them, unchanged from the website's wire format
(topics `afs.reaction`/`afs.hand`), so desktop reactions/hands interoperate with the website (and
later mobile) in the same call.

- **Reactions**: a "React" button opens a small picker with the fixed 6-emoji set
  (👍 👏 ❤️ 🎉 😂 😮 — anything else is dropped on receipt, never rendered). Picking one publishes
  it (unreliable — stale after a second, no retransmission needed) and shows it floating up over
  the sender's own tile for ~2 seconds.
- **Raised hand**: a "Raise hand" button toggles a reliable, rate-limited data message. A raised
  hand is **state, not a one-off event** — a participant who joins later is told directly
  (twice, in case their data channel wasn't open yet for the first message) so latecomers still see
  who has a hand up. Shown as a small ✋ badge on the corner of that person's tile.

### Files

| Where | What |
|---|---|
| `src/renderer/call/messages.ts` | Restored `REACTIONS`/`Reaction`, `encodeReaction`/`encodeHand`/`isReaction`, and the reaction/hand branches of `decodeMessage` (previously chat-only) |
| `src/renderer/call/dataMessaging.ts` (new, replaces `chatMessaging.ts`) | The full `DataMessaging` class (chat + reactions + hands), an unchanged port of the website's `dataMessaging.ts` — `chatMessaging.ts` is deleted, superseded |
| `src/renderer/call/callController.ts` | `sendReaction()`, `setHand()`, `handUp` getter, `Tile.handRaised`, `CallHooks.onReaction`/`onHand`, wired into `startMessaging()` |
| `src/renderer/components/call.ts` | Reactions picker (built from `REACTIONS`, not hardcoded — one source of truth), `showReaction()` (the floating-emoji animation), raised-hand toggle + tile badge rendering |
| `src/renderer/html/call.html` | Reactions button + picker popover, raised-hand button, `.call-tile-hand` badge, `.call-reaction-float` CSS animation |
| `src/renderer/locales.json` | `call.raiseHand`/`call.reactions` in all four languages |
| `scripts/test_call.ts` | 18 new unit tests: encode/decode round-trips for both topics, rejecting a reaction outside the fixed set, rejecting a non-boolean `raised`, and `DataMessaging`'s reaction/hand behavior (send, receive, rate limiting inherited from the existing per-topic `RateLimiter`, a departing peer's hand lowering automatically) |
| `w/communications/scripts/lab-desktop-call.ts`, `afsdesktop5/scripts/smoke_call.js` | Extended again (not new files) — the running `01`/`04` e2e scenario now also exercises reactions and raised hand for real |

### Verification

- `npx tsc --noEmit` — 0 errors.
- `npm run test-call` — 68 checks (54 existing from `01`/`04` + 14 new), all passing.
- `npm run build` — webpack compiles successfully.
- `NODE_EXTRA_CA_CERTS='...' npm run e2e:desktop` (lab up) — **23/23 checks passing** after fixing
  one bug found along the way (below). Confirms for real: the picker lists the fixed 6-emoji set;
  sending a reaction closes the picker and floats the emoji over a tile; raising a hand shows the
  button's active state AND the tile badge; lowering it reverts both.

### A bug found and fixed — in the test, not the app

The first e2e run failed: `page.waitForSelector('#call-reactions-picker.call-hidden')` timed out.
Root cause: that selector can **never** resolve to Playwright's default wait state ("visible"),
because `.call-hidden` sets `display: none` — waiting for a hidden element to become visible is
self-contradictory. Fixed by checking `classList.contains('call-hidden')` directly via
`waitForFunction`/`.evaluate()`, matching the pattern every other hidden/shown check in
`smoke_call.js` already uses. The app's actual behavior (closing the picker on send) was correct
the whole time; only the test's assertion was wrong. Re-ran clean afterward.

### Not verified / follow-up

- Reactions/hand-raise are only exercised **locally** (the local participant's own picker, tile,
  and button) — same limitation as `01`/`04`'s reports: the "fake host" in this e2e setup is a
  `media-probe` window with no UI, so a *receiving* participant's tile update was not independently
  checked with a second real UI. `DataMessaging`'s receive-side logic is unit-tested and is an
  unchanged port of the website's own (already real-verified there), but a second real desktop
  window seeing someone else's reaction/hand is still open, same as noted for chat/screen-share.
- No visual regression check on the reaction float animation beyond "the element exists in the DOM
  for ~2 seconds" — timing/easing was not eyeballed.

---

## §6 — Invite integration (done)

### The plan's premise didn't hold — checked before building anything

`05` §6 says this is "low effort, deferred only because it depends on which of the app's existing
integrations are still active" — i.e. reuse something already there. A dedicated read-only
investigation (not just a grep) found **no** email/SMTP capability, no `mailto:` handling anywhere
in the app, no contacts/address-book feature, and no generic share-sheet/dialog to plug into
anywhere in `afsdesktop5`. The in-call `TOPIC` data-message system (`afs.chat` etc.) can't help
either — it only reaches people already in the room, not someone who hasn't joined yet. So this
isn't "wire up an existing integration"; there's nothing to wire up. Rather than build a whole new
subsystem (a real mail-sending pipeline, a contacts feature) to satisfy a premise that turned out
false, the scope was kept to the one thing that's genuinely a one-line addition and already
precedented elsewhere in this exact codebase.

### What was built

An "Email invite" button next to the existing "Copy invite link", opening the OS's own default
mail client via `shell.openExternal('mailto:...')` — the same mechanism `renderer.ts` already uses
for the Help menu's external links, just pointed at a `mailto:` URI instead of `https:`. Nothing
leaves the machine without the person hitting send themselves in their own mail client.

### Files

| Where | What |
|---|---|
| `src/renderer/call/invite.ts` (new) | `buildMailtoUrl(subject, body)` — the only part with anything to get wrong (URL encoding), kept pure/Electron-free and unit-tested |
| `src/renderer/components/call.ts` | `emailInvite()`, wired to a new button |
| `src/renderer/html/call.html` | The "Email invite" button, next to "Copy invite link" |
| `src/renderer/locales.json` | `call.emailInvite`/`inviteEmailSubject`/`inviteEmailBody` in all four languages |
| `scripts/test_call.ts` | 3 new unit tests for `buildMailtoUrl` (subject encoding, body/link encoding, special characters like `&`) |

### Verification

- `npx tsc --noEmit` — 0 errors. `npm run test-call` — 71 checks (68 + 3 new), all passing.
  `npm run build` — compiles successfully.
- `npm run e2e:desktop` (lab up) — re-ran the full existing suite to confirm no regression: still
  **23/23 checks passing**, unchanged.

### Not verified — and can't be, with this project's own safety rules

The "Email invite" button lives on the **host-only** card (`#call-host-allowed`), which only
renders for a member who is genuinely logged in and entitled to host — a state this project's
e2e setup deliberately cannot reach, because doing so needs a real Checkpoint login, and per
standing rule that must be the user's own, never mine. No e2e check was added for the button
itself; verification here is code review + the unit-tested `buildMailtoUrl` helper + a clean
build. This is the same gap already noted for every other host-side-only piece of this feature
(starting a meeting, mute-all, waiting-room admit/deny, and now this).

---

## §2 — Meeting whiteboard (done)

### Summary

A "Whiteboard" button opens a persistent, shared Excalidraw canvas — the full drawing surface
(shapes, text, multi-select), distinct from `03-draw-over-screen.md`'s transient marker-pen overlay
on top of *anything on screen*. Synced via LiveKit data messages on a dedicated `afs.wb` topic (the
same mechanism as chat), with the exact same trust model as the website's already-verified
implementation: an allow-listed set of drawing shapes/properties (no images, no links, no custom
data, bounded numbers/lengths), higher-`version`-wins merging (tied on a lower `versionNonce`, so
every client converges regardless of arrival order), per-sender rate limiting, and a host/co-host-
only "only hosts can draw" lock. A newcomer who opens the board asks the room for the current
drawing and gets it from whoever already holds it, addressed only to them.

`afsdesktop5` already ships `@excalidraw/excalidraw`/`react`/`react-dom`
(`src/renderer/components/flow/views/DrawView.tsx`), so **no new dependencies were needed** — this
was the whole reason the plan orders this feature ahead of live captions/virtual backgrounds.

### A structural difference from the website's version, and why

The website's `whiteboard.ts` loads React/Excalidraw/its stylesheet with several dynamic `import()`s
scattered through the file, so a call that never opens the whiteboard downloads none of it. Doing
the same here hit a wall: a *dynamic* `import()` of a `.css` file has no type declarations to
satisfy under this project's strict `tsc`, while the existing `DrawView.tsx` already gets away with
a *static* `import '@excalidraw/excalidraw/index.css'` (TypeScript treats static side-effect imports
more leniently than dynamic ones). Rather than fight the type system, the file was restructured:
`whiteboard.ts` itself uses plain static imports (React, ReactDOM, Excalidraw, its CSS — matching
`DrawView.tsx` exactly, including relying on the same already-working font resolution, no
`EXCALIDRAW_ASSET_PATH` override needed), and the CALL SITE (`components/call.ts`) does one dynamic
`import('../call/whiteboard')` for the whole module, only when the whiteboard button is first
clicked. Same lazy-loading outcome, reached a different way, with less code than the website's own
per-statement dynamic-import juggling.

### Files

| Where | What |
|---|---|
| `src/renderer/call/whiteboardSync.ts` (new) | Verbatim port of the website's `whiteboardSync.ts` — pure logic (sanitisation, merge rules, rate limiting, lock enforcement, newcomer catch-up), no DOM/React/LiveKit |
| `src/renderer/call/whiteboard.ts` (new) | Adapted port of the website's `whiteboard.ts` — the Excalidraw glue (see structural difference above); `Localizer` → this app's own `I18n` |
| `src/renderer/call/callController.ts` | `publishBoard()`, `onBoard`/`onPeerJoined`/`onPeerLeft` hooks, `afs.wb`-topic routing in the `DataReceived` handler — `WhiteboardSync` itself lives at the UI layer (`components/call.ts`), same split as the website's own `views/conference.ts` |
| `src/renderer/components/call.ts` | `startBoardSync()` (called from `CallController`'s `onConnected` hook — needs `localIdentity`, known once connected), `toggleWhiteboard()`, `loadWhiteboard()` (the one dynamic import) |
| `src/renderer/html/call.html` | Whiteboard toolbar button, `#call-board-host` container, `.call-board*` CSS (a large fixed overlay — Excalidraw needs real screen space, unlike the smaller share-picker modal) |
| `src/renderer/locales.json` | A nested `call.board.*` object (title, note, close, lock/unlock, banner, notices) in all four languages |
| `scripts/test_call.ts` | 25 new unit tests: `sanitizeElement`'s allow-list, `decodeBoardMessage`'s message kinds, `wins()`'s merge rule, `chunkElements()`, and `WhiteboardSync`'s send/receive/lock/newcomer-catch-up behavior |
| `w/communications/scripts/lab-desktop-call.ts`, `afsdesktop5/scripts/smoke_call.js` | Extended again — now also opens the real whiteboard, draws a real rectangle with the mouse, and closes it |

### Two real bugs found and fixed, both in the integration, not the ported logic

1. **Excalidraw's own keyboard shortcuts need real focus first.** `handleKeyboardGlobally: false`
   (deliberate — the call screen has other keyboard users: chat, name fields) means pressing 'r'
   for the rectangle tool does nothing unless Excalidraw's own canvas already has DOM focus. The
   first e2e attempt pressed the shortcut right after opening the board, with focus wherever the
   previous click left it, and drew nothing (0 elements). Fixed in the **test** by clicking into the
   canvas first to focus it, then sending the shortcut — this is also the correct real-world
   sequence (a person clicks the canvas before typing a shortcut too), so no app change was needed.
2. **The close button didn't close anything.** `whiteboard.ts`'s close button (ported unchanged)
   only calls `o.onClose()` — a *notification* hook, not the hiding mechanism itself (`close()` is a
   separate public method the caller is expected to invoke). `components/call.ts`'s `onClose`
   callback only removed the toolbar button's active CSS class and never called
   `this.boardView?.close()` — a real wiring bug in this session's own code, caught by the e2e test
   timing out waiting for the board to actually hide. Fixed by calling `boardView.close()` from the
   callback.

### Verification

- `npx tsc --noEmit` — 0 errors. `npm run test-call` — **96 checks** (71 + 25 new), all passing.
- `npm run build` — webpack compiles successfully (a visibly larger bundle now that Excalidraw is
  reachable from the call feature too, though still lazy-loaded).
- `npm run e2e:desktop` (lab up) — **28/28 checks passing** after fixing the two bugs above. Confirms
  for real: clicking "Whiteboard" downloads and mounts the actual Excalidraw editor; drawing a real
  rectangle with the mouse genuinely adds an element (not just a UI state flip); closing hides it.

### Not verified / follow-up

- **Cross-participant sync** — same standing limitation as every other feature in this report: the
  "fake host" is a `media-probe` window with no UI, so a second real client seeing the drawing, the
  "X opened the whiteboard" notice, the lock banner, or a newcomer's catch-up "ask" was not
  independently checked with two real desktop windows. `WhiteboardSync`'s logic is the website's own
  unchanged, already-verified-there implementation, and is thoroughly unit-tested here, but that is
  not the same as watching two real people draw on the same board.
- Nothing is saved — same as the website's whiteboard (per `TODOS.md`), the drawing lives only in
  memory for the session.
- Only tested with a mouse; touch/stylus input on a real tablet was not tried.

---

## §3 — Virtual backgrounds / blur (done)

### The library decision — asked, not assumed

The plan doc itself flags this as "the one advanced feature with a real 'build vs. depend on a
library' decision" and recommends evaluating a lightweight in-browser segmentation model before
considering native code. Research (before writing any code) found `@livekit/track-processors` —
LiveKit's own official package (Apache-2.0, ~615k downloads/month), purpose-built to plug into
`LocalVideoTrack.setProcessor()` (which `livekit-client`, already a dependency, exposes natively)
and wrapping MediaPipe's selfie-segmentation model under the hood. This is a materially better fit
than a generic MediaPipe wrapper — no segmentation glue code to write ourselves. Presented to the
user as two explicit decisions before installing anything (per the standing "don't install
packages without asking" rule):
1. **Install `@livekit/track-processors`?** → **Yes** (2026-09-19).
2. **Where do its wasm runtime + segmentation model live?** The library's default is to fetch
   ~9.4 MB of wasm from `cdn.jsdelivr.net` and a ~250 KB model from `storage.googleapis.com` the
   first time a person turns the feature on. The alternative is bundling both into the app so it
   works fully offline with zero third-party network calls, at the cost of ~9.7 MB added to every
   install whether the feature is used or not. **User chose: bundle locally.**

### What was built

A "Background" button in the call controls that cycles **None → Blur → Virtual background → None**
on each click (mirrors the low-friction, no-submenu style of the reactions/hand buttons). Blur uses
the library's default radius; the virtual background is one bundled image
(`assets/backgrounds/office.svg`, an original vector graphic drawn for this feature — not a
licensed photo). The effect survives muting/unmuting the microphone but is re-applied (not lost)
if the camera itself is fully turned off and back on, since LiveKit gives a freshly-disabled/
re-enabled camera a **new** `LocalVideoTrack` instance that does not carry over a processor
attached to the old one.

### Bundling locally, for real — not just "it's a checkbox"

Bundling meant solving a genuine Electron mechanics problem, not just copying files:
- **`MediaPipe`'s `FilesetResolver`/`ImageSegmenter` load their wasm runtime via a `<script>`-tag-
  style loader** (confirmed by inspecting the library's actual fetch calls, not assumed), which is
  subject to the page's CSP `script-src`, and separately **`fetch()` of a local `file://` resource
  from a `file://` page is blocked by Chromium's same-origin rules for programmatic fetch** (unlike
  the `<img src>`-style asset loads this app already relies on elsewhere) — neither problem is
  solved by simply pointing `assetPaths` at a local folder path.
- Fixed with a dedicated privileged Electron protocol, `callasset://`
  (`src/main/call/assetProtocol.ts`): registered with `standard: true, secure: true,
  supportFetchAPI: true, corsEnabled: true, bypassCSP: true` before `app.whenReady()` (Electron's
  own requirement), with a handler that reads the bundled files via `fs.readFileSync` (asar-
  transparent, no `asarUnpack` needed unlike the native-binary/font assets that need a real on-disk
  path) and returns them with `Access-Control-Allow-Origin: *`. This avoids `--allow-file-access-
  from-files`, which would have loosened file:// access for the **whole app**, not just this one
  feature.
- The wasm runtime is not fetched separately at all — `@livekit/track-processors` pulls in
  `@mediapipe/tasks-vision` as a real npm dependency, which already ships the wasm files inside its
  own package (`node_modules/@mediapipe/tasks-vision/wasm/`), so `npm install` is the only
  "download". Only the ~250 KB model file needed a one-time fetch from its (Google-hosted) default
  URL to commit into `assets/mediapipe/selfie_segmenter.tflite` — the exact same file, from the
  exact same source, that a "use the CDN" build would have fetched at runtime instead; bundling
  just moves that one fetch from runtime to build time.
- `scripts/copy_assets.js` (the existing pattern used for native binaries and watermark fonts,
  see `src/main/images/fonts.ts`) gained a new section copying both into `dist/main/mediapipe/`.
- The one built-in virtual-background image (`assets/backgrounds/office.svg`) is loaded by the
  library via `new Image()`, not `fetch()` — confirmed by reading the library's source — so it did
  **not** need the `callasset://` treatment; it ships as an ordinary webpack asset (added `svg` to
  the existing `png|jpg|jpeg|gif` asset-resource rule) at a same-origin relative URL, same as
  every other bundled image in this app.

### Files

| Where | What |
|---|---|
| `src/main/call/assetProtocol.ts` (new) | The `callasset://` privileged protocol: registration + `fs`-backed handler, dev/packaged path resolution mirroring `src/main/images/fonts.ts` |
| `src/main/index.ts` | Registers the scheme's privileges at module load (before `app.whenReady()`) and the handler inside it, alongside `registerCallIpc()` |
| `src/renderer/call/background.ts` (new) | Pure mapping from this app's three-way toggle to `@livekit/track-processors`' own option shapes, and the `callasset://` asset paths — kept Electron/browser-free so it's unit-testable in plain Node |
| `src/renderer/call/callController.ts` | `setBackgroundMode()`, `backgroundMode` getter, `reapplyBackground()` (re-applies on a freshly-published camera track), wired to `RoomEvent.LocalTrackPublished` |
| `src/renderer/components/call.ts` | `toggleBackground()`/`renderBackgroundButton()`, cycling the three-way toggle |
| `src/renderer/html/call.html` | The "Background" button |
| `src/renderer/locales.json` | A nested `call.background.*` object (button, none/blur/image labels, unsupported, failed) in all four languages |
| `src/assets/backgrounds/office.svg` (new) | The one bundled virtual-background image (original vector art) |
| `assets/mediapipe/selfie_segmenter.tflite` (new) | The bundled segmentation model, committed to source control like `assets/fonts/*.ttf` |
| `scripts/copy_assets.js` | Copies the model and `@mediapipe/tasks-vision`'s wasm files into `dist/main/mediapipe/` |
| `webpack.config.js` | Added `svg` to the image asset-resource rule |
| `package.json` | New dependency `@livekit/track-processors`; new dev dependency `@types/dom-mediacapture-transform` (its peer type declarations) |
| `scripts/test_call.ts` | 4 new unit tests for `background.ts`'s pure mapping functions |
| `w/communications/scripts/lab-desktop-call.ts`, `afsdesktop5/scripts/smoke_call.js` | Extended again — cycles the real button through blur → virtual background → off, checks the label and camera tile at each step, and (new) asserts **zero new console/page errors** during the whole sequence |

### Two real bugs found and fixed — both in this session's own integration code, caught by the real e2e run, not by the (initially too-shallow) assertions

The first e2e run reported all checks green on the button's label/CSS-class state, but the console
showed the feature was silently broken underneath:
1. **`assetPaths` was flattened instead of nested.** `BackgroundProcessorOptions.assetPaths` is a
   nested object (`{ assetPaths: { tasksVisionFileSet, modelAssetPath } }`); `background.ts`
   originally spread `{ tasksVisionFileSet, modelAssetPath }` as top-level siblings, which the
   library silently ignores — so it fell through to its **default CDN URLs** and got blocked by
   this app's CSP (`Refused to load the script '...cdn.jsdelivr.net...'`), the opposite of what
   "bundle locally" was supposed to guarantee. Fixed by nesting correctly in `backgroundAssetPaths()`.
2. **The `callasset://` handler double-joined the path.** After fixing (1), requests hit
   `callasset://mediapipe/wasm/...` and got a 404: the handler was prepending the URL's *hostname*
   ("mediapipe") onto the already-`mediapipe`-named asset directory, producing
   `dist/main/mediapipe/mediapipe/wasm/...`. Fixed by resolving only from `url.pathname`, treating
   the hostname as this scheme's own label, not a path segment.
   A third, smaller issue in the bundled asset itself: `office.svg` had no `width`/`height`
   attributes (only a `viewBox`), which made `createImageBitmap()` throw ("SVG image without
   intrinsic dimensions") when the library tried to load it as the virtual background — fixed by
   adding explicit `width="1920" height="1080"`.

The smoke test's own assertions were tightened afterward (see "Files" above) to assert zero new
console/page errors during this section, not just that a button's label changed — the same class of
false-positive risk flagged in this project's own gotchas (a UI state flip passing while the real
feature fails underneath).

### Verification

- `npx tsc --noEmit` — 0 errors. `npm run test-call` — **100 checks** (96 + 4 new), all passing.
- `npm run build` — webpack compiles successfully; `copy_assets.js` confirmed copying the model and
  all four wasm/js files into `dist/main/mediapipe/`.
- `NODE_EXTRA_CA_CERTS='...' npm run e2e:desktop` (lab up) — **32/32 checks passing** after fixing
  the two bugs above. Confirms for real, against the actual bundled files (not mocked): turning on
  blur genuinely loads the segmentation model over `callasset://` and activates it (not just a CSS
  class); switching to the virtual background image updates correctly and reuses the already-loaded
  segmenter (`switchTo()`, no reload); the camera tile keeps playing throughout; cycling back to
  "no effect" turns it off cleanly; and — the check added specifically because the first two bugs
  would otherwise have gone unnoticed — no console or page errors occur anywhere in the sequence
  (one upstream library teardown message, `@livekit/track-processors`' own benign "Stream closed"
  race in `stopProcessor()`, is explicitly filtered as a known, harmless, non-app bug — see the
  comment in `smoke_call.js`).
- This also proves WebGL2 + `OffscreenCanvas` + `VideoFrame` (`ProcessorWrapper`'s hard
  requirements) are genuinely available in the lab VM's Electron/Chromium, not just assumed.

### Not verified / follow-up

- **Visual quality was not eyeballed** — the e2e check confirms the pipeline runs (model loads,
  processor activates, camera keeps playing, no errors) but not that the blur/segmentation *looks*
  good with the fake camera's synthetic test pattern (`--use-fake-device-for-media-stream`), which
  is not a real face and may segment oddly. Worth a manual look with a real webcam before shipping.
- Only one built-in virtual-background image — no user-supplied custom image upload, which the plan
  doc doesn't ask for either.
- Not tested on a machine without a capable GPU (`supportsBackgroundProcessors()` returning false
  is handled — `setBackgroundMode` returns `'unsupported'` and the UI notifies rather than breaking
  — but that path itself has only been read-reviewed, not exercised, since the lab VM's Electron
  does support it).
- Cross-participant: like every other `05` feature, this only proves the local participant's own
  camera gets the effect; a remote viewer seeing our blurred/replaced background was not checked
  with a second real client (there is nothing to "receive" here — the effect is applied before the
  frame is ever published — but visually confirming a peer sees the same altered video was not done).

---

## §5 — Breakout rooms (done)

### Summary

A host can split an active meeting's participants into N breakout rooms, each its own real
LiveKit room. Scope, decided with the user before any code was written (the fuller option at
every fork): **auto-split with manual override** (a `<select>` per participant, not literal
drag-and-drop — this codebase has no DnD anywhere), the **host can visit any room** on demand
without leaving the main one, and an **optional auto-close timer** alongside manual "close now".
The full design — the data-model decision (a dedicated table, not another session row) and why,
the delivery mechanism (a server-pushed LiveKit data message, no new WebSocket), and the backend
surface — is written up in `desktop/05-advanced-features.md` §5 itself, not duplicated here.

### Delivery mechanism, in one sentence

`w/communications` pushes a `{kind:'moved', destination}` message on the `afs.breakout` LiveKit
data topic directly into whichever room a participant is currently connected to (via
`livekit-server-sdk`'s `RoomServiceClient.sendData()`, which needs no connection of its own), and
the desktop client's `CallController` reacts to it immediately by disconnecting its `Room` and
reconnecting to the one named in the message — the exact same mechanism used for the "return to
main" push when the host closes the rooms, and for the recovery path when a sleep/rejoin lands a
participant back in main after they had already been moved to a room.

### Files

| Where | What |
|---|---|
| `w/www/database/migrations/2026_09_20_000001_create_members_communications_breakout_rooms_table.php` (new) | `members_communications_breakout_rooms` table + `current_breakout_room_id` on participants — a genuinely new, separate migration file (never an edit to the original create-tables migration), applied with a plain `php artisan migrate --force`, never `migrate:fresh` |
| `w/communications/src/domain/types.ts`, `src/domain/breakoutAssignment.ts` (new) | `BreakoutRoom` type, `Participant.currentBreakoutRoomId`; the pure `splitParticipants()` auto-split/manual-override algorithm |
| `w/communications/src/ports.ts`, `src/adapters/livekitGateway.ts`, `src/adapters/mysqlRepositories.ts` | `BreakoutRoomRepository` + `ParticipantRepository` breakout methods; `LiveKitGateway.sendData()` (new — the server-push mechanism); `MysqlBreakoutRoomRepository` (new) |
| `w/communications/src/services/meetingService.ts` | `startBreakouts`, `listBreakouts`, `reassignParticipant`, `broadcastToBreakouts`, `closeBreakouts`, `joinBreakoutAsHost`, `enforceBreakoutDeadlines` (folded into the existing duration-cap sweep interval in `index.ts`), and `JoinPayload.activeBreakout` (the rejoin recovery field) |
| `w/communications/src/http/app.ts` | Six new routes under `/v1/sessions/:sessionId/breakouts*` |
| `afsdesktop5/src/renderer/call/breakoutMessages.ts` (new) | The `afs.breakout` wire format — `BreakoutDestination`, `BreakoutSignal`, `decodeBreakoutSignal()` |
| `afsdesktop5/src/renderer/call/callController.ts` | `attachRoom()` (factored out of `connect()`), `moveToRoom()`, `connectDirect()` (for the host's visit connection), the `BREAKOUT_TOPIC` dispatch branch, `onBreakout`/`onRoomChanged` hooks |
| `afsdesktop5/src/renderer/call/hostApi.ts`, `call/types.ts` | Six new HTTP client methods; `AdmittedPayload.active_breakout` |
| `afsdesktop5/src/renderer/components/call.ts`, `html/call.html`, `locales.json` | The host breakout panel (start/list/reassign/broadcast/close/visit), the participant-facing "you're in Room N" banner, a **second, independent `CallController`** for the host's "visit" connection (see below) |
| `w/communications/scripts/media-probe/probe.js` | Opt-in (`p.breakoutAware`) `afs.breakout` listener, so a fake e2e participant can prove the server's push is actually actionable, not just sent |
| `w/communications/scripts/lab-breakouts.ts`, `afsdesktop5/scripts/smoke_breakout.js` (new) | The real e2e scenario — see Verification |

### Why the host's "visit" needs a second connection, not a reused one

While breakouts are running, the main LiveKit room typically holds only the host. If "visit"
reused the host's one connection (disconnect from main, connect to the breakout, same mechanism
as an ordinary participant's move), the main room would sit empty long enough for LiveKit's
configured `emptyTimeoutSeconds` to tear it down, and the host would lose the ability to
broadcast/close-breakouts/end-meeting while "away" — those all target the main session by
identity/role, which requires staying connected to it. `CallManager` therefore keeps its always-
connected primary `CallController` and adds a second, independent one (own hidden audio sink)
for a visit, muting the host's own mic/camera on the primary connection first so they do not
transmit into two rooms at once, restored when the visit ends.

### Four real bugs found and fixed — all via the real e2e run, none by inspection alone

1. **A genuine race condition in `startBreakouts`.** The "are breakouts already running?" check
   and the room-creation loop below it were not atomic — caught for real when a bug in the *test
   harness* (next item) caused two near-simultaneous calls to `startBreakouts` for the same
   session, both passing the check, then racing to insert the same deterministic room names
   (`${session.livekitRoomName}-bN`). The loser hit the table's own unique constraint and threw a
   raw MySQL error instead of a clean rejection. Fixed in `meetingService.ts`: the room-creation
   loop is now wrapped so a failure rolls back whatever THIS call created and re-checks whether
   rooms are open now — if so, some other call won the race and this one gets the same clean
   `validation_error` the check above would have given it with better timing; anything else is
   rethrown as a genuine unexpected error. This is a real, plausible production scenario (a host
   double-clicking "Start"), not just a test artifact — covered by a new unit test that simulates
   the race directly.
2. **A silently-vacuous pre-existing test check, discovered as a side effect.** Both the new
   `smoke_breakout.js` and the existing `smoke_call.js` read notification text via
   `document.getElementById('notifications-list')?.innerText`. `#notifications-list` sits inside
   `#notifications-popover`, hidden (`class="hidden"`) until the bell icon is clicked, and
   `innerText` is layout-aware — it returns `""` for a `display:none` ancestor regardless of what
   HTML was injected. `smoke_call.js`'s existing check (`!/problem/i.test(notifications)`) had
   therefore **never actually verified anything** — it was checking an always-empty string and
   could only ever pass. This only became visible once a check needed the text to be *present*
   (the breakout broadcast). Fixed in both files by switching to `textContent`, which reads real
   DOM content regardless of layout/visibility.
3. **A test-marker substring collision causing a real duplicate invocation.** `smoke_breakout.js`
   printed `READY_FOR_BREAKOUT` followed immediately by a log line that also happened to contain
   the same text (`"signaled READY_FOR_BREAKOUT, waiting..."`). The orchestrator matches the
   marker with a plain substring test (mirroring `media-probe`'s own `READY_TO_LEAVE` convention),
   so both lines fired its callback, invoking `startBreakouts` twice concurrently — this is what
   exposed bug 1. Fixed by rewording the follow-up log line so it does not contain the marker text.
4. **`media-probe`'s process-level watchdog killed the probe before it finished.**
   `media-probe/main.cjs` force-exits with an errors-only result after `PROBE_TIMEOUT_MS`
   (default 90s) regardless of the config's own `holdMs`. This script's `HOLD_MS` (100s) plus its
   setup/connect overhead already exceeded that default, so the first clean run (after bugs 1–3
   were fixed) still failed with `probe.connected` undefined — the watchdog fired mid-sequence.
   Fixed by passing an explicit `PROBE_TIMEOUT_MS` (`HOLD_MS + 30s`) when spawning the probe,
   rather than trying to shrink the choreography to fit an unrelated default.

### Verification

- `npx tsc` (both `w/communications` and `afsdesktop5`) — 0 errors. Both `npm run build` — clean.
- `w/communications`: **238/238 tests passing** (188 pre-existing + 49 from §3's own work +
  1 new, for the race-condition fix above) — `breakoutAssignment.test.ts` (8, the pure split
  algorithm), `breakouts.test.ts` (31, service-level: starting/listing/reassigning/broadcasting/
  closing/the deadline sweep/the host visit/the `activeBreakout` recovery field/the race), and
  `breakoutsHttp.test.ts` (11, route wiring and validation).
- `afsdesktop5`: `npm run test-call` — **111 checks** (100 pre-existing + 11 new, for
  `breakoutMessages.ts`'s `decodeBreakoutSignal`), all passing.
- `NODE_EXTRA_CA_CERTS='...' npm run e2e:breakouts` (lab up) — **every check passing**, for real,
  after fixing the four bugs above. Confirms, against the actual real lab (not mocked): the
  backend genuinely splits admitted guests across distinct LiveKit rooms (verified both via the
  API's own response AND a direct database query, so "the split landed" isn't just trusting the
  API's own claim); the real, built desktop app's `CallController.moveToRoom()` reconnect
  actually happens (the breakout banner appears, naming the room); a real host broadcast reaches
  that real client as an actual visible notification; closing the rooms genuinely pushes the
  client back to main (banner clears, grid re-renders for the main room again); and — checked
  independently of the desktop client, via a second real LiveKit participant
  (`media-probe`'s new `breakoutAware` mode) — the server's push is provably *actionable* by a
  real client, not merely sent: that participant's own `RoomEvent.DataReceived` listener
  disconnects and reconnects on receipt, recorded as real `breakoutMoves`/`breakoutBroadcasts` in
  its own result object, independently confirming both the move into a room and the move back to
  main. Zero console/page errors throughout the whole sequence on the real desktop client.

### Not verified / follow-up

- **The host's "visit a room" feature has no e2e coverage** — `joinBreakoutAsHost` is unit- and
  HTTP-tested, and the desktop `visitBreakoutRoom()`/`leaveVisit()` code paths are type-checked
  and code-reviewed, but the real e2e run only exercises the participant side (move/broadcast/
  close). Visiting needs a THIRD real LiveKit connection in the same test (the host's own second
  `CallController`) to verify for real, which this run does not attempt.
- **Manual reassignment (the `<select>` UI) is unit/HTTP-tested on the backend but has no desktop
  e2e coverage** — the real run only exercises the auto-split path.
- **The auto-close timer's real-time behavior is not e2e-tested** — `enforceBreakoutDeadlines()`
  is unit-tested with an injected fake clock (forcing `close_deadline_at` into the past), the same
  technique the existing duration-cap sweep test already uses, but no real e2e run waits out an
  actual timer in real wall-clock time.
- **Only one non-host participant's client is real** in the e2e run (the rest is one real host —
  `media-probe`, no UI — plus one breakout-aware fake guest); a THIRD real desktop client, or two
  real desktop clients in the same breakout room together, was not attempted.
- Mirrors §2/§3's own standing note: this proves the mechanism works for the participants
  actually connected during the test, not every possible room-count/participant-count
  combination (e.g. `count` near the 50 maximum, or an auto-split with a very uneven remainder).
