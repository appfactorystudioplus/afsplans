# 06 — Implementation Phases

Step-by-step build order for Coordinator in Appfactory Studio.

**Status:** ✅ Phases 0–11 complete (`apps/afsdesktop5`). Notes: [complete/README.md](complete/README.md).

---

## Phase 0 — Shell placeholder

**Goal:** Navigable empty view proves wiring.

### Tasks

- [ ] Add `coordinator` to `views.ts`, `html/coordinator.html`, `CoordinatorManager`
- [ ] Sidebar nav under **Apps**, **above Notes** (`data-view="coordinator"`)
- [ ] i18n keys `sidebar.coordinator`, `coordinator.title` (en/es/de/zh)
- [ ] Search entry in `search.ts`
- [ ] Extend `get-app-dirs` with `coordinatorDir`
- [ ] Placeholder UI: "Coordinator — coming soon" + Open Workspace Folder button
- [ ] Phase 0 spike: Tailwind vs scoped SCSS decision (document in completion notes)

### Exit criteria

- Click nav → view swaps without error
- Nav order: Studio → **Coordinator** → Notes → PDF → Dream Graph
- Webpack build succeeds
- Old apps unaffected

**Estimate:** 0.5–1 day

---

## Phase 1 — Local workspace persistence

**Goal:** Main-process store reads/writes workspace on disk.

### Tasks

- [ ] `main/coordinator/store.ts` — load/save `workspace.json`
- [ ] `main/coordinator/paths.ts` — `coordinatorDir` under `~/Documents/afsfiles/coordinator/`
- [ ] Per-project `.coordproj` read/write (atomic temp+rename)
- [ ] IPC handlers in `main/coordinator/index.ts`
- [ ] `renderer/components/coordinator/ipc.ts`
- [ ] First-run: seed demo workspace if missing
- [ ] Register IPC in `main/index.ts`

### Exit criteria

- First launch creates `workspace.json` + demo project files
- Restart app → demo data still present
- Open Workspace Folder opens OS file manager

**Estimate:** 2–3 days

---

## Phase 2 — Data spine

**Goal:** Source-first sync engine runs locally.

### Tasks

- [ ] Copy `types.ts`, `project-sync.ts`, `project-data-manager.ts`, `project-persist.ts`
- [ ] Copy `activity/*` helpers
- [ ] Copy `load-demo-data.ts`
- [ ] Create `pm-api-desktop.ts` adapter → IPC (replace web `pm-api.ts` imports)
- [ ] Adapt `state.ts` — remove Checkpoint imports; use local stub + IPC
- [ ] Unit smoke: load demo → `syncDerivedViews` → stats populated

### Exit criteria

- Console test: load workspace → `projectData[1].stats` has non-zero task counts
- No `fetch('/api/...')` in copied spine modules
- Build succeeds

**Estimate:** 2–3 days

---

## Phase 3 — Shell and internal router

**Goal:** Coordinator UI shell with hash navigation between placeholder views.

### Tasks

- [ ] Copy `checkpointHelper/static/appfactory3.ts`, web `views.ts` (adapted)
- [ ] Copy `html/index.html` shell structure into coordinator bootstrap
- [ ] Copy `ui.ts`, `projects.ts` — project switcher
- [ ] `bootstrap.ts` — load workspace, init router, no Checkpoint redirect
- [ ] `checkpointStub.ts` — local user/member
- [ ] Sidebar sections: Dashboard, Kanban, Tasks, Table, Gantt, Budget, Settings
- [ ] Each route shows "View name — loading…" stub until its phase
- [ ] Scoped styles under `#coordinator-root`

### Exit criteria

- Navigate `#/dashboard`, `#/kanban`, etc. — shell swaps content area
- Project switcher changes `currentProject` and persists to workspace
- Debounced save writes `.coordproj` after project switch

**Estimate:** 3–4 days

---

## Phase 4 — Task list and edit

**Goal:** Core task CRUD offline.

### Tasks

- [ ] Copy `tasks.ts`, `modal.ts`, task HTML templates
- [ ] Copy related SCSS
- [ ] Wire list render to `projectData.tasks`
- [ ] Task create/edit/delete → `ProjectDataManager` → persist
- [ ] Subtask offcanvas + assign users modal (local users)
- [ ] Parent progress rollup

### Exit criteria

- Add task → appears in list → restart → still present
- Edit task title/status → kanban column mapping preserved in `source`
- Assign user from local roster

**Estimate:** 3–4 days

---

## Phase 5 — Kanban

**Goal:** Full kanban board with drag-and-drop.

### Tasks

- [ ] Add `sortablejs` to afsdesktop5
- [ ] Copy `kanban.ts` + template + styles
- [ ] Column CRUD dialogs
- [ ] Card CRUD dialogs
- [ ] SortableJS column reorder + card drag
- [ ] Status sync back to `source[]`

### Exit criteria

- Drag card between columns → `source[].status` updates → persists
- Add/rename/delete column → survives restart
- **No** afs-connect kanban badges (deferred)

**Estimate:** 3–4 days

---

## Phase 6 — ProTable

**Goal:** Hierarchical task table (benchmark view).

### Tasks

