# 07 — Rollout plan

## 1. Dependency order across the shared and platform plans

```
02-turn-infrastructure.md ──────┐
                                 ├──> 01-architecture-and-media-topology.md (LiveKit decision)
checkpoint_registry_v4.json ────┤         │
 + TODO-member-resources.md     │         v
 (communications resource)  ────┘   03-signaling-and-session-model.md
                                          │
                                          v
                                   04-database-schema.md (w/www migrations)
                                          │
                                          v
                              w/communications service exists
                                          │
                    ┌─────────────────────┼─────────────────────┐
                    v                     v                     v
        desktop/                    website/                    mobile/
     (desktop client)            (website client)            (mobile client)
```

The three client plans cannot meaningfully start until `w/communications` exists and the
signaling/token contract (`03`) is stable — each client plan says so explicitly and links back
here rather than restating it.

## 2. Phases

**Phase 0 — Infrastructure and entitlements**
- Stand up the first Coturn server (`s1`) per `02`, confirm TURN REST API credentials work
  against it manually.
- Land the `communications` resource in `checkpoint_registry_v4.json` and
  `TODO-member-resources.md` (`05`) — done as part of this planning pass, not deferred.
- Decide and record the LiveKit-vs-mediasoup call (`01` §1) before writing any
  `w/communications` code.

**Phase 1 — `w/communications` core**
- Room/session/participant REST endpoints (`03` §2–3).
- LiveKit token minting with entitlement checks (`05` §6).
- `members_communications_*` migrations land in `w/www` (`04`).
- Signaling WebSocket for waiting-room/host-control/chat events (`03` §5).

**Phase 2 — One client, end to end**
- Pick **website** as the first client (`website/`)
  — it has the fewest platform-specific concerns (no native call UX, no screen-capture
  permission model) and proves the signaling/token contract fastest.
- Host from the web, join as a guest from the web, confirm join-code flow, waiting room, and
  basic recording end to end before touching desktop or mobile.

**Phase 3 — Desktop and mobile clients**
- `desktop/` — call client first, then screen recording +
  draw-over-screen (the desktop-specific asks), then screen-share-in-call.
- `mobile/` — call client first, then native call UX
  (CallKit/ConnectionService), then mobile screen share.
- These two can proceed in parallel once Phase 2 has validated the contract.

**Phase 4 — Fleet scale-out and advanced features**
- Add `s2`, `s3`, ... once real load data justifies it (`02` §6).
- Advanced features catalog per client plan's own `advanced-features.md` (live captions,
  virtual backgrounds, breakout rooms, meeting whiteboard, etc.) — prioritized after the
  baseline call/record/join-by-code experience ships, not before.

## 3. Acceptance criteria for the baseline (end of Phase 3)

- A member on any one of desktop/web/mobile can start a meeting and get a join code.
- A guest with no account can join that meeting from any of the three surfaces using only the
  code.
- 2, and separately 6+, participants can hold a call with usable audio/video (validating both
  the trivial and the SFU-necessary case).
- A free-tier host's 3+-participant session is cut off at the configured cap; a paid-tier
  host's is not.
- A Professional-tier-or-above host can start and retrieve a cloud recording; a Free/Starter
  host is correctly denied.
- Desktop can record its screen with live draw-over-screen annotations visible in the output.
- Losing and regaining network mid-call reconnects to the same session without a new join code.

## 4. Testing on more than one computer (vagrant lab)

The baseline acceptance in §3 needs **separate computers**: "2, and separately 6+, participants" and "losing and
regaining network mid-call" mean nothing when every client and every server is on one machine. Until this section
existed the lab could only be reached from the PC that hosts it.

**How the lab is reached from another computer** (built 2026-09-19; the VMs `web` and `web2` each have a second, bridged
network card on the router's own network, see `C:\projects\vagrant\AFS-DEPLOYMENT-VAGRANT.md` §8):

| | |
|---|---|
| Servers | `web` = `10.0.0.240` (apps, `w/communications`, LiveKit, Coturn `s1`), `web2` = `10.0.0.241` (Coturn `s2`) |
| Names | The lab DNS is not reachable from other computers, so each one needs hosts-file entries for the `*.appfactory.test1` names (the `s1`/`s2` TURN names too, because the service hands them to every client) |
| Trust | The mkcert root CA (`certs/mkcert-rootCA.pem`, public part only) installed on that computer. Camera and microphone need `https://`, so a plain-http address cannot host a call |
| What must be true of the servers | LiveKit offers **both** its host-only and its router-network address (never `node_ip`, which names one); Coturn listens and relays on both, and allows LiveKit's router-network address as a relay peer |

The hand-off for a second computer (setup, the tests below, what to report) is the macOS guide written for that machine:
`D:\AFS-LAB-MACOS-TEST-GUIDE.md`. Tests that only make sense with a second computer, and the plan item each one closes:

- Guest joins a host on another computer by code, with the media path on the LAN (`website/04` §3, first bullet;
  `01` "media across a real network").
- Three or more participants, including Safari and Firefox (`website/04` §3; `website/01` §5's Safari warning).
- Waiting-room admit/deny across computers (`website/02` §4).
- Network drop and rejoin from a real client (`03` §6; §3's last bullet).
- A relay-only client on a second computer (`02`; verified so far only on one machine).

Not covered by this setup: a client **outside** the router's network (a phone on mobile data, another house). That needs
LiveKit and Coturn on public addresses and is the production deployment's job (`packages/afsserver/docker/builds/video_comminucation/`).
