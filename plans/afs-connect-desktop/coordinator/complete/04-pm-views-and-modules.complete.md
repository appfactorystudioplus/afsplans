# 04-pm-views-and-modules — Completion

**Completed:** 2026-07-31

**Status:** Complete. All seven PM views shipped with the parity the plan asks for, across
plan phases 4–10. Every module on the plan's "explicitly excluded" list stayed excluded.

## Summary

Each view replaced its phase-3 stub in turn. All styles are scoped under
`#coordinator-root`, and all icons were converted from Font Awesome to Tabler (`ti ti-*`)
during the port.

### Task list — `#/task` (phase 4)

`src/tasks.ts` adapted from web (~1100 lines), with `src/modal.ts` for alert/confirm
helpers, and the shell split across `taskViewHtml.ts`, `taskOverlaysHtml.ts`, and
`taskEvents.ts` (`data-action` delegation).

Add / edit / delete with sub-task cascade delete; complete-toggle blocked while
descendants are incomplete; an edit offcanvas covering title, status, priority, dates,
duration, parent, and progress; sub-task add/toggle/edit/delete with parent progress
rollup; and an assign-users modal that degrades gracefully to "No users assigned to this
project." while the roster is empty. Status is stored as the **kanban column name** and
synced to `source[]` through `syncFromTasks()`.

### Kanban — `#/kanban` (phase 5)

`src/kanban.ts` plus `kanbanViewHtml.ts`, `kanbanOverlaysHtml.ts`, `kanbanEvents.ts`.
Dependencies: `sortablejs` and `@types/sortablejs`.

Columns and cards render from `projectData.kanbanColumns`. SortableJS handles column
reorder by header handle and card drag between columns. A card drop calls
`updateTaskStatus()` → `persistProject(..., 'kanban')` → `source[].status`. Column
add/rename/delete protects the four required statuses — To Do, In Progress, Staging,
Completed. Cards add and edit through a modal that creates a linked task, with delete
delegating to `confirmDeleteTask` and the edit button opening the task offcanvas. Deleting
a task refreshes the board when it is visible.

### ProTable — `#/table2` (phase 6)

A 12-file module at `src/protable/` — `index.ts`, `store.ts`, `columns.ts`, `render.ts`,
`features.ts`, `editors.ts`, `dnd.ts`, `tree.ts`, `rollup.ts`, `task-dialog.ts`,
`types.ts`, `utils.ts` — with `fuse.js` for fuzzy search.

Full web parity: tree expand/collapse per row and globally, inline cell editing,
add/delete/duplicate/indent/outdent, fuzzy search with status and priority filters, column
sort and a visibility picker, row and column reorder via SortableJS, bulk selection and
delete, a task detail modal, and undo/redo on Cmd/Ctrl+Z. Persist runs through
`persistProject(..., 'table')` → `syncFromTable()` → IPC. CSV export uses the
`dialog-save-file` IPC and `fs.writeFileSync` rather than a browser download.
`destroyProTable()` runs on navigate-away.

### Gantt — `#/gantt` (phase 7)

`src/gantt.ts`, `shell/ganttViewHtml.ts`, `styles/gantt.css`. Read-only, matching web v1:
a 10-year rolling timeline from the earliest task year, daily and quarterly column modes,
bars positioned by start/end date, hover tooltips (title, dates, status, progress), and
horizontal drag-scroll. Reads `projectData[currentProject].ganttData`, tolerating missing
or empty data.

### Dashboard — `#/dashboard` (phase 8)

`src/dashboard.ts`, `src/charts.ts`, `src/chart-data.ts`, `shell/dashboardViewHtml.ts`,
with `chart.js` imported via `chart.js/auto`. The default landing section.

Four stat cards — total, in progress, completed, overdue — from `projectData.stats`, and
four charts: status doughnut, priority pie, weekly progress line, team workload bar. Chart
data is built from `projectData.source`, so the numbers are live rather than hardcoded
demo values. Charts take their colors from the Coordinator `--coord-*` tokens rather than
Tabler globals, and `destroyDashboardCharts()` runs on every non-dashboard navigation.

### Budget2 — `#/budget2` (phase 9)

A 21-file module at `src/budget2/` covering core (`index`, `store`, `render`, `features`),
calculation (`calc.ts`, `calc-*.ts`, `format.ts`, `migrate.ts`, `history.ts`), dialogs
(item, assignee, expense, settings, history panel), and charts/export/permissions.

