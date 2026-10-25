# 05 — Advanced features

Features beyond baseline call/record/draw/share, ordered by how directly they reuse
infrastructure this app already ships (highest reuse — and therefore lowest-cost to build —
first).

## 1. Live captions and meeting transcription (Whisper reuse)

`src/native/whisper_bridge.{cpp,h}` already exists and is shipped for the Speech plan's file
transcription and live dictation
(`plans/speech/08-live-dictation.md`). This plan feeds the call's own audio (local mic, and
optionally each remote participant's decoded audio track) through the same bridge to produce:

- **Live captions** overlaid on the call UI (`01` §3), per speaker.
- **Post-meeting transcript**, stored alongside a cloud recording
  (`../04-database-schema.md` §2) — a natural pairing, since a
  recording's transcript is exactly the kind of artifact the Speech plan's
  [`11-speaker-diarization.md`](../../../../../afsdesktop5/plans/speech/11-speaker-diarization.md) and
  [`12-transcript-editor.md`](../../../../../afsdesktop5/plans/speech/12-transcript-editor.md) already know how to produce and
  edit. This plan does not re-design transcript editing — a call transcript is just another
  transcript once the meeting ends.

Gated by the `live_transcription` tier field
(`../05-feature-tiers-and-entitlements.md` §5) — off on Free, on from
Starter up.

## 2. Meeting whiteboard (Excalidraw reuse — distinct from screen-draw)

`@excalidraw/excalidraw` is already a dependency, already used for a full drawing surface in
`src/renderer/components/flow/views/DrawView.tsx`. Where `03-draw-over-screen.md` is a
transient marker-pen overlay on top of *anything on screen*, this feature is the opposite: a
**persistent, shared, in-call canvas** — participants collaboratively sketch on a normal
Excalidraw board, most naturally synced via a LiveKit **data channel** (LiveKit supports
arbitrary data messages between participants, not just media) carrying Excalidraw's own scene
diffs. This is the appropriate place to actually use Excalidraw's full feature set (shapes,
text, multi-select) that `03` deliberately avoided pulling in for the overlay.

## 3. Virtual backgrounds / background blur

Chromium's WebRTC pipeline supports inserting a processing step on the local video track before
it's published (`MediaStreamTrackProcessor`/`MediaStreamTrackGenerator`, or a canvas-based
frame loop). Segmentation (person vs. background) needs a model — this is the one advanced
feature with a real "build vs. depend on a library" decision, since nothing already in this
codebase does image segmentation. **Recommendation:** evaluate a lightweight in-browser
segmentation model (e.g. MediaPipe Selfie Segmentation, WASM-based, no native addon) before
considering a native/C++ approach — matches this plan's `00` §3 preference for no new native
code where a JS-level solution is viable.

## 4. Reactions and raised hand

