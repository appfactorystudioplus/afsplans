# 00 — Overview

## Completion document

When this plan step is completed, write a completion document in this directory's `complete/`
folder. Name it using this file's basename with `.complete.md` appended. Include status,
summary, files changed, verification steps, and any follow-up work.

**Status:** In progress — guest join-by-code (2026-09-21,
[`complete/01-webrtc-client.complete.md`](complete/01-webrtc-client.complete.md)), `02`'s
background-audio continuation (2026-09-21,
[`complete/02-call-ux-and-notifications.complete.md`](complete/02-call-ux-and-notifications.complete.md),
push-to-ring explicitly deferred), `03`'s Android screen share (2026-09-21,
[`complete/03-mobile-screen-share.complete.md`](complete/03-mobile-screen-share.complete.md), iOS
deferred — see `plans/TODOS.md`), `04`'s reactions/hand, whiteboard, and battery/data (2026-09-21,
[`complete/04-advanced-features.complete.md`](complete/04-advanced-features.complete.md), live
captions explicitly deferred), and `05`'s host flow (2026-09-21,
[`complete/05-delivery-plan.complete.md`](complete/05-delivery-plan.complete.md), a bounded v1
slice — recording/breakout rooms/co-host promotion excluded) are built. Native call UX's
push-to-ring, mobile-scoped recording/breakout rooms, and every device-level testing/acceptance
criterion across all five steps remain open.
**Target:** A WebRTC video-call client inside `afsmobile_flutter` — host or join a meeting
started from any of the three AFS surfaces — with mobile-native call UX (incoming-call
handling, background behavior) and mobile screen sharing.

---

## This plan's place in the larger system

This is the mobile-specific slice of a cross-platform plan. Shared architecture (SFU choice,
signaling contract, room/session model, TURN fleet, entitlements) is specified once in
[`../`](../00-overview.md)
and is not repeated here — read that tree first, specifically `01`, `03`, and `05`. This
document covers only what's specific to the Flutter/iOS/Android client.

## Document index

| Doc | Covers |
|---|---|
| `00-overview.md` (this file) | Scope, current app state, architecture fit |
| `01-webrtc-client.md` | LiveKit's official Flutter SDK, state management fit |
| `02-call-ux-and-notifications.md` | Incoming-call push, CallKit/ConnectionService, background/PiP behavior |
| `03-mobile-screen-share.md` | Screen sharing from a phone — a materially different problem from desktop |
| `04-advanced-features.md` | Mobile-appropriate subset of the desktop advanced-features list |
| `05-delivery-plan.md` | Phases, dependencies, testing |

---

## 1. Current state of `afsmobile_flutter`

**As built (2026-09-21):** `README_FEATURES.md` still describes this as a demo app (a local
`AuthBloc`/`AuthRepository` storing a username in `SharedPreferences`, plus four unrelated demo
features — calculator, calendar, notes, PDF viewer), but that description is stale. The app has
grown well past it: real feature areas exist (storage/cloud-sync, domains, email accounts,
forms, subscribers), and — the fact that matters for this plan —
**real Checkpoint authentication already exists and is code-complete**, built in
[`checkpoint-server-auth/08-mobile-client-auth.md`](../../checkpoint-server-auth/08-mobile-client-auth.md)
(done 2026-09-19; see its
[`complete/`](../../checkpoint-server-auth/complete/08-mobile-client-auth.complete.md) doc). A
real `AuthRepository` (`afsmobile_flutter/lib/repositories/auth_repository.dart`) wraps the
vendored `checkpoint_client` SDK (`afsmobile_flutter/packages/checkpoint`): real login, 2FA,
secure token storage, and primary-only enforcement (a secondary/sub-account is rejected, not
silently downgraded), matching desktop's own primary-only login guarantee. `AuthBloc`
(`afsmobile_flutter/lib/bloc/auth/`) exposes `Authenticated`/`Unauthenticated` states the rest of
the app already listens to.

**Resolved — this was flagged as a decision in an earlier draft of this document; it isn't one
anymore.** Real member auth for `afsmobile_flutter` is NOT a prerequisite this plan needs to
build: it already exists. `01` §4's "Auth prerequisite" and `05-delivery-plan.md`'s "Phase 0" are
updated to point at `checkpoint-server-auth/08-mobile-client-auth.md` instead of re-specifying
auth work — this plan can start directly on the call client (`01`), using the existing
`AuthRepository`/`AuthBloc` for the "host must be a member" check exactly the way it already
gates other member-only features in this app.

There is still no existing call, notification, or WebRTC infrastructure to build on for THIS
plan's own subject matter (unlike `afsdesktop5`, which already had Whisper and Excalidraw to
reuse) — the call client, native call UX, and screen share genuinely start from nothing.

## 2. Scope

### In

1. **Call client** — join/host via the shared signaling contract, using LiveKit's Flutter SDK
   (`01`).
2. **Native call UX** — incoming-call notification/push, CallKit (iOS) / ConnectionService
   (Android) integration so an incoming call behaves like a native phone call, not just an
   in-app banner (`02`).
3. **Background and lifecycle handling** — what happens to an active call when the app is
   backgrounded, and picture-in-picture where the platform supports it (`02`).
4. **Mobile screen share** — sharing the phone's screen into a call via each OS's own
   screen-capture API (`03`) — a different mechanism from desktop's `desktopCapturer`
   entirely.

### Out

- **Screen recording and draw-over-screen** — these were asked for specifically on desktop
  (`../desktop/`), not mobile. Mobile OS sandboxing makes a
  desktop-equivalent "overlay drawn on top of any other app" materially harder (both iOS and
  Android restrict persistent system-wide overlays far more than desktop OSes do) and it was
  not requested for this platform — out of scope unless separately asked for.
- Telephony/PSTN — out of scope per the master plan.
- Tablet-specific layout optimization — v1 targets phone form factor; tablet layout is a later
  polish pass, not a blocker.
