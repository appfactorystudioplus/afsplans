# 06-implementation-phases — Completion

**Completed:** 2026-07-31

**Status:** Complete. All twelve phases (0–11) shipped, each meeting its exit criteria with
a green webpack build.

## Summary

This document defined the build order; the rest of the folder is organised by subject. The
completion records were originally written one per phase and are now filed per document.
The mapping:

| Phase | Deliverable | Recorded in |
|-------|-------------|-------------|
| 0 | Shell placeholder, nav order, Tailwind decision | [02-desktop-architecture.complete.md](02-desktop-architecture.complete.md) |
| 1 | Local workspace persistence, `.coordproj`, demo seed | [03-data-model-and-files.complete.md](03-data-model-and-files.complete.md) |
| 2 | Data spine — `project-sync`, `ProjectDataManager` | [05-source-first-sync-engine.complete.md](05-source-first-sync-engine.complete.md) |
| 3 | Shell, hash router, project switcher | [02-desktop-architecture.complete.md](02-desktop-architecture.complete.md) |
| 4 | Task list and edit | [04-pm-views-and-modules.complete.md](04-pm-views-and-modules.complete.md) |
| 5 | Kanban | [04-pm-views-and-modules.complete.md](04-pm-views-and-modules.complete.md) |
| 6 | ProTable | [04-pm-views-and-modules.complete.md](04-pm-views-and-modules.complete.md) |
| 7 | Gantt | [04-pm-views-and-modules.complete.md](04-pm-views-and-modules.complete.md) |
| 8 | Dashboard | [04-pm-views-and-modules.complete.md](04-pm-views-and-modules.complete.md) |
| 9 | Budget2 | [04-pm-views-and-modules.complete.md](04-pm-views-and-modules.complete.md) |
| 10 | Settings, theme, i18n polish | [04-pm-views-and-modules.complete.md](04-pm-views-and-modules.complete.md) |
| 11 | Import / export | [03-data-model-and-files.complete.md](03-data-model-and-files.complete.md) |

`00-overview.md` and `01-web-app-audit.md` have no completion documents — the first is
framing, the second an audit of the existing web app; neither produces desktop code.

## Phase exits

Every phase met its exit criteria:

- **0** — nav click swaps the view, nav order is Studio → Coordinator → Notes → PDF →
  Dream Graph, build succeeds, older apps unaffected.
- **1** — first launch creates `workspace.json` plus the demo project files, data survives
  restart, and Open Workspace Folder opens the OS file manager.
- **2** — `projectData[1].stats` carries non-zero task counts, no `fetch('/api/...')`
  remains in the spine modules.
- **3** — hash navigation swaps content, the project switcher persists `currentProject`,
  a debounced `.coordproj` flush precedes a switch, styles are scoped.
- **4** — a task added, edited, and survived restart with the `source[]` kanban mapping
  preserved; the empty assign roster degrades gracefully.
- **5** — a card dragged between columns updates `source[].status` and persists; custom
  columns survive restart; no AFS Connect badges.
- **6** — tree expand/collapse, inline edit persistence, and CSV export through the
  Electron save dialog.
- **7** — dated tasks appear on the timeline, project switch updates the chart, and the
  view stays read-only.
- **8** — stat cards match real task counts, charts update on revisit, project switch
  refreshes.
- **9** — create → line items → submit → archive round-trips and survives restart; budget
  charts render.
- **10** — project create/delete from settings reaches disk, the Coordinator-scoped theme
  toggle works, i18n resolves in all four languages.
- **11** — export then import reproduces an identical task tree, with kanban columns and
  budget surviving the round-trip.

## Build verification rule

The plan requires a webpack build after each phase. Every phase note records
`npm run build  ✅ success (2026-07-31)`.

## Files changed

None. This document is the build order; the code it schedules is recorded in the four
completion documents above.

## Verification steps

```bash
cd apps/afsdesktop5
npm run build
```

Per-area steps are in each subject completion document.

## Follow-up work

- **Cloud sync** — out of scope here by design. Coordinator's cloud work is
  [03-coordinator-pm-sync.md](../../account-and-cloud-sync/03-coordinator-pm-sync.md) and
  [04-desktop-apps-report-to-coordinator.md](../../account-and-cloud-sync/04-desktop-apps-report-to-coordinator.md),
  both blocked on desktop studio auth (phase C1), which has not started.
- **Optional stretch items** from `04-pm-views-and-modules.md` (post-phase-11) were not
  taken up.
- **Full workspace backup zip** — a stretch goal on the import/export phase; only
  per-project JSON shipped.
