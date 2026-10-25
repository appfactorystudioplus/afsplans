# 04 — PM Views and Modules

Scope for porting each Coordinator view from web to desktop offline v1.

## Shell layout (shared)

Copied from `resources/js/html/index.html`:

| Region | Contents |
|--------|----------|
| Left sidebar | Section nav: Dashboard, Kanban, Tasks, Table, Gantt, Budget, Settings |
| Top bar | Project switcher, theme toggle, notifications bell |
| Main content | View-specific HTML injected by Appfactory3 router |
| Modals/offcanvas | Task edit, assign users, confirm dialogs |

**Exclude from sidebar:** AFS Connect (`#/afs-connect`) until cloud sync plan.

## View port checklist

Each view follows the same integration steps:

1. Copy TS module(s) + HTML template + SCSS
2. Replace any `fetch('/api/...')` with IPC / local state
3. Replace Checkpoint user lookups with local `users[]` from workspace
4. Bind `data-action` handlers in view `init()` (web pattern)
5. Call `syncDerivedViews` after mutations (via `ProjectDataManager`)
6. Verify dark mode via `ThemeManager` + `--tblr-*` tokens

---

## Dashboard (`#/dashboard`)

**Files:** `dashboard.ts`, `charts.ts`, `chart-data.ts`, `html/views/dashboard.html`

| Feature | Port scope |
|---------|------------|
| Stat cards | Wire to `projectData.stats` from sync engine |
| Chart.js charts | Real data from `chart-data.ts` helpers |
| Project switcher context | Show stats for `currentProject` |
| Portfolio strip | Multi-project summary if time permits Phase 8 |

**Known web gap:** Some chart data still hardcoded — Phase 8 explicitly fixes this.

**Dependencies:** Chart.js (CDN or npm).

---

## Kanban (`#/kanban`)

**Files:** `kanban.ts`, `html/views/kanban.html`

| Feature | Port scope |
|---------|------------|
| Column CRUD | ✅ |
| Card CRUD dialogs | ✅ |
| SortableJS column reorder | ✅ |
| SortableJS card drag between columns | ✅ |
| Status sync to `source` | ✅ |
| Cross-app badges | ❌ `afsConnectKanbanBadges.ts` — cloud only |

**Dependencies:** `sortablejs`

---

## Task list (`#/task`, `#/task/edit`)

**Files:** `tasks.ts`, `html/views/task.html`, `html/views/task_edit.html`

| Feature | Port scope |
|---------|------------|
| Flat task list | ✅ |
| Subtask offcanvas editor | ✅ |
| Assign users modal | ✅ (local users) |
| Parent progress rollup | ✅ |
| Task create/delete | ✅ |

---

## ProTable (`#/table2`)

**Files:** `src/protable/*` (~15 files), `html/views/table2.html`

| Feature | Port scope |
|---------|------------|
| Tree table with expand/collapse | ✅ |
| Row + column drag-and-drop | ✅ |
| Inline cell editors | ✅ |
| Sort, search, filter | ✅ |
| Bulk actions | ✅ |
| CSV export | ✅ |
| Task dialog integration | ✅ |
| Column prefs in localStorage | ✅ (already client-side) |

**Notes:** Largest view module — dedicate full Phase 6. Benchmark for other views.

---

## Gantt (`#/gantt`)

**Files:** `gantt.ts`, `html/views/gantt.html`

| Feature | Port scope |
|---------|------------|
| Timeline rendering | ✅ |
| Tooltips | ✅ |
| Drag-scroll | ✅ |
| Hierarchy rows | ✅ (from derived ganttData) |
| Drag-to-reschedule | ⏭ Stretch (web is read-only too) |
| Dependency arrows | ⏭ Future (SourceTask v2) |

Phase 7 delivers **read-only parity** with web.

---

## Budget2 (`#/budget2`)

**Files:** `src/budget2/*` (~30 files), `html/views/budget2.html`

| Feature | Port scope |
|---------|------------|
| Create/edit budget | ✅ |
| Recurring + one-time line items | ✅ |
| Expense tracking | ✅ |
| Assignee labor rates | ✅ |
| Submit / archive workflow | ✅ |
| Charts + history | ✅ |
| Export | ✅ |
| Vitest unit tests | Port tests optional; run calc tests in desktop CI if copied |

**Reference plan:** `coordinator/resources/js/_plan/budget/`

Lazy-load budget2 bundle on first navigation to reduce initial webpack size.

---

## Settings (`#/settings`)

**Files:** `teams.ts`, `projectsManagement.ts`, `html/views/settings.html`

| Feature | Port scope |
|---------|------------|
| Project CRUD | ✅ |
| Team CRUD | ✅ |
| User management (local roster) | ✅ — demo users editable offline |
| Checkpoint secondary user sync | ❌ Cloud sync plan |

---

## Cross-cutting modules

| Module | Files | Phase |
|--------|-------|-------|
| Projects switcher | `projects.ts` | 3 |
| Modals | `modal.ts` | 4 |
| UI chrome | `ui.ts` | 3 |
| i18n | `i18n.ts` | 10 |
| Theme | `theme/ThemeManager.ts` | 10 |
| Notifications UI | `notifications.ts` | 10 (seed data OK offline) |
| Auth display | `auth-display.ts` | 10 (show "Local workspace") |
| Activity | `activity/*` | 2+ |

---

## Modules explicitly excluded (offline v1)

| Module | Reason |
|--------|--------|
| `afsConnect.ts` | Hub view |
| `afsConnectItemDrawer.ts` | Cross-app item detail |
| `afsConnectTaskLinks.ts` | Task ↔ external links |
| `afsConnectKanbanBadges.ts` | Cross-app badges on cards |
| `afs-connect-api.ts` | REST client to hub |

These ship in [../account-and-cloud-sync/04-desktop-apps-report-to-coordinator.md](../account-and-cloud-sync/04-desktop-apps-report-to-coordinator.md).

---

## Optional stretch (post Phase 11)

From web `_plan/overall/` — not required for offline MVP:

| Feature | Web plan doc |
|---------|--------------|
| SourceTask v2 (tags, deps, comments) | `01-source-task-v2.md` |
| Dashboard v2 widgets | `02-dashboard-v2.md` |
| Kanban rich cards + WIP | `03-kanban-advanced.md` |
| Gantt drag-reschedule | `04-gantt-v2.md` |
| Calendar / Workload views | `06-calendar-workload.md` |
| Command palette (Cmd+K) | `07-command-palette-notifications.md` |
| Templates + reports | `08-templates-reports-export.md` |

Track as Phase 12+ only if product priority demands; do not block offline MVP.
