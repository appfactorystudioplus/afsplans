# 01 — WebRTC call client

## 1. Where this runs

Entirely in the renderer process (`src/renderer/components/`, a new `communications/` or
`videoAudio`-sibling module — final naming decided at implementation time against
`src/renderer/views.ts`'s existing view registry). Electron's renderer is Chromium, so LiveKit's
web/JS client SDK (`01` decision in the master plan) runs unmodified — no Electron-specific
WebRTC shim is needed, unlike screen capture (`02`/`04`) which does need Electron's
`desktopCapturer` because browser `getDisplayMedia` alone can't enumerate windows the way a
desktop app's screen-share picker needs to.

## 2. Call lifecycle against the shared contract

1. Member clicks "Start a meeting" → main process already holds a Checkpoint session (however
   this app currently authenticates the member for other member-gated features — reuse that,
   do not build a second auth path) → renderer calls `w/communications`'s
   `POST /rooms` / `POST /rooms/:id/sessions` (`../03-signaling-and-session-model.md` §2).
2. Renderer receives a LiveKit token + the two-candidate TURN server list
   (`../02-turn-infrastructure.md` §5) and connects via LiveKit's client SDK.
3. Join code is surfaced prominently (copy-to-clipboard, and — advanced feature, `05` —
   optionally sent via the app's existing notification/invite channels) for the host to share.
4. A participant joining via code (host or guest) goes through the same
   `POST /sessions/join` flow (`../03-signaling-and-session-model.md` §3) — desktop join is not
   privileged over web/mobile join in any way.

## 3. UI surface (v1)

- Participant grid with per-tile mute/video-off indicators.
- Self-view with camera/mic/speaker device pickers (Electron's `navigator.mediaDevices`).
- Mute, camera toggle, leave, and — host/co-host only — mute-all, admit-from-waiting-room,
  end-meeting-for-all, matching `../03-signaling-and-session-model.md` §4's role table exactly.
- Chat panel using the signaling WebSocket's `chat.message` event
  (`../03-signaling-and-session-model.md` §5).
- A persistent on-screen **recording indicator** whenever `recording.status` is `active`
  (`../06-security.md` §5 — required, not optional).

## 4. Reconnection

Electron surfaces network-change and system-sleep/resume events the browser alone doesn't
always expose cleanly — hook `powerMonitor` (`suspend`/`resume`) in the main process and forward
to the renderer's call module so a laptop sleep triggers the same token-refresh reconnection
flow as a network blip (`../03-signaling-and-session-model.md` §6), rather than leaving the call
silently dead until the member notices.
