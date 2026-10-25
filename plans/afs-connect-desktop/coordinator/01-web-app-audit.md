# 01 — Web App Audit

Inventory of `apps/coordinator` for the desktop port. Separates **offline PM**
from **sync/hub** functionality.

## Tech stack

| Layer | Technology |
|-------|------------|
| Backend | Laravel 12, PHP 8.2+, MySQL (`pm_*`, `afsconnect_*` tables) |
| Frontend | Vanilla TypeScript SPA, **Appfactory3** hash router |
| Build | Vite 7, Tailwind CSS 4, Sass |
| UI | Bootstrap 5, Tabler, Font Awesome (CDN), Chart.js (CDN), SortableJS |
| Auth | Embedded Checkpoint client (`resources/js/checkpoint/`) |
| Tests | Vitest (budget2, protable), PHPUnit (afs-connect) |

## Directory map (port-relevant)

```
apps/coordinator/
├── app/
│   ├── Http/Controllers/Api/
│   │   ├── Pm*Controller.php          ← PM REST (port contract to local store)
│   │   └── AfsConnect/                ← SKIP offline (hub)
│   └── Services/
│       ├── Pm/PmPersistenceService.php ← Schema reference for main store
│       └── AfsConnect/                ← SKIP offline
├── database/migrations/
│   ├── 2026_07_13_*_pm_*.php          ← PM schema reference
│   └── *_afsconnect_*.php             ← SKIP offline
├── resources/js/
│   ├── main.ts                        ← Auth gate + workspace bootstrap
│   ├── views.ts                       ← Hash route registry
│   ├── html/                          ← Shell + per-view HTML templates
│   ├── styles/                        ← SCSS/CSS
│   ├── src/                           ← PM core (PORT)
│   ├── checkpoint/                    ← SKIP offline (stub local auth)
│   ├── checkpointHelper/              ← Appfactory3 router (PORT)
│   └── _obsolete/                     ← Skip (old table/budget v1)
└── routes/api.php                     ← PM + afs-connect routes (contract ref)
```

## Client hash routes

Registered in `resources/js/views.ts`:

| Route ID | Hash | Module | Offline v1 |
|----------|------|--------|------------|
| `init` | (bootstrap) | Shell, i18n, notifications, project select | ✅ |
| `dashboard` | `#/dashboard` | `dashboard.ts`, `charts.ts` | ✅ |
| `kanban` | `#/kanban` | `kanban.ts` | ✅ |
| `task` | `#/task` | `tasks.ts` | ✅ |
| `taskEdit` | `#/task/edit` | `tasks.ts` | ✅ |
| `table2` | `#/table2` | `protable/*` | ✅ |
| `gantt` | `#/gantt` | `gantt.ts` | ✅ |
| `budget2` | `#/budget2` | `budget2/*` | ✅ |
| `settings` | `#/settings` | `teams.ts`, `projectsManagement.ts` | ✅ |
| `afs-connect` | `#/afs-connect` | `afsConnect*.ts` | ❌ Cloud sync plan |

## PM API (web — replace with IPC offline)

| Endpoint | Purpose | Desktop adapter |
|----------|---------|-----------------|
| `GET /api/pm/workspace` | Load full workspace | `coordinator:load-workspace` |
| `PUT /api/pm/workspace` | Save workspace metadata | `coordinator:save-workspace` |
| `GET /api/pm/projects/{id}` | Load one project payload | `coordinator:load-project` |
| `PUT /api/pm/projects/{id}` | Save project payload | `coordinator:save-project` |
| `GET /api/pm/member` | Member prefs | Folded into workspace load |

Headers on web (`X-User-Id`, `X-Checkpoint-User-Id`, `X-Coordinator-Role`):
**not used offline** — local workspace has no multi-tenant scoping in v1.

## Core frontend modules (port priority)

### Tier 1 — Data spine (must-have first)

| File | Role |
|------|------|
| `src/types.ts` | All TS interfaces |
| `src/project-sync.ts` | `source[]` → derived views |
| `src/project-data-manager.ts` | Mutation API + persist origins |
| `src/project-persist.ts` | Payload shape for save (source-only) |
| `src/state.ts` | Global state — **replace `pm-api` calls with IPC** |
| `src/load-demo-data.ts` | Demo seeds for first-run |
| `src/activity/*` | Activity log helpers |

### Tier 2 — Bootstrap & routing

| File | Role |
|------|------|
| `main.ts` | Entry — **remove Checkpoint redirect for offline** |
| `views.ts` | Route registry |
| `src/ui.ts` | Sidebar section switching |
| `html/index.html` + `html/views/*.html` | Templates |
| `checkpointHelper/static/appfactory3.ts` | Hash router |
| `styles/*` | Scoped under `#coordinator-root` |

