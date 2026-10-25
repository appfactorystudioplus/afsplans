# 00 — Overview

## Completion document

When this plan step is completed, write a completion document in this directory's `complete/`
folder. Name it using this file's basename with `.complete.md` appended. Include status,
summary, files changed, verification steps, and any follow-up work.

**Status (2026-09-20):** All three clients now have a real, working baseline plus most of the advanced-features
catalog, all built and running in the vagrant lab. Done, each with its report in `complete/`: `01`–`05` (the
`w/communications` service, LiveKit, a two-server Coturn fleet, schema, entitlements); `website/01`–`03` (guest
join page, host controls/Meetings screen, screen share/chat/reactions/hands/whiteboard); `desktop/01`–`05` (the
call client, screen recording, draw-over-screen, screen-share-in-call, and the full advanced-features set —
reactions/hands, email invite, whiteboard, virtual backgrounds/blur, breakout rooms); every `mobile/` plan `01`–`05`
(the call client, native call UX groundwork, mobile screen share (Android only), advanced features (reactions/hands,
read-and-annotate whiteboard, cellular-aware quality), and the host flow). `desktop/06-delivery-plan.md` and
`website/04-delivery-plan.md` are meta/phasing docs satisfied in substance by the above (see their own
`complete/` write-ups) rather than independent features. `03` §5's signaling WebSocket was never built — every
client instead uses LiveKit data messages (chat/reactions/whiteboard/breakout) or short polling (waiting room),
a deliberate, consistently-applied deviation documented in each client's own advanced-features completion report.
**Real gaps, not "not started"** — see `feature-parity-between-app.md` for the full audit and fix plan: breakout
rooms and virtual backgrounds exist only on desktop; cloud recording and co-host promotion are fully built on the
backend but unwired in the desktop UI; mobile has no in-call chat (a deliberate prior scope cut), no breakout rooms,
no co-host promotion, no recording UI, and a parsed-but-unused reconnect token; iOS screen share needs a Mac+Xcode
session. Never exercised yet: two or more **separate computers** in one call (the lab was one machine),
Safari/Firefox, a real member hosting from the dashboard, or any of this on a real mobile device. The lab can now be
reached from other computers on the router's network: see `07-rollout-plan.md` §4 and
`complete/01-architecture-and-media-topology.complete.md` addendum 5.
**Target:** A self-hosted, WebRTC-based video conferencing system — a Zoom/Teams equivalent —
reachable from the desktop app (`afsdesktop5`), the website (`w/members`), and the mobile app
(`afsmobile_flutter`), with a member-hosted / guest-joins-by-code session model.

---

## Problem statement

The AFS ecosystem has no live audio/video communication product. `afsdesktop5` has recorded
media (Audio/Video plan) and speech-to-text (Speech plan) but nothing real-time. There is no
signaling server, no SFU, no TURN infrastructure, and no session/room model anywhere in the
estate. `w/members` has a `TwilioController` (phone number provisioning — SMS/voice, not video)
which is unrelated infrastructure and out of scope here.

This plan builds a **new backend service**, **new database tables**, and **three client
integrations** (desktop, web, mobile) that together let a member start a meeting and have
anyone — member or not — join it with a code, the way Zoom or Teams work.

## Where implementation actually happens

**This `afsserver/plans` tree is documentation only — it hosts no runtime code**, matching the
convention already established by `plans/afs-connect` (real code in `apps/coordinator`,
`apps/notes`, etc., planned from here) and `plans/afs-connect-desktop` (real code in
`afsdesktop5`, planned from here).

### One plan directory, three pointers

**Every plan document for this feature lives in this one directory tree**
(`packages/afsserver/plans/video_communications/`) — shared docs at the root, platform docs in
`desktop/`, `mobile/` and `website/`. The project folders below each keep only a `README.md`
pointing back here, so there is a single place to read, edit and review the plan.

| Concern | Plan (all in this tree) | Pointer left in the project | Code lives here |
|---|---|---|---|
| **Cross-platform architecture, TURN fleet, signaling contract, DB schema, entitlements** | this folder's `00`–`07` | — | `w/communications` (**new** backend service — does not exist yet) |
| **Database migrations** | `04-database-schema.md` | — | `w/www/database/migrations/` — tables prefixed `members_communications_*` (see `04`) |
| **Website client** (start/host a meeting, guest join-by-code page) | `website/` | `packages/afsserver/plans/members/video_communications/README.md` | `w/members` |
| **Desktop client** (call UI, screen recording, draw-over-screen, screen share) | `desktop/` | `afsdesktop5/plans/video_communications/README.md` | `afsdesktop5` |
| **Mobile client** (call UI, CallKit/ConnectionService, mobile screen share) | `mobile/` | `afsmobile_flutter/plan/video_communications/README.md` | `afsmobile_flutter` |
| **TURN relay** | `02-turn-infrastructure.md` | — | Coturn, built from `C:\projects\coturn-master`, deployed as an independent fleet — not part of any app repo |

The platform folders cross-reference the shared docs for anything common (signaling contract,
room/session model, entitlements). Do not duplicate those sections — link to them.

---

## Goals

1. **Member hosts, anyone joins.** Starting a meeting requires a member account gated by the
   `communications` entitlement (see `05-feature-tiers-and-entitlements.md`). Joining a meeting
   with a join code requires **no account** — same UX contract as Zoom/Teams.
2. **Works from all three surfaces.** Desktop (Electron/Chromium), web (`w/members`, any
   modern browser), and mobile (Flutter/iOS/Android) can all host and all join the same
   meeting, mixed freely (a desktop host, a web guest, a mobile participant, in one call).
3. **Self-hosted media path.** No third-party video SaaS. TURN relay runs on infrastructure
   we own (Coturn, multi-server — `02-turn-infrastructure.md`). Media routing runs on a
   self-hosted SFU (`01-architecture-and-media-topology.md`).