- [ ] Copy entire `protable/*` module
- [ ] Copy `table2.html` template + styles
- [ ] Row/column DnD, inline editors, sort/search
- [ ] Bulk actions, CSV export
- [ ] Task dialog integration

### Exit criteria

- Tree expand/collapse works
- Inline edit cell → persists
- CSV export downloads file via Electron save dialog

**Estimate:** 5–7 days

---

## Phase 7 — Gantt

**Goal:** Read-only gantt parity with web.

### Tasks

- [ ] Copy `gantt.ts` + template + styles
- [ ] Wire to `projectData.ganttData`
- [ ] Tooltips, drag-scroll, hierarchy rows

### Exit criteria

- Tasks with dates appear on timeline
- Switch project → gantt updates
- Read-only (match web — no drag-reschedule in v1)

**Estimate:** 2–3 days

---

## Phase 8 — Dashboard

**Goal:** Dashboard shows **real** stats and charts.

### Tasks

- [ ] Copy `dashboard.ts`, `charts.ts`, `chart-data.ts`
- [ ] Wire stat cards to `projectData.stats`
- [ ] Chart.js integration (npm or CDN — match Phase 0 decision)
- [ ] Fix hardcoded chart data from web

### Exit criteria

- Stat card numbers match actual task counts
- Charts update when tasks added/removed
- Switch project → dashboard refreshes

**Estimate:** 2–3 days

---

## Phase 9 — Budget2

**Goal:** Full budget module offline.

### Tasks

- [ ] Copy `budget2/*` (~30 files)
- [ ] Copy `budget2.html` + styles
- [ ] Lazy-load budget bundle on first `#/budget2` visit
- [ ] Create/edit/submit/archive flow
- [ ] Persist `budget2Data` + `budget2Archive` in `.coordproj`

### Exit criteria

- Create budget → add line items → submit → archive
- Restart → budget state intact
- Budget charts render

**Estimate:** 5–7 days

---

## Phase 10 — Settings, theme, i18n polish

**Goal:** Project/team management and UX parity.

### Tasks

- [ ] Copy `teams.ts`, `projectsManagement.ts`, settings template
- [ ] Project CRUD (create/rename/delete) via IPC
- [ ] Team CRUD with local users
- [ ] Copy `ThemeManager.ts`, `i18n.ts`
- [ ] Copy `notifications.ts` (seed OK)
- [ ] `auth-display.ts` → show "Local workspace" badge
- [ ] Flush pending saves on navigate away from coordinator

### Exit criteria

- Create/delete project from settings → files created/removed on disk
- Dark mode toggle works inside coordinator shell
- i18n keys resolve for en + at least one other locale

**Estimate:** 3–4 days

---

## Phase 11 — Import / export

**Goal:** Project portability without cloud.

### Tasks

- [ ] Export single project as JSON (native save dialog)
- [ ] Import project JSON → new `.coordproj` + workspace entry
- [ ] Full workspace backup zip (optional stretch)
- [ ] `projectDataToSeed` round-trip test

### Exit criteria

- Export project → import on fresh workspace → identical task tree
- Kanban columns + budget survive round-trip

**Estimate:** 2–3 days

---

## Phase summary table

| Phase | Deliverable | Estimate |
|-------|-------------|----------|
| 0 | Shell + nav (Apps, above Notes) | 0.5–1 d |
| 1 | Local workspace store + IPC | 2–3 d |
| 2 | Data spine (source-first sync) | 2–3 d |
| 3 | Shell + hash router + project switcher | 3–4 d |
| 4 | Task list + edit | 3–4 d |
| 5 | Kanban | 3–4 d |
| 6 | ProTable | 5–7 d |
| 7 | Gantt | 2–3 d |
| 8 | Dashboard (real data) | 2–3 d |
| 9 | Budget2 | 5–7 d |
| 10 | Settings + polish | 3–4 d |
| 11 | Import/export JSON | 2–3 d |
| **Total** | **Offline MVP** | **~35–50 dev-days** |

---

## Completion checklist (Phases 0–11)

- [x] Sidebar Coordinator nav (Apps, above Notes)
- [x] Local workspace + `.coordproj` persistence
- [x] Source-first sync engine (tasks → kanban/gantt/stats)
- [x] All 7 PM views: dashboard, kanban, tasks, table, gantt, budget, settings
- [x] Budget2 create/submit/archive
- [x] Settings project CRUD + teams + theme toggle
- [x] Import/export project JSON
- [x] Webpack build green

---

## Risk register

| Risk | Mitigation |
|------|------------|
| Tailwind ↔ webpack mismatch | Phase 0 spike; prefer scoped SCSS conversion |
| Large bundle from budget2 + protable | Lazy-load heavy views; code-split |
| Appfactory3 inside Electron | Keep hash router contained in `#coordinator-root` |
| SortableJS + Electron DnD quirks | Test early in Phase 5 |
| Copy drift from web coordinator | Tag copied files with source path comment; periodic diff |
| Scope creep (SourceTask v2, calendar) | Defer to Phase 12+ stretch; strict phase gates |

---

## Suggested first sprint

1. Phase 0 + Phase 1 (shell + workspace store)
2. Phase 2 spike: demo data loads, stats compute in console

Stop and demo before copying all view modules.
