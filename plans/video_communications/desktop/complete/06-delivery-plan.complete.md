# 06 — Delivery plan — Completion

**Status: satisfied in substance by `01`–`05`, not independent work.** This file is a
phasing/sequencing meta-plan, not a feature spec of its own — every section maps directly onto
work that already has its own completion report. Written up now (2026-09-20) as part of the
feature-parity audit (`../../feature-parity-between-app.md`), which flagged that this doc had no
`complete/` entry even though nothing in it was actually outstanding.

## §1–2 — Phases and build order

All five phases (A call client, B screen recording, C draw-over-screen, D screen-share-in-call, E
advanced features) are done — see `01-webrtc-client.complete.md` through
`05-advanced-features.complete.md` in this folder. The suggested build order (recording/draw
first, independent of `w/communications`; call client blocked on the backend; share-in-call and
advanced features after) is exactly the order they were actually built in.

## §3 — File manifest

The manifest here was explicitly marked "indicative, finalized at implementation time," and the
real paths differ from the prediction — not a gap, just how it actually landed: call UI lives at
`src/renderer/components/call.ts` + `src/renderer/call/*` (not
`src/renderer/components/communications/`), and screen recording/draw-over-screen live under
`src/main/videoComms/*` (the pre-existing local screen-recorder module, reused rather than a new
`src/main/communications/` tree). No action needed; this section was never meant to be binding.

## §4 — Testing

Substance is met, mapped onto the actual test scripts:

- Automated unit coverage: `scripts/test_call.ts` (`npm run test-call`, 111 checks as of the
  breakout-rooms work) covers messages/session/joinFlow/communicationsApi/hostApi/
  `mapDisconnectReason` — the reconnection-reason classification this section calls for.
  `scripts/test_video_comms.ts` (`npm run test-video-comms`) covers recording file-naming/
  mime→extension logic and the draw-over-screen undo stack. `scripts/test_media_addon.js`
  covers FFmpeg stream-copy remux, matching this section's own "extends the existing
  `test_media_addon.js`-style pattern" description exactly.
- Manual/Electron-dependent pieces have real e2e scripts (`scripts/smoke_call.js`,
  `scripts/smoke_video_comms.js`, `scripts/smoke_breakout.js`), each run against the live vagrant
  lab per their own completion reports.
- What's genuinely still manual-only, unchanged by this write-up: a real 2-person/6+-person call
  across **separate computers** (the lab was one machine until `07-rollout-plan.md` §4's LAN
  work), Windows-vs-macOS recording parity, and sleep/resume on real hardware. Same standing
  limitation every other completion doc in this tree already states — not specific to this file.

## §5 — Acceptance

Matches `../../07-rollout-plan.md` §3's desktop rows: screen recording with visible draw-over-
screen ink in the output (`02`/`03`), a call surviving sleep/resume (`01` §4), and desktop hosting
and joining by code alone (`01`) are all implemented and unit/e2e-tested as described above.

## Conclusion

No new code was written for this report — it exists to close the gap the feature-parity audit
found (a plan file with no completion write-up, incorrectly readable as "not done"). All five
phases are real, working, and independently verified per their own reports.
