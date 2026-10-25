# 02 — Screen recording

## 1. Capture mechanism

Electron's `desktopCapturer` (main process, exposed to the renderer via a preload bridge, same
IPC-boundary pattern the app already uses for `src/main/native/addon.ts`) enumerates available
screens and windows. The renderer requests a `MediaStream` for the chosen source via
`navigator.mediaDevices.getUserMedia({ audio: false, video: { mandatory: { chromeMediaSource: 'desktop', chromeMediaSourceId: sourceId } } })`,
then records it with the standard `MediaRecorder` API to WebM (VP8/VP9 + Opus if system audio
is captured — see §3).

This is a completely different code path from FFmpeg's `avdevice`, and does not reopen the
`--disable-avdevice` decision recorded in
[`plans/audio_video/02-codecs-and-patents.md`](../../../../../afsdesktop5/plans/audio_video/02-codecs-and-patents.md) §4.4 —
that decision is about FFmpeg capturing from OS devices directly; `desktopCapturer` is Chromium
capturing its own compositor output, entirely inside Electron, before FFmpeg ever sees a frame.

## 2. What can be recorded

- **Full screen** (per-monitor — a multi-monitor picker, not a single combined canvas).
- **A single window or application**, using the same `desktopCapturer` source list.
- **Screen + the draw-over-screen overlay (`03`)**, automatically — because the overlay is a
  real, visible, composited window, it appears in the captured frames with no special-case
  compositing code needed. This is the single biggest reason draw-over-screen and screen
  recording are planned together: the overlay approach makes "recording with live annotations"
  fall out of the architecture for free rather than needing a separate ink-compositing pipeline.

## 3. Audio

`MediaRecorder` on its own only gets the video track from `desktopCapturer`; system audio
capture varies by OS (Windows/macOS both support looping back system audio into
`desktopCapturer`'s audio constraint in recent Electron/Chromium; Linux support is weaker and
should be treated as best-effort). Microphone audio (for narration while recording) is a
second, separate `getUserMedia({ audio: true })` stream, mixed with system audio via the Web
Audio API (`AudioContext` + a `MediaStreamDestination`) before being handed to `MediaRecorder`
— standard technique, no native code required.

## 4. Output handling — reuse the Audio/Video pipeline, don't build a second one

`MediaRecorder` produces WebM. Per
[`plans/audio_video/00-overview.md`](../../../../../afsdesktop5/plans/audio_video/00-overview.md) §3's reuse table, this plan
pipes the finished recording through the **same** `media_bridge`/FFmpeg remux-or-transcode path
already being built there, rather than adding a second video-writing pipeline to the codebase:

- **Remux only** (fast, lossless) if the member wants to keep VP8/VP9+Opus in WebM or MKV.
- **Transcode** to MP4/H.264 if the member wants broader compatibility — same codec/container
  matrix and same "stream copy whenever possible" rule as the Audio/Video plan
  (`plans/audio_video/00-overview.md` §4).

Recordings taken **during a call** (as opposed to a standalone screen recording outside any
call) additionally have the option to be uploaded as the session's official cloud recording
(`../04-database-schema.md` §2,
`../05-feature-tiers-and-entitlements.md` §5's `cloud_recording` tier
gate) — a standalone recording made with no active call is always purely local and never
touches that entitlement check.

## 5. Storage and entitlement

A **local-only** recording (not tied to a call session) is subject to no `communications`
entitlement check at all — recording your own screen is not a metered feature in this plan. A
recording explicitly saved as a **call's cloud recording** consumes the member's existing
`storage_files_size_kb` allocation exactly as any other file would
(`../04-database-schema.md` §2's `storage_ref` design) — no new storage
metering is introduced.

## 6. UI

- Start/stop from the system tray and from within the call UI (`01`) when recording a session.
- A recording-in-progress indicator consistent with the one specified in
  `../06-security.md` §5 for call recordings, and with the app's
  existing notification patterns (`src/renderer/notifications.ts`) for standalone recordings.
- Source picker (full screen / specific window) reusing Electron's native thumbnail previews
  from `desktopCapturer.getSources()`.
