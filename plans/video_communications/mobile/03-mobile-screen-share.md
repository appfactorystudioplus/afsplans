# 03 — Mobile screen share

## Completion document

**Status:** Android done, see
[`complete/03-mobile-screen-share.complete.md`](complete/03-mobile-screen-share.complete.md). iOS
deferred — needs a ReplayKit broadcast extension (a second Xcode target), not buildable on this
machine (no Xcode); tracked in [`plans/TODOS.md`](../../TODOS.md), "Video communications" §6.

Materially different problem from desktop's `desktopCapturer`
(`../desktop/02-screen-recording.md` §1) — phone OSes don't expose
an equivalent "list of windows/screens" API to an app; screen capture is a system-level,
user-confirmed broadcast of the whole device screen.

## 1. Mechanism

- **iOS**: `ReplayKit`'s `RPScreenRecorder`/broadcast extension — the standard mechanism any
  iOS app uses to publish its screen as a stream, including to a WebRTC peer connection via a
  broadcast upload extension.
- **Android**: `MediaProjection` API — a system permission dialog, then a virtual display whose
  contents can be captured as a stream and published the same way.

Both are OS-level "share my whole screen" flows with a mandatory, non-suppressible system
permission/consent prompt — there is no per-window picker like desktop's, and no way around the
user-facing consent step (this is an OS platform requirement, not a design choice available to
change).

## 2. Publishing to the call

Once captured, the stream is published to the LiveKit room as a screen-share track exactly as
on desktop (`../desktop/04-screen-share-in-call.md` §1) — the
LiveKit Flutter SDK's screen-share support wraps both platforms' native APIs behind one call, so
this plan does not need platform-specific LiveKit integration code, only the platform-specific
permission/broadcast-extension setup each OS requires to make the stream available in the first
place.

## 3. Scope note

This document is **screen share only** — there is no mobile equivalent of desktop's
`02-screen-recording.md` (save a local file) or `03-draw-over-screen.md` (system-wide overlay
drawing) in this plan, per `00` §2's non-goals. If either is wanted on mobile later, it needs
its own decision given the platform constraints noted there (particularly draw-over-screen,
which both iOS and Android restrict far more heavily than a desktop OS does).
