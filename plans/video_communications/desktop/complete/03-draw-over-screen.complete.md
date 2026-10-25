# 03 — Draw over screen — completion

**Status:** Implemented (2026-09-18) — works on Windows, verified by recording the screen with ink
on it. Multi-monitor logic is written but was only exercised on a single display.

## Summary

Tools → Screen Recorder → **Show drawing tools** (also opened automatically when a recording starts,
if the option is on) puts a transparent, always-on-top overlay over every display and a small floating
toolbar. The overlay sits above whatever is on screen — browser, IDE, anything — so the ink is part of
the screen and therefore part of a recording (or a screen share) with no compositing code.

- **Two modes.** *Click-through* (default when auto-opened): the mouse passes to the app underneath.
  *Drawing*: the overlay captures the mouse. Toggle from the toolbar or `Alt+Shift+D`.
- **Tools.** Pen, highlighter (uniform opacity per stroke), eraser (undoable), 7 colours, 3 widths,
  undo (`Alt+Shift+Z`), clear (`Alt+Shift+C`), close (`Alt+Shift+X`). Shortcuts are registered only
  while the overlay is up; if another app already owns one, the view says so and the toolbar buttons
  still work.
- **Toolbar excluded from recordings.** It is its own window with `setContentProtection(true)` (option
  "Hide the drawing toolbar from the recording", default on), so the ink is captured but the toolbar is
  not.
- **Multi-monitor.** One overlay window per display, kept in step with monitors being added, removed or
  changing resolution. Undo is global: it removes the most recent stroke on whichever display it was drawn.
- **Recording a single window.** Window capture shows only that window's pixels, so ink from the
  full-screen overlay is not in it; the view shows a warning when drawing is on and a window is selected
  (plan §4).

## Deviations from the plan

| Plan said | What was built |
|---|---|
| §3 click-through via `setIgnoreMouseEvents(true, { forward: true })` | Plain `setIgnoreMouseEvents(true)` — `forward` was only needed if the toolbar lived inside the overlay; it is a separate window instead (the plan's other option). |
| §3 toolbar "docked region or a third tiny window" | Separate window, chosen so it can be excluded from capture independently of the ink. |
| §5 plain canvas, no Excalidraw | As planned: two stacked canvases (committed ink, live stroke). |
| — | In drawing mode the overlay page gets a 1 % black background so every pixel has non-zero alpha; a fully transparent layered-window pixel can be hit-test transparent on Windows and clicks would fall through. **Unverified with a physical mouse** — see below. |

## Files

Main — `src/main/videoComms/overlay.ts` (windows, modes, shortcuts, display events), `stroke_undo.ts`,
`types.ts`, IPC in `index.ts`. Overlay pages — `src/renderer/videoComms/overlay/` (`overlay.html/.ts`,
`toolbar.html/.ts`), built by a fourth config in `webpack.config.js` to `dist/overlay.html` and
`dist/overlayToolbar.html`. Strings in all four locales (`videoComms.*`).

## Verification

Same suite as `02` (`test_video_comms.ts` covers the undo stack; `smoke_video_comms.js` the rest):
one overlay window per display plus toolbar; windows are always-on-top and non-focusable; the four
global shortcuts register on show and release on hide; a stroke drawn with the mouse produces ink on every
display; undo removes exactly the last stroke and leaves the others; clear removes all; closing the toolbar
removes every window. Decoding a recorded frame: **19,180 pixels of the pen colour are in the video**, and
**0 pixels of the toolbar's green mode button** are in the toolbar's screen area (i.e. it was excluded).

## Not verified

- Real physical mouse input in drawing mode (the test drives the overlay page through Chromium's input
  API, not the OS mouse) and therefore the Windows hit-testing behaviour behind the 1 % background.
- More than one display; hot-plugging a monitor while the overlay is up.
- macOS (`'screen-saver'` window level, all-spaces/fullscreen visibility) and Linux (compositor-dependent).
- Exclusive-fullscreen apps, which can draw over the overlay (known, accepted limitation — plan §2).
- The physical shortcut key presses (registration is tested; the presses are not).
