# 03 — Signaling and session model — completion

**Status:** **Partly done** (2026-09-19). The REST half of the contract — rooms, sessions, guest join by code, waiting
room, host decisions, reconnection — is built in `w/communications` and verified. The **WebSocket** half (§5's live
events: chat, host controls, role changes, recording status) is **not built**; the waiting room works by polling instead. **Update (website step 02, 2026-09-19):** the host controls themselves (co-host promotion, mute-all, participant list, recording start/stop) are now built as REST routes; see [`website/02`](../website/complete/02-host-controls-and-ui.complete.md). Only the WebSocket transport and chat remain.

## What is built (`w/communications`)

| Plan (03) | Built | Notes |
|---|---|---|
| §1 room vs session | `members_communications_rooms` (durable, join code stable) and `_sessions` (one live occurrence; LiveKit room name rotates per session) | One personal room per member in v1 (unique `member_reference_num`) |
| §2 starting a meeting needs a member | `POST /v1/rooms` (get-or-create), `POST /v1/rooms/:roomId/sessions` → `{ session, join }` | Bearer token validated by Checkpoint; entitlement checked first (`05`); `403 entitlement_denied` carries `reason` and `upgrade_worthy` |
| §3 join by code | `POST /v1/join { join_code, display_name }` (public); an `Authorization` header joins as that member | Plan wrote `POST /sessions/join`; the service uses the `/v1` prefix |
| §3 waiting room | `GET /v1/join-requests/:id` (guest polls), `GET /v1/sessions/:id/waiting`, `POST /v1/sessions/:id/participants/:pid/(admit\|deny\|remove)` | **Polling**, not WebSocket events |
| §4 roles | `host`, `participant` (and `co_host` in the token grants: `roomAdmin` only for host/co-host) | Co-host promotion **is now built** (`POST …/participants/:pid/role`, host only, role pushed to clients in LiveKit participant metadata); a guest can never be host |
| §6 reconnection | A signed **rejoin token** issued on admission maps back to the same participant without a second seat | Plan said token refresh; a guest has no account to prove identity with |
| Ending | `POST /v1/sessions/:id/end` ends for everyone and deletes the LiveKit room | |
| LiveKit events | `POST /v1/webhooks/livekit` (signature verified): `participant_joined`/`_left` drive who is connected and the cap clock; `room_finished` ends the session and frees seats | |

## Not built

- **The `/signal` WebSocket** and every §5 event: `waiting_room.request`, `role.changed`, `host.mute_all`, `chat.message`,
  `recording.status`. **Update (website step 03):** chat, reactions and raised hands now exist, but as LiveKit data messages between
  clients (topics `afs.chat`, `afs.reaction`, `afs.hand`), not through this WebSocket; see
  [`website/03`](../website/complete/03-advanced-features.complete.md). The WebSocket is still needed for instant waiting-room
  push and host-control events, and if chat ever needs stored history or moderation.
- ~~Recording start/stop endpoints~~ and ~~co-host promotion~~ — built in website step 02 (see [`website/02`](../website/complete/02-host-controls-and-ui.complete.md)); recording capture itself is unverified because the lab has no Egress.
- `GET /rooms/:id` exists as `GET /v1/rooms/:roomId` (with `PATCH` for title and waiting room).

## Verification

- 136 unit tests (in-memory fakes; the **real LiveKit SDK** for token decoding and webhook signatures): the full meeting
  lifecycle, waiting room, rejoin, removal, seat cap, the HTTP layer and its error codes.
- 26 database integration tests on MySQL 8.4 and the lab MariaDB, including **seat-cap races** (20 simultaneous admits into
  5 seats admit exactly 5).
- `npm run e2e:lab` and `npm run e2e:media` against the lab: a real LiveKit room is created and ended, guests join by
  code, and the deployed service receives LiveKit's real `participant_joined` / `participant_left` / `room_finished`
  webhooks.

## Not verified / follow-up

- **A real member hosting through the HTTP API** with a real Checkpoint token has never run (the owner-path check `npm run e2e:owner` exists for you to run, see [`website/02`](../website/complete/02-host-controls-and-ui.complete.md)): `POST /v1/rooms` and
  `POST /v1/rooms/:id/sessions` are covered by fakes and by tests that hand the service a made-up host.
- Reconnection from a real client (only at the service level). The WebSocket and chat above. (Co-host promotion and recording start/stop are now built: [`website/02`](../website/complete/02-host-controls-and-ui.complete.md).)

See `01-architecture-and-media-topology.complete.md` for the full deviations table.
