# 04 — Delivery plan

## 1. Phases

**Phase A — Guest join page (`01` §2)**
Depends only on `w/communications` existing with a working `POST /sessions/join`
(`../07-rollout-plan.md` Phase 1) — no member auth involved, so this
can be the very first end-to-end thing validated once the backend exists.

**Phase B — Host UI (`02`)**
Depends on the `communications` entitlement being live in `checkpoint_registry_v4.json`
(`../05-feature-tiers-and-entitlements.md`, landed as part of the
master plan's Phase 0) and on `POST /rooms` existing.

**Phase C — Full call client (`01` §1, §3)**
Grid UI, screen share, chat — builds out once Phases A and B prove the connection flow.

**Phase D — Advanced features (`03`)**
Per-feature dependencies as noted there.

## 2. Why this is the master plan's Phase 2 (first client)

Per `../07-rollout-plan.md` §2 Phase 2: no native permission model, no
app-store review cycle, no platform-specific call UX to build before a basic join/host flow is
testable — this plan is explicitly the fastest path to proving the signaling/token contract
before desktop (`../desktop/`) and mobile
(`../mobile/`) start building against it.

## 3. Testing

- Manual: guest joins via a raw join code, and separately via a `:code`-embedded link, from a
  browser with zero prior session/cookie state (incognito) — this is the realistic guest
  scenario and must not accidentally depend on any authenticated session existing.
- Manual: a member below the `communications` tier sees the correct denial state (`02` §1), not
  a broken or silently-hidden host screen.
- Manual: 2-participant and 6+-participant sessions, mixing web guests with a web host, to
  validate the SFU path before cross-platform (desktop/mobile) testing begins.
- Manual: waiting-room admit/deny round trip (`02` §4).

## 4. Acceptance

Matches `../07-rollout-plan.md` §3's baseline for the website rows: a
member can start and share a join code from the browser; a guest with zero account and zero
prior session joins successfully from a fresh browser using only that code.
