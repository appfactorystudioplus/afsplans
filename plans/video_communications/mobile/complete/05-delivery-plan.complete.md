# 05-delivery-plan — Completion (host flow built; testing/acceptance still device-dependent)

**Status:** This file is mostly a phase-tracking/testing-criteria document, not code to write
directly (§2's master-plan dependency is satisfied — the client already exists and is built
against a real `w/communications`; §3/§4 are manual, device-dependent criteria this session
cannot execute). Its one concrete open item, Phase 1's host flow, is now built as a bounded v1
slice: start a meeting, the waiting room (admit/deny), mute-all, end meeting. Recording, breakout
rooms, and role promotion (co-host) are real `w/communications` host surface but deliberately not
built here — see `host_types.dart`'s own header for why. **No device/emulator run was done this
session**, same constraint as every other mobile step.

## What was built

A member signed in via the existing `AuthBloc`/`AuthRepository` (real Checkpoint auth,
`checkpoint-server-auth/08-mobile-client-auth.md`) can now host a meeting from `CallPage` — no
display name to collect, `w/communications` derives the host's identity from the bearer token
itself.

- **`packages/checkpoint`'s `CheckpointClient` gained `getAccessToken()`** — a small, surgical
  addition exposing the SDK's own already-existing refresh-if-needed logic
  (`CheckpointHttpClient.getAccessToken()`/`isAuthenticated()`), not new auth behavior. Needed
  because `w/communications`'s host endpoints authenticate with a raw bearer token, unlike this
  SDK's own profile/authz/alloc trio which never expose the token directly.
  [`lib/repositories/auth_repository.dart`](../../../../../afsmobile_flutter/lib/repositories/auth_repository.dart) gained a one-line `accessToken()` delegating wrapper, matching its existing style.
- [`lib/models/call/host_types.dart`](../../../../../afsmobile_flutter/lib/models/call/host_types.dart) (new) — `RoomInfo`, `WaitingGuest`, `WaitingDecision`, scoped to this pass (see the file's own header for what's deliberately excluded).
- [`lib/repositories/host_repository.dart`](../../../../../afsmobile_flutter/lib/repositories/host_repository.dart) (new) — `ensureRoom`, `startSession`, `waiting`, `decide`, `muteAll`, `endSession`, mirroring `afsdesktop5/src/renderer/call/hostApi.ts`'s equivalent methods. Reuses `CommunicationsException` from the existing guest repository rather than a parallel error type.
- `CallBloc` gained host-only state and events: `HostSessionRequested` (ensures the room, starts the session, then calls the same `_connect()` every join path already uses — hosting a meeting and joining one converge on identical LiveKit connection logic once there's an `AdmittedPayload`), a 3-second waiting-room poll (mirrors the guest-side join-request poll — `w/communications` has no push channel for either), `WaitingDecided`, `MuteAllRequested`, `EndSessionRequested`. `CallConnected` gained `isHost`/`roomJoinCode`/`waitingGuests`.
- UI: `call_page.dart`'s join form gains a "Host a meeting" button, shown only when `AuthBloc` reports `Authenticated` (join-by-code needs no account, so the button stays hidden for a signed-out visitor rather than existing as a dead control). New [`lib/pages/call/host_controls_page.dart`](../../../../../afsmobile_flutter/lib/pages/call/host_controls_page.dart) — room-code share card, waiting-room admit/deny list, mute-all, and an end-meeting button gated behind an explicit confirmation dialog (ending disconnects every other participant — the same caution desktop's own host panel uses for this action).

## Files

| Where | What |
|---|---|
| `afsmobile_flutter/packages/checkpoint/lib/src/checkpoint_client.dart` | New `getAccessToken()`. |
| `afsmobile_flutter/lib/repositories/auth_repository.dart` | New `accessToken()` wrapper. |
| `afsmobile_flutter/lib/models/call/host_types.dart` (new) | `RoomInfo`, `WaitingGuest`, `WaitingDecision`. |
| `afsmobile_flutter/lib/repositories/host_repository.dart` (new) | The host-only API client. |
| `afsmobile_flutter/lib/bloc/call/{call_bloc,call_event,call_state}.dart` | Host state/events, the waiting-room poll, `_connect()` reuse for hosting. |
| `afsmobile_flutter/lib/pages/call/call_page.dart` | "Host a meeting" entry point, host-controls entry button with a waiting-guest count badge. |
| `afsmobile_flutter/lib/pages/call/host_controls_page.dart` (new) | The host controls screen. |
| `afsmobile_flutter/test/call/host_types_test.dart` (new) | JSON-parsing tests for the new wire types. |

## Verification

- Every new API surface (`CheckpointHttpClient.getAccessToken`, `SharePlus.instance.share`) was
  checked against the exact installed package sources before being used.
- `flutter analyze` — **27 issues, all pre-existing**, identical set to every prior mobile
  completion report; zero new lints or errors.
- `flutter test` — **106/106 passing** (102 prior + 4 new for `host_types_test.dart`'s JSON
  parsing). `CallBloc` itself has no direct unit tests — matches this codebase's own existing
  precedent (`HostRepository`/`CommunicationsRepository` are both thin HTTP plumbing with no
  dedicated test file either, same as before this pass).
- **Not verified — needs a real device, a real member account, and a real call with at least one
  guest**: that hosting a meeting actually works end to end, that the waiting-room poll picks up
  a real guest, that admit/deny/mute-all/end-meeting actually affect a connected guest's client,
  and that `getAccessToken()`'s refresh-if-needed path behaves correctly against a real,
  near-expiry Checkpoint session.

## §2/§3/§4 of the delivery plan itself

- **§2 (dependency on the master plan)**: satisfied — `w/communications` exists, has a stable
  contract, and this client (guest join, `01`–`04`, and now host flow) is already built against
  it. The gate this section describes was real when the file was written; it no longer blocks
  anything.
- **§3 (testing) / §4 (acceptance)**: both are manual, device-and-real-account criteria
  (physical-device camera/mic behavior, cold-start push, cellular-to-Wi-Fi handoff, a real member
  hosting from a real account). None of this session's work changes what's needed here — still
  entirely unexercised, for the same reason every other mobile step's device-level behavior is
  unverified this session.

## Follow-up work

1. **Run the full host flow on a real device against the vagrant lab, with a real second
   participant joining as a guest** — the single most important gap, same as every prior mobile
   step but now covering a materially larger surface (start, admit, mute, end).
2. Recording, breakout rooms, and co-host promotion for mobile hosts — real `hostApi.ts` surface,
   deliberately out of this pass (see `host_types.dart`).
3. `02`'s deferred push-to-ring — would let a host *invite* someone directly rather than only
   sharing a code, a natural pairing with hosting now existing; still needs the FCM/APNs/signaling
   work already tracked in `02`'s own completion doc.
4. Cellular-to-Wi-Fi mid-call handoff/reconnection (`01` §5) — flagged as unbuilt since `01`,
   unrelated to this pass, still open.
