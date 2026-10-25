# 04 — Screen share in a call

Distinct from `02-screen-recording.md`: this is presenting a screen/window to other
participants live, not saving a file. Both use `desktopCapturer` as their capture primitive
(`02` §1) but diverge from there.

## 1. Mechanism

The captured `MediaStream` (full screen or a single window, same source picker as recording) is
published to the LiveKit room as a screen-share track (`publishTrack` with the LiveKit JS
client's screen-share track type) rather than recorded locally. LiveKit forwards it to other
participants through the SFU exactly like a camera track — no new server-side handling needed
beyond what `../01-architecture-and-media-topology.md` already
specifies.

## 2. Interaction with draw-over-screen

Sharing a screen while the draw-over-screen overlay (`03`) is active and covering that same
region means other call participants see the ink live, the same way it would appear in a
recording (`03` §4) — including the same single-window-capture caveat (`03` §4's last bullet):
sharing a specific application window does not show ink from a full-screen overlay layered
above it.

## 3. Interaction with recording

A call session can be simultaneously **shared to participants** and **recorded** (either the
host's local screen recording, `02`, or the call's own cloud recording,
`../04-database-schema.md` §2) — these are independent capabilities
that happen to reuse the same `desktopCapturer` source, not mutually exclusive modes.

## 4. UI

- "Share screen" / "Share window" entry point in the call toolbar (`01` §3), alongside
  mute/camera.
- A distinct visual state (border highlight, toolbar indicator) showing *what* is currently
  shared, and a one-click "stop sharing" that doesn't require finding the original share
  dialog again.
- Only one participant's screen share is the "main" shared view at a time in v1 — simultaneous
  multi-share (Teams-style) is listed as an advanced feature, not baseline (`05`).
