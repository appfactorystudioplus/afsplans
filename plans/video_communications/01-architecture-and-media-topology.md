# 01 — Architecture and media topology

## 1. Where `w/communications` runs and what it's built from

**Decision needed**, recorded here as a recommendation rather than settled fact.

`w/www` and `w/members` are Laravel/PHP. A real-time SFU signaling server is not a good fit
for PHP's request-response model (no persistent process, no first-class WebSocket/UDP media
handling). Every mainstream self-hosted SFU (mediasoup, LiveKit's `livekit-server`, Janus) is
either Node.js or a standalone Go/C binary with its own control API — none are PHP.

**Recommendation: `w/communications` is a standalone Node.js service**, sitting alongside
`w/www` and `w/members` as a sibling under `w/`, talking to the shared MySQL database that
`w/www`'s migrations create (`members_communications_*` tables, `04-database-schema.md`) and
to Checkpoint for identity, the same "brain" member-resolution pattern `afs-connect` already
uses (`plans/afs-connect/00-overview.md` glossary — "brain" API resolves a Checkpoint identity
to a `member_reference_num`).

### SFU library: mediasoup vs. LiveKit

| | mediasoup | LiveKit |
|---|---|---|
| What it is | A Node.js **library** — you build your own signaling server around it | A complete **server binary** with its own built-in signaling (gRPC/WebSocket) and room API |
| Control | Full control over the room/session model, entitlement checks inline with every signaling event | Less control — LiveKit owns the room model; entitlements enforced at the edges (token minting) |
| Client SDKs | `mediasoup-client` (web) — Electron and web share this; Flutter needs a community wrapper or raw `flutter_webrtc` + hand-rolled protocol | Official SDKs for JS/Web, Flutter, Swift, Kotlin, React Native — all three of our platforms covered out of the box |
| Operational surface | One more thing `w/communications` fully owns and must get right (reconnection, room GC, recording hooks) | LiveKit process(es) to deploy and upgrade; `w/communications` becomes thinner — issues tokens, calls LiveKit's server API, listens to its webhooks |
| Fit with "member hosts, guest joins by code" | Natural — this plan's room/session model (`03`) is exactly what mediasoup expects you to build | Also natural — LiveKit rooms map 1:1 to this plan's rooms; guest tokens are LiveKit access tokens scoped per-room |

**Recommendation: LiveKit**, specifically because it already ships a first-class **Flutter**
client SDK — `afsmobile_flutter` would otherwise be the hardest of the three clients to get
right against a hand-rolled mediasoup protocol. `w/communications` then has a narrower job:
mint room-scoped access tokens (member host tokens carry the member's entitlement snapshot;
guest tokens carry only a display name and room grant), start/stop LiveKit's server-side
recording via its Egress API, and own the `members_communications_*` tables as the durable
record LiveKit itself doesn't keep. LiveKit's own TURN/STUN config is disabled — Coturn
(`02-turn-infrastructure.md`) is used instead, both because it is self-hosted and to reuse the
multi-server fleet across every media path.

This is the single highest-leverage open decision in the whole plan — confirm it before any
other document here is treated as final, since `03` (signaling contract) and every client plan
(desktop `01-webrtc-client.md`, mobile `01-webrtc-client.md`, website
`01-web-client-and-guest-join.md`) are written assuming LiveKit's room/participant/token model.

## 2. Why SFU, not mesh

Mesh WebRTC connects every participant directly to every other participant. For N
participants each uploads N-1 streams and downloads N-1 streams. At N=2 this is optimal (no
media server at all — but even 1:1 calls in this plan go through the SFU for uniformity,
recording, and consistent TURN behavior). At N=5 each participant is already uploading 4
simultaneous video streams from a laptop webcam — most home uplinks choke well before that.
Zoom and Teams both use SFU media routing for exactly this reason.

An SFU inverts this: every participant uploads **one** stream to the SFU, and downloads up to
N-1 streams **from** the SFU (with simulcast/adaptive layers so the SFU can downgrade what it
forwards to a struggling downlink without re-encoding). This is the only topology that makes
"2 or more recipients" in the original ask scale credibly past a handful of people, and it is
the precondition for server-side recording (`04` — recording captures the SFU's mixed/composed
output, not N individual peer connections).

## 3. End-to-end flow

```
Host (member)                     w/communications                    Guest (no account)
     |                                    |                                    |
     |-- POST /rooms (JWT) ------------->|                                    |
     |<- room_id, join_code -------------|                                    |
     |                                    |                                    |
     |-- POST /rooms/:id/join (JWT) ---->|                                    |
     |<- LiveKit access token -----------|                                    |
     |                                    |<---- POST /join {code, name} -----|
     |                                    |---- LiveKit access token -------->|
     |                                    |
     |============== both sides connect directly to LiveKit ==================|
     |                                    |
     |--------- WebRTC (SRTP) -----------> LiveKit SFU <----- WebRTC (SRTP) ---|
     |                                    |      |
     |                          ICE/TURN as needed via Coturn fleet (02)
     |                                    |
     |                          Egress (recording) -> members_communications_recordings (04)
```

Four points worth being explicit about:

1. **`w/communications` is off the media path.** Once tokens are issued, video/audio flows
   directly between each client and LiveKit over WebRTC (relayed through Coturn only when a
   direct/STUN path fails). `w/communications` never touches a media byte — it only mints
   tokens, tracks room/session state, and receives LiveKit webhooks (participant joined/left,
   recording started/stopped) to write `members_communications_*` rows.
2. **Token minting is where entitlement is enforced.** The host's token is only issued if
   `05-feature-tiers-and-entitlements.md`'s `communications` resource check passes for their
   tier (duration cap, participant cap, recording availability are all encoded into the token
   grant / room config at creation time, not re-checked continuously).
3. **Guests never touch Checkpoint.** The guest join endpoint takes a join code and a display
   name, checks the room exists and isn't full/locked (waiting room, `03`), and mints a LiveKit
   token scoped to exactly that room, nothing else, with an expiry (`06-security.md`).
4. **Reconnection is a token refresh, not a new join.** A dropped participant (network blip,
   laptop sleep) re-requests a token for the same room/identity from `w/communications` and
   rejoins the same LiveKit room — this is what makes "join with a code" resilient rather than
   fragile.

## 4. What each client plan builds against this

Every platform plan assumes the contract above and does **not** re-derive it:

- **Desktop** (`desktop/01-webrtc-client.md`) — Electron
  renderer using LiveKit's JS client (same SDK as web, since Electron is Chromium).
- **Website** (`website/01-web-client-and-guest-join.md`)
  — LiveKit JS client in the browser; hosts the guest join-by-code page.
- **Mobile** (`mobile/01-webrtc-client.md`) — LiveKit's
  official Flutter SDK.

None of the three client plans re-implement signaling, ICE handling, or SFU protocol — that is
entirely LiveKit's client SDK. Each client plan's job is UI, platform-native call handling
(CallKit, screen capture, notifications), and calling `w/communications`'s REST API to get a
token.
