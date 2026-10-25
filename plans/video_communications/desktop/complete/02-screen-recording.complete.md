# 02 — Screen recording — completion

**Status:** Implemented (2026-09-18) — local recording works end to end on Windows. Two parts of the
plan are deliberately not done yet (§ Not implemented).

## Summary

Tools → **Screen Recorder** records a whole screen or a single window to a WebM file. Optional
system audio (Windows), optional microphone, frame rate and quality presets, countdown, pause/resume,
and a configurable save folder (default `~/Documents/afsfiles/recordings`, never overwrites an
existing file). The app window minimizes itself while recording so it is not in its own video, and
`Alt+Shift+R` stops the recording from anywhere. A recording keeps running if the user leaves the
view; the view re-attaches to it when they come back.

Local recordings are not metered by the `communications` entitlement (plan §5) — nothing to check.

## Deviations from the plan

| Plan said | What was built | Why |
|---|---|---|
| §1 capture with `getUserMedia({ chromeMediaSource: 'desktop' })` | `getDisplayMedia()` answered by `session.setDisplayMediaRequestHandler` in main, using a source the renderer pre-selects (`videocomms:prepare-capture`) | The handler is the only Electron 28 route to Windows system-audio loopback, and it lets the app use its own source picker. With no pending selection the handler denies the request, so nothing else in the app can start a capture. |
| Recording buffered then written | Each 1 s `MediaRecorder` chunk is streamed to disk over IPC, in order, with back-pressure | Long recordings never sit in renderer memory, and a crash leaves a playable partial file. |
| §6 start/stop from the system tray | Global shortcut `Alt+Shift+R` (registered only while a recording is open) + auto-minimize | No tray exists in the app yet; the shortcut covers "stop a full-screen recording without bringing the window back". |

## Not implemented (follow-up)

- **§4 remux/transcode handoff to the Media pipeline.** Recordings are saved as raw WebM (VP9/VP8 + Opus).
  The Media view has no "open this file" entry point today, so a one-click "Convert to MP4/MKV" needs
  either that entry point or a main-side call to `buildRemuxRequest` → `media:plan` → `media:start`
  (`src/main/media/remux.ts`). Note `plan.ts` re-encodes Opus to AAC for MP4.
- **Known limitation until then:** `MediaRecorder` WebM has no duration or seek cues, so some players
  show an unknown length or seek poorly. A remux fixes this.
- **§6 system tray** icon and **in-call** start/stop (needs the call client).
- **Tying a recording to a call session** as its cloud recording (needs `w/communications`).

## Files

Main process — `src/main/videoComms/`: `index.ts` (IPC + hotkey), `capture.ts` (sources, display-media
handler), `recorder.ts` (disk writer, `uniquePath`/exclusive open), `recording_files.ts` (naming),
`types.ts` (shared channels/types). Renderer — `src/renderer/videoComms/recorderSession.ts` (capture,
audio mixing, `MediaRecorder`), `src/renderer/components/videoComms.ts` + `html/videoComms.html` (view),
Tools card (`tools.html`, `tools.ts`), navigation (`renderer.ts`, `views.ts`), search entry, and
`videoComms.*` strings in all four locales. Tests — `scripts/test_video_comms.ts`,
`scripts/smoke_video_comms.js`; `package.json` scripts `test-video-comms`, `smoke-video-comms`.

## Verification

- `npx tsc --noEmit` — 0 errors; `npm run build` — all four bundles compile.
- `scripts/test_video_comms.ts` — 27 checks: file naming, never-overwrite, ordered chunk writes,
  `Uint8Array` views written by their own offset, abort keep/delete, saved-path allow-list.
  (`ts-node` is not installed in this repo, so it was compiled with `tsc` and run with `node`.)
- `scripts/smoke_video_comms.js` — drives the real Electron app with Playwright (isolated profile):
  lists sources with thumbnails, records ~4 s of the real screen, stops via the hotkey path, then decodes
  the saved file with the bundled ffmpeg: VP9, 1920x1080, frames present, saved in the chosen folder,
  window restored, hotkey released, `open-file` refuses non-recording paths.

## Not verified

macOS and Linux; system-audio loopback and microphone capture (the smoke test records video only);
recording a single *window* source; the physical `Alt+Shift+R` key press (registration and the handler
path are tested, the key press is emulated); disk-full/permission-error paths.
