# Flow — Desktop (Appfactory Studio) Port Plan

Port the web **Flow** app (`apps/flow`) into the Electron desktop **Appfactory
Studio** (`apps/afsdesktop5`) as an **offline-first** Excalidraw drawing tool.

Flow is intentionally **simpler** than Coordinator or Dream Graph: one JSON scene
per project, four views, and a single React editor surface.

**Status:** ✅ Complete (Phases 0–5). See [complete/](complete/README.md).

## How to use this plan

Work **phase by phase**. Each phase has exit criteria. Do not skip Phase 0–1;
they establish the same IPC + local-file patterns used for Notes Pro, Dream
Graph, and Coordinator.

| Doc | Purpose |
|-----|---------|
| [00-overview.md](00-overview.md) | Goals, non-goals, glossary |
| [01-web-app-audit.md](01-web-app-audit.md) | What Flow is today (web) |
| [02-desktop-architecture.md](02-desktop-architecture.md) | Shell, IPC, adapter swap |
| [03-data-model-and-files.md](03-data-model-and-files.md) | Project records, on-disk layout |
| [04-implementation-phases.md](04-implementation-phases.md) | **Build order + exit criteria** |

## Related documents

| Document | Relevance |
|----------|-----------|
| `apps/flow/` | Source web app |
| `apps/afsdesktop5/src/renderer/components/coordinator/` | Vanilla TS shell + hash router reference |
| `apps/afsdesktop5/src/renderer/components/dreamGraph/` | React-in-Electron + main store reference |
| `apps/afsdesktop5/src/main/notesPro/store.ts` | Local project file I/O pattern |

## Phase summary

| Phase | Deliverable | Status |
|-------|-------------|--------|
| **0** | Placeholder view + sidebar nav + i18n | ✅ |
| **1** | Local project CRUD on disk (main store + IPC) | ✅ |
| **2** | Dashboard — list, create, delete, open project | ✅ |
| **3** | Draw editor — Excalidraw mount, save, load | ✅ |
| **4** | Rename project, canvas theme presets, polish | ✅ |
| **5** | Import/export project JSON | ✅ |

Detail: [04-implementation-phases.md](04-implementation-phases.md).
