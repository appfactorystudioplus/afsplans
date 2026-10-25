# 03 — Draw over screen

## 1. Mechanism: a transparent, always-on-top overlay window

A second `BrowserWindow` per display, sized to that display's full bounds:

```
new BrowserWindow({
  transparent: true,
  frame: false,
  alwaysOnTop: true,          // 'screen-saver' level on macOS — see §2
  skipTaskbar: true,
  fullscreenable: false,
  hasShadow: false,
  webPreferences: { ... }
})
```

Because this is a real, OS-composited window layered above every other window — including a
browser, an IDE, a video call in another app, anything — drawing on its `<canvas>` visually
appears over whatever is on screen underneath it. This is the same mechanism tools like ZoomIt
or Epic Pen use, and it requires no OS-level screen-hooking or accessibility APIs.

## 2. Platform notes

- **Windows/Linux**: `alwaysOnTop: true` is sufficient in practice.
- **macOS**: plain `alwaysOnTop` can still lose to some other apps' own always-on-top windows;
  use `win.setAlwaysOnTop(true, 'screen-saver')` to sit above nearly everything, matching the
  approach this doc's earlier feasibility discussion with the user already covered.
- **Exclusive fullscreen** applications (a game or player that bypasses the compositor) can
  render on top of the overlay instead of under it — a known, accepted limitation, not solvable
  without OS-specific low-level hooks that are out of scope here. Normal browser fullscreen
  (F11, fullscreen video) is compositor-based and is not affected.
- **Multi-monitor**: one overlay `BrowserWindow` per connected display, tracked via Electron's
  `screen` module `display-added`/`display-removed` events so the overlay set stays correct
  when a monitor is connected/disconnected mid-session.

## 3. Click-through toggling

The overlay must not permanently steal input from whatever is underneath it — the member needs
to keep using their browser/IDE/other app normally except while actively drawing. Toggle via
`win.setIgnoreMouseEvents(true, { forward: true })` (click-through, mouse events pass to the
window below) vs. `win.setIgnoreMouseEvents(false)` (overlay captures input), bound to:

- A global keyboard shortcut (Electron's `globalShortcut`) so it works regardless of which
  application currently has focus.
- A small always-visible toolbar (itself a third, tiny, always-on-top window, or a docked
  region of the overlay that stays interactive even while the rest is click-through) holding
  pen/color/eraser/clear controls, matching the interaction model of the existing Excalidraw
  usage in `src/renderer/components/flow/views/DrawView.tsx` where reasonable, though this is a
  distinct, simpler canvas — see §5 for why full Excalidraw is not used here.

## 4. Where the ink is captured

Because the overlay is a real on-screen window, it needs no special handling to appear in
output — it's just part of what's on screen:

- **Screen recording** (`02`) — captured automatically by `desktopCapturer`, since the overlay
  is visibly composited like any other window.
- **Screen share in a call** (`04`) — same reasoning; whatever the call is sharing (full screen
  or a specific window) includes the overlay if the overlay covers that region.
- Drawing while sharing **a single application window** rather than the full screen has a
  caveat: most OS window-capture APIs capture only that window's own content, not other windows
  layered above it — so ink drawn via a full-screen overlay will **not** appear when the shared
  source is a single window rather than the full screen. This should be surfaced in the UI
  (disable or warn when draw-mode is active and the active share/recording source is a single
  window, not the full screen) rather than silently producing a recording that doesn't show the
  annotations the member thinks it does.

## 5. Ink persistence and undo — a lightweight canvas, not full Excalidraw

The existing Excalidraw dependency and `DrawView.tsx` usage (`00` §2) is a full-featured diagram
editor — shapes, text, an element inspector, file-based save/load. The screen-draw overlay's
job is closer to a marker pen: freehand strokes, a few colors, an eraser, "clear all" — visible
immediately, not saved as a document. **Recommendation: a plain `<canvas>` with pointer-event
freehand drawing**, not an embedded Excalidraw instance, because Excalidraw's own UI chrome
(toolbars, panels) would have to be suppressed or fought to get an unobtrusive full-screen
overlay, and none of its document-model features (save/load, shape library) are useful for
transient screen ink. This keeps `03` and the meeting whiteboard (`05`, which **does** use
Excalidraw deliberately) clearly distinct features rather than one confused hybrid — see `05`
§1 for the full reasoning on why the app ends up with two different drawing surfaces.

## 6. Entitlement

Per `../05-feature-tiers-and-entitlements.md` §5, `draw_annotations` is
`true` at every tier — this is not a gated feature. The overlay works identically to a
standalone screen-recording session (no call, no entitlement check at all) and to a
call-attached one (host's tier only matters for the call/recording itself, not for drawing).
