# 06 — Delivery plan

## 1. Phases

**Phase A — Call client (`01`)**
Depends on `w/communications` existing and the signaling contract being stable
(`../07-rollout-plan.md` Phase 1–2). Cannot start meaningfully before
then — build against a mocked/staging `w/communications` if desktop work needs to start early.

**Phase B — Screen recording (`02`)**
Independent of the call client — a standalone recording has no entitlement check and no
`w/communications` dependency at all (`02` §5). Can be built and shipped before Phase A if
sequencing favors it.

**Phase C — Draw over screen (`03`)**
Independent of both A and B individually, but its value is highest once B exists (draw +
record together is the combined pitch). No hard dependency either way.

**Phase D — Screen share in call (`04`)**
Depends on Phase A (needs a live call to share into).

**Phase E — Advanced features (`05`)**
Each sub-feature has its own dependency noted in `05` — live captions needs nothing new
(Whisper already shipped), the meeting whiteboard and reactions need Phase A's data-channel
plumbing, breakout rooms need coordination with the master plan first.

## 2. Suggested build order

```
02 (recording) ──┐
                  ├──> 03 (draw-over-screen) ──> [combined record+draw demoable]
   (no dependency)┘
01 (call client, blocked on w/communications) ──> 04 (share-in-call) ──> 05 (advanced)
```

Recording and draw-over-screen can genuinely ship as a standalone desktop feature — record your
screen with live annotation, no call involved — well before the call client is ready, if that
sequencing is preferred. This is worth calling out because it means the two features the user
asked about specifically (screen recording, draw-over-screen) are **not blocked on the rest of
this plan or on `w/communications` existing at all**, unlike the call-client and share-in-call
work.

## 3. File manifest (indicative, finalized at implementation time)

| Area | Likely location |
|---|---|
| Call UI, participant grid, chat | `src/renderer/components/communications/` (new) |
| Screen recording controller | `src/main/communications/recording.ts` (new) — main-process, owns `desktopCapturer` source enumeration |
| Draw-over-screen overlay window(s) | `src/main/communications/overlay.ts` (new) + a small renderer bundle for the canvas |
| Recording → FFmpeg remux/transcode handoff | Reuses `src/main/media/` (existing, from the Audio/Video plan) |
| Live captions | Reuses `src/native/whisper_bridge.*` (existing) via `src/main/media` or a new thin wrapper |
| Meeting whiteboard | Reuses `@excalidraw/excalidraw` + patterns from `src/renderer/components/flow/views/DrawView.tsx` |

## 4. Testing

- Manual: record + draw simultaneously, verify ink appears in output file, on Windows and macOS
  at minimum (Linux best-effort per `02` §3's audio caveat).
- Manual: a 2-person and a 6+-person call across desktop + web (once website client exists) to
  validate the SFU path end to end, not just desktop-to-desktop.
- Manual: sleep/resume and network-drop reconnection (`01` §4).
- Automated: unit coverage for anything that isn't purely UI — recording source selection
  logic, overlay display-add/remove tracking (`03` §2), FFmpeg remux argument construction
  (extends the existing `scripts/test_media_addon.js`-style pattern already used elsewhere in
  this repo, per `package.json`'s `test-media` script).

## 5. Acceptance

Matches `../07-rollout-plan.md` §3's baseline criteria for the desktop
rows specifically: screen recording with visible draw-over-screen ink in the output, a call
survives sleep/resume, and desktop can both host and join using only a code.
