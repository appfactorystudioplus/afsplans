# Coordinator — Desktop (Appfactory Studio) Port Plan

Port the web **Coordinator** app (`apps/coordinator`) into the Electron desktop
**Appfactory Studio** (`apps/afsdesktop5`) as an **offline-first** project-management
hub.

Coordinator is the **most comprehensive** desktop app: full PM tool locally (tasks,
kanban, table, gantt, budget, settings, import/export).

**Status:** ✅ Complete (Phases 0–11). See [complete/README.md](complete/README.md).

## How to use this plan

Work **phase by phase**. Each phase has exit criteria. Do not skip Phase 0–2;
they establish the same patterns used for Notes Pro and Dream Graph.

| Doc | Purpose |
|-----|---------|
| [00-overview.md](00-overview.md) | Goals, non-goals, glossary, relationship to other apps |
| [01-web-app-audit.md](01-web-app-audit.md) | What Coordinator is today (PM + afs-connect split) |
| [02-desktop-architecture.md](02-desktop-architecture.md) | Electron shell, IPC, nav placement, module layout |
| [03-data-model-and-files.md](03-data-model-and-files.md) | `ProjectData`, on-disk layout, persistence adapter |
| [04-pm-views-and-modules.md](04-pm-views-and-modules.md) | Dashboard, kanban, tasks, table, gantt, budget, settings |
| [05-source-first-sync-engine.md](05-source-first-sync-engine.md) | Client-side `source[]` → derived views (offline core) |
| [06-implementation-phases.md](06-implementation-phases.md) | **Step-by-step build order with exit criteria** |

Completion notes (written after each phase ships): [complete/README.md](complete/README.md)

## Phase summary (headline)

| Phase | Deliverable | Status |
|-------|-------------|--------|
| **0** | Placeholder view + nav (Apps, above Notes) + i18n | ✅ |
| **1** | Local workspace folder + load/save via main process | ✅ |
| **2** | Data spine (`types`, `project-sync`, `ProjectDataManager`) | ✅ |
| **3** | Shell + hash router + project switcher | ✅ |
| **4** | Task list + task edit offcanvas | ✅ |
| **5** | Kanban board | ✅ |
| **6** | ProTable (`table2`) | ✅ |
| **7** | Gantt | ✅ |
| **8** | Dashboard (real data from sync engine) | ✅ |
| **9** | Budget2 module | ✅ |
| **10** | Settings (projects, teams) + theme/i18n polish | ✅ |
| **11** | Import/export project JSON | ✅ |

Detail and acceptance tests: [06-implementation-phases.md](06-implementation-phases.md).

## Related documents

| Document | Relevance |
|----------|-----------|
| `apps/coordinator/` | Source web app (Laravel + TypeScript SPA) |
| `apps/coordinator/resources/js/_plan/overall/` | Web PM feature roadmap (SourceTask v2, etc.) |
| `apps/afsdesktop5/src/renderer/components/notesPro/` | Reference desktop port (IPC store, manager, shell) |
| `apps/afsdesktop5/src/renderer/components/dreamGraph/` | Reference for large React subsystem port |
| [../dream-graph/README.md](../dream-graph/README.md) | Sister offline-first port (complete Phases 0–6) |
| [../flow/README.md](../flow/README.md) | Sister offline-first port (complete Phases 0–5) |

## Nav placement

Coordinator sidebar item is in the **Apps** section, **above Notes**:

```
Apps
  Studio
  Coordinator
  Notes
  PDF
  Dream Graph
  Flow
```

See [02-desktop-architecture.md](02-desktop-architecture.md) for the wiring chain.