Small, low-effort — LiveKit data-channel messages (`§2`'s mechanism, reused) broadcasting an
emoji/raised-hand event, rendered as a transient overlay on the sender's tile in every
participant's grid (`01` §3). No server-side persistence needed.

## 5. Breakout rooms

Splits an active session's participants into N sub-rooms, each a separate LiveKit room, with
the host able to broadcast a message to all sub-rooms and close them back into the main room.
This is the most structurally significant advanced feature — it needed a real extension to
`../03-signaling-and-session-model.md`'s room/session model, not a purely client-side UI
addition, so per this section's own original instruction it was designed jointly with the user
before desktop-specific work started (2026-09-20). Scope, as decided with the user: auto-split
assignment **with manual override**, the **host can visit any breakout room** on demand (not
just broadcast/close from outside), and an **optional auto-close timer** in addition to manual
close.

### The data model: a dedicated table, not a child session

**Rejected:** a `parent_session_id` self-FK on `members_communications_sessions` (a breakout
room as "just another session row"). Two concrete problems, not just a style preference:
- `SessionRepository.findActiveByRoom(roomId)` resolves "the active meeting for this room" as
  `ORDER BY id DESC LIMIT 1` among non-ended sessions. A breakout row sharing `room_id` with its
  parent risks winning that query over the real meeting — a guest joining by code could land in
  a breakout by accident.
- A session row carries `duration_cap_minutes`/`participant_cap`/`cloud_recording_enabled`/
  `live_transcription_enabled` snapshot columns and its own `cap_deadline_at` (the meeting-wide
  duration cap) — all either meaningless on a breakout row or, worse, a second unrelated meaning
  collision with the breakout's own auto-close timer.

**Chosen:** a new `members_communications_breakout_rooms` table (`session_id` -> the MAIN
session being split, `label`, `livekit_room_name`, `status`, its own `close_deadline_at`), plus
a `current_breakout_room_id` nullable FK on `members_communications_participants` (`NULL` = the
main room — a participant is in at most one room at a time, so one column is enough, and moving
someone is a single race-free `UPDATE`). Migration:
`w/www/database/migrations/2026_09_20_000001_create_members_communications_breakout_rooms_table.php`
— a genuinely new, separate migration file (never an edit to the original
`2026_09_18_000001_create_members_communications_tables.php`, and applied with a plain
`php artisan migrate --force`, never `migrate:fresh` — `w/AGENTS.md` §5).

### Delivery: the server pushes a LiveKit data message, no new WebSocket

`03-signaling-and-session-model.md` §5's planned `/signal` WebSocket was never built; chat,
reactions and the whiteboard all ship instead as peer-to-peer LiveKit data-channel messages.
Breakout rooms need a message *from the server* ("you've been moved: here's your token"),
which `livekit-server-sdk`'s `RoomServiceClient.sendData(room, data, kind, {destinationIdentities,
topic})` provides directly — the server pushes into any LiveKit room without holding a
connection there. No new signaling infrastructure was built; `LiveKitGateway` gained one method
(`sendData`), used on the existing `afs.breakout` topic the same way chat/reactions/whiteboard
already use their own topics, just server-originated instead of peer-originated.

### Backend surface (`w/communications`) — built

`MeetingService` gained, all requiring `moderator` authority (host or a promoted co-host, same
tier as `muteAll`/`decide` — the host is never auto-assigned into a room, but a co-host can run
the feature):

- `startBreakouts(caller, sessionUuid, { count, assignments?, durationMinutes? })` — auto-splits
  admitted non-host participants via `domain/breakoutAssignment.ts`'s pure `splitParticipants()`
  (manual overrides win, the rest spread evenly), creates one real LiveKit room per breakout,
  pushes each moved participant a "moved" message to the MAIN room (where they are still
  connected at that instant).
- `listBreakouts`, `reassignParticipant` (move between rooms or recall to main with
  `targetRoomUuid: null` — always pushes to whichever room the person is CURRENTLY connected
  to, main or another breakout), `broadcastToBreakouts` (one push per open room, no
  `destinationIdentities`), `closeBreakouts` (manual "close now": pushes everyone back to main,
  deletes the LiveKit rooms, clears every assignment — DB state clears even if a push to someone
  no longer connected fails), `joinBreakoutAsHost` (mints the caller their OWN token for a room
  without touching `current_breakout_room_id` — visiting is not being assigned), and
  `enforceBreakoutDeadlines` (the auto-close timer's sweep, folded into the same `setInterval`
  as the existing duration-cap sweep in `index.ts`, not a second timer).
- **The rejoin-token recovery path**: `JoinPayload` gained `activeBreakout: {id, label,
  closeDeadlineAt} | null`, populated in `buildJoin()` from the participant's
  `currentBreakoutRoomId` whenever it points at a still-open room. This is what makes a
  sleep/crash recovery work without the desktop client's single-slot rejoin storage
  (`session.ts`) ever needing to know about breakouts: `attemptRejoin()`/`enterCall()` land the
  person in main exactly as they do today, and only then does the client see `active_breakout`
  and move itself.
- Six new routes under `/v1/sessions/:sessionId/breakouts*` and
  `/v1/sessions/:sessionId/participants/:participantId/breakout` — see `src/http/app.ts`'s own
  header comment for the exact list.

Verification: 49 new unit/HTTP tests (`tests/breakoutAssignment.test.ts`,
`tests/breakouts.test.ts`, `tests/breakoutsHttp.test.ts`), all passing alongside the full
existing 188-test suite (237/237). Real-database coverage was also added to
`tests/mysql.integration.test.ts` (opt-in, needs a scratch `comms_it` MySQL database — not yet
run against one).

### Desktop client (`afsdesktop5`) — not yet built

Planned shape, to be built as one coherent step (schema + backend above is the prerequisite,
already done):
- `call/breakoutMessages.ts` (new): the `afs.breakout` wire format — `BreakoutDestination`
  (kind, room/label/deadline, a full `{url, token, room_name}` + ICE servers — the server embeds
  the ready-to-use connection payload directly, since it already mints tokens routinely) and
  `BreakoutSignal` (`moved` | `broadcast`). Kept separate from `messages.ts` the same way
  `whiteboardSync.ts` owns `BOARD_TOPIC`, since this payload is structurally bigger than a plain
  chat/reaction/hand message.
- `callController.ts`: a `BREAKOUT_TOPIC` branch in `startMessaging()`'s `RoomEvent.DataReceived`
  handler (before the `BOARD_TOPIC` check) calling a new `moveToRoom(destination)` method
  directly — not routed through a UI hook, since the controller itself must disconnect/
  reconnect the instant the message arrives. `connect()`'s room-construction body is factored
  into a private `attachRoom()`, reused by both the first connect and `moveToRoom`.
  `old.removeAllListeners()` before `old.disconnect()` keeps the old room's own `Disconnected`
  handler from firing and tearing down the whole call.
- `session.ts`'s single-slot rejoin storage is left alone — never overwritten for a breakout
  move in either direction; the server's `activeBreakout` field (above) is the recovery path
  instead.
- Host panel (`components/call.ts` + `html/call.html`), modeled on the existing
  `renderWaiting()` waiting-room list: room-count + optional-timer inputs to start, one row per
  room with a `<select>` per occupant for manual reassignment (chosen over literal drag-and-drop
  — this codebase has no DnD anywhere, and a `<select>` gives the same capability for far less
  code), a per-room "Visit" button, a broadcast box, and "Close all now".
- **The host visiting a room needs a SECOND concurrent `CallController`/`Room`** (its own hidden
  audio sink), alongside the always-connected main one — reusing the main connection for a visit
  would leave the main room empty long enough for LiveKit's `emptyTimeoutSeconds` to tear it
  down, and would strand the host's ability to broadcast/close/end-meeting (which target the
  main session by role/identity) while "away." The host's own mic/camera are muted on the
  primary controller before connecting the visit controller, restored when the visit ends.

### Testing plan for the desktop half (not yet run)

Extends the established real-e2e pattern (`compose()`/`asHost()` seeding, `media-probe` fake
participants, a real Playwright-launched `afsdesktop5` guest). Two genuinely new pieces, not
already available: `media-probe` needs a small (~20-40 line) addition to listen for
`RoomEvent.DataReceived` on `afs.breakout` and reconnect when told to move (it currently only
connects/holds/leaves); a new `w/communications/scripts/lab-breakouts.ts` (pattern:
`lab-desktop-call.ts`) drives a fake host, one real desktop guest, and one more `media-probe`
guest through start/reassign/broadcast/close, plus forcing `close_deadline_at` into the past to
test the auto-close sweep without waiting real wall-clock minutes, with a new
`smoke_breakout.js` (sibling to `smoke_call.js`) exercising the real app's side.

## 6. Invite integration

Sending a join link/code through the app's existing notification and (if applicable) email/
contact integrations, rather than only copy-to-clipboard (`01` §3) — low effort, deferred only
because it depends on which of the app's existing integrations are still active at
implementation time.
