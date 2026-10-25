# 00 — Overview

## Completion document

When this plan step is completed, write a completion document in this directory's `complete/`
folder. Name it using this file's basename with `.complete.md` appended. Include status,
summary, files changed, verification steps, and any follow-up work.

## Code documentation

Document all new and changed code thoroughly using `/** */` block comments (JSDoc for
TypeScript/JavaScript; Doxygen-style for C++ if any native code is touched — this plan is
expected to need none, see §3). Inline `//` comments are for brief local clarification only.

**Status (2026-09-19):** Partly implemented. `02` (screen recording) and `03` (draw-over-screen) are built, see `complete/`.
**`01` (the WebRTC call client) is not started**, so the desktop app cannot join a call yet, and `02`/`03` are the parts that
do not need one. `04`–`06` are not started.
**Target:** Turn `afsdesktop5` into a full WebRTC video-call client — host or join a meeting
started from any of the three AFS surfaces — plus two desktop-only capabilities on top: screen
recording, and drawing over the screen (including over other applications) while recording or
while in a call.

---

## This plan's place in the larger system

This is the desktop-specific slice of a cross-platform plan. The shared architecture — SFU
choice, signaling contract, room/session model, TURN fleet, entitlements — is specified once in
[`packages/afsserver/plans/video_communications/`](../00-overview.md)
and is not repeated here. Read that tree first, specifically:

- `01-architecture-and-media-topology.md` — LiveKit client model this plan's call UI is built against.
- `03-signaling-and-session-model.md` — room/session/role model, reconnection.
- `05-feature-tiers-and-entitlements.md` — the `communications` entitlement gating hosting,
  participant caps, duration caps, and cloud recording.

This document covers only what's specific to the Electron/Windows-Mac-Linux desktop client.

## Document index

| Doc | Covers |
|---|---|
| `00-overview.md` (this file) | Scope, architecture fit, non-goals |
| `01-webrtc-client.md` | Call UI — LiveKit's JS client inside the Electron renderer |
| `02-screen-recording.md` | `desktopCapturer` + `MediaRecorder`, output handling via the vendored FFmpeg |
| `03-draw-over-screen.md` | Transparent always-on-top overlay window, click-through toggling, multi-monitor |
| `04-screen-share-in-call.md` | Sharing a screen/window *into* a live call — distinct feature from recording |
| `05-advanced-features.md` | Live captions (Whisper reuse), virtual backgrounds, meeting whiteboard (Excalidraw reuse), and more |
| `06-delivery-plan.md` | Phases, file manifest, testing, acceptance |

---

## 1. Scope

### In

1. **Call client** — join/host a meeting via the shared signaling contract; camera/mic
   selection, mute/video toggle, participant grid, chat, reactions (`01`).
2. **Screen recording** — record the desktop (or a window) to a local file, independent of
   being in a call (`02`).
3. **Draw over screen** — a transparent, always-on-top annotation layer that draws over
   whatever is on screen, including other applications and browsers, toggleable between
   click-through and draw mode, capturable by the screen recorder or shared into a live call
   (`03`).
4. **Screen share in a call** — present a screen/window/app-window to other call participants,
   separate code path from recording though they share the same `desktopCapturer` primitive
   (`04`).
5. **Advanced features** building on infrastructure this app already has —
   Whisper (`src/native/whisper_bridge.*`) for live captions, Excalidraw (already a dependency,
   already used in `src/renderer/components/flow/views/DrawView.tsx`) for a persistent meeting
   whiteboard distinct from the transient screen-draw overlay (`05`).

### Out

- Non-linear video editing of recordings — that's the existing
  [Audio/Video plan](../../../../../afsdesktop5/plans/audio_video/00-overview.md)'s job; a finished recording is just another
  file a member can open there.
- Live capture via FFmpeg's `avdevice` — remains deliberately disabled
  (`plans/audio_video/02-codecs-and-patents.md` §4.4). Screen recording and screen share in
  this plan use Electron's own `desktopCapturer`/`getDisplayMedia`, which does not touch
  `avdevice` at all — the two are unrelated capture paths, and this plan does not reopen that
  decision.
- Telephony/PSTN — out of scope per the master plan's non-goals.

---

## 2. Why this reuses more of the existing app than a typical new feature

Three pieces of infrastructure already in `afsdesktop5` are directly reusable, which changes
the effort estimate for "advanced features" from "build from scratch" to "wire together":

| Need | Already exists as | Reused in |
|---|---|---|
| Speech-to-text | `src/native/whisper_bridge.{cpp,h}`, shipped for the Speech plan | `05` — live captions/transcription during a call |
| Freeform drawing canvas | `@excalidraw/excalidraw` (dependency), `src/renderer/components/flow/views/DrawView.tsx` (working usage) | `05` — persistent meeting whiteboard (distinct from `03`'s screen-draw overlay — see `05` §1 for why these are two different features, not one) |
| Video/audio encode + remux | Vendored FFmpeg (`vendor/ffmpeg-dist/`), `src/native/media_bridge.*` | `02` — turning `MediaRecorder`'s WebM output into whatever container/codec the member wants, reusing the Audio/Video plan's pipeline wholesale rather than building a second one |

## 3. Why no new native (C++) addon is needed

Screen capture (`desktopCapturer`), screen recording (`MediaRecorder`), the draw-over-screen
overlay (a transparent `BrowserWindow`), and the WebRTC call itself (LiveKit's JS client, using
Chromium's built-in WebRTC stack) are all renderer/main-process JavaScript/TypeScript using
Electron and Chromium APIs directly — none require new C++. The only touch point with existing
native code is optional and additive: piping a finished recording through
`src/native/media_bridge.*` for remux/transcode (`02`), and optionally through
`whisper_bridge` for captions (`05`) — both existing, both already proven in this codebase.