### Tier 3 — PM views

| Module | Files | Notes |
|--------|-------|-------|
| Projects | `projects.ts` | Project switcher |
| Tasks | `tasks.ts`, `modal.ts` | List + offcanvas editor |
| Kanban | `kanban.ts` | SortableJS DnD |
| ProTable | `protable/*` | Largest view module |
| Gantt | `gantt.ts` | Read-only timeline |
| Dashboard | `dashboard.ts`, `charts.ts`, `chart-data.ts` | Stats from sync engine |
| Budget2 | `budget2/*` | Full submodule (~30 files) |
| Settings | `teams.ts`, `projectsManagement.ts` | CRUD |
| Theme/i18n | `theme/ThemeManager.ts`, `i18n.ts` | Client-only |

### Tier 4 — Explicitly skip offline

| Path | Reason |
|------|--------|
| `src/afs-connect-api.ts` | Hub — cloud only |
| `src/afsConnect*.ts` | Hub UI |
| `src/afsConnectKanbanBadges.ts` | Cross-app badges — cloud only |
| `checkpoint/*` (full SDK) | Replace with local auth stub |
| `app/Services/AfsConnect/*` | Server hub |
| `public/core/**` | Legacy PHP monolith |
| `routes/api.php` afs-connect routes | Hub |

## Data model summary

**Canonical:** `ProjectData.source: SourceTask[]`

**Persisted (matches `projectDataToPersistPayload`):**
- `source[]`
- `kanbanColumns` (column defs only — no cards)
- `budget2Data`, `budget2Archive`
- `activity[]`, `history[]`, `tagRegistry[]`

**Derived (rebuilt on load via `project-sync.ts`):**
- `tasks`, `tableData`, `kanbanColumns.cards`, `ganttData`, `stats`

**Workspace envelope:**
- `currentProject`, `users[]`, `projects[]`, `teams[]`
- `projectData: Record<projectId, ProjectData>`

Web persists normalized rows in `pm_*` MySQL tables via `PmPersistenceService`.
Desktop persists JSON files mirroring the **API payload shape** (not normalized SQL).

## Sync-related vs offline-local

### Requires network (defer)

| Feature | Why |
|---------|-----|
| Checkpoint auth gate | `main.ts` redirects unauthenticated users |
| `pm-api.ts` REST calls | All PM persistence when online |
| Checkpoint secondary users | User roster from API |
| Entire afs-connect subsystem | Index, reconcile, links, insights |
| Brain member resolution | `GET /member?user_id=` |
| Engine surface | Game editor push/sync |
| Scheduled reconcile jobs | Background pull from peer apps |
| Parse / Flagsmith entitlement | Premium gating |

### Works offline (port target)

| Feature | Notes |
|---------|-------|
| Demo data bootstrap | `load-demo-data.ts` |
| In-memory PM editing | All views on `projectData` |
| `project-sync.ts` engine | Pure client logic |
| Kanban / tasks / table / gantt / budget UI | No network during editing |
| Theme, i18n, modals | Client-only |
| ProTable/Budget2 UI prefs | Already use `localStorage` |
| Project/team CRUD logic | Needs local persistence adapter |

## Web PM maturity notes (inform port expectations)

From `resources/js/_plan/overall/00-overview.md`:

| View | Current web state |
|------|-------------------|
| Dashboard | Charts partially hardcoded — `stats` computed but not always rendered |
| Kanban | Solid core; bare cards (no due/assignee on card face in all cases) |
| Task list | Functional; no comments grouping in list |
| ProTable | Most advanced view — benchmark |
| Gantt | Read-only; fixed decade window |
| Budget2 | Full create/edit/submit — own plan folder |
| Notifications | Hardcoded seed — no real event feed |

Desktop v1 ports **as-is web behavior**; dashboard real-data fix is Phase 8.

## npm dependencies to add to afsdesktop5

From coordinator `package.json` (evaluate in Phase 0 spike):

| Package | Used by |
|---------|---------|
| `sortablejs` | Kanban, ProTable DnD |
| `dayjs` | Date formatting across views |
| `fuse.js` | ProTable search (optional Phase 6+) |
| `chart.js` | Dashboard charts |
| `@tabler/core` | Already in afsdesktop5 |
| `bootstrap` | Already in afsdesktop5 |

**Do not add:** `parse`, `mysql2`, `express`, `jsonwebtoken` (server/auth only).

## Reference docs

| Doc | Path |
|-----|------|
| Web PM roadmap | `coordinator/resources/js/_plan/overall/` |
| Budget2 plan | `coordinator/resources/js/_plan/budget/` |
| AFS Connect (hub) | `apps/afsserver/plans/afs-connect/` |
| Laravel PM service | `coordinator/app/Services/Pm/PmPersistenceService.php` |
