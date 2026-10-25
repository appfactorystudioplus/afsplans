# 02 — Call UX and notifications

## Completion document

**Status:** Partial — see
[`complete/02-call-ux-and-notifications.complete.md`](complete/02-call-ux-and-notifications.complete.md).
§1 (push-to-ring) explicitly out of scope for v1, decided below. §2's background-audio
continuation and persistent notification are built (Android via a foreground service, iOS via
`UIBackgroundModes`); picture-in-picture is not (the doc below already called it v2). §3
(contextual permissions) turned out to already be satisfied by the existing join flow — no new
code needed, see the completion doc for why.

## 1. Incoming-call handling

**Decided (2026-09-21): out of scope for v1.** Mobile stays join-by-code/link only, matching
`01`'s already-built scope — no push-to-ring, no CallKit/ConnectionService, no new signaling
event. This app has no push infrastructure at all today (no FCM/APNs setup of any kind), so
saying yes here would mean building push delivery, a native call-screen bridge, and a new
cross-repo signaling event from nothing, not adding one piece to something that already exists.
Revisit if/when there's a real need for host-initiated ringing.

An invited member (not a cold join-by-code guest, but someone the host explicitly rings or a
scheduled meeting a member is expected at) should get a native-feeling incoming-call screen,
not a generic push banner they might miss. Standard Flutter approach:

- **Push delivery**: FCM (Android) / APNs (iOS) carries a call-invite payload from
  `w/communications` when a host starts a session a member is expected in.
- **Native call UI**: `flutter_callkit_incoming` (or equivalent) bridges to iOS CallKit and
  Android's `ConnectionService`/full-screen-intent notification, so the incoming call renders
  as a real system call screen (answer/decline, ringing, works from the lock screen) rather
  than an in-app-only alert — matching what a Zoom/Teams mobile user already expects.
- **Cold start**: answering from a killed-app state must be able to launch straight into the
  call join flow (`01` §3), not just open the app to its home screen.

**Decision needed:** whether "invited member gets rung like a phone call" is in scope for v1,
or whether v1 is join-by-code/link only (no push-to-ring). The signaling model
(`../03-signaling-and-session-model.md`) doesn't
currently define a "ring a specific member" event — if this is wanted, it's an addition to the
master plan's signaling contract, not purely a mobile-client concern, and should be raised
there before mobile-specific push work starts.

## 2. Background behavior

- **Audio-only continuation**: if the OS backgrounds the app mid-call, audio should keep
  flowing (standard `flutter_webrtc` background-audio handling, plus the platform-specific
  background modes declared in `Info.plist` / Android's foreground-service requirements for an
  ongoing call).
- **Picture-in-picture**: where the platform supports it (iOS PiP, Android PiP), keep video
  visible in a floating window while the member uses another app — a v2 nicety, not baseline,
  since the underlying `flutter_webrtc` stream keeps working without it.
- **Explicit backgrounding notice**: at minimum, a persistent notification ("Call in progress —
  tap to return") while backgrounded, so the member always has a way back into the call UI.

## 3. Permissions

Camera, microphone, and (for `03-mobile-screen-share.md`) screen-capture permissions are
requested contextually — at first use of each capability, not all up front at install — matching
current iOS/Android platform guidance and avoiding a wall of permission prompts before the
member has done anything.
