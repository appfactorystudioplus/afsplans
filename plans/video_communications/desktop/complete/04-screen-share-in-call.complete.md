# 04 — Screen share in a call — completion

**Status:** Implemented and verified (2026-09-19) by typecheck, a clean webpack build, 54 unit
tests, and a real end-to-end run against the lab — sharing a real screen/window from the actual
built `afsdesktop5` app into a real LiveKit call.

## Summary

The call toolbar (`01`'s participant controls row) gets a **Share screen** button. Clicking it opens
a source picker — real screens and windows, with thumbnails, using the exact same
`desktopCapturer`/`prepareCapture` machinery `02-screen-recording.md` already built and installed
session-wide. Picking one publishes it to the room as a `Track.Source.ScreenShare` track via
LiveKit's `setScreenShareEnabled`; the button shows an active state and a one-click "Stop sharing".
Whoever else is in the call sees a prominent "someone is sharing" view above the participant grid
(only one share is the "main" view at a time, per plan §4 — a second person sharing would need to
wait or take over, not shown simultaneously in this v1).

Per the plan's §2 and §3, no code was needed for the two "interaction with..." sections:
- **Draw-over-screen** (`03`): the overlay draws real pixels onto the actual screen, so ink is
  already part of whatever `desktopCapturer` captures — sharing and recording pick it up identically,
  automatically, with the same single-window-capture caveat `03` already documents.
- **Recording** (`02`, and the call's own cloud recording): screen share publishes a LiveKit track;
  local recording captures the screen independently via `MediaRecorder`. Two unrelated pipelines that
  happen to share a capture primitive — nothing stops both running at once.

## The key technical fact this step depends on

Electron's `getDisplayMedia()` has no browser-style picker: `02-screen-recording.md` already
installed `session.defaultSession.setDisplayMediaRequestHandler` **for the whole app**, which denies
any `getDisplayMedia()` call unless the renderer first told main which source to hand back
(`videocomms:prepare-capture`). This is true regardless of *who* calls `getDisplayMedia()` — including
LiveKit's own `setScreenShareEnabled`, which calls it internally. So the sequence in
`components/call.ts` is: list sources (`VC.getSources`) → show this feature's own picker → on pick,
`VC.prepareCapture` → *then* `controller.setScreenShare(true)`. Skipping the `prepareCapture` step
would make LiveKit's internal `getDisplayMedia()` call get silently refused. No main-process code
was needed — `02`'s handler and IPC channels are reused exactly as they already existed.

## Files

| Where | What |
|---|---|
| `src/renderer/call/callController.ts` | Ported back the screen-share pieces trimmed out of the website's `callController.ts` during `01`: `ScreenShare`, `ScreenShareProblem`, `screenShareSupported()`, `classifyScreenShareError()`, `CallSnapshot.screens`, `setScreenShare()`, `screensOf()` |
| `src/renderer/components/call.ts` | `toggleScreenShare()`, the source-picker flow (`VC.getSources` → `VC.prepareCapture` → `setScreenShare(true)`), and `renderShareView()` (the "main shared view", attach/detach diffing same pattern as the tile grid) |
| `src/renderer/html/call.html` | Share button, the shared-view `<video>` + label, and a modal source-picker panel (`.call-share-tile` grid, cribbed from the Screen Recorder's own tile styling) |
| `src/renderer/locales.json` | `call.shareScreen`/`stopSharing`/`pickSource`/`cancel`/`shareFailed`/`shareUnsupported`/`someoneSharing`/`youAreSharing` in all four languages |
| `scripts/test_call.ts` | 4 new unit tests for `classifyScreenShareError` |
| `w/communications/scripts/lab-desktop-call.ts`, `afsdesktop5/scripts/smoke_call.js` | Extended (not new files) — the existing `01` e2e infrastructure now also drives the real share/stop-share flow |

No main-process files changed; no new IPC channels; no new dependencies.

## Verification

- `npx tsc --noEmit -p tsconfig.json` — 0 errors.
- `npm run test-call` — 54 checks (50 from `01` + 4 new), all passing.
- `npm run build` — webpack 5.104.1 compiles successfully; assets copied.
- `NODE_EXTRA_CA_CERTS='...' npm run e2e:desktop` (from `w/communications`, lab up) — the existing
  `01` end-to-end scenario (fake host seeded via `compose()`/`asHost`, connected to real LiveKit via
  `media-probe`, the real built app joining as guest) now also exercises, for real:
  - the picker lists real capturable sources from the actual machine (4 found: this host's
    screen(s)/window(s), thumbnails included);
  - clicking one makes the share button show its active state (proving the IPC round trip and
    LiveKit's `setScreenShareEnabled` both actually worked, not just that a UI class got toggled by
    hand);
  - the "You are sharing" view appears while sharing;
  - clicking again stops the share and both revert correctly.
  - 18/18 checks passing, one clean run.

## Not verified / follow-up

- **The receiving side's rendering of a remote share** — this session's test only has the local
  desktop app do the sharing; the "fake host" is a `media-probe` window (no UI to check a shared
  view on). `screensOf()`'s remote-share attach logic is the same code pattern already exercised for
  camera tiles and was code-reviewed, but not independently re-verified with a second real UI. Two
  desktop windows sharing/watching each other is the natural next check, same follow-up already
  noted in `01`'s report for two-window calls generally.
- Audio-with-screen-share (sharing a video with sound) was deliberately left off in v1
  (`setScreenShareEnabled(on, { audio: false, ... })`) — the plan doesn't ask for it and Windows
  system-audio loopback adds real complexity (`02`'s own report notes the same trade-off for
  recording). `prepareCapture`'s `systemAudio` flag already supports it if this is wanted later; only
  the call-side option needs adding.
- Simultaneous multi-share (more than one person's screen visible at once) is explicitly out of `04`
  v1 per the plan and was not built.
- macOS and Linux are unverified (Windows only, same as every other desktop step so far).

See `01-webrtc-client.complete.md` for the call client this builds on, and
`02-screen-recording.complete.md` for the capture infrastructure this reuses.
