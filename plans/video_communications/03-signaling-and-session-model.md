# 03 — Signaling and session model

## 1. Room vs. session

A **room** is durable and member-owned — created once, reused indefinitely, like a personal
Zoom room. A **session** is one live occurrence of that room being active. This split exists so
a member's join code never changes between meetings, while each meeting still has its own
recording, participant list, and duration accounting.

```
member (host)
   |
   | owns 1..N
   v
room  ──(join_code, stable)──  reused across time
   |
   | has 0..N
   v
session  ──  one per "first joiner to last leaver" window
   |
   | has 2..N
   v
participant  ──  one row per person per session (host, co-host, member, or guest)
```

## 2. Starting a meeting: host must be a member

`POST /rooms` (create, idempotent per member — a member's "personal room" is created once and
reused) and `POST /rooms/:id/sessions` (start a session in that room) both require a Checkpoint
JWT. `w/communications` resolves it to a `member_reference_num` via the existing "brain"
pattern (`00` — same identity resolution `afs-connect` already relies on) and checks the
`communications` entitlement (`05-feature-tiers-and-entitlements.md`) before minting anything.
A member below the required tier, or with the feature flag disabled, gets a `403` with a reason
code the client UI turns into an upgrade prompt or a plain "not available" message per the
Checkpoint `resolution_order` convention (`checkpoint_registry_v4.json` §`conventions.feature_flags`)
— never conflate `tier_disabled` (upgrade-worthy) with `feature_disabled`/`level_denied`
(never upgrade-worthy).

## 3. Joining: guests need only a code

`POST /sessions/join { join_code, display_name }` — no auth header. `w/communications`:

1. Looks up the active session by `join_code`.
2. If the room has a **waiting room** enabled (host-configurable per room), creates a
   `pending` participant row and notifies the host via a signaling event; the guest's client
   polls/subscribes for the host's admit/deny decision before a token is minted.
3. Otherwise mints a LiveKit guest token immediately, scoped to that room only, with the
   supplied `display_name` as the LiveKit participant identity, and an expiry
   (`06-security.md`).

A **member** joining someone else's room follows the same endpoint but may optionally include
their Checkpoint JWT to join *as themselves* (recognized identity, avatar, etc.) rather than as
an anonymous guest — this does not change what they can do in the room (host/co-host role is
granted explicitly, not implied by having an account) but does change what's recorded in
`members_communications_participants.member_reference_num` (`04`).

## 4. Roles

| Role | Granted by | Can |
|---|---|---|
| **Host** | Is the room's owning member | Start/end session, admit from waiting room, mute/remove participants, start/stop recording, promote a co-host |
| **Co-host** | Promoted by the host during a session | Same as host except cannot end the room permanently or change its settings |
| **Participant** | Default for anyone who joined | Publish own audio/video/screen-share, use chat/reactions (`08` in each client plan) |

Guests default to **participant**; a guest can never be promoted to host (a room always has
exactly one owning member), but can be promoted to co-host by the host if the host chooses to
extend that trust — this is an explicit decision surfaced in each client's host-controls UI,
not a default.

## 5. Signaling message contract

> **As built (2026-09-19):** the waiting room works by REST polling for now, not the WebSocket events below; the WebSocket is a follow-up. See [`complete/01-architecture-and-media-topology.complete.md`](complete/01-architecture-and-media-topology.complete.md).

`w/communications` exposes two surfaces:

1. **REST** (`POST /rooms`, `POST /sessions/join`, `POST /sessions/:id/end`,
   `POST /sessions/:id/recording/start|stop`, `GET /rooms/:id`) — request/response, used for
   everything that isn't itself part of the live call.
2. **WebSocket** (`wss://communications.appfactory.studio/signal`) — used only for events that
   must reach a live participant immediately and that LiveKit's own room events don't already
   cover: waiting-room admit/deny, host-control changes (mute-all, role promotion), and chat.
   Actual media negotiation (SDP, ICE) is entirely inside the LiveKit client SDK per `01` §4 —
   this WebSocket never carries SDP or ICE candidates.

| Event | Direction | Payload |
|---|---|---|
| `waiting_room.request` | guest → host(s) | `{ session_id, guest_name, requested_at }` |
| `waiting_room.decision` | host → guest | `{ admitted: bool }` |
| `role.changed` | host → participant | `{ participant_id, role }` |
| `host.mute_all` | host → all | `{ audio: bool, video: bool }` |
| `chat.message` | any → all | `{ participant_id, text, sent_at }` |
| `recording.status` | server → all | `{ state: starting|active|stopping|stopped }` |

## 6. Reconnection

A client that drops (sleep, network blip, app backgrounded on mobile) re-requests a token for
the **same** room and **same** LiveKit participant identity from `w/communications` — the
server recognizes the identity is already a participant of an active session and reissues
rather than creating a duplicate row. This is what makes the "join with a code" model resilient
without the client needing to remember anything beyond the room's join code and its own
identity — see each client plan's reconnection handling (desktop `01`, mobile `01`, website
`01`) for platform-specific triggers (mobile: app resume; desktop: network-change events).
