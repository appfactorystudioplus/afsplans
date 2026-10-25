# 04 — Advanced features

## Completion document

**Status:** §2 (reactions/hand) and §4 (battery/data) done; §3 (whiteboard) done as a
read-and-annotate view, not a full editor. §1 (live captions) explicitly not attempted — no
server-side captioning infrastructure exists anywhere yet, matching desktop's own deferral. See
[`complete/04-advanced-features.complete.md`](complete/04-advanced-features.complete.md).

Mobile-appropriate subset of
[`../desktop/05-advanced-features.md`](../desktop/05-advanced-features.md)
— not every desktop advanced feature makes sense here, and one mobile-specific concern (battery)
doesn't apply to desktop.

## 1. Live captions

Same value proposition as desktop (`afsdesktop5` `05` §1), but this app has **no existing
Whisper integration** to reuse (`00` §1 — this app is presently a demo with no native speech
infrastructure). Two paths: run captioning server-side (send audio to `w/communications` or a
transcription service and stream captions back over the signaling channel — no on-device model
needed) or bundle an on-device model (heavier, offline-capable, but real engineering weight on
a platform with tighter binary-size and battery constraints than desktop). **Recommendation:
server-side** for v1, revisit on-device only if latency or cost becomes a problem — this avoids
this plan needing to port `whisper_bridge`'s native integration work to mobile at all.

## 2. Reactions and raised hand

Same mechanism as desktop (`afsdesktop5` `05` §4 — LiveKit data-channel messages), directly
portable, low effort.

## 3. Meeting whiteboard

Same value proposition as desktop (`afsdesktop5` `05` §2), but this app has no existing
Excalidraw-equivalent dependency to reuse. A simpler read-and-annotate view (participant can
view the shared board and add strokes, without needing the full editing feature set) is likely
the right scope for a phone-sized screen regardless of what library backs it — full parity with
a desktop whiteboard editor is not a realistic mobile UX goal.

## 4. Battery and data usage

Mobile-specific, no desktop equivalent: video calls are expensive on both battery and cellular
data. Baseline behaviors worth planning for, not just accepting as a given:

- Default to a lower video resolution/bitrate on cellular vs. Wi-Fi (LiveKit supports adaptive
  simulcast — this is largely a client-side subscription-quality preference, not new
  infrastructure).
- Explicit audio-only mode (camera off by default when joining on cellular, with a clear
  one-tap way to turn it on) as a data-saving option, not just a side effect of the member
  manually muting video.

## 5. Not planned for mobile

Screen recording and draw-over-screen — see `00` §2's non-goals and `03` §3's scope note.
Breakout rooms — listed on desktop as needing master-plan-level design first
(`afsdesktop5` `05` §5); if built, the mobile client consumes whatever room-model extension
that produces rather than this plan designing it independently.
