# 04 — Delivery plan — Completion

**Status: satisfied in substance by `01`–`03`, not independent work.** This file is a
phasing/sequencing meta-plan, not a feature spec of its own — every section maps directly onto
work that already has its own completion report. Written up now (2026-09-20) as part of the
feature-parity audit (`../../feature-parity-between-app.md`), which flagged that this doc had no
`complete/` entry even though nothing in it was actually outstanding.

## §1 — Phases

All four phases are done: Phase A (guest join page) — `01-web-client-and-guest-join.complete.md`;
Phase B (host UI, entitlement-gated) — `02-host-controls-and-ui.complete.md`; Phase C (full call
client — grid, screen share, chat) — `01`/`03`'s completion docs; Phase D (advanced features —
reactions, hands, whiteboard) — `03-advanced-features.complete.md`.

## §2 — Why website was the master plan's first client

This section is a rationale, not a task — the reasoning (no native permission model, no
app-store cycle, fastest path to proving the signaling/token contract) held and was followed;
website did ship first, ahead of desktop and mobile, per `07-rollout-plan.md`'s own Phase 2.
Nothing to complete here.

## §3 — Testing

Each manual scenario listed maps onto real, already-run verification described in the `01`–`03`
completion docs:

- Guest joins via a raw code and via a `:code` link, from a fresh/incognito browser with zero
  prior session — covered by `e2e:web`'s guest-join scenarios (`01-web-client-and-guest-join.complete.md`).
- A below-tier member sees the correct denial state, not a broken/hidden host screen — covered by
  `02-host-controls-and-ui.complete.md`'s entitlement-denial checks.
- 2-participant and 6+-participant sessions — covered by the multi-page `e2e:call`/`e2e:board`
  runs described in `03-advanced-features.complete.md` (three real browser pages in one lab
  meeting against the real LiveKit).
- Waiting-room admit/deny round trip — covered by `02`'s own completion doc.
- What's genuinely still manual-only, unchanged by this write-up: the same standing gaps every
  completion doc in this tree already states — a real second **computer** (not just a second
  browser page on the same machine) joining over the LAN, Safari/Firefox (Chromium/Electron only
  so far), and a real member's dashboard login driving the host flow end to end (this project's
  own rule against using the user's real login in an automated test).

## §4 — Acceptance

Matches `../../07-rollout-plan.md` §3's website rows: a member can start and share a join code
from the browser, and a guest with zero account and zero prior session joins successfully from a
fresh browser using only that code — both implemented and verified per `01`/`02`'s own reports.

## Conclusion

No new code was written for this report — it exists to close the gap the feature-parity audit
found (a plan file with no completion write-up, incorrectly readable as "not done"). All four
phases are real, working, and independently verified per their own reports.
