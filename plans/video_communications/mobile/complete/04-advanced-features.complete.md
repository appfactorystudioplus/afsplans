# 04-advanced-features — Completion (partial: §1 deferred)

**Status:** §2 (reactions/raised hand) and §4 (battery/data usage) fully built. §3 (whiteboard) built
as a genuine "read-and-annotate" view, not a full editor, matching the plan's own framing. §1
(live captions) explicitly **not** attempted — no server-side captioning infrastructure exists
anywhere in the estate yet (see below), matching the desktop client's own precedent of deferring
this by the user's choice. §5 ("not planned for mobile") needed no work, it is a scope note, not a
feature. **No device/emulator run was done this session** — `flutter analyze`/`flutter test` cover
the Dart side fully (this step's logic is pure Dart, no new native platform code), but nothing here
has been exercised against a real call.

## §1 — Live captions: deliberately not attempted

The plan's own recommendation is server-side captioning (a bot participant joins the call, runs
speech-to-text on each track, publishes text on a LiveKit data topic). **None of that exists
anywhere in the estate** — confirmed via `plans/TODOS.md`'s own "Video communications" §1, which
already records that the desktop client's live-captions work was investigated then **explicitly
deferred by the user's own choice** (2026-09-19/20), and that the underlying server-side pipeline
(speech engine choice, bot participant, data topic) was never built for any client. Building mobile
captions would mean building that entire cross-repo pipeline first — a materially larger effort
than anything else in this plan file, and already a known, deliberately-deferred decision, not a
gap this session introduced. Not re-litigated; no code written for this section.

## §2 — Reactions and raised hand

