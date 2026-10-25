# 00 — Overview

## Completion document

When this plan step is completed, write a completion document in this directory's `complete/`
folder. Name it using this file's basename with `.complete.md` appended. Include status,
summary, files changed, verification steps, and any follow-up work.

**Status (2026-09-19):** Partly implemented. `01` (guest join page, basic call client) and `02` (host controls, Meetings
screen) are built and verified in the lab, see `complete/`. `03` (screen share, chat, and the signaling WebSocket chat needs)
is not started.
**Target:** The website (`w/members`) half of the video conferencing system — a member-facing
"start a meeting" screen, host controls, and a **no-account** join-by-code page any visitor can
use.

---

## This plan's place in the larger system

This is the website-specific slice of a cross-platform plan. Shared architecture (SFU choice,
signaling contract, room/session model, TURN fleet, entitlements) is specified once in
[`../`](../00-overview.md) and is not repeated
here — read that tree first, specifically `01`, `03`, and `05`.

Per the master plan's Phase 2 (`../07-rollout-plan.md`), **the website
client is built first** among the three — it has no native call UX, no OS screen-capture
permission model, and no app-store distribution cycle to work around, making it the fastest
path to validating the signaling/token contract end to end before desktop and mobile start.

## Document index

| Doc | Covers |
|---|---|
| `00-overview.md` (this file) | Scope, why website goes first, where code lives |
| `01-web-client-and-guest-join.md` | LiveKit JS client in-browser, the no-account join-by-code page |
| `02-host-controls-and-ui.md` | Member-facing start/host screen, role-based controls |
| `03-advanced-features.md` | Website-appropriate advanced features |
| `04-delivery-plan.md` | Phases, testing, acceptance |

---

## 1. Scope

### In

1. **Host UI** — a member-facing "Start a meeting" screen in `w/members`, gated by the
   `communications` entitlement (`../05-feature-tiers-and-entitlements.md`),
   surfacing the room's join code/link and host controls (`02`).
2. **Guest join page** — a public, unauthenticated page at a stable URL pattern
   (`w/members`'s public routes, no login wall) where anyone with a code or link joins
   immediately (`01` §2) — the website is the natural home for this specific flow, since a
   guest clicking a shared link with no app installed should land in a browser, not be asked to
   install anything.
3. **In-browser call client** — full participant experience (grid, mute, chat, screen share)
   using LiveKit's web SDK (`01`).

### Out

- Screen recording and draw-over-screen — desktop-only asks, not requested for web
  (`../desktop/00-overview.md` §1). A browser tab can screen-share
  *into* a call (`01` §3, standard `getDisplayMedia`) but that is call participation, not a
  recording/annotation product.
- Any native app installation prompt/gate on the guest join page — it must work with nothing
  but a browser, matching the "anyone can join the session with a code... they do not need an
  account" requirement precisely.

## 2. Where code lives

Per the master plan's `00-overview.md` table: UI and routes in `w/members` (this is the
"website belongs to `w/members`" project the user specified); backend (rooms, sessions, token
minting, entitlement checks) in `w/communications`, not in `w/members` itself — `w/members`
calls that backend's public API exactly as the desktop and mobile clients do, over HTTP/WebSocket,
not through any shared PHP code. `w/members` already talks to backend services this way for
other features (`AfsConnectTokenController`, `AfsConnectSettingsController` — existing
controllers in `w/members/app/Http/Controllers/` show the established pattern of a thin
Laravel controller calling out to another service and rendering the result, which this feature
follows for its own `w/communications` calls).