Web parity: empty state into create-budget-for-year; Overview, Assignees, One-time,
Recurring, Expenses, and Quarters tabs; the submit / approve / reject / reopen workflow
against a local super_admin user; line-item CRUD through Bootstrap modals injected on
first use; burn, cost-mix, and category-variance charts; CSV export (plan, expenses,
variance) plus a print report; an audit history offcanvas; and scenario snapshots with
year archive. State persists through `persistProject(..., 'budget2')`, with `budget2Data`
seeded in the demo projects. `getCurrentUser()` is a local admin stub and
`notifications.ts` an in-memory stub.

### Settings — `#/settings` (phase 10)

`src/settings.ts` orchestrating General / Teams / Projects tabs, with
`src/projectsManagement.ts` (project CRUD over the existing IPC), `src/teams.ts`,
`src/authz.ts`, `src/auth-display.ts`, and `src/theme/ThemeManager.ts`; shell in
`settingsViewHtml.ts`, `settingsOverlaysHtml.ts`, `settingsEvents.ts`.

Projects create/rename/delete/set-current write straight to disk. Teams persist in
`localStorage` under `coordinator_desktop_teams_v1` until the sync plan. The theme toggle
sets `data-coord-theme` on `#coordinator-root` and persists to
`coordinator-theme-preference`, so it is Coordinator-scoped rather than app-wide. The
account panel shows a "Local workspace" badge from the admin stub.

## Files changed

- `src/renderer/components/coordinator/src/` — `tasks.ts`, `modal.ts`, `kanban.ts`,
  `gantt.ts`, `dashboard.ts`, `charts.ts`, `chart-data.ts`, `settings.ts`,
  `projectsManagement.ts`, `teams.ts`, `authz.ts`, `auth-display.ts`, `notifications.ts`,
  `theme/ThemeManager.ts`, plus the `protable/` (12 files) and `budget2/` (21 files) trees.
- `src/renderer/components/coordinator/shell/` — the view HTML, overlay, and event modules
  for tasks, kanban, table2, gantt, dashboard, budget2, and settings; `router.ts` updated
  per phase.
- `src/renderer/components/coordinator/src/state.ts` — exports for `projectData`, `users`,
  `editingTaskId`, `persistProject`, and the kanban editing ids.
- `src/renderer/components/coordinator/styles/` — `tasks.css`, `kanban.css`,
  `protable.css`, `gantt.css`, `dashboard.css`, `budget2.css` (with print rules),
  `settings.css`.
- `src/renderer/renderer.ts` — style imports; `destroyUI()` flush on leaving the view.
- `src/renderer/locales.json` — strings for every view, 4 languages.
- `package.json` — `sortablejs`, `@types/sortablejs`, `fuse.js`, `chart.js`.

## Verification steps

```bash
cd apps/afsdesktop5
npm run build
```

- **Tasks** — add a task, edit its status, restart; the task persists. Open the assign
  modal and see the empty-roster message.
- **Kanban** — drag a card to another column, restart; placement and status persist. Add a
  custom column, restart; it remains.
- **Table** — expand/collapse the tree, edit a cell, restart. Click the CSV icon; the save
  dialog writes a file.
- **Gantt** — bars appear for dated tasks; hover tooltips, drag-scroll, and the
  Days/Quarters toggle all work; switching project updates the chart.
- **Dashboard** — stat numbers match actual task counts; add or delete a task on Tasks and
  return to see counts update; switching project refreshes.
- **Budget** — create a budget, add one-time and recurring items, submit, archive, export
  CSV, restart; state is preserved.
- **Settings** — toggle the theme, create/rename/delete a project, create a team, then
  leave the Coordinator nav item; pending saves flush.

```
npm run build  ✅ success (2026-07-31)
```

## Deviations from plan

- **Views do not live-refresh while you stay on them.** Gantt, dashboard, and kanban
  re-render on project switch or on navigating back, not while an edit happens on another
  view. Accepted for v1 in each phase's notes.
- **Budget2 is bundled with the renderer**, not lazy-loaded into its own webpack chunk;
  the plan leaves splitting as an option.

## Follow-up work

Deferred deliberately, matching the plan's exclusion list:

- AFS Connect kanban badges (`afsConnectKanbanBadges.ts`) and task links — cloud-sync plan.
- Gantt bar drag/resize to reschedule; hierarchy, progress, and milestone rows — not in
  web v1 either.
- Sortable.js drag reorder on the task list, guarded when the library is absent.
- The legacy full-page `task_edit.html` route.
- Real user roster for assignees and workload, and teams/users in `workspace.json` —
  blocked on the sync plan.
- A global notification panel for budget threshold alerts — in-memory stub only.
- `updateChartTheme` exists but is not wired to the app theme toggle.