Ported `afsdesktop5/src/renderer/call/messages.ts` and `dataMessaging.ts`'s reaction/hand slice
(chat excluded — not part of mobile's scope) to Dart:

- [`lib/models/call/call_messages.dart`](../../../../../afsmobile_flutter/lib/models/call/call_messages.dart) (new) — `Topic`, `kReactions`, encode/decode, `RateLimiter`.
- [`lib/services/call_messaging.dart`](../../../../../afsmobile_flutter/lib/services/call_messaging.dart) (new) — `CallMessaging`: sender-and-receiver-side rate limiting, hand-raised state tracking, the "tell a newcomer our hand is up, twice" welcome-resend behavior — all ported unchanged from the TS original's own reasoning, not simplified.

Wired into `CallBloc`: a `DataReceivedEvent` listener dispatches by topic (to `CallMessaging` or,
for `afs.wb`, to `WhiteboardSync` — see §3); `ParticipantConnected`/`Disconnected` call
`peerJoined`/`peerLeft`; new `ReactionSent`/`HandToggled` events. Reactions are exposed as a
`Stream<ReactionEvent>` (`CallBloc.reactionEvents`) rather than persisted `CallState` — they fade on
their own, unlike hand-raised, which is `CallTile.handRaised` (persistent per-tile state, mirrors
`micOn`/`camOn`). UI (`call_page.dart`): a horizontal reaction-emoji row, a hand-raise toggle in the
primary control row, floating reaction "toasts" (2-second auto-dismiss) driven by
`reactionEvents`, and a small hand badge on any tile with its hand up.

## §3 — Whiteboard (read-and-annotate, not a full editor)

Ported `afsdesktop5/src/renderer/call/whiteboardSync.ts` in full — the merge rule (version/nonce,
Excalidraw's own tie-break), the allow-list sanitizer (7 shape types, bounded numbers/lengths, no
images/embeds/links/custom-data), rate limiting, lock enforcement, and the ask/open/answer
newcomer-catch-up flow:

- [`lib/models/call/whiteboard_messages.dart`](../../../../../afsmobile_flutter/lib/models/call/whiteboard_messages.dart) (new) — `sanitizeElement`, `decodeBoardMessage`, `chunkElements`, `wins`. `BoardElement` is a `Map<String, dynamic>` (matching the TS source's own loosely-typed `Record<string, unknown>`) rather than a rigid class with dozens of optional per-shape fields.
- [`lib/services/whiteboard_sync.dart`](../../../../../afsmobile_flutter/lib/services/whiteboard_sync.dart) (new) — `WhiteboardSync`, including the full lock/moderator logic even though **mobile has no host role reachable at all today** (`01-webrtc-client.md`'s guest-only scope) — a desktop or website host in the same call can still lock the board, and mobile must respect that; there is just no lock-*toggle* control in mobile's own UI.
- [`lib/pages/call/whiteboard_page.dart`](../../../../../afsmobile_flutter/lib/pages/call/whiteboard_page.dart) (new) — a `CustomPainter` renders every element type a desktop/website host can draw (rectangle, diamond, ellipse, line, arrow, freedraw, text) at best-effort fidelity — no rough.js-style sketchy rendering, no per-element selection/move/resize/erase. This client only ever **creates** freedraw strokes (a pan gesture, 4-color palette), matching the plan's own "add strokes, without needing the full editing feature set" framing exactly, not a simplification I invented.

`CallBloc` gained a `whiteboard` getter (the raw `WhiteboardSync`, read directly by the page rather
than routed through `CallEvent`s — a side-effecting service call, the same pattern
`ScreenShareService`/`CallForegroundService` already use) and a `whiteboardEvents` stream for
remote-change/opened/lock notifications.

## §4 — Battery and data usage

New [`lib/services/network_service.dart`](../../../../../afsmobile_flutter/lib/services/network_service.dart) — `NetworkService.isCellular()` via `connectivity_plus`
(promoted from transitive to direct, same reasoning as `flutter_webrtc` in `03`), checked once at
join time (not tracked live mid-call — a live renegotiation feature is out of this pass's scope,
the plan's own text only asks for a join-time default).

- **Receiving side** (unconditional, any network): `RoomOptions(adaptiveStream: true, dynacast: true)` — LiveKit's own mechanism for adjusting subscribed video quality to what is actually rendered. Checked directly against the installed `livekit_client` source rather than assumed to exist at this pinned version.
- **Publishing side** (cellular only): `defaultCameraCaptureOptions` uses `VideoParametersPresets.h180_169` (320×180, the lowest 16:9 preset the SDK ships) and `defaultVideoPublishOptions` caps encoding at 15fps/300kbps — both are `RoomOptions` fields, so they also apply automatically if the person turns their camera on later via the existing toggle, not just at the initial join.
- **Camera-off-by-default on cellular**: `_connect()` skips the auto-enable-camera call when `isCellular()` is true (mic still auto-enables either way) — a deliberate default per the plan's own text, not a failure path. `CallConnected.onCellular` surfaces this to the UI, which shows a dismissible-by-turning-camera-on hint banner explaining why video is off and how to turn it on.

## Files

| Where | What |
|---|---|
| `lib/models/call/call_messages.dart`, `lib/services/call_messaging.dart` (new) | §2 wire format + sync engine. |
| `lib/models/call/whiteboard_messages.dart`, `lib/services/whiteboard_sync.dart`, `lib/pages/call/whiteboard_page.dart` (new) | §3 wire format, sync engine, and the read/annotate UI. |
| `lib/services/network_service.dart` (new) | §4 cellular detection. |
| `lib/models/call/call_types.dart` | New `roleFromMetadata()` — shared by §2/§3's peer-role reading, mirrors desktop's `roleOf()`. |
| `lib/bloc/call/{call_bloc,call_event,call_state}.dart` | `CallMessaging`/`WhiteboardSync` wiring, `ReactionSent`/`HandToggled` events, `CallTile.handRaised`, `CallConnected.onCellular`, network-aware `Room` construction. |
| `lib/pages/call/call_page.dart` | Reaction row, hand-raise button, whiteboard button, reaction toasts, hand badges, cellular hint banner. |
| `pubspec.yaml`/`pubspec.lock` | New deps `connectivity_plus` (promoted direct), dev dep `fake_async` (for testing the hand-raise welcome-resend timer without a real wait). |
| `test/call/{call_messages,call_messaging,whiteboard_messages,whiteboard_sync}_test.dart` (new) | 63 new tests — see Verification. |

## Verification

- Every new plugin/SDK surface (`RoomOptions.adaptiveStream`/`dynacast`/`defaultCameraCaptureOptions`/`defaultVideoPublishOptions`, `VideoParametersPresets.h180_169`, `VideoEncoding`, `connectivity_plus`'s `Connectivity().checkConnectivity()`, `LocalParticipant.publishData`, `DataReceivedEvent`, `Participant.metadata`) was checked against the exact installed package sources before being used, not written from memory.
- `flutter pub add connectivity_plus`/`dev:fake_async --dry-run` — resolved cleanly before adding either for real.
- `flutter analyze` — **27 issues, all pre-existing**, identical set to every prior mobile completion report; zero new lints or errors (one real new lint surfaced mid-way, an unused test import, fixed).
- `flutter test` — **102/102 passing** (39 prior + 63 new: 12 for `call_messages.dart`, 14 for `call_messaging.dart`, 20 for `whiteboard_messages.dart`, 17 for `whiteboard_sync.dart` — including the allow-list sanitizer's rejection paths, the version/nonce merge tie-break, lock enforcement from non-moderators, and rate-limiting on both the sending and receiving side). This step's logic is pure Dart with no native platform code, so — unlike `02`/`03` — `flutter test` is a genuinely complete verification of the logic itself, not just a compile check; only the actual on-device behavior (gesture handling, real LiveKit data delivery, the cellular-detection plugin's native side) remains unverified.
- **Not verified — needs a real device and a real call with at least one other participant**: that reactions/hand actually round-trip through a live LiveKit room, that the whiteboard renders another client's drawing correctly, that a freedraw stroke drawn on mobile appears correctly on desktop/website, and that the cellular default (camera off, lower-resolution publish) behaves correctly on real cellular data.

## Follow-up work

1. **Run all three sub-features on a real device against the vagrant lab, with a second real
   participant** (desktop or website) — the single most important gap, larger here than usual
   since three separate features are all equally unverified at the device level.
2. If live captions are ever wanted: build the server-side pipeline first (a master-plan-level
   decision, not mobile-specific) — see `plans/TODOS.md`'s "Video communications" §1.
3. Whiteboard: no persistence (matches desktop — "nothing is saved" is a known, shared limitation,
   not new here), no undo/erase, no element selection.
4. `05-delivery-plan.md` — the mobile plan's own remaining sequencing (hosting, native call UX
   from `02`'s deferred push-to-ring, `05` itself) not started.
