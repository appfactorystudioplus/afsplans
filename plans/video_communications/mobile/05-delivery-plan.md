# 05 — Delivery plan

## Completion document

**Status:** Phase 1's host flow (start a meeting, waiting-room admit/deny, mute-all, end meeting)
is built as a bounded v1 slice — recording, breakout rooms, and co-host promotion deliberately
excluded. §2's master-plan dependency is now satisfied (already built against a real
`w/communications`). §3/§4 (testing/acceptance) remain entirely manual and device-dependent, not
exercised this session. See
[`complete/05-delivery-plan.complete.md`](complete/05-delivery-plan.complete.md).

## 1. Phases

**Phase 0 — real Checkpoint authentication — removed, already satisfied.** An earlier draft of
this plan assumed this app had only demo auth and needed a whole prerequisite phase to build
real Checkpoint login. As built (2026-09-21): `checkpoint-server-auth/08-mobile-client-auth.md`
already shipped real, primary-only-enforced Checkpoint auth in this app (`00` §1) — there is
nothing to build here. Both guest joining and hosting can start directly from Phase 1.

**Phase 1 — Call client (`01`)**
Guest join-by-code first (matches the desktop client's own sequencing — proves the call
connection and UI before layering host-only controls on top), host flow immediately after using
the already-existing `AuthBloc`.

**Phase 2 — Native call UX (`02`)**
Incoming-call push/CallKit/ConnectionService, background handling. Depends on Phase 1's call
connection existing to ring into.

**Phase 3 — Mobile screen share (`03`)**
Independent of Phase 2, depends only on Phase 1's live call existing.

**Phase 4 — Advanced features (`04`)**
Per-feature dependencies as noted in `04` itself.

## 2. Dependency on the master plan

Cannot productively start before
`../07-rollout-plan.md` Phase 1
(`w/communications` core + stable signaling contract) — same constraint as the desktop client
(`../desktop/06-delivery-plan.md` §1), and the master plan's own
Phase 2 recommends validating the contract against the **website** client first, precisely so
that by the time mobile (and desktop) start, the contract they're building against has already
been proven once.

## 3. Testing

- Manual: guest join via a shared link/code on both iOS and Android physical devices (simulator
  camera/mic behavior is unreliable enough not to trust for this).
- Manual: incoming call while app is backgrounded and while fully killed (cold start into
  call).
- Manual: screen share permission flow on both platforms, including the case where the member
  denies the OS permission prompt — must fail gracefully, not crash or hang.
- Manual: cellular-to-Wi-Fi handoff mid-call (reconnection, `01` §5).

## 4. Acceptance

Matches `../07-rollout-plan.md` §3's baseline for
the mobile rows: host and join using only a code, from a real member account (already possible —
`checkpoint-server-auth/08-mobile-client-auth.md`); a call survives backgrounding and
network-type changes without a new join code.