4. **Scales past a handful of participants.** Group calls use a Selective Forwarding Unit, not
   mesh WebRTC — see `01` §2 for why mesh was rejected.
5. **Entitlements mirror Zoom/Teams' own plan restrictions** — meeting duration caps,
   participant caps, cloud recording availability — wired through the existing Checkpoint
   allocation registry (`05-feature-tiers-and-entitlements.md`), not a bespoke system.
6. **Desktop gets the deepest feature set**: screen recording with live draw-over-screen
   annotation, in addition to being a full call client (`desktop/`).

## Non-goals (v1)

- **Recording editing / NLE.** Recordings are raw or lightly remuxed output; editing them is
  the existing Audio/Video plan's job (`afsdesktop5/plans/audio_video`), not this one's.
- **Third-party calendar/webinar integrations** (Zoom Webinars, registration pages, etc.).
  Meetings are ad-hoc or member-scheduled; no public webinar product in v1.
- **End-to-end encryption beyond standard WebRTC/DTLS-SRTP.** SFU media is decrypted and
  re-encrypted at the SFU by design (required for server-side recording and for any SFU
  topology at all). True E2EE (insertable streams, à la Zoom's E2EE mode) is a possible v2.
- **Telephony (PSTN) dial-in.** No bridge to `TwilioController`'s phone numbers in v1.
- **Live public streaming (RTMP/YouTube/Facebook simulcast out).** Only the WebRTC session
  itself; broadcasting out is a possible v2, not this plan.

---

## Glossary

| Term | Meaning |
|---|---|
| **Member** | An account holder resolved via Checkpoint (`w/auth`), same identity system every other app uses. The unit that can *host*. |
| **Guest** | A participant with no account, holding only a short-lived, room-scoped join token issued by `w/communications` (not Checkpoint). Can *join*, never *host*. |
| **Room** | A persistent, member-owned meeting space with a stable join code. A member can reuse a room across many sessions (like a personal Zoom room). |
| **Session** | One live occurrence of a room being active — from first participant joining to last leaving. |
| **SFU** | Selective Forwarding Unit — the media server each participant uploads one stream to and downloads N-1 streams from, instead of every participant uploading N-1 streams directly (mesh). See `01` §2. |
| **Signaling** | The WebSocket control channel (room join, SDP offer/answer, ICE candidates, producer/consumer events) — carries no media itself. |
| **TURN / STUN** | NAT traversal. STUN discovers a participant's public address; TURN relays media when a direct path isn't possible. Self-hosted via Coturn — `02-turn-infrastructure.md`. |
| **`communications`** | The Checkpoint allocation-registry resource key gating this entire feature — see `05-feature-tiers-and-entitlements.md`. |

## Apps involved and their roles

| App | Role |
|---|---|
| `w/communications` (**new**) | The hub. Signaling server, SFU orchestration, TURN credential issuance, room/session state. Node.js (see `01` §1 for the stack decision). |
| `w/members` | Website host/join UI. Owns the member-facing "Start a meeting" screen and the no-account guest join-by-code page. Talks to `w/communications` over its public API, same as any other client. |
| `w/www` | Owns the database. All `members_communications_*` tables live in `w/www/database/migrations` per the project's existing convention (storage, website, market tables are all migrated from `www` and owned by other apps — see `04` §1). Also owns `resources/js/afs` (member signup — unchanged by this plan). |
| `w/auth` | Identity only. Issues the JWT that proves a host is a member in good standing. Owns `checkpoint_registry_v4.json`, where the `communications` resource is registered (`05`). |
| `afsdesktop5` | Full call client + screen recording + draw-over-screen + screen share. See `desktop/`. |
| `afsmobile_flutter` | Full call client + mobile screen share + native call UX (CallKit/ConnectionService). See `mobile/`. |
| Coturn fleet (`coturn-master` built artifact) | TURN/STUN relay, deployed as multiple independent servers (`s1.conference.members.appfactory.studio`, `s2...`, ...). Not part of any app's codebase — infrastructure. See `02-turn-infrastructure.md`. |

---

## Document index

| Doc | Covers |
|---|---|
| `00-overview.md` (this file) | Problem, goals, non-goals, apps involved, where code lives |
| `01-architecture-and-media-topology.md` | SFU vs. mesh decision, stack choice for `w/communications`, end-to-end media/signaling flow |
| `02-turn-infrastructure.md` | Coturn build/config from `coturn-master`, multi-server fleet, `sN.conference.members.appfactory.studio` naming, load-balancing strategy, credential issuance |
| `03-signaling-and-session-model.md` | Room/session data model, host-is-a-member rule, guest-by-code join, waiting room, signaling message contract |
| `04-database-schema.md` | `members_communications_*` tables in `w/www`, why they live there, storage reuse |
| `05-feature-tiers-and-entitlements.md` | The `communications` resource in `checkpoint_registry_v4.json` / `TODO-member-resources.md`, Zoom/Teams-style tier mirroring |
| `06-security.md` | Guest token scoping, defense in depth, TURN secret handling |
| `07-rollout-plan.md` | Phases, dependencies between the platform plans, acceptance criteria |

**Platform plans** — same directory tree, one subfolder each:

| Folder | Covers | Start at |
|---|---|---|
| `desktop/` | Electron call client, screen recording, draw-over-screen, screen share, advanced features | [`desktop/00-overview.md`](desktop/00-overview.md) |
| `mobile/` | Flutter call client, CallKit/ConnectionService, mobile screen share | [`mobile/00-overview.md`](mobile/00-overview.md) |
| `website/` | `w/members` host UI and the no-account guest join-by-code page | [`website/00-overview.md`](website/00-overview.md) |
