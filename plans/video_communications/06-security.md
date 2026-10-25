# 06 — Security

Mirrors `plans/afs-connect/06-security-and-service-auth.md`'s "defense in depth" principle:
every cross-service read/write is re-authorized at the service that owns the data, never
trusted solely because it arrived from another AFS service.

## 1. Guest tokens are room-scoped and short-lived

A guest never authenticates against Checkpoint — by design, joining with a code requires no
account (`00` goal 1). The LiveKit token `w/communications` mints for a guest (`03` §3) is
scoped to exactly one room, carries no member identity, and expires on a short TTL refreshed by
the client's reconnection flow (`03` §6), not extended indefinitely. A leaked guest token is
therefore only useful for the room it was issued for, only until it expires, and grants no
access to anything else in the AFS estate — it is not a Checkpoint credential and cannot be
exchanged for one.

## 2. The TURN shared secret

The `static-auth-secret` (`02-turn-infrastructure.md` §4) is the one credential that, if
leaked, is valid across the **entire** Coturn fleet. It is:

- Stored only in `w/communications`'s server-side config and each `sN`'s `turnserver.conf` —
  never sent to any client, never present in any client-side bundle (desktop, web, or mobile).
- Rotated independently of any single server's compromise — because it's fleet-wide, rotation
  is one operation (update `w/communications` + push to every `sN`), not N operations, which is
  the operational tradeoff accepted for the fleet-wide (vs. per-server) design in `02` §4.
- Never used directly by a client — clients only ever receive the derived, time-limited
  `username`/`password` pair (`02` §4), which is useless once expired even if captured.

## 3. Recording access

A session recording (`04` §2) is stored against the hosting member's own storage allocation
and is only ever readable by that member through the same access-control path as any other file
in `members/storage_nodes` — this plan adds no new recording-specific ACL, deliberately, so it
inherits whatever access rules storage already enforces rather than becoming a second place
that access logic has to be kept in sync.

## 4. Host controls are re-checked server-side

Waiting-room admission, mute-all, and role promotion (`03` §4–5) are host/co-host-only actions.
`w/communications` checks the acting participant's role from its own
`members_communications_participants` row on every such request — the signaling WebSocket
message itself is not trusted to prove role, since a compromised or modified client could
otherwise send a `host.mute_all` or `waiting_room.decision` event it has no right to send.

## 5. Recording consent

**Decision needed**, not resolved by this plan: several jurisdictions require all-party consent
before recording a call. Every client plan (desktop `05`, mobile `04`, website `03`) should
surface a clear on-screen recording indicator to all participants the moment
`recording.status` (`03` §5) reports `active`, and this document flags that a consent/notice
requirement should be confirmed with whoever owns compliance before recording ships, not after.
