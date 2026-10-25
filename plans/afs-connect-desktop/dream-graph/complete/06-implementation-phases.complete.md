# 06-implementation-phases — Completion

**Completed:** 2026-07-31

**Status:** Complete. All seven phases (0–6) shipped with green builds. Two items are
carried as open: the web-compatible `.dgpack` round-trip (a stated stretch goal) and the
unbenchmarked tilemap flush budget.

## Summary

This document defined the build order; the rest of the folder is organised by subject. The
completion records were originally written one per phase and are now filed per document.
The mapping:

| Phase | Deliverable | Recorded in |
|-------|-------------|-------------|
| 0 | Shell placeholder, nav, i18n, search, `dreamGraphDir` | [02-desktop-architecture.complete.md](02-desktop-architecture.complete.md) |
| 1 | Local project CRUD, `.dgproj` layout, IPC | [03-data-model-and-files.complete.md](03-data-model-and-files.complete.md) |
| 2 | Graph editor MVP — canvas, node types, nesting, autosave | [04-graph-editor.complete.md](04-graph-editor.complete.md) |
| 3 | Catalogs and assets, image node | [04-graph-editor.complete.md](04-graph-editor.complete.md) (panels) and [03-data-model-and-files.complete.md](03-data-model-and-files.complete.md) (persistence) |
| 4 | Play mode — traversal, panel, presentation | [04-graph-editor.complete.md](04-graph-editor.complete.md) |
| 5 | Tilemap subsystem, sub-phases 5a–5e | [05-tilemap-subsystem.complete.md](05-tilemap-subsystem.complete.md) |
| 6 | Import/export — `.dgpack` bundles and `joystick-tilemap/1` zips | [03-data-model-and-files.complete.md](03-data-model-and-files.complete.md) (bundles) and [05-tilemap-subsystem.complete.md](05-tilemap-subsystem.complete.md) (engine zips) |

`00-overview.md` and `01-web-app-audit.md` have no completion documents — the first is
framing, the second an audit of the existing web app; neither produces desktop code.

## Phase exits

Every phase met its exit criteria:

- **0** — nav click swaps the view, webpack build succeeds, Notes and PDF unaffected.
- **1** — create, restart-and-still-listed, delete-with-confirm all pass; `{Name}.dgproj`
  appears under the projects root.
- **2** — full graph editing per the `04-graph-editor.md` acceptance list, 5s autosave
  plus working manual save, and no network calls.
- **3** — image node inserts from a local PNG, the thumbnail survives restart, variables
  are editable in the catalog panel.
- **4** — hub branches walk with conditions evaluating, instructions mutate session
  variables, and an unknown expression surfaces inline instead of crashing.
- **5** — create from a `tilemap_ref`, paint/erase/undo, multi-layer reorder and
  visibility, persistence across restart, and autotile rules — all pass. The
  `joystick-tilemap/1` export criterion was satisfied by phase 6.
- **6** — engine zip export/import works, and the desktop `.dgpack` round-trips.

## Open from the phase exit lists

| Item | State |
|------|-------|
| Export project from desktop → import on **web** (same member) | **Stretch, not done.** `.dgpack` wraps the raw `.dgproj` folder rather than a web-canonical `project.json`. |
| Large sparse map flush &lt; 200ms | **Not benchmarked.** Dirty-chunk-only writes are the intended architecture, but no measurement exists. |
| Localization CSV export/import | Deferred; optional task in the plan. |
| Client-side stats panel | Deferred; optional task in the plan. |

## Files changed

None. This document is the build order; the code it schedules is recorded in the four
completion documents above.

## Verification steps

```
npm run build  ✅ success (2026-07-31)
```

Per-area steps are in each subject completion document.

## Follow-up work

- **Cloud sync and engine live sync** — out of scope here by design, tracked in
  [../../account-and-cloud-sync/](../../account-and-cloud-sync/). Dream Graph's cloud work
  is [01-cloud-sync.md](../../account-and-cloud-sync/01-cloud-sync.md) and
  [02-engine-live-sync.md](../../account-and-cloud-sync/02-engine-live-sync.md), both
  blocked on desktop studio auth (phase C1).
