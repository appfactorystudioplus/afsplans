# 06 — Security — completion

**Status:** Verified against the current code (2026-09-19). This plan document describes properties that other steps'
implementations (`02` TURN, `03` signaling, website `02` host controls, website `03` recording) were responsible for
actually building; this step's own work is auditing that they hold true, not writing new infrastructure. Four of five
properties are confirmed true in the code. The fifth (recording access) is true only because the feature it would apply
to isn't built yet, and one item (§5, recording consent) is explicitly a decision for the user/compliance owner, not
something resolved here.

## Summary

| § | Property | Verified |
|---|---|---|
| 1 | Guest tokens are room-scoped, carry no member identity, short TTL, refreshed on rejoin (not extended indefinitely) | **True** |
| 2 | `TURN_STATIC_AUTH_SECRET` never reaches any client; only derived, time-limited username/password pairs do | **True** |
| 3 | Recording access has no recording-specific ACL, inheriting `members/storage_nodes`'s access rules | **True, but vacuous** — no retrieval endpoint exists yet at all (see below) |
| 4 | Waiting-room admission, mute-all, and role promotion re-check the acting participant's role from the service's own DB row, not from client-supplied claims | **True** |
| 5 | Recording consent | **Not resolved** — flagged as a decision needed from whoever owns compliance, added to `TODOS.md` §5 |

## Evidence

**§1 Guest tokens.** `w/communications/src/services/meetingService.ts:264` mints guest identity as `` `g:${uuid()}` ``
(random, no member reference) versus a host/member's `` `m:${memberReferenceNum}` `` (`meetingService.ts:113`). Tokens are
built in `buildJoin()` (`meetingService.ts:531-539`) via `livekitGateway.ts:79-95`, which grants exactly one
`{ room: request.roomName }` — no broader scope. TTL is `LIVEKIT_TOKEN_TTL_SECONDS` (`config.ts:107`, default 1800s).
Reconnection (`rejoin()`, `meetingService.ts:508-525`) verifies an HMAC-signed `RejoinClaims` token
(`domain/rejoinToken.ts`) and mints a **fresh** LiveKit token for the same `participantUuid` — the rejoin token itself
has its own TTL (`rejoinTtlSeconds`, default 86400s) and is re-signed on every admit (`meetingService.ts:555-559`),
not extended in place.

**§2 TURN secret.** `w/communications/src/domain/turn.ts:51-61` derives a time-limited `username:expiry` /
HMAC-SHA1 password pair server-side from `TURN_STATIC_AUTH_SECRET` (loaded via `secret()`, `config.ts:111`); only
`{ username, credential, expiresAtUnix }` and ICE server URLs are sent to clients (`buildIceServers`,
`turn.ts:105-120`). A grep of `w/members/resources/js` for the secret name or `turn_static` returns zero matches.

**§3 Recording access.** No retrieval endpoint exists: `w/communications/src/http/app.ts` only exposes
`POST …/recording/start`, `POST …/recording/stop`, and `GET …/recordings` (list/metadata). `Recording.location`
(`domain/types.ts:108-118`) is stored but is never returned to a client — `RecordingView` in `recordingService.ts:20-28`
omits it entirely. This matches `TODOS.md` §3 ("members cannot get the file yet"). The plan's claim ("no new
recording-specific ACL") is therefore true only in the trivial sense that there's nothing yet to gate — it will need
re-checking once retrieval is built.

**§4 Host controls.** `w/communications/src/services/sessionAccess.ts:66-88` `authorize()` verifies the rejoin token
cryptographically, then reads the caller's current `status`/`role` fresh from `ParticipantRepository.findByUuid`
on every call (comment at line 83: "a demotion or removal must bite at once") — never from the token payload, which
carries only `participantUuid`/`sessionUuid`/`expiresAtUnix` (`rejoinToken.ts:18-23`), and never from the request body.
Waiting-room decide (`meetingService.ts:341-342`), mute-all (`meetingService.ts:387-388`), and `setRole` (`:401-402`,
which specifically requires `'host'` authority, not just `'moderator'`) all call `access.authorize(...)` before acting.

**§5 Recording indicator (built) vs. consent (not resolved).** The on-screen indicator the plan asks for is built:
LiveKit's `RoomEvent.RecordingStatusChanged` is a room-wide broadcast every connected participant receives, not a
host-only signal. `w/members/resources/js/conference/callController.ts:91,179,362` subscribes to it and sets
`recording: room.isRecording`; `views/conference.ts:802-805` toggles the `#conf-rec` badge from that shared state in
the base call template used by hosts, co-hosts, *and* guests (comment: "LiveKit tells everyone, so everyone sees the
same REC indicator (consent)"). What the plan explicitly leaves open — whether a passive indicator satisfies all-party
consent requirements in every jurisdiction this ships to, or whether an explicit acknowledgment step is needed — is
a legal/compliance call, not an engineering one, so it isn't resolved here. Added as a tracked item:
`TODOS.md` §5 "Recording consent — decision needed, not resolved by this plan."

## Files changed

| Where | What |
|---|---|
| `packages/afsserver/plans/TODOS.md` | Added the recording-consent decision item under "Video communications" §5 |
| `packages/afsserver/plans/video_communications/complete/06-security.complete.md` (this file) | New |

No application code changed — this step is a verification pass; §§1, 2, 4 were already correct as built by earlier
steps, and §3 has nothing to check yet because retrieval doesn't exist.

## Not verified / follow-up

- §3 needs re-verification once recording retrieval (`TODOS.md` §3) is built — inheriting `storage_nodes` access rules
  is only a plan intention right now, not code.
- §5's consent decision needs an answer from whoever owns compliance before recording ships to production; nothing
  in this codebase currently blocks recording on that answer.
- No penetration-style testing was done (e.g., actually attempting to forge a rejoin token or role claim); verification
  here is a code-reading audit of the enforcement paths, consistent with how `04`'s and `05`'s completion reports were
  verified (unit/integration tests plus reading the enforcement code), not a red-team exercise.

See `01-architecture-and-media-topology.complete.md`.
