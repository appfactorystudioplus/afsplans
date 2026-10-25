# 01 — WebRTC call client

## 1. SDK

LiveKit's official Flutter SDK (`livekit_client` on pub.dev) — the specific reason the master
plan recommends LiveKit over mediasoup
(`../01-architecture-and-media-topology.md` §1): a
maintained, first-class Flutter client means this app is not left hand-rolling WebRTC signaling
against a Node-library-only SFU. Underlying transport is `flutter_webrtc`, which the LiveKit SDK
depends on directly — no separate integration work.

## 2. State management fit

The app already uses `flutter_bloc` (`README_FEATURES.md`). A `CallBloc` (or `CallCubit`, if
the call state machine turns out simple enough not to need discrete events) owns: connection
state, local track state (muted/camera-off), remote participant list, and role
(`../03-signaling-and-session-model.md` §4) — mirrors
the existing `AuthBloc`/`ThemeCubit` pattern rather than introducing a second state-management
approach into the app.

## 3. Call lifecycle against the shared contract

Identical contract to every other client (`03` §2–3 in the master plan): `POST /rooms`
requires a Checkpoint-authenticated member (§4 below); `POST /sessions/join` with a join code
requires nothing but a display name for a guest. The mobile UI's "join a meeting" entry point
should treat the join-code field as the primary, most prominent action — matching Zoom/Teams
mobile apps' own emphasis, since a guest joining from a shared link/code is expected to be a
disproportionately common mobile flow (tapping a link from a text message).

## 4. Auth prerequisite — already satisfied

**As built (2026-09-21):** per `00` §1, real Checkpoint authentication already exists in this
app (`checkpoint-server-auth/08-mobile-client-auth.md`, done 2026-09-19) — `AuthRepository`/
`AuthBloc` are real, not a demo. Hosting a meeting reads the current member from the existing
`AuthBloc`'s `Authenticated` state (its bearer token, via the same `SecureTokenStorage` every
other member-only feature in this app already reads from — see `storage_repository.dart` for the
precedent) and calls `POST /rooms`/`POST /rooms/:id/sessions` with it, the same
`Authorization: Bearer <token>` contract every other client uses. No mobile-specific auth work
was needed before `CallBloc` could gate hosting correctly — Phase 0 in `05-delivery-plan.md` is
removed as a build phase; guest joining and hosting can both start from Phase 1.

## 5. Reconnection

Flutter's `AppLifecycleState` (`resumed`/`paused`/`detached`) plus connectivity-change events
(`connectivity_plus` or equivalent) drive the same token-refresh reconnection flow specified in
`../03-signaling-and-session-model.md` §6 — a call
resuming from background (`02`) is the mobile-specific trigger for this that desktop's
sleep/resume (`../desktop/01-webrtc-client.md` §4) doesn't have.
