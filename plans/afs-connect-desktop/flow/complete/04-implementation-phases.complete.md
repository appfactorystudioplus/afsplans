# 04-implementation-phases — Completion

**Completed:** 2026-08-01

**Status:** Complete. All six phases (0–5) shipped, each meeting its stated exit criteria,
with a green webpack build and no regression in the other Studio apps.

## Summary

This document defined the build order; the rest of the folder is organised by subject. The
completion records were originally written one per phase and are now filed per document.
The mapping:

| Phase | Deliverable | Recorded in |
|-------|-------------|-------------|
| 0 | Shell placeholder, sidebar nav, i18n, search, `flowDir` | [02-desktop-architecture.complete.md](02-desktop-architecture.complete.md) |
| 1 | Local project store on disk + typed IPC | [03-data-model-and-files.complete.md](03-data-model-and-files.complete.md) |
| 2 | Dashboard — list, create, delete, open; router scaffold | [02-desktop-architecture.complete.md](02-desktop-architecture.complete.md) |
| 3 | Draw editor — Excalidraw mount, save/load, themes | [02-desktop-architecture.complete.md](02-desktop-architecture.complete.md) |
| 4 | Rename, open workspace folder, cleanup | [02-desktop-architecture.complete.md](02-desktop-architecture.complete.md) |
| 5 | Import / export project JSON | [03-data-model-and-files.complete.md](03-data-model-and-files.complete.md) |

`00-overview.md` and `01-web-app-audit.md` have no completion documents — the first is
framing and the second is an audit of the existing web app; neither produces desktop code.

## Phase exits

Every phase's exit criteria were met:

- **0** — nav click swaps the view without error, webpack build succeeds, other apps
  unaffected.
- **1** — create → get → save → list → delete round-trips; project folder appears under
  `flow/projects/`; atomic `.tmp` + rename write verified.
- **2** — create appears in the list, survives restart, delete removes the folder, open
  navigates to the draw route.
- **3** — Excalidraw renders, shapes persist across a reload, all three theme presets
  work, leaving the draw view unmounts the React root, and no `fetch('/api/flow')` occurs.
- **4** — rename works from the dashboard Edit action, phase 3 behaviour still passes, and
  switching away from Flow cleans up without error.
- **5** — export → delete → import restores the scene; an invalid file errors without
  corrupting the workspace.

## Lessons applied

The plan opened with four rules carried over from the Coordinator, Dream Graph, and PDF
ports. All four held:

- No second global CSS framework was added.
- Styles are scoped under `#flow-root`.
- The webpack build was verified after each phase.
- Only `projectStore` was swapped; `DrawView.tsx` logic stayed intact.

## Completion checklist

All nine items in the plan's own checklist are done: sidebar nav, local CRUD,
draw/save/load, rename, canvas themes, brand-styled shell, import/export, green webpack
build, and no regression in Coordinator, Dream Graph, or Notes.

## Files changed

None. This document is the build order; the code it schedules is recorded in the two
completion documents above.

## Verification steps

```
npm run build   # success, 2026-08-01
```

Full per-area steps are in
[02-desktop-architecture.complete.md](02-desktop-architecture.complete.md) and
[03-data-model-and-files.complete.md](03-data-model-and-files.complete.md).

## Follow-up work

- **Cloud sync** — out of scope here by design. Flow's cloud work continues in
  [../../account-and-cloud-sync/05-flow-cloud-sync.md](../../account-and-cloud-sync/05-flow-cloud-sync.md),
  and is blocked on desktop studio auth (phase C1) landing first.
- **Risk-register items still open** — draw-route lazy loading and replacing
  `window.confirm` with the shared modal; both recorded in
  [02-desktop-architecture.complete.md](02-desktop-architecture.complete.md).
